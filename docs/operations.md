# Setup and operations

[Back to the project overview](../README.md)

Run the commands below from the repository root.

## Setup

`composer setup` installs dependencies, prepares `.env`, generates the application key, creates the default SQLite file if missing, runs migrations, and builds frontend assets. The file-creation step does not truncate an existing database file. Redis/Valkey and appropriate test-mode credentials are required for the corresponding runtime flows. A custom SQLite path must exist before commands open its connection.

Older development plans created before calculation preparation was introduced may lack a calculation record and remain blocked from UI funding. Current plan creation and renewals prepare that record in the same transaction; future imports must use the same service or preserve that invariant.

## Demo mode

Use a separate migrated database for `APP_ENV=demo`. Configure the dedicated audit and simulator signing keys, enable `PAYMENT_SIMULATION_ENABLED`, and create the initial administrator. Demo history requires an empty customer, product, and financial dataset; existing staff may remain.

Set `DEMO_CUSTOMER_PASSWORD` to at least 12 characters, then run `php artisan db:seed --class=DemoHistorySeeder` independently. It creates `demo@example.test` and fictional history through the ordinary financial services. It resumes completed stages instead of repeating their financial effects. `DEMO_START_AT` can set the initial UTC instant; otherwise the clock starts 400 application-calendar days before setup, at 00:05 in the application timezone.

Active administrators with `simulator.use` can advance time from the admin Time Machine page by up to 365 whole days per request. `demo:process`, scheduled every minute in this environment, continues pending work after a page disconnect. Saved failures require investigation and an explicit resume. Time cannot rewind, and real provider records or open provider investigations block advancement.

Demo jobs run synchronously, including jobs that select the connection named `redis`; Redis/Valkey still provides the default cache and financial locks. Paystack calls and provider discovery are disabled. In-app notices are retained, while email sending is suppressed. Staff approval decisions and simulated provider outcomes remain manual.

## Ledger maintenance

Run these commands against a migrated database with Redis/Valkey available:

| Command                                    | Purpose                                                                                          |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------ |
| `php artisan wallets:provision`            | Idempotently create empty wallets for existing customers without inventing opening balances.     |
| `php artisan ledger:projections`           | Compare stored balance projections with ledger entries; exit unsuccessfully if mismatches exist. |
| `php artisan ledger:projections --rebuild` | Rebuild stored balances from ledger entries, then verify them.                                   |

## Verification

Feature tests are grouped under `Audit`, `Auth`, `Infrastructure`, `Integration`, `Ledger`, `Payments`, `Savings`, `Settings`, `Staff`, and `Withdrawals`. Demo clock/history, dashboard, and example tests remain at the feature root; arithmetic tests remain under `tests/Unit`. Coverage includes financial lifecycles, staff actions, recovery, exact naira conversion and formatting, persisted state aliases, and seeded sequences across features.

For the ledger and wallet subset:

```sh
php artisan test --compact tests/Unit/MoneyTest.php tests/Feature/Ledger/LedgerTest.php tests/Feature/Ledger/WalletTest.php
```

Concurrency tests use independent workers, a temporary SQLite database, and a running Redis/Valkey service. They exercise overspending, operation replay, account closure races, expired locks, and database contention:

```sh
RUN_REDIS_TESTS=1 php artisan test --compact tests/Feature/Ledger/LedgerConcurrencyTest.php
```

Run the full suite with `php artisan test --compact`. CI enables Redis integration coverage through `RUN_REDIS_TESTS=1`.
