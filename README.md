# Tupay

Tupay is a Laravel ledger and settlement API for NGN/CNY wallet operations. It focuses on integer-safe money movement, auditable ledger entries, Sanctum bearer-token authentication, TOTP-gated financial actions, atomic currency swaps, and idempotent settlement webhooks.

## Highlights

- Multi-currency wallets backed by integer minor-unit balances.
- Immutable ledger rows for deposits, transfers, swaps, and settlement credits.
- Laravel Sanctum bearer tokens plus TOTP verification for financial actions.
- HMAC-SHA256 settlement webhook verification.
- Database transactions, row-level locks, Redis atomic locks, and idempotency keys for safe retries.
- Laravel Pint and Larastan/PHPStan quality gates.

## Stack

- PHP 8.2
- Laravel 11
- SQLite for tests and local development; MySQL/PostgreSQL-ready migrations
- Redis for production cache, locks, and queues
- PHPUnit 11
- Laravel Pint
- Larastan / PHPStan
- Vite for frontend assets

## Project Structure

```text
app/Actions      Application use cases for authentication and money movement
app/Enums        Ledger direction, status, and type values
app/Http         API controllers, middleware, requests, and resources
app/Jobs         Asynchronous settlement webhook processing
app/Models       Eloquent models for users, accounts, rates, ledgers, webhooks
app/Services     Wallet, exchange-rate, and two-factor domain services
database         Migrations, factories, and seeders
postman          API collection for manual review
tests            Feature and unit tests
```

## Local Setup

Install dependencies:

```bash
composer install
npm install
```

Create the environment file and application key:

```bash
cp .env.example .env
php artisan key:generate
```

Prepare the local database:

```bash
touch database/database.sqlite
php artisan migrate:fresh --seed
```

Start the API:

```bash
php artisan serve
```

Run the queue worker when testing settlement webhook processing outside the test suite:

```bash
php artisan queue:work
```

## API Surface

| Method | Endpoint | Description | Security Level |
| --- | --- | --- | --- |
| POST | `/api/login` | Authenticate user and return token. | Rate-limited |
| POST | `/api/2fa/verify` | Verify TOTP for the current session. | Rate-limited |
| POST | `/api/swap` | Exchange NGN for CNY. | 2FA Required |
| POST | `/api/webhooks/settlement` | Third-party confirmation. | Signature Verified |
| GET | `/api/ledger/{wallet_id}` | Paginated transaction history. | Optimized Indexing |

Additional implemented endpoints:

| Method | Endpoint | Description | Security Level |
| --- | --- | --- | --- |
| POST | `/api/transfer` | Transfer between same-currency accounts. | 2FA Required |
| POST | `/api/accounts` | Create an NGN or CNY wallet. | 2FA Required |
| GET | `/api/accounts/{account}` | View wallet details. | 2FA Required |
| POST | `/api/accounts/{account}/deposits` | Seed or credit a wallet through an idempotent deposit flow. | 2FA Required |

Assessment helper routes for account creation and deposits are also protected by bearer token, TOTP, and finance rate limits.

## Architecture

Controllers stay thin and delegate business rules into action and service classes:

- `app/Actions` contains use cases such as `AuthenticateUserAction`, `SwapAction`, `TransferAction`, and settlement webhook intake/processing.
- `app/Services/WalletService.php` owns balance mutation, ledger entry creation, idempotency checks, and integer-safe rate conversion.
- `app/Services/ExchangeRateService.php` hides cached exchange-rate lookup.
- `app/Services/TwoFactorService.php` implements RFC-style TOTP generation and verification without floats.
- `app/Http/Middleware` contains the high-value action gate and webhook signature verification.
- `app/Jobs/ProcessSettlementWebhook.php` performs asynchronous settlement crediting and notification.

The database separates current balances (`accounts.balance_minor`) from immutable audit history (`ledger_transactions`). A balance can be checked by summing completed credit/debit ledger rows for the wallet, and every mutation stores `balance_before_minor`, `balance_after_minor`, `idempotency_key`, and JSON `metadata`.

## Concurrency Strategy

Swaps are protected at three layers:

- `SwapAction` takes a per-user `Cache::lock("swap:user:{id}")`, backed by Redis in production, so the same user cannot run overlapping swaps.
- `WalletService` wraps swaps/transfers/credits in `DB::transaction()`.
- Source and destination accounts are selected in stable ID order with `lockForUpdate()`, preventing lost updates and reducing deadlock risk.

Idempotency keys are unique per ledger direction, so retrying the same swap or transfer returns the existing debit/credit pair instead of mutating balances again.

## Security Measures

`POST /api/login` validates credentials and issues a Laravel Sanctum bearer token. Dashboard-style reads can use the token, but financial routes also require a verified TOTP session via `X-Two-Factor-Session`.

The 2FA flow is intentionally short-lived:

- Login creates a random pending 2FA session token for 10 minutes.
- `POST /api/2fa/verify` requires `Authorization: Bearer <token>` and a valid six-digit TOTP code.
- Successful verification moves the session into a verified cache key for 10 minutes.

Auth, finance, and webhook endpoints use named Laravel rate limiters. Webhooks are protected with `X-Tupay-Signature`, an HMAC-SHA256 of the exact raw request body using `SETTLEMENT_WEBHOOK_SECRET`.

## Performance Optimization

Exchange rates are read through `ExchangeRateService`, which caches the active NGN/CNY `rate_micro` for 30 seconds. This avoids repeated database reads during swap bursts while keeping rates fresh.

Production should set:

```env
CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

That makes both exchange-rate caching and per-user swap locks shared across app servers. Local tests still run without Redis by using Laravel's test database/cache environment.

## Settlement Webhook Assumptions

The mock partner sends `provider_reference`, `account_id`, `amount_minor`, `currency`, and `status`. Only completed CNY payouts are accepted. Duplicate webhooks with the same payload return success without re-crediting; duplicates with conflicting amount/account/currency/status return `409 Conflict`.

## Two-Factor Flow

Log in with the seeded reviewer account:

```bash
curl -X POST http://127.0.0.1:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password","device_name":"local"}'
```

The response includes `two_factor.session_token`. The first login also returns a TOTP `secret` and `provisioning_uri` so the account can be added to an authenticator app.
The response also includes `access_token`; pass it as `Authorization: Bearer <token>`.

Verify the current six-digit code:

```bash
curl -X POST http://127.0.0.1:8000/api/2fa/verify \
  -H "Authorization: Bearer PASTE_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"code":"123456","session_token":"PASTE_SESSION_TOKEN"}'
```

Use the same token for protected financial requests:

```bash
curl -X POST http://127.0.0.1:8000/api/swap \
  -H "Authorization: Bearer PASTE_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Two-Factor-Session: PASTE_SESSION_TOKEN" \
  -d '{"source_account_id":1,"destination_account_id":2,"amount_minor":50000,"idempotency_key":"local-swap-1"}'
```

The seeded reviewer account is:

```text
Email: test@example.com
Password: password
TOTP secret: JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP
NGN opening balance: 100000 minor units, inserted through the ledger
CNY wallet: created with zero balance
Seeded rate: NGN/CNY rate_micro = 500
```

## Quality Gates

Run the full verification suite before opening or updating a pull request:

```bash
composer quality
```

Individual commands are available when you need a narrower check:

```bash
composer lint      # Laravel Pint in check mode
composer format    # Laravel Pint auto-formatting
composer analyse   # Larastan/PHPStan static analysis
composer test      # PHPUnit feature and unit tests
```

The PHPStan configuration lives in `phpstan.neon` and currently uses level 5. Raise the level only when the codebase is clean enough to keep the signal high.

## Professional Standards

Keep changes readable and reviewable:

- Use descriptive class, method, variable, route, and test names.
- Put business rules in actions or services instead of growing controllers.
- Store money as integer minor units; do not introduce floating point arithmetic for balances or ledger amounts.
- Add or update tests for behavior changes, especially money movement, authentication, idempotency, and webhook processing.
- Run Pint, PHPStan, and PHPUnit before committing.



## Environment Notes

Set `SETTLEMENT_WEBHOOK_SECRET` in every runtime environment. Webhook middleware fails fast when the secret is missing, and production review should reject empty or example secrets.

For multi-server deployments, use Redis for `CACHE_STORE` so user-level swap locks work consistently across app servers.
