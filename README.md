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

## Postman Collection

The repository includes a Postman collection for manual API testing at `postman/tupay-api.postman_collection.json`. Import that file into Postman, set `base_url` to your local server such as `http://127.0.0.1:8000`, then run the authentication and wallet requests from the collection.

## API Surface

| Method | Endpoint | Description | Security Level |
| --- | --- | --- | --- |
| POST | `/api/login` | Authenticate user and return token. | Rate-limited |
| POST | `/api/2fa/verify` | Verify TOTP for the current session. | Rate-limited |
| POST | `/api/swap` | Exchange NGN for CNY. | 2FA Required |
| POST | `/api/webhooks/settlement` | Third-party confirmation. | Signature Verified |
| GET | `/api/ledger/{wallet_id}` | Paginated transaction history. | Bearer Auth + Optimized Indexing |

Additional implemented endpoints:

| Method | Endpoint | Description | Security Level |
| --- | --- | --- | --- |
| POST | `/api/transfer` | Transfer between same-currency accounts. | 2FA Required |
| POST | `/api/accounts` | Create an NGN or CNY wallet. | 2FA Required |
| GET | `/api/accounts/{account}` | View wallet details. | Bearer Auth |
| POST | `/api/accounts/{account}/deposits` | Seed or credit a wallet through an idempotent deposit flow. | 2FA Required |

Dashboard-style read routes require bearer auth and finance rate limits. Account creation, deposits, swaps, and transfers are protected by bearer token, TOTP, and finance rate limits.

## Architecture

Controllers stay thin and delegate business rules into action and service classes:

- `app/Actions` contains use cases such as `AuthenticateUserAction`, `SwapAction`, `TransferAction`, and settlement webhook intake/processing.
- `app/Services/WalletService.php` owns balance mutation, ledger entry creation, idempotency checks, and integer-safe rate conversion.
- `app/Services/ExchangeRateService.php` hides cached exchange-rate lookup.
- `app/Services/TwoFactorService.php` implements RFC-style TOTP generation and verification without floats.
- `app/Http/Middleware` contains the high-value action gate and webhook signature verification.
- `app/Jobs/ProcessSettlementWebhook.php` performs asynchronous settlement crediting and notification.

The database separates current balances (`accounts.balance_minor`) from immutable audit history (`ledger_transactions`). A balance can be checked by summing completed credit/debit ledger rows for the wallet, and every mutation stores `balance_before_minor`, `balance_after_minor`, `idempotency_key`, and JSON `metadata`. Deposits and settlement credits are also double-entry: the user wallet receives the credit, and a system-owned clearing wallet records the matching debit.

## Concurrency Strategy

Swaps are protected at three layers:

- `SwapAction` takes a per-user `Cache::lock("swap:user:{id}")`, backed by Redis in production, so the same user cannot run overlapping swaps.
- `WalletService` wraps swaps/transfers/credits in `DB::transaction()`.
- Source and destination accounts are selected in stable ID order with `lockForUpdate()`, preventing lost updates and reducing deadlock risk.

Idempotency keys are unique per ledger direction, so retrying the same deposit, swap, transfer, or settlement returns the existing ledger entries instead of mutating balances again.

## Security Measures

`POST /api/login` validates credentials and issues a Laravel Sanctum bearer token. Dashboard-style reads can use the token, but financial write routes also require a verified TOTP session via `X-Two-Factor-Session`. TOTP secrets are stored with Laravel's encrypted cast so the raw shared secret is not persisted in plaintext.

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

The mock partner sends `provider_reference`, `account_id`, `amount_minor`, `currency`, and `status`. Only completed CNY payouts are accepted. Duplicate webhooks with the same payload return success without re-crediting; duplicates with conflicting amount/account/currency/status return `409 Conflict`. Webhook processing runs through a queued job with retries and backoff before the user notification is queued.

## Two-Factor Flow

Log in with the seeded reviewer account:

```bash
curl -X POST http://127.0.0.1:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password","device_name":"local"}'
```

The response includes `access_token` and `two_factor.session_token`. Pass the token as `Authorization: Bearer <token>` on the verify request, and pass the session token in the request body.

For a brand-new 2FA setup, the login response also returns `two_factor.secret` and `two_factor.provisioning_uri`. Add the secret or provisioning URI to Google Authenticator, Authy, 1Password, or another TOTP app, then use the current six-digit code from that app.

For local testing, you can generate the same six-digit code from the terminal. Make sure the email in the command is the same email you used for `/api/login`; otherwise the code will be generated from a different user's secret and `/api/2fa/verify` will return `Invalid two-factor code`.

```bash
php artisan tinker --execute='$user = App\Models\User::where("email", "test@example.com")->firstOrFail(); echo app(App\Services\TwoFactorService::class)->totp($user->two_factor_secret).PHP_EOL;'
```

The generated code changes every 30 seconds, so run the command immediately before verifying 2FA.

Verify the current six-digit code:

```bash
curl -X POST http://127.0.0.1:8000/api/2fa/verify \
  -H "Authorization: Bearer PASTE_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"code":"123456","session_token":"PASTE_SESSION_TOKEN"}'
```

Use the same bearer token and verified 2FA session for protected financial requests. The seeded account usually has NGN wallet ID `1` and CNY wallet ID `2`; replace IDs if your local database differs.

Create an account:

```bash
curl -X POST http://127.0.0.1:8000/api/accounts \
  -H "Authorization: Bearer PASTE_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Two-Factor-Session: PASTE_SESSION_TOKEN" \
  -d '{"currency":"CNY"}'
```

View an account:

```bash
curl -X GET http://127.0.0.1:8000/api/accounts/1 \
  -H "Authorization: Bearer PASTE_ACCESS_TOKEN"
```

Deposit into an account:

```bash
curl -X POST http://127.0.0.1:8000/api/accounts/1/deposits \
  -H "Authorization: Bearer PASTE_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Two-Factor-Session: PASTE_SESSION_TOKEN" \
  -d '{"amount_minor":25000,"idempotency_key":"local-deposit-1","metadata":{"channel":"curl"}}'
```

Swap NGN to CNY:

```bash
curl -X POST http://127.0.0.1:8000/api/swap \
  -H "Authorization: Bearer PASTE_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Two-Factor-Session: PASTE_SESSION_TOKEN" \
  -d '{"source_account_id":1,"destination_account_id":2,"amount_minor":50000,"idempotency_key":"local-swap-1","metadata":{"channel":"curl"}}'
```

Transfer between same-currency accounts:

```bash
curl -X POST http://127.0.0.1:8000/api/transfer \
  -H "Authorization: Bearer PASTE_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Two-Factor-Session: PASTE_SESSION_TOKEN" \
  -d '{"source_account_id":1,"destination_account_id":3,"amount_minor":10000,"idempotency_key":"local-transfer-1","metadata":{"channel":"curl"}}'
```

List ledger transactions:

```bash
curl -X GET "http://127.0.0.1:8000/api/ledger/1?per_page=25" \
  -H "Authorization: Bearer PASTE_ACCESS_TOKEN"
```

Send a signed settlement webhook. The signature must be generated from the exact raw body sent to the API, and the secret must match `SETTLEMENT_WEBHOOK_SECRET`.

```bash
BODY='{"provider_reference":"provider-rmb-local-1","account_id":2,"amount_minor":12500,"currency":"CNY","status":"completed"}'
SIGNATURE=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "settlement-test-secret" -binary | xxd -p -c 256)

curl -X POST http://127.0.0.1:8000/api/webhooks/settlement \
  -H "Content-Type: application/json" \
  -H "X-Tupay-Signature: $SIGNATURE" \
  -d "$BODY"
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

For multi-server deployments, use MySQL or PostgreSQL for `DB_CONNECTION` and Redis for `CACHE_STORE` and `QUEUE_CONNECTION` so ledger row locks, cached exchange rates, user-level swap locks, and queued settlement processing work consistently across app servers.
