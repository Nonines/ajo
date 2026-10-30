# ADR 0016: Reversal receivables and recovery

Status: Accepted.

## Decision

- Verify each external reversal, link it to the original deposit, and record its actual effect once.
- Recover available wallet funds without making the wallet negative. Record the remaining attributable principal and incurred fees in a separate customer receivable asset.
- Bound cumulative principal reversal effects by the deposit's original confirmed net customer-value recognition, including both wallet credit and receivable repayment. Subtract reversal effects already booked through wallet debits and receivable creation. Later debt collections do not reopen that cap.
- A payment never recognized as customer value creates no principal debit or debt. Verified attributable fees remain separately governed by the [fee policy](0009-deposits-and-processing-fees.md).
- While debt remains, restrict withdrawals, new plan funding, and rollover, retaining access to records and repayment. Apply incoming funds, including eligible net maturity proceeds, to debt before exposing a wallet remainder.
- Preserve existing savings contracts. Recovery does not automatically liquidate locked plans, change interest terms, or suspend them.
- Cancel unsubmitted withdrawals, including approved queued requests, when restrictions activate. Atomically release unused reservations into recovery, invalidate pending proposals, and serialize cancellation against dispatch. Recheck restrictions at approval, dispatch, and provider approval.
- Keep submitted or externally uncertain reservations until verification; subsequent releases/returns follow recovery priority.
- Create an ops exception, notify the customer, and disclose recovery authority and allocation. Require maker-checker for manual adjustments, write-offs, and restriction release; repayment cannot override an independent plan hold.
- Apply this mechanism to verified payout-fee excess without altering the original approval snapshot or sending another payout. No automatic write-off or external bank/card collection applies.
- Permit discretionary refunds only when customer funds cover the refund and incurred fees. Accounting corrections use reversing journals; this policy adds no separate refund-request feature.

## Rationale

External reversals can remove funds after customer value has been spent or committed. Receivables preserve the attributable claim while protecting wallet availability and existing contracts.

## Consequences

Unknown or partial effects require investigation. Never infer a full reversal or charge the same principal or fees twice. Debt repayment is recognized customer value even when spendable credit is zero.

For example, NGN 9,000 debt followed by a net NGN 5,000 deposit leaves wallet zero and debt NGN 4,000. Reversing that recognized deposit restores debt to NGN 9,000; replay changes nothing. Provenance must support full/partial debt allocations and cumulative reversals. A receivable records a claim, not guaranteed collection.
