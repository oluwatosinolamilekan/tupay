<?php

namespace App\Services;

use App\Enums\LedgerTransactionDirection;
use App\Enums\LedgerTransactionStatus;
use App\Enums\LedgerTransactionType;
use App\Models\Account;
use App\Models\LedgerTransaction;
use App\Models\User;
use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService
{
    private const RATE_MICRO_SCALE = '1000000';

    private const SYSTEM_EMAIL = 'system@tupay.local';

    public function createAccount(User $user, string $currency): Account
    {
        $currency = strtoupper($currency);

        return Account::firstOrCreate(
            ['user_id' => $user->id, 'currency' => $currency],
            [
                'account_number' => $this->generateAccountNumber(),
                'balance_minor' => 0,
            ]
        );
    }

    public function deposit(Account $account, int $amountMinor, string $idempotencyKey, array $metadata = []): LedgerTransaction
    {
        return $this->externalCredit($account, $amountMinor, $idempotencyKey, LedgerTransactionType::Deposit, $metadata);
    }

    public function creditSettlement(Account $account, int $amountMinor, string $idempotencyKey, array $metadata = []): LedgerTransaction
    {
        return $this->externalCredit($account, $amountMinor, $idempotencyKey, LedgerTransactionType::Settlement, $metadata);
    }

    /**
     * @return array{debit: LedgerTransaction, credit: LedgerTransaction}
     */
    public function swap(Account $source, Account $destination, int $amountMinor, int $rateMicro, string $idempotencyKey, array $metadata = []): array
    {
        if ($source->currency !== 'NGN' || $destination->currency !== 'CNY') {
            throw new DomainException('Swaps are only supported from NGN to CNY.');
        }

        $convertedAmountMinor = $this->convertMinorUnits($amountMinor, $rateMicro);

        if ($convertedAmountMinor < 1) {
            throw new DomainException('Swap amount is too small for the configured exchange rate.');
        }

        return DB::transaction(function () use ($source, $destination, $amountMinor, $convertedAmountMinor, $idempotencyKey, $metadata): array {
            $existing = LedgerTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('direction', LedgerTransactionDirection::Debit->value)
                ->first();

            if ($existing !== null) {
                $credit = LedgerTransaction::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->where('direction', LedgerTransactionDirection::Credit->value)
                    ->firstOrFail();

                return ['debit' => $existing, 'credit' => $credit];
            }

            /** @var Collection<int, Account> $lockedAccounts */
            $lockedAccounts = Account::query()
                ->whereIn('id', [$source->id, $destination->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Account $lockedSource */
            $lockedSource = $lockedAccounts->get($source->id);
            /** @var Account $lockedDestination */
            $lockedDestination = $lockedAccounts->get($destination->id);

            if ($lockedSource->balance_minor < $amountMinor) {
                throw new DomainException('Insufficient funds.');
            }

            $sourceBefore = $lockedSource->balance_minor;
            $sourceAfter = $sourceBefore - $amountMinor;
            $destinationBefore = $lockedDestination->balance_minor;
            $destinationAfter = $destinationBefore + $convertedAmountMinor;
            $reference = $this->reference();

            $lockedSource->forceFill(['balance_minor' => $sourceAfter])->save();
            $lockedDestination->forceFill(['balance_minor' => $destinationAfter])->save();

            $debit = LedgerTransaction::create([
                'account_id' => $lockedSource->id,
                'counterparty_account_id' => $lockedDestination->id,
                'reference' => $reference.'-NGN',
                'idempotency_key' => $idempotencyKey,
                'type' => LedgerTransactionType::Swap,
                'direction' => LedgerTransactionDirection::Debit,
                'amount_minor' => $amountMinor,
                'balance_before_minor' => $sourceBefore,
                'balance_after_minor' => $sourceAfter,
                'status' => LedgerTransactionStatus::Completed,
                'metadata' => $metadata,
            ]);

            $credit = LedgerTransaction::create([
                'account_id' => $lockedDestination->id,
                'counterparty_account_id' => $lockedSource->id,
                'reference' => $reference.'-CNY',
                'idempotency_key' => $idempotencyKey,
                'type' => LedgerTransactionType::Swap,
                'direction' => LedgerTransactionDirection::Credit,
                'amount_minor' => $convertedAmountMinor,
                'balance_before_minor' => $destinationBefore,
                'balance_after_minor' => $destinationAfter,
                'status' => LedgerTransactionStatus::Completed,
                'metadata' => $metadata,
            ]);

            return ['debit' => $debit, 'credit' => $credit];
        });
    }

    /**
     * @return array{debit: LedgerTransaction, credit: LedgerTransaction}
     */
    public function transfer(Account $source, Account $destination, int $amountMinor, string $idempotencyKey, array $metadata = []): array
    {
        if ($source->is($destination)) {
            throw new DomainException('Source and destination accounts must be different.');
        }

        if ($source->currency !== $destination->currency) {
            throw new DomainException('Transfers are only supported between accounts with the same currency.');
        }

        return DB::transaction(function () use ($source, $destination, $amountMinor, $idempotencyKey, $metadata): array {
            $existingDebit = LedgerTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('direction', LedgerTransactionDirection::Debit->value)
                ->first();

            if ($existingDebit !== null) {
                $existingCredit = LedgerTransaction::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->where('direction', LedgerTransactionDirection::Credit->value)
                    ->firstOrFail();

                return ['debit' => $existingDebit, 'credit' => $existingCredit];
            }

            /** @var Collection<int, Account> $lockedAccounts */
            $lockedAccounts = Account::query()
                ->whereIn('id', [$source->id, $destination->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Account $lockedSource */
            $lockedSource = $lockedAccounts->get($source->id);
            /** @var Account $lockedDestination */
            $lockedDestination = $lockedAccounts->get($destination->id);

            if ($lockedSource->balance_minor < $amountMinor) {
                throw new DomainException('Insufficient funds.');
            }

            $sourceBefore = $lockedSource->balance_minor;
            $sourceAfter = $sourceBefore - $amountMinor;
            $destinationBefore = $lockedDestination->balance_minor;
            $destinationAfter = $destinationBefore + $amountMinor;
            $reference = $this->reference();

            $lockedSource->forceFill(['balance_minor' => $sourceAfter])->save();
            $lockedDestination->forceFill(['balance_minor' => $destinationAfter])->save();

            $debit = LedgerTransaction::create([
                'account_id' => $lockedSource->id,
                'counterparty_account_id' => $lockedDestination->id,
                'reference' => $reference.'-DR',
                'idempotency_key' => $idempotencyKey,
                'type' => LedgerTransactionType::Transfer,
                'direction' => LedgerTransactionDirection::Debit,
                'amount_minor' => $amountMinor,
                'balance_before_minor' => $sourceBefore,
                'balance_after_minor' => $sourceAfter,
                'status' => LedgerTransactionStatus::Completed,
                'metadata' => $metadata,
            ]);

            $credit = LedgerTransaction::create([
                'account_id' => $lockedDestination->id,
                'counterparty_account_id' => $lockedSource->id,
                'reference' => $reference.'-CR',
                'idempotency_key' => $idempotencyKey,
                'type' => LedgerTransactionType::Transfer,
                'direction' => LedgerTransactionDirection::Credit,
                'amount_minor' => $amountMinor,
                'balance_before_minor' => $destinationBefore,
                'balance_after_minor' => $destinationAfter,
                'status' => LedgerTransactionStatus::Completed,
                'metadata' => $metadata,
            ]);

            return ['debit' => $debit, 'credit' => $credit];
        });
    }

    private function generateAccountNumber(): string
    {
        do {
            $number = (string) random_int(1000000000, 9999999999);
        } while (Account::query()->where('account_number', $number)->exists());

        return $number;
    }

    private function reference(): string
    {
        return 'TXN-'.Str::ulid();
    }

    private function convertMinorUnits(int $amountMinor, int $rateMicro): int
    {
        try {
            return BigInteger::of((string) $amountMinor)
                ->multipliedBy((string) $rateMicro)
                ->dividedBy(self::RATE_MICRO_SCALE, RoundingMode::Down)
                ->toInt();
        } catch (IntegerOverflowException) {
            throw new DomainException('Converted swap amount exceeds supported minor-unit range.');
        }
    }

    private function externalCredit(Account $account, int $amountMinor, string $idempotencyKey, LedgerTransactionType $type, array $metadata = []): LedgerTransaction
    {
        return DB::transaction(function () use ($account, $amountMinor, $idempotencyKey, $type, $metadata): LedgerTransaction {
            $existing = LedgerTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('direction', LedgerTransactionDirection::Credit->value)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $clearing = $this->clearingAccount($account->currency);

            /** @var Collection<int, Account> $lockedAccounts */
            $lockedAccounts = Account::query()
                ->whereIn('id', [$clearing->id, $account->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Account $lockedClearing */
            $lockedClearing = $lockedAccounts->get($clearing->id);
            /** @var Account $lockedAccount */
            $lockedAccount = $lockedAccounts->get($account->id);

            $clearingBefore = $lockedClearing->balance_minor;
            $clearingAfter = $clearingBefore - $amountMinor;
            $accountBefore = $lockedAccount->balance_minor;
            $accountAfter = $accountBefore + $amountMinor;
            $reference = $this->reference();

            $lockedClearing->forceFill(['balance_minor' => $clearingAfter])->save();
            $lockedAccount->forceFill(['balance_minor' => $accountAfter])->save();

            LedgerTransaction::create([
                'account_id' => $lockedClearing->id,
                'counterparty_account_id' => $lockedAccount->id,
                'reference' => $reference.'-CLR',
                'idempotency_key' => $idempotencyKey,
                'type' => $type,
                'direction' => LedgerTransactionDirection::Debit,
                'amount_minor' => $amountMinor,
                'balance_before_minor' => $clearingBefore,
                'balance_after_minor' => $clearingAfter,
                'status' => LedgerTransactionStatus::Completed,
                'metadata' => array_merge($metadata, ['clearing_account' => true]),
            ]);

            return LedgerTransaction::create([
                'account_id' => $lockedAccount->id,
                'counterparty_account_id' => $lockedClearing->id,
                'reference' => $reference.'-CR',
                'idempotency_key' => $idempotencyKey,
                'type' => $type,
                'direction' => LedgerTransactionDirection::Credit,
                'amount_minor' => $amountMinor,
                'balance_before_minor' => $accountBefore,
                'balance_after_minor' => $accountAfter,
                'status' => LedgerTransactionStatus::Completed,
                'metadata' => $metadata,
            ]);
        });
    }

    private function clearingAccount(string $currency): Account
    {
        $user = User::firstOrCreate(
            ['email' => self::SYSTEM_EMAIL],
            [
                'name' => 'Tupay Clearing',
                'password' => Str::password(32),
                'email_verified_at' => now(),
            ],
        );

        return $this->createAccount($user, $currency);
    }
}
