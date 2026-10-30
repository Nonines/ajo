# ADR 0002: Stack, storage, queues, and CI

Status: Accepted.

## Decision

- Retain PHP 8.5, Laravel 13, Filament 5, Inertia 3, Svelte 5, Fortify, and existing frontend tooling.
- Use SQLite throughout phases 0–5, including CI and tests. Concurrency tests spanning connections or processes use isolated file-backed databases. No PostgreSQL transition is scheduled.
- Use Redis and Horizon, with separate named queues and dedicated worker capacity for webhooks, accrual, and payouts on the Redis infrastructure.
- Keep conventional Laravel directories, grouping actions and services by feature.
- Preserve formatting, PHP/static analysis, frontend checks, and tests. Add real Redis integration coverage alongside the relevant features.

## Rationale

Retaining the existing stack limits infrastructure churn. Dedicated queue capacity protects scheduled financial work from competing webhook traffic.

## Consequences

Database invariants and contention handling must work on SQLite. Concurrency cannot depend on PostgreSQL constraints or row-level FOR UPDATE behavior. Separate named queues do not require separate Redis connections. CI needs Redis integration checks, and implementation must confirm installed package versions before relying on their APIs.
