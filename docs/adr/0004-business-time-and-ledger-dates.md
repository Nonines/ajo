# ADR 0004: Business time and ledger dates

Status: Accepted.

## Decision

- Inject a Clock returning CarbonImmutable, with SystemClock and FrozenClock implementations. Domain code reads time through this interface.
- Store timestamps in UTC and determine business dates in Africa/Lagos, including weekends and holidays.
- Start ordinary accrual at the next Lagos midnight after funding. Earn the selected number of full days; the initial partial day earns nothing.
- Begin daily processing at 00:05 Lagos. Process missing eligible dates chronologically, completing accrual before dependent settlement.
- Record economic effective dates separately from actual journal posting times. Audit timestamps reflect actual processing time.
- Rollover children start accrual at the next Lagos midnight after actual funding. Select renewal terms effective at parent maturity, while retaining actual child funding dates.

## Rationale

A single business calendar and injectable clock make expiry, accrual, replay, and delayed processing reproducible. Separate effective and posting dates preserve both economic history and operational evidence.

## Consequences

Delayed rollover can create an accrual gap; child accrual is never backdated to promise continuity. Boundary coverage includes UTC/Lagos midnight, leap dates, delayed workers, and action-time expiry checks when scheduled processing is late.
