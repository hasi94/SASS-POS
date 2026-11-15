---
name: t026-create-owner-verify-activate
description: T-026 steps 13-14 (create_owner/verify/activate) - the password side-channel, the attach/create ordering trap, and the sole-writer scan activate collides with.
metadata:
  type: project
---

T-026 (FP-206) steps 13-14 landed in session 3: `CreateOwner`, `Verify`, `Activate` under
`packages/platform/src/Provisioning/Steps/`.

**A step's `run()` return value is journalled verbatim as JSON (AC-18) - a password can never live in it.**
`create_owner` generates the owner's one-time password and must hand it to the console command, but
`ProvisioningRunner::run()` writes every step's return array straight into
`tenant_provisioning_steps.output`. The fix was a tiny non-persisted side channel,
`Provisioning/OwnerCredentialAnnouncement.php`, bound as a container singleton: the step writes onto
it mid-run, `ProvisionTenantHandler` reads it back after `runner->run()` returns (same process, same
request), and attaches it to `ProvisionTenantResult` for the command to print once. Not in the plan's
section 3 file list - flag this kind of gap in future AI-authored plans: a plan can describe a side effect
("print once from an in-memory result", D-26's own words) without specifying the mechanism that must exist
for it to be possible at all.

**Which outcome (create vs. attach) must be decided *before* the tenant-side employee is created, not when
the master transaction runs.** AC-31 says `--owner-name` is ignored for the employee's own `display_name`
on an attach - but Ruling 7's step order puts the tenant work (steps 2-4) before the master write (step 5)
that actually learns whether the email is new. Solution: a read-only peek at `users.email_lower` before the
tenant `Tenancy::run()` call, to pick `displayName` for `OwnerSeed`; the master transaction re-reads the
same row under its own lookup rather than trusting the peek, so a genuinely-raced resume is still answered
from current state.

**`activate` writing `tenants.status` directly collides with an existing sole-writer source scan.**
`QaPlatformSourceRulesTest`'s `qaPlatformOtherWriters('tenants', 'status', 'TenantStatusSync.php')` asserts
*nothing but* `TenantStatusSync.php` writes that column - true before this task, false after, by design
(D-7: `activate` is the one explicit `provisioning -> trial|active` transition `TenantStatusSync` itself
declines to make for a protected status). Widened `$exceptBasename` to `string|array` and passed
`['TenantStatusSync.php', 'Activate.php']` at the one real-source-scan call site; the planted-file control
call sites (which scan only a synthetic temp directory) didn't need the change. This is exactly the kind
of "pre-existing file legitimately edited beyond section 9's list" case [[feedback_defer_in_a_comment_or_file_a_row]]
talks about - name it by its own title change, don't bury it.

**Baseline gate signature at this point (13 failures, unrelated to T-026):** `ModulesEnvParsingTest` (1),
`QaHealthProbeResilienceTest` (2, one needs `MissingAppKeyException`/APP_KEY - see
[[project_worktree_env_and_gate_baseline]]), `QaModuleNameValidationTest` (3), `QaModuleRegistryContractTest`
(6), `QaVocabularyRenameSweepTest` (1 - a stray `svc` abbreviation hit inside `frontend/package-lock.json`,
file untouched by this task, already committed). 2958 passed, 25 skipped (ENGINE_HOLD).
