# ADR 0006: Ledger, projections, and concurrency

Status: Accepted.

## Decision

- A single posting service atomically writes complete, single-currency journals with at least two entries and a signed zero sum. Cross-row balance and complete membership are domain and test contracts.
- Application controls and database triggers prohibit updates and deletes of posted journals and entries. Corrections use linked reversing journals.
- Spending checks read authoritative ledger balances. Rebuildable SQLite projections update in the same transaction and serve balance reads.
- Protect wallet operations with Redis-backed Laravel cache locks, SQLite IMMEDIATE transactions, and bounded contention handling.
- From phase 1, create wallets at registration and idempotently provision earlier customers without invented opening balances. Demonstrate manual credit through tests; controlled staff adjustments arrive in phase 4.
- Exercise reproducible seeded operation sequences from phase 1, expanding them with each financial feature.

Account classification:

| Account | Type |
| --- | --- |
| customer:{id}:wallet | Liability |
| plan:{id}:principal | Liability |
| plan:{id}:interest_payable | Liability |
| house:cash_at_gateway | Asset |
| house:interest_expense | Expense |
| house:penalty_income | Income |
| house:gateway_fees | Expense |
| clearing:deposits_in_transit | Asset |
| clearing:payouts_in_transit | Liability |
| house:fee_recovery_income | Income |
| house:withholding_tax_payable | Liability |
| customer:{id}:reversal_receivable | Asset |

Approved write-offs require an expense counterpart.

## Rationale

The ledger provides one financial authority. Atomic projections preserve convenient reads, while layered locks address competing wallet operations within SQLite's concurrency model.

## Consequences

Mutation triggers protect history but do not independently establish cross-row balance or authorize inserts. Posting must enforce account ownership and membership. Coverage must verify direct mutation rejection, competing spends, contention, rollback consistency, projection equality, and rebuilds. Receivables are not automatically written off.
