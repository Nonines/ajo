# ADR 0012: Withholding and settlement

Status: Accepted.

## Decision

- Configure withholding per product in basis points, defaulting to 10%, and snapshot it onto each plan.
- Withhold at final maturity, early termination, or rollover settlement. Intermediate compounding uses gross interest.
- Apply withholding to retained interest after forfeiture, including retained capitalized interest. Exclude original principal and forfeited interest.
- Calculate cumulative tax per plan using half-even rounding. Each taxable settlement deducts only the difference from tax already withheld.
- Record a separate withholding-tax-payable liability and deduction line.
- Settlement records show original/remaining principal, gross interest, forfeiture, withholding, principal penalties, incurred fees, recovery allocation, and net proceeds.
- Automatically settle ordinary maturity to the wallet and close as matured_withdrawal. Tax remittance is outside phases 0–5.

## Rationale

Final-settlement withholding preserves gross compounding while taxing only retained interest. Cumulative calculation and contract snapshots keep deductions consistent across settlement events and product changes.

## Consequences

The 10% default is configurable application policy, not comprehensive statutory tax treatment. Existing contracts retain their snapshots. Original-principal and interest provenance prevents double taxation at maturity or rollover.
