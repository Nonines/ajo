# ADR 0013: Withdrawals and transfer recovery

Status: Accepted.

## Decision

- Withdraw positive amounts from available wallet funds, subject to provider constraints. Plans settle first; no additional business limits or KYC tiers apply.
- Support multiple saved NGN destinations, with provider resolution, encryption, masked display, and saved-record ownership checks. Third-party account holders are allowed.
- Snapshot amount, destination, fee quote, and global threshold. Amendments require cancellation and a new request. Atomically reserve principal plus fee by debiting wallet and crediting payout-clearing liability.
- Total reservations at or below the initial NGN 100,000 threshold need one authorized staff review on WithdrawalRequest. Larger reservations need a maker and distinct checker through ApprovalRequest. Configurable threshold changes affect new requests only.
- Customers may cancel until final approval, including during review. Both review paths expire at one globally configurable deadline, initially 24 hours after customer request creation. Staff activity and maker proposals cannot extend it. Check expiry before approval; cancel and release unused reservations atomically.
- Use a stable transfer reference, provider server approval, and verified completion. Timeouts and uncertain outcomes retain reservations.
- Recheck recovery eligibility at approval, dispatch, and provider approval. Activating restrictions cancels unsubmitted requests, including approved queued requests, and invalidates their pending proposals. Serialize cancellation against dispatch.
- Already-submitted or externally uncertain attempts retain reservations until verification. Confirmed failure closes failed; another attempt needs a new request and approval. Confirmed post-success return changes paid to reversed and returns verified funds once.
- Releases and returns follow [debt-recovery priority](0016-reversal-receivables-and-recovery.md). Incurred fees remain customer-paid. Pre-dispatch increases require a new quote/request; verified post-charge excess follows the [fee policy](0009-deposits-and-processing-fees.md).
- Sweep every five minutes for pending payouts older than 15 minutes; escalate unresolved cases after 24 hours.

## Rationale

Ledger reservations prevent competing spends. Immutable requests and threshold-based review bind authorization to the actual reserved amount, while verification protects funds during external uncertainty.

## Consequences

NGN 100,000 principal plus fees exceeds the initial threshold. The single-review path does not create a self-approved ApprovalRequest. Expiry jobs may lag, so approval enforces the deadline directly. Cancellation and dispatch cannot both consume the same reservation; missing responses never justify release or a duplicate payout.

Provider references: [account resolution](https://paystack.com/docs/identity-verification/verify-account-number/) and [transfer states](https://paystack.com/docs/transfers/how-transfers-work/).
