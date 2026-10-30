# ADR 0008: Webhook inbox and verification

Status: Accepted.

## Decision

- Authenticate webhooks using HMAC-SHA512 over the unmodified raw body. Persist authenticated payloads encrypted before returning 200, then dispatch processing.
- Recover persisted unprocessed or abandoned work every minute. Retain encrypted raw payloads throughout phases 0–5.
- Fingerprint identical raw payloads for delivery deduplication. Independently enforce provider-reference and business-operation uniqueness at the financial boundary.
- Route webhooks, callbacks, and verification sweeps through the same verification-first, idempotent financial operation.
- Every five minutes, verify pending transactions older than 15 minutes. Escalate unresolved cases after 24 hours and confirmed mismatches immediately.
- Introduce an /ops exception queue and verification retry in phase 2. Financial corrections require phase-4 approval controls.
- Permit later verified success to confirm a previously failed deposit once. Detect failed or abandoned attempts through verification; do not depend on a universal delivery ID or charge.failed event.

## Rationale

Durable receipt survives dispatch failure. Separate delivery deduplication and financial idempotency protect against retries, changed payloads, concurrent recovery, and missing events.

## Consequences

Preserve operation keys across retries and crashes before or after financial commit. Older observations cannot downgrade verified success; inconsistent facts require investigation. Receipt alone is not successful processing, and no webhook-pruning policy is introduced.

Provider references: [webhooks](https://paystack.com/docs/payments/webhooks/) and [verification statuses](https://paystack.com/docs/payments/verify-payments/).
