# ADR 0014: Maker-checker and administration

Status: Accepted.

## Decision

- ApprovalRequest stores its subject, proposed action/payload, requester, state, decider, reason, expiry, and application evidence. Enforce decided_by != requested_by in domain checks and the database, with no admin bypass.
- Persist proposals before business changes. Checker approval revalidates and atomically applies local changes with decision evidence; external payout work follows afterward.
- Require maker-checker for above-threshold withdrawals, manual ledger adjustments, penalty waivers, existing-product rate changes, plan holds/releases, recovery-restriction release, write-offs, and corrective closure.
- From phase 4, existing-product rate changes require approval. Initial rate publication for a new product is a direct admin action. Other financial-term changes remain versioned without automatically requiring maker-checker.
- Pending approvals expire after a globally configurable 24 hours from proposal creation. Withdrawal reviews instead share the absolute deadline from customer request creation, including the single-review path. Enforce deadlines during approval and expiry processing.
- Cancelled/expired withdrawal subjects cannot be approved through stale proposals. Recovery cancellation covers approved-but-unsubmitted requests, while externally uncertain reservations remain protected.
- Suspended plans accrue through original maturity but cannot early-exit, roll over, or settle. Release settles overdue maturity without additional post-maturity interest.
- Administrative closure requires two authorized staff and returns remaining principal plus earned interest, less withholding and incurred fees, without ordinary early-exit penalties.
- Uncancellable plans permit corrective pre-maturity closure only for erroneous creation or funding, never accelerated debt recovery.

## Rationale

Separate proposal and application prevent unilateral financial changes. Revalidation binds approval to current eligibility, while distinct treatment of suspension, recovery, and contractual locks preserves their purposes.

## Consequences

Direct configuration still creates new versions. Expiry atomically cancels pending withdrawals and releases unused reservations once; it does not release already-approved externally uncertain payouts. Verification must cover permission loss, stale payloads, expiry/application races, hold boundaries, and corrective-closure eligibility.
