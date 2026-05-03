# Tupay

Tupay is a Laravel 12 ledger and settlement API for NGN/CNY wallet operations. It focuses on integer-safe money movement, auditable ledger entries, TOTP-gated financial actions, atomic currency swaps, and idempotent settlement webhooks.

## Highlights

- Multi-currency wallets backed by integer minor-unit balances.
- Immutable ledger rows for deposits, transfers, swaps, and settlement credits.
- Password plus TOTP verification for financial actions.
- HMAC-SHA256 settlement webhook verification.
- Database transactions, row-level locks, and idempotency keys for safe retries.
- Laravel Pint and Larastan/PHPStan quality gates.

## Stack

- PHP 8.2
- Laravel 12
- SQLite for local development and tests
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
php artisan db:seed --class=TestUserSeeder
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

```http
POST /api/login
POST /api/2fa/verify
POST /api/swap
POST /api/transfer
POST /api/webhooks/settlement
GET  /api/ledger/{wallet_id}
```

Assessment helper routes for account creation, deposits, and same-currency transfers remain available for local testing.

## Two-Factor Flow

Log in with the seeded reviewer account:

```bash
curl -X POST http://127.0.0.1:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password","device_name":"local"}'
```

The response includes `two_factor.session_token`. The first login also returns a TOTP `secret` and `provisioning_uri` so the account can be added to an authenticator app.

Verify the current six-digit code:

```bash
curl -X POST http://127.0.0.1:8000/api/2fa/verify \
  -u test@example.com:password \
  -H "Content-Type: application/json" \
  -d '{"code":"123456","session_token":"PASTE_SESSION_TOKEN"}'
```

Use the same token for protected financial requests:

```bash
curl -X POST http://127.0.0.1:8000/api/swap \
  -u test@example.com:password \
  -H "Content-Type: application/json" \
  -H "X-Two-Factor-Session: PASTE_SESSION_TOKEN" \
  -d '{"source_account_id":1,"destination_account_id":2,"amount_minor":50000,"idempotency_key":"local-swap-1"}'
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

## Commit Workflow

Use Conventional Commits and stage only the files that belong to the current change:

```bash
git add README.md
git commit -m "docs: refresh project readme"

git add composer.json composer.lock phpstan.neon
git commit -m "chore: add static analysis tooling"
```

Common commit types for this project:

- `feat:` user-facing behavior or API additions
- `fix:` bug fixes
- `test:` test-only changes
- `docs:` README, API notes, or reviewer guidance
- `chore:` tooling, dependency, or maintenance updates
- `refactor:` internal code changes with no behavior change

When a change touches unrelated areas, split it into separate commits. Each commit should be easy to review, easy to revert, and named after the value it delivers.

## Environment Notes

Set `SETTLEMENT_WEBHOOK_SECRET` in every runtime environment. Webhook middleware fails fast when the secret is missing, and production review should reject empty or example secrets.

For multi-server deployments, use Redis for `CACHE_STORE` so user-level swap locks work consistently across app servers.
