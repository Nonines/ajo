# ADR 0017: Early termination and waivers

Status: Accepted.

## Decision

- Allow full early closure of active, terminable plans after the snapshotted minimum holding period. Plan suspension and recovery restrictions block exit; partial plan withdrawals are unavailable.
- Accrue through the last completed Lagos date. Present an exact settlement preview and revalidate on confirmation. Ordinary contractual exit needs no staff approval.
- Apply product-configured percentage interest forfeiture, including capitalized interest, and percentage penalties on original principal.
- Forfeit interest through new journals: debit interest payable or the capitalized-interest component of principal and credit interest expense. Credit principal penalties separately to penalty income.
- Withhold only on retained interest under the [settlement policy](0012-withholding-and-settlement.md).
- Permit full or partial penalty waivers with a monetary ceiling, reason, expiry, audit evidence, and two distinct authorized staff. A waiver is single-use and valid for a globally configurable period, initially 24 hours after approval. Recompute settlement at use within its ceiling.
- Waivers cannot unlock uncancellable contracts or waive incurred processing fees. Administrative closure follows the [corrective-closure controls](0014-maker-checker-and-administration.md).

## Rationale

Contractual eligibility makes ordinary exit predictable. Separate forfeiture and principal-penalty accounting preserves the origin of deductions, while capped approvals constrain discretionary waivers.

## Consequences

Capitalization does not protect interest from forfeiture. Retain original-principal and capitalized-interest provenance and immutable forfeiture history. Half-even-round deductions at posting boundaries, derive the integer-kobo residual, and record terminal rounding differences. Settlement must balance and reconcile net interest expense separately from gross recognition and forfeitures.
