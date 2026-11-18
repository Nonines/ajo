# Ajo

[![tests](https://github.com/Nonines/ajo/actions/workflows/tests.yml/badge.svg)](https://github.com/Nonines/ajo/actions/workflows/tests.yml)

Ajo is a naira savings platform for a fictional company. Customers keep money in a wallet, lock it into fixed-term plans that earn daily interest, and withdraw to a bank account. Staff review withdrawals, approve sensitive changes, and configure savings products.

It is a portfolio project about financial correctness rather than screens. Money must stay exact, every change must be explainable, and failures must recover without rewriting history.

**Stack:** PHP 8.5, Laravel 13, Inertia 3, Svelte 5, Filament 5, SQLite, and Redis/Horizon.

## Features

- **Double-entry ledger.** Every money movement is a journal whose signed entries sum to zero. Database triggers reject updates and deletes, so corrections are new reversing journals. Stored balances are projections that can be rebuilt from entries.
- **Exact money.** Amounts are integer kobo in a value object that rejects floats. Rates are basis points, and rounding is half-even.
- **Replay-safe operations.** Each journal has a unique operation key and a fingerprint of its inputs. A retry returns the original journal; reusing a key with different inputs fails.
- **Interest without rounding drift.** Daily Actual/365 interest is calculated in millionths of a kobo. Each posting books only the change in rounded cumulative interest, so daily rounding never accumulates. One engine covers simple, daily-compounding, and 30-day-compounding plans. A unique constraint on plan and date makes accrual safe to re-run or backfill.
- **Contracts that keep their terms.** Products are versioned, and each plan snapshots its terms when created. Later rate changes never rewrite an existing agreement.
- **Payments verified before they count.** A browser returning from checkout never moves money. Signed webhooks land in a durable inbox, and workers fetch provider evidence before crediting a deposit or completing a payout. A reversal larger than the wallet becomes recovery debt instead of a negative balance.
- **Maker-checker controls.** Large withdrawals, ledger adjustments, rate changes, plan holds and releases, waivers, administrative plan closures, and recovery-debt releases and write-offs need a proposal from one staff member and a decision from another. Code and database triggers both enforce the separation.
- **Tamper-evident audit trail.** Audit events form an HMAC-SHA256 hash chain signed with a key kept outside the database. Database access alone cannot alter, insert, or reorder events, or remove one from the middle of the chain, without verification failing. Older events move to encrypted archives with signed boundaries. The chain head has no external checkpoint, so deleting the newest events cannot be detected.
- **Separate identities.** Customers and staff use separate tables and guards. Both staff panels require multi-factor authentication.
- **Concurrency tested with real processes.** Tests run independent PHP workers against a shared database and Redis locks to show that overspending, replays, and account-closure races cannot spend the same money twice.
- **A demo time machine.** In demo mode an administrator advances a shared clock by up to a year at a time. Accrual, maturity, and rollover run through the same services as normal operation, so a plan can mature in minutes.

## Where to look first

| Area                                 | Start here                                                                         |
| ------------------------------------ | ---------------------------------------------------------------------------------- |
| Ledger posting and invariants        | [Ledger.php](app/Support/Ledger/Ledger.php)                                        |
| Money value object                   | [Money.php](app/Support/Money/Money.php)                                           |
| Interest accrual and compounding     | [SavingsAccrual.php](app/Support/Savings/SavingsAccrual.php)                       |
| Settlement, early exit, and rollover | [SavingsSettlements.php](app/Support/Savings/SavingsSettlements.php)               |
| Webhook inbox                        | [PaymentInbox.php](app/Support/Payments/PaymentInbox.php)                          |
| Maker-checker approvals              | [Approvals.php](app/Support/Auth/Approvals.php)                                    |
| Audit hash chain                     | [AuditTrail.php](app/Support/Audit/AuditTrail.php)                                 |
| Database immutability guards         | [ledger migration](database/migrations/2026_11_04_093335_create_ledger_tables.php) |
| Multi-process concurrency tests      | [LedgerConcurrencyTest.php](tests/Feature/Ledger/LedgerConcurrencyTest.php)        |

## Scope

The scope is deliberately narrow so that the financial core can be complete:

- NGN only, with fixed-term savings as the one savings product. Flexible-wallet interest, goal-based auto-save, and KYC tiers are not built.
- Payments use Paystack in test mode or a built-in simulator.

## Run locally

Requires PHP 8.5, Composer, Node.js 22, SQLite, and Redis/Valkey. Start Redis/Valkey, then run:

```sh
composer setup
```

This installs dependencies, prepares `.env`, generates the application key, runs migrations, and builds frontend assets.

Sign-in and financial actions write signed audit events, so set `AUDIT_CHAIN_KEY` in `.env` to a secret of at least 32 bytes. This command prints a suitable value:

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Create the first staff administrator, then start the app:

```sh
php artisan staff:create-initial-admin
composer run dev
```

Customers register at `/register`. Staff sign in at `/admin` or `/ops` and must set up multi-factor authentication. For seeded fictional history and the time machine, use [demo mode](docs/operations.md#demo-mode). Other setup notes are in [setup and operations](docs/operations.md#setup).

## Verification

```sh
php artisan test --compact
```

Redis-backed concurrency tests run only when `RUN_REDIS_TESTS=1` is set, and they need a running Redis/Valkey. `composer ci:check` runs the same lint, static analysis, type, and test checks as CI. CI also sets `RUN_REDIS_TESTS=1`. See [verification details](docs/operations.md#verification).

## Documentation

- [Architecture](docs/architecture.md): directory structure, services, financial flows, and stored relationships.
- [Setup and operations](docs/operations.md): setup notes, demo mode, ledger maintenance, and verification.
- [Definitions](docs/definitions.md): financial and operational terminology.
