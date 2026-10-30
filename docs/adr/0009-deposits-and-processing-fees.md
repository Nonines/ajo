# ADR 0009: Deposits and processing fees

Status: Accepted.

## Decision

- Accept net deposits from NGN 1,000 to NGN 100,000,000, subject to provider constraints and safe integer bounds. Net means after provider fees and before debt recovery. Paystack adds fees to the requested net amount.
- Verify gross receipt, actual fees, and expected net value. Separately record full net customer-value recognition, wallet-credit/receivable-repayment allocations, gateway-fee expense, and fee-recovery income with linked journal provenance.
- Reserve payout principal plus a snapshotted fee quote. Initial configurable estimates are NGN 10 through NGN 5,000; NGN 25 above NGN 5,000 through NGN 50,000; and NGN 50 above NGN 50,000.
- Customers bear actual incurred fees, including on failures and reversals. Return unused reservations; incurred fees remain charged. No provider cost means no processing-fee charge.
- A higher fee known before dispatch requires a fresh quote and request. Preserve the original reservation, quote, and approval-threshold snapshot.
- Record an unavoidable verified charge above the reservation as a separate fee exception, preserving the factual payout outcome. Automatically collect the uncollected excess from available wallet funds, then book any shortfall as a receivable under the [recovery policy](0016-reversal-receivables-and-recovery.md).
- Disclose actual-fee recovery authority, notify each adjustment, and route discrepancies to ops. Recovery is audited and idempotent; ambiguous facts require investigation.

## Rationale

Net pricing makes the intended deposit value explicit. Separating provider costs, customer allocations, and fee recovery preserves accounting provenance while ensuring incurred costs remain customer-paid.

## Consequences

Receipts distinguish requested value, total charged or reserved, quoted and actual fees, recovery allocation, and unused release. Debt allocation can leave a valid deposit's spendable wallet credit at zero without violating the net minimum. Its full recognized value remains relevant to reversal bounds. A paid payout with a fee exception stays paid and is never resent; replay cannot collect the same excess twice.

Provider references: [fee passing](https://support.paystack.com/en/articles/2130306) and [transfer pricing](https://paystack.com/pricing).
