# ADR 0005: Money, rates, and rounding

Status: Accepted.

## Decision

- Represent NGN as signed 64-bit integer kobo through a Money value object and Eloquent cast. Validate storage bounds and reject floating-point monetary inputs and calculations.
- Record debits as positive and credits as negative; display customer liabilities as positive entitlements. Transport minor-unit amounts as decimal strings between PHP and JavaScript.
- Store annual rates, withholding, interest forfeiture, and principal penalties as integer basis points: increments of 0.01 percentage point.
- Calculate nominal annual interest using Actual/365, with a fixed denominator in leap years.
- Declare brick/math as a direct dependency for exact intermediate calculations. Store daily accrual as integer micro-kobo, with 1,000,000 micro-kobo per kobo.
- Round half to even at stored-precision and whole-kobo boundaries. Carry fractional amounts while a plan remains open, including negative carry after rounding up. At closure, round cumulative entitlement and record the fractional difference within that plan.

## Rationale

Integer money and exact intermediate arithmetic preserve ledger equality across SQLite, PHP, and JavaScript. Cumulative recognition avoids drift from independently rounding each day's interest.

## Consequences

Whole-kobo postings use changes in rounded cumulative recognition. For example, 2.5 kobo rounds to 2 and 3.5 to 4. Capitalization preserves fractional carry; closure does not pool it across customers or plans. Validate arithmetic bounds before persistence and label rates as nominal annual rates.
