# ADR 0001: Scope and phased delivery

Status: Accepted.

## Decision

Deliver Ajo's NGN savings core in six ordered phases:

| Phase | Scope |
| --- | --- |
| 0 | Laravel foundation, authentication, Filament panels, and CI |
| 1 | Double-entry ledger, wallet, and invariant tests |
| 2 | Paystack deposits, webhooks, idempotency, and verification |
| 3 | Fixed-term savings, interest accrual, and maturity |
| 4 | Withdrawals, maker-checker, and audit trail |
| 5 | Compounding, early termination, penalties, and rollover |

Each phase has a goal, ordered steps, verification evidence, and a definition of done, followed by a review checkpoint. Routine implementation follows repository conventions and documented package defaults. New architectural or business policies require explicit approval; concrete visual tokens require design sign-off before UI implementation.

Flexible-wallet interest, goal/auto-save mandates, KYC tiers, full reconciliation/accounting close, hosting, seeded demo history, and later demo polish are outside these phases. Multi-currency, group savings, investments, loans, cards, and bills are also excluded. Recovery receivables record claims rather than a lending product.

## Rationale

The ordered scope establishes financial integrity before adding gateway activity, savings contracts, and controlled operations. Review checkpoints keep each increment independently assessable.

## Consequences

Delivery depends on evidence that each phase's financial and operational guarantees hold. Later features cannot silently expand scope or alter accepted policies.
