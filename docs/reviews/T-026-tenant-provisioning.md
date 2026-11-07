# Review: T-026 — Tenant provisioning

## Round 1 — CHANGES REQUESTED

Reviewer ran the T-026 test files directly (platform Provisioning, ProvisionTenant,
AbandonProvisioning, kernel Tenancy/Provisioning, access BootstrapOwner, the end-to-end test,
the containment test, `QaPlatformSourceRulesTest`): **198 passed, 1 skipped (the engine test,
as designed), 82.4s**. Did not re-run the full `composer check`; QA's 815s run (13 pre-existing
failures) stands.

### Critical (must fix)

- **R-1** `ProvisionTenantHandler.php:83-85,95-97,105-110`, `ProvisionTenantCommand.php:73-77,99`,
  `CreateOwner.php:197-198,206-213` — the owner's one-time password is lost whenever the run
  doesn't finish in the same process that created the login, with no recovery path (no reset
  flow exists in production code). Specifically: a failure in `create_owner`'s `linkUser()`, or
  in `verify`/`activate`, reaches the command's catch with only the error printed, password
  gone; a `--resume` after `create_owner` is `done` prints nothing; a `--resume` while it's not
  yet `done` prints a false "password was already shown" message. The end-to-end crash dataset
  hides this because the `OwnerCredentialAnnouncement` singleton survives in-process between a
  simulated crash and its resume, which a real process restart would not. Required fix: announce
  only after the master transaction commits (not inside the closure, which would also announce a
  rolled-back insert); show the password on failure too (wrap `execute()`, throw a new exception
  carrying the announcement, print before the error); on resume into an unshown, unused owner
  login, rotate the password and show the new one (logged as D-31 below); add a test that forgets
  the singleton before resuming to simulate a real new process.
- **R-2** `modules/host/config/tenancy.php:181`, no `.gitignore` under `modules/host/storage/` —
  the default secrets root (`storage_path('secrets')`) is not git-ignored. `FileSecretStore`
  writes each tenant's database passwords there in plaintext; any real-driver run followed by a
  broad `git add` would commit live credentials. Required fix: add
  `modules/host/storage/secrets/.gitignore` (`*` / `!.gitignore`), plus a test asserting the
  default root is covered.

### Warnings — all folded into this round (each a real safety/security gap, not a style note)

- **W-1** Abandon only drops database users / forgets secrets when the corresponding step is
  `done`, contradicting plan Ruling 10's own wording ("if create_users reached it" /
  "on both secret references, whatever the status"). A failure mid-`create_users` or
  mid-`store_credentials` leaves a live grant or an orphaned plaintext file abandon can't reach,
  even though the Ruling 3 journal fix exists specifically to make them findable.
- **W-2** Abandon can archive an already-**live** tenant: `activate`'s master transaction can
  commit (mapping active, `tenant_count+1`, subscription started, status `trial`) before the
  runner marks the step/run done; a crash in that exact gap leaves a `failed`/stale `running` run
  that abandon will happily accept, dropping a live tenant's database users and secrets.
  `Activate::compensate()`'s docblock claim that this can't happen is wrong. Must re-read
  `tenants.status`/`tenant_databases.status` before any engine-side work and refuse unless still
  `provisioning`/not yet `active`, pointing the operator to `--resume` instead (which already
  completes safely per AC-35).
- **W-3** The compare-and-set lease isn't enforced after the initial claim — `claimed_by` is
  written once and never checked again by any later write. A step running longer than
  `stale_after_minutes` lets a second `--resume`/`--abandon` claim the same run while the first
  is still working; both then write, and `markRunCompleted` can overwrite a `rolled_back` row
  back to `completed`. Every later mutating call needs a `where('claimed_by', $claimedBy)`
  guard, aborting on zero affected rows.
- **W-4** `grantOwner()`'s only guard is "no other live employee holds owner" — broader than
  D-23's one chicken-and-egg case. In a live tenant whose owner was soft-deleted, any caller of
  this published contract could make any employee the new owner without `Escalation`. Must also
  require `code = 'OWNER'` on the target, plus a lock to close the check/insert race.
- **W-5** The real-engine test (currently skipped under `ENGINE_HOLD`) will, once G-1 clears,
  write to whatever `database.connections.mysql` points at by default (the shared XAMPP
  engine) — the only protection today is a comment. Must build its server connection from
  Docker-only settings and refuse outright if host:port matches the XAMPP default, plus add
  cleanup so a second run doesn't fail its own precondition.
- **W-6** The refusing-default safety net depends entirely on no local `.env` setting
  `TENANCY_PROVISIONING_DRIVER=mysql` — nothing pins it in `phpunit.xml` the way `ENGINE_HOLD`
  itself is pinned elsewhere. A developer following `.env.example`'s own documented key could
  silently bind the real driver for every test that doesn't explicitly bind the stand-in.
- **W-7** A `rolled_back` (abandoned) run falls through to the `leaseHeld` refusal on a later
  `--resume`/`--abandon`, with a misleading "wait for the staleness window" message for a run
  that can never become claimable again. Needs its own named refusal.
- **W-8** `verify` never actually checks the owner-employee ↔ master `tenant_users` link in
  either direction (AC-32's own requirement), despite reading both pieces of data it would need
  to compare.

### Suggestions filed as follow-ups (non-blocking): FP-412 to FP-418

- **FP-412** `SecretStatement::sql()` escapes `'` but not `\` — safe only because the generator's
  alphabet excludes it; harden regardless.
- **FP-413** `FileSecretStore`'s temp file isn't created with a restrictive mode from the start,
  and `file_put_contents`/`rename`'s return values are ignored.
- **FP-414** `CredentialGenerator`'s `256 mod 62` has a small, negligible-at-40-chars modulo bias;
  `random_int(0, 61)` removes it entirely.
- **FP-415** `charset`/`collation`/`user_host` flow from config into SQL unchecked; validate at
  the point config is read.
- **FP-416** `ProvisionerStatements.php`'s docblock claims a broader set of files may spell these
  statements than containment check 3 actually allows (which is the correct, narrower rule) —
  fix the docblock.
- **FP-417** `--actor` isn't checked against `users` during AC-1 validation, so a bad id surfaces
  as a raw `QueryException` from the foreign key instead of a clean refusal.
- **FP-418** `CreateOwner::$masterFailureHook`/`ReserveIdentity::$raceHook` are public static
  mutable test seams inside production classes; replace with an injected no-op seam.

### Done well

- The four mid-task fixes (the `exec(` lookbehind, the `Package_Kernel_TenancyStatus` deptrac
  edge, `StoreCredentials`'s credential-reference journaling, both `tenants.status` sole-writer
  widenings) were each independently re-verified by the reviewer, not taken on the notes' word —
  all four sound, with W-1 the one residual gap in the credential-journaling fix's actual use.
- Abandon genuinely cannot drop a database or delete rows — confirmed by reading the handler and
  grepping every T-026 production file for `->delete(`/truncate/force-delete/database drop: none
  found. Containment checks 1 and 3 both hold as real, controlled scans.
- `ENGINE_HOLD` is fail-closed at three independent levels (the `EngineOnHold::HELD` constant,
  the refusing provisioner as default binding, lazy PDO construction) plus two identifier checks
  before any statement runs — W-5/W-6 are the only remaining gaps.
- No secret reaches the logs or the journal: double-cleaned errors, `ProvisioningStepFailed`
  deliberately drops the original exception, `SecretStatement` hides the password in every
  string/JSON/debug form, password-bearing statements run on raw PDO so no query listener sees
  them, and `create_owner`'s own step output never holds the password (which is exactly why R-1's
  side-channel design exists — the gap is in retrieving it on a later path, not in it leaking).
- "Record the intended action, then do it" is applied consistently across every step
  (`reserve_identity`'s identity, `create_database`'s attempted marker, `create_users`'s one-time
  pre-existence check, `migrate_remainder`'s run id, `create_owner`'s user id).
- `migrate_remainder` is a thin driver over T-008's existing migration runner, not a
  reimplementation, exactly as AC-20/D-4 intend.
- The single generic crash-and-resume wrapper is a sound design — it exercises the real step
  classes and their real journal writes; the one gap is that it never simulates a genuinely fresh
  process, which is exactly how R-1 stayed hidden.

## QA

- Round 1: **PASS**, 0 defects. `docs/qa/T-026-tenant-provisioning.md`.

## Round 2 — CHANGES REQUESTED (fix commit `fefc82c`, follow-ons `8adc554`, QA `b423c83`)

Reviewer re-ran the affected suites (platform Provisioning/AbandonProvisioning/ProvisionTenant,
kernel Tenancy/Provisioning, access BootstrapOwner/`AccessBumpSweepTest`, the end-to-end,
`SecretsStorageRootIsGitIgnoredTest`, `TenantProvisionerEngineTest`,
`DestructiveStatementContainmentTest`): **211 passed, 2 skipped (both engine tests, as
designed), 154s.**

**7 of 10 items verified fully closed:** R-2, W-1, W-2, W-4, W-6, W-7, W-8.

**One Critical remains (R-3, what's left of R-1):** the fix closes two paths (an in-process
failure, and a crash *before* `create_owner` is marked `done`) but not the underlying class of
problem. If the process dies *after* `create_owner` is `done` — during `verify`, `activate`,
`markRunCompleted`, a SIGTERM/SSH-disconnect/OOM/PHP-fatal (none of which are a catchable
`Throwable`) — no recovery path exists: resume skips the already-`done` step, the announcement
stays `null`, and nothing is printed, with no command able to reach a `completed` run either
(`claim()` refuses it as `alreadyCompleted`). Required fix: extract the D-31 rotation's
precondition and update into a small shared service usable from two places — `resume()` when
the announcement came back `null` but `create_owner`'s own output shows `created`/`rotated`,
and a new `tenant:provision --reissue-owner-password=<run_id>` command that also works against
a `completed` run. Both must print an explicit "already signed in, nothing to reissue" message
when the precondition fails, never silence.

**Two Warnings partly fixed:**
- **W-3** the lease is checked on heartbeats and the four `mark*` writes, but `markStepRunning`/
  `setCurrentStep`/`writeStepOutput` remain unchecked, so a worker that already lost the lease
  can still flip a step to `running` and move `current_step` before its next checked write
  catches up. Call `touchHeartbeat` first in each iteration, before `markStepRunning`, and add
  the lease check to the three unchecked writes too.
- **W-5** the random database-name suffix is hex, but `TenantDatabaseNames::assertDatabaseName`
  requires `[0-9]{6,}` — this test will fail the moment `ENGINE_HOLD` lifts (fails safe, but
  broken; use digits). Also `assertNotTheXamppEngine()` only refuses the literal
  `127.0.0.1:3306`, letting `localhost:3306`/`::1:3306` through — refuse port 3306 on any
  loopback host, or outright.

**One Suggestion:** put R-3's rotation read behind `lockForUpdate()` to close a sub-second
concurrent-first-login race (free to add, not currently exploitable in any tested path).

## Verdict after Round 2

**CHANGES REQUESTED.** R-3 (a residual Critical) plus the W-3/W-5 gaps folded into a third fix
round.

## Verdict after Round 1

**CHANGES REQUESTED.** R-1 and R-2 plus all 8 Warnings folded into one fix round — every one is
a real safety/security gap found by a careful adversarial read, not a style note.
