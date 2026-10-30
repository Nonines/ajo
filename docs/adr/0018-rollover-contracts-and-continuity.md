# ADR 0018: Rollover contracts and timing

Status: Accepted.

## Decision

- Rollover is off by default. Customers may elect, amend, or cancel renewal before the maturity boundary.
- Offer a chosen renewal duration and either original funded principal only or all net maturity proceeds. Return any remainder to the wallet.
- Apply all product terms effective at maturity under advance renewal consent, including per-term rates and financial/lock terms.
- Settle parent withholding first, then fund the child from eligible wallet proceeds through the existing funding boundary.
- Carry enabled elections into successive children until disabled, rechecking eligibility each time.
- If product availability, duration, limits, consent, or recovery restrictions prevent renewal, settle to the wallet under recovery priority and close the parent as matured_withdrawal.
- Successful renewal creates a new snapshotted plan linked by parent_plan_id and closes the parent as rolled_over. Commit local settlement, funding, and linkage consistently, retaining idempotency keys.
- Start child accrual at the next Lagos midnight after actual funding, including when processing is delayed.

## Rationale

A separate child preserves each contract and its settlement history. Advance consent supports repeat renewal on the terms effective at maturity, with a defined settlement fallback when renewal is ineligible.

## Consequences

Disclose current-at-maturity terms and possible accrual gaps. Closed parent contracts remain immutable. Business ineligibility settles normally; transient transaction failures roll back incomplete changes and retry without duplicate children or payouts.
