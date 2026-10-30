# ADR 0010: Savings contracts and funding

Status: Accepted.

## Decision

- Use versioned SavingsProduct templates and SavingsPlan snapshots taken at creation. Admins configure offered durations, per-duration rates, principal limits, and financial terms; no default commercial products or rates are supplied.
- Express durations as positive multiples of exactly 30 days.
- Support draft → pending_funding → active → matured → closed, unfunded cancellation, and guarded suspension/resumption. Closed and cancelled are terminal.
- Use spatie/laravel-model-states for deposits, plans, withdrawals, and approvals, starting in phase 2.
- Snapshot the globally configurable funding deadline, initially 24 hours from creation. Expire unfunded drafts/pending plans and reject late funding at the action boundary.
- Fund once through a complete wallet transfer, with no partial funding or top-ups. Automatically settle ordinary maturity to the wallet and close as matured_withdrawal.
- Phase 5 adds early-terminable and uncancellable-until-maturity contracts. Terminable products require a positive whole-day minimum holding period measured from accrual start, configured shorter than every offered term.
- Preserve existing contract snapshots through subsequent product versions and feature additions.

## Rationale

Versioned templates and immutable snapshots make contractual rates, durations, and restrictions reproducible. Full funding establishes an unambiguous interest-bearing principal and start.

## Consequences

Clock-based funding and transition checks remain necessary when scheduled jobs are delayed. State management does not replace authorization or financial transactions. Product publication and rate changes follow [administrative controls](0014-maker-checker-and-administration.md).
