---
name: project-t026-provisioning-kernel-layer
description: T-026 FP-206 tenant provisioning - what the kernel-layer half (steps 1-5) actually shipped, and two gotchas measured while building it
metadata:
  type: project
---

Built 2026-11-07: `packages/kernel/src/Tenancy/Provisioning/*` (the engine
port - `TenantDatabaseProvisioner` contract, `MySqlTenantDatabaseProvisioner`
/`RecordingTenantDatabaseProvisioner`/`RefusingTenantDatabaseProvisioner`,
`ProvisionerStatements`, `SecretStatement`, `TenantDatabaseNames`,
`TenantMappingWriter`, `SchemaMetaStamp`), `packages/kernel/src/Tenancy/
Secrets/{WritableSecretStore,SchemeSecretStore,FileSecretPath,
FileSecretResolver,FileSecretStore,CredentialGenerator}.php`, the two master
migrations (`tenant_provisioning_runs`/`steps`), and
`packages/platform/src/Provisioning/TenantCode.php`. This is steps 1-5 of
`docs/plans/T-026-tenant-provisioning.md` only - the cross-package bootstrap
(`packages/access`/`packages/organization` contracts), the platform
orchestrator, `tenant:provision`, and the end-to-end/abandon proofs (steps
6-18) are **not built**. The 19-step plan is multi-session scope; do not
assume the rest exists without checking.

**A SQL template containing a literal `%s`-shaped host wildcard breaks
`sprintf()`.** MySQL/MariaDB's `@'%'` (any host) is a legitimate substring of
`CREATE USER ... @'%' IDENTIFIED BY ...`; building that string with
`sprintf($template, $quotedPassword)` throws "Unknown format specifier" the
moment the template itself contains an unescaped `%`. `SecretStatement` uses
a literal placeholder token (`%%PASSWORD%%`) and `str_replace()` instead.

**A stand-in provisioner that `touch()`es a database-named file in the
process cwd pollutes the repository if a test doesn't isolate its
directory.** `RecordingTenantDatabaseProvisioner::createDatabase()` on a
`sqlite`-engine `ProvisioningServer` creates an empty file named exactly the
database name in `getcwd()` (the step 4 probe's own design) - a test that
calls it without `chdir()`-ing into a throwaway temp directory first leaves a
stray `flx_t_000001` file sitting in `modules/host/`, which then makes the
*next* run's "Absent" assertion read "Empty" instead - a flake that only
shows up when tests run in a different order or a second time. Every test
that calls `createDatabase()`/`databaseState()` against a sqlite-engine
server must `chdir()` into an isolated temp directory and restore + clean up
in a `finally`.

**This machine can make a full `composer test` run look hung when it is
only very slow.** Observed running the full suite after this task's changes:
many unrelated foreign `php.exe` processes were alive at the same time
(several `artisan serve` instances and a queue worker from a different
project, `SimplePos-System`), and the pest process's own CPU time crept up
only a few seconds every few minutes of wall-clock - consistent with real
contention, not a deadlock in the new code (confirmed separately by running
the new test files alone, which completed in ~6s with no hang). Before
concluding a run is stuck, check the process's `CPU` time twice a few
minutes apart (`Get-Process -Id <pid> | Select CPU`) rather than only
checking wall-clock elapsed - related: [[project_gate_timing_spread]].

**Why:** steps 6-18 need this layer's exact method names and the engine
port's three-implementation shape; re-deriving them from the plan without
reading this file risks a second, slightly different design for the same
contract.

**How to apply:** before resuming T-026 at step 6, read
`packages/kernel/src/Tenancy/Provisioning/Contracts/TenantDatabaseProvisioner.php`
and `TenantMappingWriter.php` directly rather than only the plan - the
constructor signatures and the executor seam are real now, not proposed.
