# Tupay Git Push Guide

Use this guide when preparing the Tupay app for Git. It explains which files should be pushed, which files should stay local, and the Conventional Commit messages to use for each type of change.

## Push These Files

These files describe the application source code, configuration, tests, and reviewer documentation. They should be committed when they are part of your change.

## File Groups and Commit Messages

Use these groups when staging and committing the app. Each group has one recommended Conventional Commit message. This plan gives you 20 focused commits, which makes the app easier to review and safer to push.

## Assessment Completion Changes

Use this section for the latest changes that make the project fully match the Laravel backend assessment. These commits are grouped by review topic, so the reviewer can see the authentication, security, seeding, documentation, and test work clearly.

## 100% Assessment Coverage Hardening

Use these commit messages for the latest changes that close the remaining assessment gaps: strict double-entry external credits, bearer-only dashboard reads, encrypted 2FA secrets, webhook retry/backoff, and reviewer documentation.

### 1. Add Clearing-Ledger Entries for External Credits

```bash
git add app/Services/WalletService.php
git add tests/Feature/Api/WalletApiTest.php tests/Feature/Api/SettlementWebhookApiTest.php
git commit -m "fix(ledger): record external credits as double entry"
```

Why:

```text
Adds a system-owned clearing wallet for deposits and settlement payouts so external credits produce both the user-facing credit and the matching clearing debit, while preserving idempotency for repeated deposits and webhooks.
```

### 2. Split Dashboard Reads From 2FA-Gated Writes

```bash
git add routes/api.php
git add tests/Feature/Api/TwoFactorGateApiTest.php
git commit -m "fix(auth): allow bearer-only dashboard reads"
```

Why:

```text
Keeps account and ledger read routes available after standard Sanctum login, while account creation, deposits, swaps, and transfers still require a verified TOTP session.
```

### 3. Harden 2FA Secret Storage

```bash
git add app/Models/User.php
git add tests/Feature/Api/AuthApiTest.php
git commit -m "fix(security): encrypt two factor secrets"
```

Why:

```text
Stores TOTP shared secrets using Laravel's encrypted cast and adds coverage that the raw secret is not persisted in plaintext.
```

### 4. Add Webhook Retry and Notification Backoff

```bash
git add app/Jobs/ProcessSettlementWebhook.php
git add app/Notifications/SettlementPayoutConfirmed.php
git commit -m "fix(webhook): add settlement retry backoff"
```

Why:

```text
Adds retry limits, timeouts, and backoff schedules to settlement webhook processing and payout notifications for more resilient asynchronous handling.
```

### 5. Update Assessment Documentation

```bash
git add README.md GIT_PUSH_GUIDE.md
git commit -m "docs: document full assessment coverage"
```

Why:

```text
Documents the bearer-read versus 2FA-write route boundary, clearing-ledger double-entry model, encrypted TOTP storage, webhook retry behavior, and production MySQL/PostgreSQL plus Redis expectations.
```

### One-Commit Alternative for This Hardening

```bash
git add README.md GIT_PUSH_GUIDE.md routes/api.php
git add app/Models/User.php app/Services/WalletService.php
git add app/Jobs/ProcessSettlementWebhook.php app/Notifications/SettlementPayoutConfirmed.php
git add tests/Feature/Api/AuthApiTest.php tests/Feature/Api/SettlementWebhookApiTest.php
git add tests/Feature/Api/TwoFactorGateApiTest.php tests/Feature/Api/WalletApiTest.php
git commit -m "fix: harden tupay assessment coverage"
```

Run these before committing:

```bash
/opt/homebrew/opt/php@8.2/bin/php artisan test
/opt/homebrew/opt/php@8.2/bin/php ./vendor/bin/pint --test
/opt/homebrew/opt/php@8.2/bin/php ./vendor/bin/phpstan analyse --memory-limit=512M
```

## Latest Pest Test Split Update

Use these commit messages for the current test-suite update. The order keeps the small API behavior change separate from the larger test/tooling conversion.

### 1. Return the 2FA Required Flag

```bash
git add app/Http/Middleware/RequireTwoFactor.php
git commit -m "fix(api): return two factor required flag"
```

Why:

```text
Adds the two_factor_required response flag to 403 responses from protected financial routes so API clients can reliably detect when 2FA verification is needed.
```

### 2. Convert the Suite to Pest and Split API Coverage

```bash
git add composer.json composer.lock tests/Pest.php
git add tests/Feature/Api/AuthApiTest.php
git add tests/Feature/Api/TwoFactorGateApiTest.php
git add tests/Feature/Api/SwapApiTest.php
git add tests/Feature/Api/SettlementWebhookApiTest.php
git add tests/Feature/Api/WalletApiTest.php
git add tests/Feature/ExampleTest.php
git add tests/Feature/TestUserSeederTest.php
git add tests/Unit/LedgerTransactionDirectionTest.php
git rm tests/Feature/Api/AssessmentApiTest.php
git commit -m "test(api): migrate endpoint coverage to pest"
```

Why:

```text
Adds Pest and the Laravel Pest plugin, introduces the Pest bootstrap file, converts the PHPUnit class tests to Pest syntax, and splits the assessment coverage into Auth, 2FA Gate, Swap, and Webhook endpoint test files.
```

### 3. Update the Git Push Guide

```bash
git add GIT_PUSH_GUIDE.md
git commit -m "docs: add pest test split commit plan"
```

Why:

```text
Documents the recommended commit messages and staging commands for the Pest conversion and endpoint-focused assessment test split.
```

### One-Commit Alternative for This Update

```bash
git add app/Http/Middleware/RequireTwoFactor.php composer.json composer.lock GIT_PUSH_GUIDE.md tests/Pest.php
git add tests/Feature/Api/AuthApiTest.php tests/Feature/Api/TwoFactorGateApiTest.php tests/Feature/Api/SwapApiTest.php tests/Feature/Api/SettlementWebhookApiTest.php
git add tests/Feature/Api/WalletApiTest.php tests/Feature/ExampleTest.php tests/Feature/TestUserSeederTest.php tests/Unit/LedgerTransactionDirectionTest.php
git rm tests/Feature/Api/AssessmentApiTest.php
git commit -m "test(api): migrate assessment coverage to pest"
```

Run `composer test` and `./vendor/bin/pint --test` before committing this update.

### A. Sanctum Token Authentication

```bash
git add composer.json composer.lock
git add app/Models/User.php
git add app/Actions/AuthenticateUserAction.php
git add app/Http/Resources/LoginResource.php
git add database/migrations/2026_05_03_000004_create_personal_access_tokens_table.php
git commit -m "feat(auth): add sanctum bearer token authentication"
```

Files:

```text
composer.json
composer.lock
app/Models/User.php
app/Actions/AuthenticateUserAction.php
app/Http/Resources/LoginResource.php
database/migrations/2026_05_03_000004_create_personal_access_tokens_table.php
```

Why:

```text
Adds Laravel Sanctum, pins Laravel 11 and Symfony 7 for PHP 8.2 assessment compatibility, creates the token table, enables HasApiTokens, and returns bearer tokens from login.
```

### B. Protect Financial Routes With Bearer Auth and 2FA

```bash
git add routes/api.php
git add app/Actions/CreateAccountAction.php app/Actions/TransferAction.php
git add app/Http/Controllers/Api/AccountController.php app/Http/Controllers/Api/DepositController.php app/Http/Controllers/Api/TransactionController.php
git add app/Http/Requests/CreateAccountRequest.php
git commit -m "fix(security): require sanctum and 2fa for wallet actions"
```

Files:

```text
routes/api.php
app/Actions/CreateAccountAction.php
app/Actions/TransferAction.php
app/Http/Controllers/Api/AccountController.php
app/Http/Controllers/Api/DepositController.php
app/Http/Controllers/Api/TransactionController.php
app/Http/Requests/CreateAccountRequest.php
```

Why:

```text
Moves account/deposit/transfer routes behind Sanctum auth and the 2FA gate, removes the unprotected transfer helper, scopes account access to the authenticated user, and prevents users from debiting wallets they do not own.
```

### C. Seed Reviewer Data Through the Ledger

```bash
git add database/seeders/TestUserSeeder.php
git commit -m "fix(seed): create ledger-backed reviewer wallets"
```

Files:

```text
database/seeders/TestUserSeeder.php
```

Why:

```text
Creates the seeded NGN and CNY wallets, inserts the opening NGN balance through WalletService so it is auditable in the ledger, and seeds the NGN/CNY exchange rate.
```

### D. Configure Redis Defaults for Locks and Queues

```bash
git add .env.example
git commit -m "chore(config): default cache and queue to redis"
```

Files:

```text
.env.example
```

Why:

```text
Sets CACHE_STORE=redis and QUEUE_CONNECTION=redis so exchange-rate caching, swap locks, and webhook processing match the production assessment requirement.
```

### E. Update Assessment Tests With Pest

```bash
git add composer.json composer.lock tests/Pest.php
git add tests/Feature/Api/AuthApiTest.php
git add tests/Feature/Api/TwoFactorGateApiTest.php
git add tests/Feature/Api/SwapApiTest.php
git add tests/Feature/Api/SettlementWebhookApiTest.php
git add tests/Feature/Api/WalletApiTest.php
git add tests/Feature/ExampleTest.php
git add tests/Feature/TestUserSeederTest.php
git add tests/Unit/LedgerTransactionDirectionTest.php
git rm tests/Feature/Api/AssessmentApiTest.php
git commit -m "test(api): migrate assessment coverage to pest"
```

Files:

```text
composer.json
composer.lock
tests/Pest.php
tests/Feature/Api/AuthApiTest.php
tests/Feature/Api/TwoFactorGateApiTest.php
tests/Feature/Api/SwapApiTest.php
tests/Feature/Api/SettlementWebhookApiTest.php
tests/Feature/Api/WalletApiTest.php
tests/Feature/ExampleTest.php
tests/Feature/TestUserSeederTest.php
tests/Unit/LedgerTransactionDirectionTest.php
```

Why:

```text
Adds Pest, converts the test suite from PHPUnit classes to Pest tests, and splits assessment coverage into compact endpoint files for Auth, 2FA Gate, Swap, and Webhook behavior while keeping wallet, seeder, and unit coverage intact.
```

### F. Update Reviewer Documentation and Postman Collection

```bash
git add README.md
git add postman/tupay-api.postman_collection.json
git add GIT_PUSH_GUIDE.md
git commit -m "docs: document assessment-ready api submission"
```

Files:

```text
README.md
postman/tupay-api.postman_collection.json
GIT_PUSH_GUIDE.md
```

Why:

```text
Documents architecture, concurrency, security, Redis optimization, webhook assumptions, seeded credentials, bearer-token API usage, and the updated Postman flow.
```

### G. One-Commit Alternative

If you prefer one clean commit for all latest assessment-completion changes:

```bash
git add .env.example README.md GIT_PUSH_GUIDE.md composer.json composer.lock routes/api.php
git add app/Models/User.php app/Actions/AuthenticateUserAction.php app/Actions/CreateAccountAction.php app/Actions/TransferAction.php
git add app/Http/Controllers/Api/AccountController.php app/Http/Controllers/Api/DepositController.php app/Http/Controllers/Api/TransactionController.php
git add app/Http/Requests/CreateAccountRequest.php app/Http/Resources/LoginResource.php
git add database/migrations/2026_05_03_000004_create_personal_access_tokens_table.php database/seeders/TestUserSeeder.php
git add tests/Pest.php tests/Feature/Api/AuthApiTest.php tests/Feature/Api/TwoFactorGateApiTest.php tests/Feature/Api/SwapApiTest.php tests/Feature/Api/SettlementWebhookApiTest.php
git add tests/Feature/Api/WalletApiTest.php tests/Feature/ExampleTest.php tests/Feature/TestUserSeederTest.php tests/Unit/LedgerTransactionDirectionTest.php
git add postman/tupay-api.postman_collection.json
git commit -m "feat: complete tupay ledger assessment requirements"
```

Run `composer quality` before committing this batch.

### 1. Repository Defaults

```bash
git add .editorconfig .gitattributes .gitignore
git commit -m "chore: add repository defaults"
```

Files:

```text
.editorconfig
.gitattributes
.gitignore
```

### 2. Environment Example

```bash
git add .env.example
git commit -m "chore: add environment example"
```

Files:

```text
.env.example
```

### 3. Laravel Bootstrap

```bash
git add artisan bootstrap/app.php bootstrap/providers.php bootstrap/cache/.gitignore
git commit -m "chore: configure laravel bootstrap files"
```

Files:

```text
artisan
bootstrap/app.php
bootstrap/providers.php
bootstrap/cache/.gitignore
```

### 4. Application Configuration

```bash
git add config
git commit -m "chore: configure laravel services"
```

Files:

```text
config/
```

### 5. PHP Dependencies and Scripts

```bash
git add composer.json composer.lock
git commit -m "chore: add laravel quality dependencies"
```

Files:

```text
composer.json
composer.lock
```

### 6. Frontend Tooling

```bash
git add package.json vite.config.js
git commit -m "build: configure vite frontend tooling"
```

Files:

```text
package.json
vite.config.js
```

### 7. Public Entry Files

```bash
git add public/.htaccess public/favicon.ico public/index.php public/robots.txt
git commit -m "chore: add public application entry files"
```

Files:

```text
public/.htaccess
public/favicon.ico
public/index.php
public/robots.txt
```

### 8. Frontend Resources

```bash
git add resources/css/app.css resources/js/app.js resources/js/bootstrap.js resources/views
git commit -m "feat: add tupay frontend resources"
```

Files:

```text
resources/css/app.css
resources/js/app.js
resources/js/bootstrap.js
resources/views/
```

### 9. Database Migrations

```bash
git add database/.gitignore database/migrations
git commit -m "feat: add wallet database migrations"
```

Files:

```text
database/.gitignore
database/migrations/
```

### 10. Database Factories and Seeders

```bash
git add database/factories database/seeders
git commit -m "test: add wallet factories and seeders"
```

Files:

```text
database/factories/
database/seeders/
```

### 11. Ledger Enums

```bash
git add app/Enums
git commit -m "feat: add ledger transaction enums"
```

Files:

```text
app/Enums/
```

### 12. Eloquent Models

```bash
git add app/Models
git commit -m "feat: add wallet ledger models"
```

Files:

```text
app/Models/
```

### 13. Domain Services

```bash
git add app/Services
git commit -m "feat: add wallet domain services"
```

Files:

```text
app/Services/
```

### 14. Authentication and Wallet Actions

```bash
git add app/Actions/AuthenticateUserAction.php app/Actions/VerifyTwoFactorAction.php
git add app/Actions/CreateAccountAction.php app/Actions/DepositAction.php app/Actions/TransferAction.php app/Actions/SwapAction.php
git add app/Actions/ListLedgerTransactionsAction.php
git commit -m "feat: add authenticated wallet actions"
```

Files:

```text
app/Actions/AuthenticateUserAction.php
app/Actions/VerifyTwoFactorAction.php
app/Actions/CreateAccountAction.php
app/Actions/DepositAction.php
app/Actions/TransferAction.php
app/Actions/SwapAction.php
app/Actions/ListLedgerTransactionsAction.php
```

### 15. Settlement Webhook Processing

```bash
git add app/Actions/ReceiveSettlementWebhookAction.php app/Actions/ProcessSettlementWebhookAction.php
git add app/Exceptions app/Jobs app/Notifications
git commit -m "feat: add settlement webhook processing"
```

Files:

```text
app/Actions/ReceiveSettlementWebhookAction.php
app/Actions/ProcessSettlementWebhookAction.php
app/Exceptions/
app/Jobs/
app/Notifications/
```

### 16. API Form Requests

```bash
git add app/Http/Requests
git commit -m "feat: add api request validation"
```

Files:

```text
app/Http/Requests/CreateAccountRequest.php
app/Http/Requests/DepositRequest.php
app/Http/Requests/LoginRequest.php
app/Http/Requests/SettlementWebhookRequest.php
app/Http/Requests/SwapRequest.php
app/Http/Requests/TransferRequest.php
app/Http/Requests/TwoFactorVerifyRequest.php
```

### 17. API Controllers

```bash
git add app/Http/Controllers
git commit -m "feat: add  api controllers"
```

Files:

```text
app/Http/Controllers/Controller.php
app/Http/Controllers/Api/AccountController.php
app/Http/Controllers/Api/AuthController.php
app/Http/Controllers/Api/DepositController.php
app/Http/Controllers/Api/LedgerController.php
app/Http/Controllers/Api/SettlementWebhookController.php
app/Http/Controllers/Api/SwapController.php
app/Http/Controllers/Api/TransactionController.php
```

### 18. Middleware and API Resources

```bash
git add app/Http/Middleware app/Http/Resources
git commit -m "feat: add api middleware and resources"
```

Files:

```text
app/Http/Middleware/AuthenticateWithPassword.php
app/Http/Middleware/RequireTwoFactor.php
app/Http/Middleware/VerifyWebhookSignature.php
app/Http/Resources/AccountResource.php
app/Http/Resources/LedgerTransactionResource.php
app/Http/Resources/LoginResource.php
app/Http/Resources/TransactionResource.php
```

### 19. Providers, Routes, and Tests

```bash
git add app/Providers routes/api.php routes/web.php routes/console.php
git add tests phpunit.xml phpstan.neon
git commit -m "test: wire routes and quality coverage"
```

Files:

```text
app/Providers/
routes/api.php
routes/web.php
routes/console.php
tests/
phpunit.xml
phpstan.neon
```

### 20. Documentation and API Review Files

```bash
git add postman/tupay-api.postman_collection.json
git add README.md GIT_PUSH_GUIDE.md
git commit -m "docs: add tupay api review documentation"
```

Files:

```text
postman/tupay-api.postman_collection.json
README.md
GIT_PUSH_GUIDE.md
```

## Do Not Push These Files

These files are generated locally, contain secrets, or are installed dependencies.

```text
.env
.env.backup
.env.production
.phpunit.result.cache
database/database.sqlite
node_modules/
public/build/
public/hot
public/storage
storage/logs/
storage/framework/cache/
storage/framework/views/
storage/pail/
vendor/
```

Important notes:

- `.env` contains local secrets and must stay private.
- `vendor/` is recreated with `composer install`.
- `node_modules/` is recreated with `npm install`.
- `storage/` contains runtime logs, cache, and compiled views.
- `database/database.sqlite` is local runtime data, not source code.

## Suggested First Push

If this folder is not already a Git repository, initialize it and stage only source-controlled files:

```bash
git init
git add .editorconfig .gitattributes .gitignore .env.example
git add README.md GIT_PUSH_GUIDE.md composer.json composer.lock package.json vite.config.js
git add artisan bootstrap config app routes database/factories database/migrations database/seeders
git add public resources tests postman phpunit.xml phpstan.neon
git status
git commit -m "feat: add tupay ledger api"
```

Before pushing, confirm `git status` does not include `.env`, `vendor/`, `node_modules/`, `storage/`, `.phpunit.result.cache`, or `database/database.sqlite`.

## Conventional Commit Reference

Use this format:

```text
type(scope): short description
```

The scope is optional, but useful when a change affects a specific area.

Good examples:

```bash
git commit -m "feat(wallet): add currency swap action"
git commit -m "fix(webhook): reject conflicting settlement payloads"
git commit -m "test(ledger): cover transaction direction enum"
git commit -m "docs(api): add postman collection"
git commit -m "chore(deps): update composer lock file"
git commit -m "refactor(auth): extract two factor verification service"
```

Common types:

```text
feat      New behavior or API capability
fix       Bug fix
test      Test additions or updates
docs      README, API notes, Postman docs, or Git instructions
chore     Tooling, dependency, config, or maintenance work
refactor  Internal code cleanup without behavior changes
style     Formatting-only changes
build     Build system or asset pipeline changes
ci        CI workflow changes
```

## Pre-Push Checklist

Run these before pushing:

```bash
composer quality
npm run build
git status
```

Check that:

- No secret files are staged.
- No generated dependency folders are staged.
- Commit messages follow Conventional Commits.
- The app can be rebuilt from committed files using `composer install`, `npm install`, migrations, and seeders.
