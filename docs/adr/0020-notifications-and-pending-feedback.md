# ADR 0020: Notifications and pending feedback

Status: Accepted.

## Decision

- Send transactional email through Laravel's configured mailer and provide database-backed in-app notifications with an inbox.
- Poll relevant status pages every five seconds while operations remain pending, extending coverage with each financial feature. No WebSocket infrastructure is required.
- Display verified financial states; pending checkouts and transfers remain pending until confirmed.
- Recovery notices and receipts show incoming funds allocated to debt and the remaining balance owed.
- Separate notification delivery and retries from financial idempotency. Ordinary daily accrual remains visible in financial history rather than generating daily email.

## Rationale

Email and an inbox provide durable communication, while polling makes asynchronous progress visible using existing infrastructure.

## Consequences

Notification retries cannot repost journals or repeat financial actions. Verification covers notifications after committed state changes, inbox ownership/unread behavior, and pending-to-terminal feedback.
