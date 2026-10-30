# Phase 0 test baseline

## Complete application

Verified on 2026-10-30 before separating the phase-0 implementation into commits.
The application was restored from the reference stash and all 56 changed or
untracked files matched their pre-stash content hashes.

- Branch: `phase-0`.
- Parent commit: `474a5eb`.
- Reference stash: `e21482f393d04a665eed36c5dcb6d91e3ee99a22`.
- Stack: PHP 8.5, Laravel 13.38.0, Pest 5.3.4, SQLite, and native Valkey 9.1.2
  through Predis.

```bash
RUN_REDIS_TESTS=1 php artisan test --compact
```

Result: **84 tests passed, 414 assertions, no failures or skips**. The restored
application run completed in 9.465 seconds. This result applies to the complete
application, including the production implementation committed in later rounds.
It does not establish that the tests-first commit passes independently.

## Tests-first commit

The first phase-0 commit adds the four new feature-test files, the staff factory,
and the frozen clock test double. Existing tests, their expectations, the user
factory, Pest setup, and PHPUnit configuration remain unchanged in the parent
history. No tests were weakened, disabled, or changed to make this commit pass.

An isolated copy of the parent commit plus these six files was checked with the
parent's Composer dependencies. Its frontend was built successfully with
`npm run build` before running the same test command.

Result: **40 tests passed, 44 test errors, 138 assertions**, with exit code 2.
All original tests passed. The new tests require implementation deliberately
scheduled for subsequent commits:

- Two clock errors: the Clock contract and its container binding are absent.
- Two Horizon provisioning errors: Horizon is not installed yet.
- Four Redis integration errors: the parent selects PhpRedis, which is absent
  locally; the planned infrastructure commit supplies Predis and configures it.
- Thirty-six staff-test setup errors: StaffPermissionSeeder is not present yet.

These are the approved tests-first limitations, not failures in the verified
complete application. Later clock, staff, and queue commits supply the missing
dependencies while preserving these tests and their expectations.

## Verification

After restoring or completing the full phase-0 implementation, start the local
Valkey service and rerun the test command above. Expect all 84 tests to pass with
no skips. A checkout containing only the tests-first commit is expected to report
the missing-feature errors described above until subsequent commits are applied.

The reference stash contains tracked and untracked phase-0 changes. Ignored local
configuration, installed dependencies, and generated build artifacts are outside
that stash and are not part of the commits.
