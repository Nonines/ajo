# ADR 0011: Accrual and compounding

Status: Accepted.

## Decision

- Use one engine for simple interest, daily compounding, and compounding every 30 days. Apply nominal annual rate / 365 to the applicable interest-bearing principal.
- Enforce UNIQUE(plan_id, accrual_date), committing the accrual, journal, and SQLite projection together.
- Replays verify original calculation inputs and produce no second financial effect. Changed inputs require investigation rather than overwriting history.
- Support dated jobs and chronological backfills. Complete accrual prerequisites before compounding, maturity, or rollover.
- Store high-precision daily accrual and post the difference between rounded cumulative recognition and recognition already journaled.
- Track capitalized, settled, and forfeited amounts separately from remaining interest payable.
- Count compounding boundaries from accrual start. Capitalize whole posted kobo and preserve fractional carry. Settle directly when maturity coincides with capitalization.

## Rationale

One calculation engine and atomic dated records keep all interest modes consistent. Cumulative recognition avoids daily rounding drift and remains correct after interest leaves the payable account.

## Consequences

Maintain both invariants:

- Net interest expense = remaining interest payable + capitalized interest still in principal + gross interest settled.
- Gross interest recognition = those same destinations + recognized forfeitures.

Gross settlement includes withholding and interest applied to receivable recovery. Principal penalties remain separate income. Provenance distinguishes original principal from capitalized interest to prevent double counting during forfeiture and closure.

Coverage includes leap dates, fractional ties, zero/small interest, chronological replay, interrupted stages, and compounding boundaries. Compounding frequency does not determine statement-display frequency.
