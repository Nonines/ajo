# ADR 0015: Audit integrity and retention

Status: Accepted.

## Decision

- Begin audit capture in phase 4 for subsequent activity; retain earlier ledger metadata without fabricating retrospective events.
- Extend Spatie Activitylog with a protected model, custom chain writer, application guards, and database triggers.
- Maintain one global HMAC-SHA256 chain over the previous hash and canonical event payload, using a dedicated secret outside the database. Serialize chain-head advancement.
- Record customer/staff/system actor, panel/origin, action, subject, applicable before/after changes, available IP, actual timestamp, and correlation/provenance.
- Cover financial operations, staff mutations, approvals, authentication/security events, and staff reads of customer financial records.
- Remove secrets and mask bank details before hashing and persistence. Successful financial audit evidence commits consistently with the business transaction.
- Provide an admin viewer and verification command.
- Retain events online for a configurable 365 days, then in encrypted archives for seven years total. Verify archives before controlled removal of database prefixes; permit retention-boundary expiry/purge through maintenance only.
- Preserve required verification keys and version metadata. Replace unsafe package cleanup and after-response buffering for protected evidence. Ledger history remains outside audit-retention deletion.

## Rationale

Package-assisted capture provides event coverage, while a keyed chain exposes alterations made with database access alone. Verified archival balances online access and bounded retention.

## Consequences

Ordinary application paths cannot update or delete audit records. Coverage includes concurrent writers, tampering, archive continuity, restore, expiry, and interrupted maintenance.

There is no external trusted chain-head checkpoint. Verification cannot independently disprove rollback to an authentic historical prefix and must report archive boundaries honestly. Retention periods are application policy rather than a statutory compliance claim.
