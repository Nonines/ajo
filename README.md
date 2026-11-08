# Ajo

Ajo is a portfolio project for a fictional NGN savings platform, focused on financial correctness, reliable interest accrual, and accountable operations.

It includes customer wallets, fixed-term savings, test-mode payments, staff approvals, an audit trail, and a demo time machine. Real Paystack acceptance and deployment verification remain separate checks; production payments are outside scope.

**Stack:** PHP 8.5, Laravel 13, Inertia 3, Svelte 5, Filament 5, SQLite, and Redis/Horizon.

## Setup

Requires PHP 8.5, Composer, Node.js 22, SQLite, and Redis/Valkey. Start Redis/Valkey, then run:

```sh
composer setup
```

This installs dependencies, prepares `.env`, generates the application key, runs migrations, and builds frontend assets. Configure audit signing and any required test-mode credentials in `.env`; see [setup and operations](docs/operations.md#setup).

Start local development with:

```sh
composer run dev
```

## Verification

```sh
php artisan test --compact
```

To include Redis-backed concurrency tests, use `RUN_REDIS_TESTS=1` with Redis/Valkey running. See [verification details](docs/operations.md#verification).

## Documentation

- [Architecture](docs/architecture.md): directory structure, services, financial flows, and stored relationships.
- [Setup and operations](docs/operations.md): environment requirements, demo mode, ledger maintenance, and verification.
- [Definitions](docs/definitions.md): financial and operational terminology.
