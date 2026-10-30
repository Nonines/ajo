# ADR 0007: Paystack integration and simulation

Status: Accepted.

## Decision

- Use Paystack test mode throughout phases 0–5, behind an injected gateway interface implemented with Laravel's HTTP client.
- Initialize checkout server-side and complete it with Paystack Popup. Secret keys remain server-side.
- Verify deposits and payout outcomes server-side before recognizing customer value or completing payment.
- Use Paystack's transfer server-approval callback to validate an approved local request and its immutable financial details.
- Provide a simulator restricted by admin permission and environment. Explicitly simulated transactions use a scenario adapter whose verification responses match its signed webhook events, processed through the normal inbox.
- Real Paystack test transactions use the real adapter. Customer input cannot select or change adapters.

## Rationale

A gateway boundary isolates provider behavior and enables deterministic adverse scenarios. Matching simulated verification and webhook responses exercises verification-first processing without weakening real transactions.

## Consequences

Scenarios cover failure, reversal, missing delivery, duplicates, ordering, and mismatches. Callback configuration and supported provider behavior require verification during implementation.

Provider references: [checkout](https://paystack.com/docs/payments/accept-payments/), [verification](https://paystack.com/docs/payments/verify-payments/), and [transfer approvals](https://paystack.com/docs/transfers/managing-transfers/).
