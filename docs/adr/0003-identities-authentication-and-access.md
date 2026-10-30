# ADR 0003: Identities, authentication, and access

Status: Accepted.

## Decision

- Retain User and its existing table for customers, using the web guard. Give Staff separate storage, provider, and guard; /ops and /admin share that staff guard. Managers access /ops, while admins access both panels.
- Retain customer registration, password reset, email verification, optional 2FA, passkeys, and profile/security settings. Financial operations require verified email.
- Staff use email/password, password reset, and mandatory 2FA, with no public registration. A command bootstraps the initial admin; /admin manages subsequent staff from phase 0.
- Use spatie/laravel-permission with explicit policies and action checks. Two-person actions require distinct authorized staff, even when one person holds both permissions. Admins cannot approve their own requests.
- Deactivate customers with financial history or retained audit references; allow deletion only when neither applies. Deactivate staff to retain approval identities.

Initial permissions:

| Capability | Manager | Admin |
| --- | --- | --- |
| Customer lookup and ops exceptions | Yes | Yes |
| Withdrawal review, making, and checking | Yes | Yes |
| Manual adjustments, waivers, plan holds, recovery-release approvals | Yes | Yes |
| Product configuration and rate approvals | No | Yes |
| Staff and permission management | No | Yes |
| Simulator and global audit viewer | No | Yes |
| Corrective closure and write-off | No | Yes |

## Rationale

Separate identities isolate staff privileges from customer sessions. Explicit permissions and preserved actor records make financial authority accountable.

## Consequences

Authentication changes must cover existing routes, actions, settings deletion, shared props/types, sessions, factories, and panel middleware. Financial objects and saved destinations still require customer ownership checks; staff roles do not bypass maker-checker rules.
