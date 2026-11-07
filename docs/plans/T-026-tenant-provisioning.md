# Plan: T-026 Tenant provisioning

Spec: `docs/specs/T-026-tenant-provisioning.md` (AC-1 to AC-40). Decisions: `docs/decisions/T-026-tenant-provisioning.md` (D-1 to D-19).
Backlog: FP-206. Branch: `ai/T-026-tenant-provisioning` at `ec15d84`. Gate directory: `modules/host`.

## 0. Verification of `ba`'s findings, and rulings

Every claim below was re-read in source, not taken from the spec.

### 0.1 What held

| Claim | Verdict | Evidence |
|---|---|---|
| Runner docblock: "`provisioning` restores to `provisioning` - FP-206 owns when that becomes `active`" | **Holds** | `MigrateTenantHandler.php:366`, `restoredStatus()`. A non-offline plan writes no status at all, so a provisioning tenant also stays `provisioning`. |
| `TenantStatusPolicy::NAMED_ONLY_STATUSES = ['provisioning', 'failed']` | **Holds** | `TenantStatusPolicy.php:57`. `selectNamed()` accepts `MIGRATING_STATUSES`; `bulk()` subtracts the named-only list. |
| `TenantCommercialStatus::PROTECTED` excludes `pending`/`provisioning`, citing FP-206 | **Holds** | `TenantCommercialStatus.php:25-28`. |
| `SecretResolver` is read-only | **Holds** | The interface has `resolve()` only. |
| `ActivationIssuer` needs `tenants.code` upper-case, enforced by `TENANT_PART_PATTERN` | **Holds** | `ActivationIssuer.php:32` and `:65`; `ActivationCodeFormat.php:36`. |
| `SyncPermissionRegistry` and `CreateBranch` are idempotent per-tenant seeds with no caller at creation time | **Holds** | Both resolve `TenantConnection` (the `tenant` connection name), so they work inside any `Tenancy::run()` scope. `CreateBranch`'s plan-limit check passes when there is no plan: `MasterEntitlementSource` returns uncapped `Entitlements` when `plan_id` is null. |
| D-15: `--plan` required, with no default | **Holds** | No default-plan marker exists anywhere: `plans` has `is_public` and `trial_days` but no `is_default`, and no config key names one. A default would be an invented business rule. |

### 0.2 What needs correcting (-> `ba`; listed again in section 10)

1. **B-1. Step order contradicts itself, and the documented order is not idempotent.** AC-2 lists `create_users` before `store_credentials`, as `docs/database.md` §8 does. AC-17 says `store_credentials` runs "before `create_users`". If `create_users` generates the passwords and the process dies before they are stored, a resumed run finds two users whose passwords nobody knows.
   - **Ruling:** the order is `… create_database, store_credentials, create_users, write_mapping …`.
   - `store_credentials` generates and persists the passwords. `create_users` reads them back through the resolver and runs `CREATE USER IF NOT EXISTS` + `ALTER USER … IDENTIFIED BY` + `GRANT`, all three idempotent.
2. **B-2. AC-9's abandon cannot drop a database.**
   - `modules/host/tests/Feature/DestructiveStatementContainmentTest.php` (check 1) fails the gate if any file except `packages/testing/src/Engine/EngineDatabase.php` contains a `DROP DATABASE`/`DROP SCHEMA` in code. That rule exists because of the 2026-10-14 incident, in which sixteen databases were dropped.
   - Deleting the `tenants` row (database.md's compensation) is also unsafe. `sequence` is allocated as `MAX + 1` (see 0.3). Deleting the highest row hands its sequence to the next tenant, which derives the same `flx_t_NNNNNN`, collides with the database left behind, and is refused forever by AC-5's negative case.
   - **Ruling:** abandon reverses everything **except the database**.
     - It deletes `tenant_users`, the owner `users` row (only if this run created it and nothing else references it), the mapping row and the secret files.
     - It runs `DROP USER` for both users. A user drop is not covered by the containment check, and it is name-guarded at the point of effect.
     - It **tombstones** the tenant (`status = 'archived'`, `deleted_at` set), so `code` and `sequence` are never reused. This is database.md §8's own deprovision rule: "keep the … rows … so the identifier is never reused".
     - It prints the database name and server for the operator to drop by hand.
     - The run ends `rolled_back` and its compensated steps end `compensated`.
3. **B-3. D-6 is built on a driver that does not exist.**
   - The container registers `env://` only (`TenancyServiceProvider.php:99-100`, plus `SchemeSecretResolver`'s docblock: "T-003 ships `env://` only"). There is **no** `file://` driver for reading either.
   - `env://` cannot be written: a process cannot persist its own environment for the next process.
   - **Ruling:** build `file://` for both reading and writing, confined to a configured root directory. `env://` stays read-only, exactly as it is.
   - The write side is a scheme-dispatching port, so `vault://` later means adding one map entry (Ruling 3). That answers the lead's D-6 question.
4. **B-4. The `schema_meta` identity writer is FP-206's, and the spec omits it.**
   - Three files say so: `SchemaMetaIdentity.php:14-16` ("the writer belongs to FP-206's provisioning step (D-28)"), `TenantIdentityMismatch.php:25-27`, and database.md §8 step 11 ("assert `schema_meta.tenant_id` matches").
   - **Ruling:** `seed_core` stamps `schema_meta` (`tenant_id`, `tenant_code`, `database_identifier`, `provisioned_at`) first.
     - The stamp is idempotent when the stored value already matches.
     - It refuses with `TenantIdentityMismatch` when the stored value names somebody else.
   - `verify` asserts the stamp. A new AC is needed.
5. **B-5. The first owner cannot be granted `owner` through `AssignRole`, and T-010 D-36's expectation cannot be implemented as written.**
   - `AssignRoleHandler` calls `Escalation::requireRoleHeld($actorId, …)`. A null actor is refused `actor_required`, and a non-null actor must already hold every key of `owner`, which nobody in an empty tenant does.
   - `employee_roles.granted_by` is a foreign key to `employees` (`…000305…:64`). So a "named system actor" that is not an employee cannot even be recorded.
   - **Ruling:** access gains a separate **bootstrap** operation, with its own precondition as its guard (Ruling 5). The escalation guard is not bypassed and not stubbed: the bootstrap is a different operation that refuses once any live employee holds the system `owner` role. A decision row is needed, recording that this replaces T-010 D-36's "named system actor" expectation.
6. **B-6. AC-29's details do not match the schema.**
   - `employees` has **no `branch_id`**: branch membership is `employee_branches`.
   - `employees.code` is required and unique.
   - **Ruling:** the owner employee's code is the constant `OWNER`, and that code is the idempotency key AC-30 calls "its own provisioning-run reference". The branch link is an `employee_branches` row.
7. **B-7. AC-40 overclaims what the stand-in proves.** "Every criterion except AC-16" is proven on stand-ins: not quite. These halves are engine-only and need `[ENGINE]` markers:
   - AC-7's real two-session race. On SQLite one process cannot race itself; this is the same fence T-008's AC-49/AC-65 carry.
   - AC-5's and AC-9's engine-side state: `information_schema` detection of an existing database or user, and `DROP USER`.
   - AC-18's server-side leakage surfaces: the general log, `SHOW PROCESSLIST` and the binlog.

   AC-16 itself is fenced correctly. It claims nothing beyond "the contract's own unit tests against the stand-in".
8. **B-8. AC-1 validates only presence, so bad input fails late.**
   - A bad timezone, currency or business type, a branch name over 100 characters, or a `--plan` that is not an active plan would be refused only at step 8 (`CreateBranch`) or step 11 (`StartSubscription`). By then a database and two users exist.
   - **Ruling:** every format check runs at input validation, before the run row is written. The checks use the owning modules' own validators, reached through contracts (Ruling 5).
   - Also: `tenants.business_type` is NOT NULL, while `CreateBranch` accepts null. So `--business-type` stays required and must be one of the organization module's set.
9. **B-9. D-16 leaves the owner with no way to sign in.** The password is random, there is no welcome mail, and no password-reset feature is shipped. The user story "a working login the moment provisioning finishes" is therefore not met. This is a question for the human (see the end of this plan). Until it is answered, the plan prints the initial password **once to the console**, never to a log channel or a stored row (Ruling 7).
10. **B-10. Minor accuracy fixes.**
    - AC-6 cites AC-9 for `reserve_identity`; it should cite AC-10. AC-10 cites AC-23 for `activate`; it should cite AC-34. AC-1 says "these five" and lists eight.
    - Current behaviour says "no code issues `CREATE DATABASE`". `EngineDatabase` does (test infrastructure), and a gate test governs drops.
    - "Nothing has inserted a `tenants` row outside a test fixture": `.github/ci/register-ci-tenant.sql` does, in CI.
11. **B-11 (D-19). Option names.**
    - Kebab-case long options match every shipped command (`--retry-failed`, `--per-server`).
    - Add `--actor=` for `initiated_by`. That is the platform-command convention (`platform:change-subscription-plan`, `-status`).
    - `--plan=` takes the **plan id**, because every platform command takes entity ids.

### 0.3 Rulings (architect's own)

**Ruling 1: where the code lives.**

| Concern | Home | Why |
|---|---|---|
| Orchestrator, steps, run/step repository, `tenant:provision` command, tenant code normaliser | `packages/platform` (`Flexipos\Platform\Provisioning\…`, slice `Features/ProvisionTenant`) | Platform already owns the master-side tenant lifecycle: `TenantStatusSync`, the other writer of `tenants.status`, sits next to it. It also owns `StartSubscription` (an in-package call), `MasterConnection`, `PlatformAudit` and the operator console. `Package_Platform` may use Kernel and Contracts, which is all this needs. |
| Engine port, naming, mapping writer, `schema_meta` stamp, writable secrets | `packages/kernel` | Mapping and secrets are the kernel's (`MasterTenantDatabaseRepository` says "writing the mapping is provisioning's job"). The mapping writer must reuse `EvictsTenantMappings` and `TenantDatabase` status constants, which `Package_Platform` is deliberately not granted. |
| Owner bootstrap, registry sync, owner verification | `packages/access` behind `Flexipos\Contracts\Access\TenantAccessBootstrap` | The established shape: `EmployeeClaimSource` is bound to `BuildEmployeeClaimsHandler` the same way. |
| Default branch and branch-field validation | `packages/organization` behind `Flexipos\Contracts\Organization\DefaultBranch` | Same. The validator is reused, never copied: `Fields::BUSINESS_TYPES` stays organization's. |

- **`deptrac.yaml` is not edited.** No new layer is added and no ruleset changes.

**Ruling 2: the resumable run.**
- **Write the run whole.** A fresh invocation validates its input, then writes one run row and eleven step rows, all `pending`, in one master transaction (AC-2). The plan-the-run-whole shape comes from `PlanMigrationRunHandler::write()`.
- **Claim with a compare-and-set lease.** One `UPDATE … SET status='running', claimed_by=?, heartbeat_at=now WHERE id=? AND (status='failed' OR (status='running' AND heartbeat_at < now - window))`. One affected row means the run is ours. This is the same shape as `ClaimMigrationJob`.
  - A fresh run is created already claimed.
  - `--resume` of a run that is `running` and not stale is refused "in progress, last heartbeat …". A stale one is resumable. Nothing auto-resumes (AC-4).
  - The window is `platform.provisioning.stale_after_minutes`, default 30, mirroring `schema.claim_ttl_minutes`.
- **Intent first, effect second, done last.** A step is marked `running` (attempts + 1, committed) before its effect.
  - A step that creates something whose identity it chooses records that identity into `output` through a `StepJournal`, committed **before** the effect. Examples: the user id, the migration run id, the "about to create" marker.
  - On re-entry the step reads its own journal and detects the effect (AC-5).
  - The heartbeat is refreshed at every step transition.
- **Failure.** The `error` is passed through `MigrationErrorText::sanitise()` with needles. The needles are every credential reference in the run's outputs plus each one's resolved value, the same idiom as `needlesFor()`. Then:
  - the step is marked `failed`;
  - the run is marked `failed` with `current_step` left on that step;
  - no later step runs (AC-3, AC-18).
- **The run row stores `input` JSON**: the validated options, with no secrets. A resume therefore needs no options. This column is new against database.md §4.7, as are `claimed_by` and `heartbeat_at`. The as-built note is recorded in step 17.

**Ruling 3: writable secrets (D-6, B-3).**
- `Kernel\Tenancy\Secrets\WritableSecretStore`: `put(string $ref, Secret $value): void`, which refuses if a *different* value already exists and is a no-op when the value is equal; `exists(string $ref): bool`; `forget(string $ref): void`.
- `SchemeSecretStore` dispatches by scheme with the same `schemeOf()`, and an unknown scheme is refused. Adding `vault://` later is one driver and one map entry.
- `FileSecretResolver` (read) and `FileSecretStore` (write) share `FileSecretPath`.
  - Every reference must resolve under the configured `tenancy.secrets.file_root`, after `..` and symlinks are resolved. Anything outside the root is refused, for reads too.
  - Writes go to a temporary file and are then renamed, with mode 0600. Windows ignores the mode; this is recorded.
- The reference for a tenant is `file://<root>/tenant_<seq6>_{app|mig}`. It is computed at `reserve_identity` and never recomputed (AC-12, AC-17).
- `SchemeSecretResolver` gains `file` next to `env`. This invalidates `MappingSource`'s docblock line "today every reference … is `env://`". Its refusal stands on its own, but the docblock is corrected.
- Passwords: 40 characters from `[A-Za-z0-9]`, built from `random_bytes`. There are no quote characters, so no statement escaping is involved.

**Ruling 4: the engine port (AC-15, AC-16), and the `ENGINE_HOLD` line.**
- `Kernel\Tenancy\Provisioning\Contracts\TenantDatabaseProvisioner`. Each method takes a `ProvisioningServer` value (engine, host, port, tls, `provisioner_credential_ref` from `database_servers`):
  - `databaseState(name): DatabaseState` (`Absent | Empty | Occupied`)
  - `createDatabase(name, charset, collation)`
  - `userExists(user)`
  - `ensureUser(user, Secret, TenantGrant)`, where `TenantGrant` is the `App | Migrate` enum
  - `dropUser(user)`
- **Three implementations.**
  - `MySqlTenantDatabaseProvisioner` is the real one. Its statements come from a pure `ProvisionerStatements` builder:
    - grants are exactly AC-15's lists;
    - identifiers go through a two-check name guard at the point of effect, copied in shape from `EngineDatabase`: `TenantDatabaseNames::assert*()` plus an independent literal `flx_t_`;
    - password-bearing statements are a `SecretStatement` type whose string forms are redacted, and they are executed on the raw PDO, so no `QueryExecuted` listener ever sees them.
  - `RecordingTenantDatabaseProvisioner` is the suite's stand-in. It records calls, with passwords replaced by a marker, and for a `sqlite` server it creates the database as an empty SQLite file named exactly `database_name` in the fixture's working directory (step 4 probe).
  - `RefusingTenantDatabaseProvisioner` is the **default binding**. It throws "no provisioner configured".
- **Binding.** The MySQL one is bound only when `tenancy.provisioning.driver = mysql`, set explicitly. `phpunit.xml` does not set it, so a test that forgets to bind the stand-in fails closed instead of reaching the shared MariaDB at `127.0.0.1:3306`.
- **The line.** Proven today:
  - the statement builder (exact SQL, grants, guards, redaction);
  - the binding (default refuses; the suite resolves the stand-in);
  - every step's behaviour against the stand-in.

  **NOT VERIFIED until G-1:** that those statements succeed on MySQL 8.4 / MariaDB 10.4; that `databaseState()`/`userExists()` read `information_schema` correctly; `DROP USER`; and server-side leakage.
- `modules/host/tests/Engine/TenantProvisionerEngineTest.php` is written and **skipped under `ENGINE_HOLD`**. Its docblock adds a second precondition for the day the hold lifts: it runs only against the T-023 Docker MySQL, never the shared XAMPP engine, because `flx_t_*` is not a reserved test prefix.

**Ruling 5: the cross-package contracts.**
- **`Contracts\Access\TenantAccessBootstrap`**, implemented by `Access\Features\BootstrapOwner\BootstrapOwnerHandler`:
  - `seedPermissions(): list<string>` runs `SyncPermissionRegistryHandler` and returns its `refused` keys. An empty list means success.
  - `bootstrapOwner(OwnerSeed): string`, where `OwnerSeed` carries code, first name, last name, display name and branch id, and the method returns the employee id. In one tenant transaction it:
    - finds the employee by code `OWNER`, or creates it through `CreateEmployeeHandler` (which validates, checks the limit and writes the audit row);
    - ensures the `employee_branches` row;
    - ensures the `employee_roles` row for the system `owner` role, with `granted_by` null;
    - bumps `perm_version`;
    - writes an audit row.

    It **refuses** (`TenantBootstrapRefused`) when any *other* live employee holds the system owner role. That is the guard: bootstrap works only on an ownerless tenant.
  - `linkUser(employeeId, userId): void` is idempotent.
  - `ownerMissingKeys(): list<string>` re-reads the database against the registry, for `verify`.
- **`Contracts\Organization\DefaultBranch`**, implemented by `Organization\Support\DatabaseDefaultBranch`:
  - `validate(BranchSeed)` makes no connection. It runs `OrgCode::normalise` and the `Fields::*` checks, used at input validation (B-8).
  - `ensure(BranchSeed): string` returns the existing branch id by code, or calls `CreateBranchHandler`.
  - `exists(string $branchId): bool` is for `verify`.
- The value objects `OwnerSeed` and `BranchSeed` and the exception `TenantBootstrapRefused` live in `packages/contracts`.

**Ruling 6: tenant-database scope for steps 8 to 10.**
- `seed_core`, `create_owner` and `verify` run inside `Tenancy::run($tenantId, …, TenantConnectionPurpose::Migrating)`. That purpose is the only one that enters a `provisioning` tenant, using the migrate credential and the version-gate waiver.
- Each of these steps first re-reads `tenant_databases.status` from the master and refuses unless it is `provisioning`. So provisioning can never write into a live tenant.
- Rejected: a new `TenantConnectionPurpose::Provisioning` case. It would be cleaner, but it changes the security-critical exhaustive `match` in `TenantStatusPolicy::accepted()` and its moved-value proofs for a property one pre-check already gives. Recorded as a possible follow-up.

**Ruling 7: `create_owner`, ordered so every crash point resumes.**
1. Journal a pre-generated `user_id` (ULID).
2. Master pre-check: if a `users` row with `email_lower` exists and its id is not ours, refuse (AC-31). Nothing has been written at this point.
3. Tenant: `bootstrapOwner()` (Ruling 5).
4. Master, in one transaction:
   - insert `users` if absent: `name` from `--owner-name` or the email local part; an argon hash of a fresh random password; `must_change_password = true`; `status = 'active'`;
   - insert `tenant_users` if absent: `access_level = 'owner'`, `status = 'active'`, `is_default = true`, `employee_id`.
5. Tenant: `linkUser()`.
6. The initial password is printed once, by the command, from an in-memory result. It is never journalled. A resume that finds the user already inserted prints "already created in an earlier attempt; the initial password was shown then" (B-9).

**Ruling 8: `migrate_remainder` drives T-008, it does not re-build it.**
1. If the journal holds no `migration_run_id`, call `PlanMigrationRunHandler`. The arguments are `tenantIds: [$tenantId]` (named, never bulk) and the set `MigrationSet::baseline(base_path(config('schema.tenant_migration_path')))`, which is exactly `TenantMigrateCommand`'s construction. The returned id is journalled **before** anything executes (AC-21, AC-23, D-13).
2. Call `RunTenantMigrationsHandler` with `runId`, `tenantIds: [$tenantId]`, `retryFailed: true` and `workerId: 'provision-<runId>'`. It claims in-process and synchronously.
3. Read this tenant's job:
   - `done`: the step succeeds, and `{migration_run_id, schema_version}` goes to `output`;
   - `failed`: the step fails with the job's already-sanitised `error_message` (AC-22);
   - `skipped` (lock held): the step fails, "locked by another session; resume later".
   - The mapping status is never touched here.
4. A `MigrationRunRefused` raised by the planner (an unpublished catalogue, a destructive guard) is a step failure carrying its own message.

**Ruling 9: `activate`, exactly once (AC-33 to AC-36).**
- One master transaction holds:
  1. `TenantMappingWriter::activate()`: `UPDATE tenant_databases SET status='active', provisioned_at=now WHERE tenant_id=? AND status='provisioning'`.
  2. Only if that affected exactly one row, `tenant_count = tenant_count + 1`.
  3. `StartSubscriptionHandler`, skipped if the tenant already has a subscription row. Its own transaction nests as a savepoint.
  4. `UPDATE tenants SET status=?, activated_at=now WHERE id=? AND status='provisioning'`, with `trial` by default and `active` with `--active`.
  5. Step `done`, and the run `completed`.
- After commit (`$connection->afterCommit`), the mapping eviction and the entitlement eviction run again. `StartSubscription`'s own in-transaction eviction is premature by itself.
- Compare-and-set on each write makes a re-entry a no-op (AC-35).
- Over-capacity is not refused at activation. A race can push a server one tenant past `max_tenants`; this is reported as a warning (see Risks).

## 1. Approach summary

1. Two master tables: `tenant_provisioning_runs` and `tenant_provisioning_steps` (database.md §4.7, plus `input`, `claimed_by` and `heartbeat_at`).
2. The kernel gains:
   - an engine port with a real MySQL driver, a recording stand-in and a refusing default;
   - a pure naming rule;
   - a mapping writer with evict-after-write;
   - a `schema_meta` stamp;
   - a writable, root-confined `file://` secret store behind a scheme-dispatching port.
3. Access and organization each publish one contract for the tenant-side seeds. The first-owner grant is a new bootstrap operation, guarded by "no owner yet".
4. Platform holds the orchestrator: eleven step classes run in a fixed order with intent-first journalling, a compare-and-set lease, sanitised errors, `tenant:provision` with `--resume` and `--abandon`.
5. `migrate_remainder` calls T-008's planner and runner with a named selector, and journals the migration run id. `activate` is the single explicit `provisioning → trial|active` transition, done with compare-and-set writes.
6. Everything is proven on the SQLite master and tenant stand-ins with the recording provisioner. The real engine half stays NOT VERIFIED behind `ENGINE_HOLD`.

## 2. Alternatives rejected

- **A new `packages/provisioning`:** it needs a new layer, a Composer path package, PHPStan paths and a third contract for `StartSubscription`, all for one slice. Platform already owns the tenant lifecycle.
- **The orchestrator in `modules/host/app`:** `HostApp` may reach every package's internals, so deptrac would prove nothing about provisioning. It would also put domain logic in the host, against the vertical-slice pattern.
- **Calling `AssignRole` with a "system actor":** that actor would need to hold `owner`, and `granted_by` is a foreign key to `employees` (B-5).
- **`env://` write support:** it cannot persist (B-3).
- **Auto-compensation on failure:** rejected by D-11, and auto-dropping the database is forbidden anyway (B-2).
- **One `RunTenantMigrations` call that plans and runs:** a crash mid-call loses the run id, and a resumed run would plan a second one (D-13).
- **A `Provisioning` connection purpose:** see Ruling 6.

## 3. Files to change / create

### `modules/host`
- `database/migrations/2026_11_07_002601_create_tenant_provisioning_runs_table.php` and `…002602_create_tenant_provisioning_steps_table.php` (new).
- `config/platform.php`: `provisioning.stale_after_minutes`.
- `config/tenancy.php`: `provisioning.driver`, `provisioning.user_host` (default `%`), `secrets.file_root` (default `storage_path('secrets')`).
- `.env.example`: the matching keys, with comments only.
- `tests/Engine/TenantProvisionerEngineTest.php` (new, skipped under the hold).
- `tests/Feature/DestructiveStatementContainmentTest.php`: **check 3**. `create database`, `create user`, `grant … on` and `drop user` appear in code only in `MySqlTenantDatabaseProvisioner`/`ProvisionerStatements`, plus the existing `EngineDatabase` create, plus the measured test-literal sites (`CiWorkflowShapeTest`). The expected map is exact, and there is a concatenated positive control.
- Predicted test-literal edits: `MasterSchemaBuildsTest` (two new master tables) and any master table inventory in `tests/Helpers.php`. The red list is measured at step 1.

### `packages/kernel/src`
- `Tenancy/Provisioning/Contracts/TenantDatabaseProvisioner.php`, `ProvisioningServer.php`, `DatabaseState.php`, `TenantGrant.php`.
- `Tenancy/Provisioning/TenantDatabaseNames.php`: AC-12 and the identifier guards.
- `Tenancy/Provisioning/ProvisionerStatements.php`, `SecretStatement.php`, `MySqlTenantDatabaseProvisioner.php`, `RecordingTenantDatabaseProvisioner.php`, `RefusingTenantDatabaseProvisioner.php`.
- `Tenancy/Provisioning/TenantMappingWriter.php`: `insert`, `activate`, `delete`, each followed by eviction.
- `Tenancy/Provisioning/SchemaMetaStamp.php`: uses `SchemaMetaIdentity::TABLE`/`KEY`.
- `Tenancy/Secrets/WritableSecretStore.php`, `SchemeSecretStore.php`, `FileSecretPath.php`, `FileSecretResolver.php`, `FileSecretStore.php`, `CredentialGenerator.php`.
- `Tenancy/TenancyServiceProvider.php`: register `file` and bind the store and the provisioner.
- `Tenancy/MappingSource.php`: docblock correction only.

### `packages/contracts/src`
- `Access/TenantAccessBootstrap.php`, `Access/OwnerSeed.php`, `Access/TenantBootstrapRefused.php`.
- `Organization/DefaultBranch.php`, `Organization/BranchSeed.php`.

### `packages/access/src`
- `Features/BootstrapOwner/BootstrapOwnerHandler.php` (new).
- `AccessServiceProvider.php`: the binding.

### `packages/organization/src`
- `Support/DatabaseDefaultBranch.php` (new).
- `OrganizationServiceProvider.php`: the binding.

### `packages/platform/src`
- `Provisioning/StepName.php`: an enum of the eleven steps, in Ruling 2's order.
- `Provisioning/ProvisioningStep.php`: the interface `run(RunContext, StepJournal): array` and `compensate(RunContext): ?string`.
- `Provisioning/RunContext.php`, `StepJournal.php`, `ProvisioningRuns.php` (repository and lease), `ProvisioningRunner.php` (the loop), `ProvisioningErrors.php` (sanitiser and needles), `TenantCode.php`.
- `Provisioning/Steps/{ReserveIdentity,SelectServer,CreateDatabase,StoreCredentials,CreateUsers,WriteMapping,MigrateRemainder,SeedCore,CreateOwner,Verify,Activate}.php`.
- `Features/ProvisionTenant/{ProvisionTenant,ProvisionTenantInput,ProvisionTenantHandler,ProvisionTenantResult,ProvisionTenantCommand}.php`.
- `Features/AbandonProvisioning/{AbandonProvisioning,AbandonProvisioningHandler}.php`.
- `PlatformServiceProvider.php`: the command and the bindings.

### `packages/testing/src`
- `Engine/EngineDatabase.php`: docblock only. "The only code permitted to issue `CREATE DATABASE`" becomes "the only test code …; the production provisioner is the other site, governed by check 3".

### `docs`
- `database.md` §4.7 and §8: "As built (T-026)" notes. They cover the new columns, the step order, no `import_template`, `seed_core`'s scope, abandon keeping the database, and `file://` as the write scheme.
- `architecture.md` §7: one paragraph.

## 4. Data / API changes

- **`tenant_provisioning_runs`:**
  - `id` (ULID PK);
  - `tenant_id` (nullable FK `tenants`, filled at `reserve_identity`);
  - `type` (string 30, only `create` written);
  - `source_tenant_id` (nullable);
  - `target_server_id` (nullable FK `database_servers`);
  - `status` (string 30: `running | completed | failed | rolled_back`);
  - `current_step` (string 40, nullable);
  - `input` (JSON, `JsonValidity`, no secrets);
  - `claimed_by` (string 100, nullable);
  - `heartbeat_at`, `started_at` and `finished_at` (datetime(3));
  - `error` (text, nullable);
  - `initiated_by` (nullable FK `users`);
  - `[TS]`;
  - indexes on `(status)` and `(tenant_id)`.
- **`tenant_provisioning_steps`:**
  - `id`;
  - `run_id` (FK, cascade);
  - `step` (string 40);
  - `sequence` (smallint);
  - `status` (string 30: `pending | running | done | failed | skipped | compensated`);
  - `attempts` (smallint, default 0);
  - `started_at` and `finished_at`;
  - `output` (JSON, nullable);
  - `error` (text, nullable);
  - `[TS]`;
  - unique `(run_id, step)`.
- **Master migrations only.** Nothing in the tenant tree changes, so there is no catalogue republish.
- **CLI:** `tenant:provision`:
  - fresh-run options: `--code --name --country --timezone --currency --business-type --owner-email --plan=<plan id>`;
  - optional: `--slug --server=<code> --branch-code --branch-name --owner-name --active --actor=<user id>`;
  - alternatives to a fresh run: `--resume=<run id>`, `--abandon=<run id>`.
  - Exit code: 0 only on `completed` (or a clean abandon).
- **Config:** see section 3. No new dependency.

## 5. Implementation steps

Each step ends with `composer check` green from `modules/host`, with the delta against the step's start being zero or explained. The rules carried from T-019 and T-020 apply:
- **Red-first.** Show red by omission or with a fixture. **Never stub a guard to allow.**
- No child processes.
- Unique test basenames.
- List any foreign `php.exe` processes before each gate run.

- [ ] **Step 0: baseline.**
  - Record the passed, failed and skipped counts, the test-step seconds and the FP-328 headroom line.
  - *(No AC.)*
- [ ] **Step 1: the master tables.**
  - Create the two migrations.
  - **Test `packages/platform/tests/Schema/ProvisioningSchemaTest.php`:**
    - the exact sorted column lists and nullability;
    - unique `(run_id, step)` as a raw double insert;
    - the cascade from run to steps;
    - invalid JSON in `input`/`output` refused.
  - **Predicted red:** `MasterSchemaBuildsTest` and its table inventory. Anything else is a stop.
  - *(AC-2 schema half.)*
- [ ] **Step 2: naming and the tenant code.**
  - **`TenantDatabaseNamesTest`:**
    - sequence 1 gives `flx_t_000001`, `flx_t000001_app` and `flx_t000001_mig`;
    - 999999 still has 6 digits, and 1000000 widens to 7;
    - the guards refuse a quote, a backtick, an uppercase letter and the wrong prefix;
    - the identifier lengths fit `app_username`'s 32 characters.
  - **`TenantCodeTest`:**
    - ` ten-001 ` gives `TEN-001`;
    - `-TEN001`, `TEN001-`, a blank code, 31 characters and `TEN_001` are refused;
    - the result is checked with `ActivationCodeFormat::TENANT_PART_PATTERN` itself, never a copied regex.
  - *(AC-11 unit half, AC-12.)*
- [ ] **Step 3: writable secrets.**
  - Create Ruling 3's files and register `file`.
  - **Tests:**
    - put, exists and resolve round-trip;
    - `put` of an equal value is a no-op (mtime unchanged), and a different value is refused (AC-17 negative);
    - `forget` removes the file;
    - an unknown scheme is refused;
    - `env://` put is refused;
    - **root confinement:** `../`, an absolute path outside the root, and on non-Windows a symlink out of the root are each refused for read **and** write;
    - generator: 40 characters, from the alphabet, and two calls differ.
  - **Mutation:** removing the confinement check turns the traversal cases red.
  - *(AC-17, D-6 as corrected.)*
- [ ] **Step 4: the engine port.**
  - **Probe first:**
    - prove that a mapping whose `database_name` is a bare `flx_t_000001` opens the SQLite file of that name when the test's working directory is the fixture root;
    - Laravel's `SQLiteConnector` tries `realpath($path)` and then `base_path($path)`;
    - restore the working directory in `finally`;
    - if the probe fails, stop and report to `architect`; do not change the kernel's connection path.
  - **`ProvisionerStatementsTest`:**
    - the exact `CREATE DATABASE` with the configured charset and collation;
    - the exact `GRANT` lists for `App` and `Migrate` (AC-15);
    - `CREATE USER IF NOT EXISTS` / `ALTER USER` returned as a `SecretStatement` whose `__toString`, `var_export` and json forms contain no password (AC-18).
  - **`MySqlTenantDatabaseProvisionerTest`,** with a recording statement-runner seam:
    - the secret statements go only to the raw path;
    - every identifier passes both guards, and a bad identifier never reaches the runner.
  - **`ProvisionerBindingTest`:**
    - the default container resolves `RefusingTenantDatabaseProvisioner`, which throws;
    - `driver=mysql` resolves the MySQL implementation;
    - the suite fixture resolves the stand-in.
  - **Containment check 3,** plus its positive and negative controls.
  - **The engine test file,** skipped under the hold.
  - **Mutation:** dropping one guard check still refuses through the other (two-check rule).
  - *(AC-15, AC-16 stand-in half, AC-18 statement half, AC-39.)*
- [ ] **Step 5: the mapping writer and the `schema_meta` stamp.**
  - **`TenantMappingWriterTest`:**
    - the inserted row has exactly AC-19's values;
    - each write is followed by an eviction (spy decorator implementing `EvictsTenantMappings`);
    - `activate` is compare-and-set: a second call affects 0 rows and reports false.
  - **`SchemaMetaStampTest`:**
    - absent: the stamp is written;
    - equal: a no-op;
    - another tenant: `TenantIdentityMismatch`, with nothing written.
  - *(AC-19, AC-33 groundwork, B-4.)*
- [ ] **Step 6: the contracts and their implementations.**
  - **Access, `BootstrapOwnerTest` on `AccessTenantFixture`:**
    - postcondition: one employee `OWNER`, linked to the branch, holding the system `owner`, `granted_by` null, one audit row;
    - a second call is idempotent (no second row, and `perm_version` bumped only when something changed);
    - **guard:** another live employee holding `owner` is refused `TenantBootstrapRefused`, with nothing written. Control: a soft-deleted holder does not refuse;
    - `seedPermissions()` returns `refused` keys from a fixture with a conflicting `is_branch_scoped` row;
    - `ownerMissingKeys()` is empty after the seed, and non-empty after one `role_permissions` row is deleted.
  - **Organization, `DatabaseDefaultBranchTest`:**
    - `validate()` refuses a non-IANA zone, `US`, an unknown business type and a 101-character name, without a connection;
    - `ensure()` creates the branch, its store location and the link; a second call returns the same id.
  - **Mutation:** removing the bootstrap's owner-exists check turns the guard case red.
  - *(AC-25, AC-26, AC-27, AC-29 tenant half, B-5, B-8.)*
- [ ] **Step 7: the runner core, with fake steps.**
  - `ProvisioningRuns`, `ProvisioningRunner`, `StepJournal` and `ProvisioningErrors`, tested with three in-test fake steps.
  - **Tests:**
    - the plan is written whole (one run, every step `pending`, `current_step` first);
    - steps run in order;
    - on failure: the step is `failed` with its error, the run is `failed` with `current_step` on it, and later steps are untouched;
    - resume skips `done` steps;
    - the journal survives a thrown step;
    - the lease: a non-stale `running` run is refused, a stale one is claimable, and two claims on one run produce one winner (sequential compare-and-set);
    - `--resume` of a `completed` run is refused with no write; an unknown id gives `not found`;
    - **AC-18 control:** a fake step that throws with the password in its message, whose reference is in the run's outputs, stores a sanitised `error` (neither the password nor the reference appears).
  - **Mutation:** dropping the "skip done" check re-executes a step (counter), and that test goes red.
  - *(AC-2, AC-3, AC-4, AC-6 resume half, AC-18.)*
- [ ] **Step 8: the command and input validation.**
  - **`ProvisionTenantCommandTest`, using `Artisan::call`:**
    - each required option missing or blank is refused, naming the field;
    - B-8's format refusals;
    - an unknown or inactive `--plan` is refused;
    - `--resume` and `--abandon` are mutually exclusive with each other and with fresh options;
    - after every refusal, **zero** run, step or tenant rows exist.
  - *(AC-1, AC-6, AC-11 refusal half, D-15, D-19.)*
- [ ] **Step 9: `reserve_identity` and `select_server`.**
  - **`reserve_identity`:**
    - the tenant row has AC-10's values, `status = 'provisioning'` and `plan_id` null;
    - `sequence` is `MAX + 1`, inside the transaction;
    - names and references are computed once and journalled;
    - a code collision is refused, naming the existing run id;
    - **the race:** a unique violation is re-read to tell a code collision (refuse, naming the winner's run) from a sequence collision (bounded retry). The sequential test pre-inserts the colliding row between the read and the insert through a hook;
    - **AC-37:** a static test tokenises `ReserveIdentity.php` and asserts that the only value written to `code` comes from `TenantCode::normalise()`.
  - **`select_server`:**
    - the lowest `tenant_count` wins;
    - each excluding condition is named when nothing qualifies;
    - an override that fails a condition is refused, with no fallback;
    - `database_servers` is unchanged (AC-14).
  - *(AC-6, AC-7 stand-in half, AC-10, AC-11, AC-13, AC-14, AC-37.)*
- [ ] **Step 10: the engine-side steps and `write_mapping`.**
  - **`create_database`:**
    - first attempt: `Absent` → create;
    - `Occupied` or `Empty` on the first attempt → refuse as pre-existing (AC-5 negative), with `preexisting: true` journalled so a resume refuses again;
    - re-entry after the journal says "about to create" and the state is `Empty` → adopt.
  - **`store_credentials`:** two `put` calls; a re-entry does not rotate (AC-17).
  - **`create_users`:**
    - passwords are read back through the resolver;
    - a user that existed before this run's first attempt is refused;
    - re-entry is idempotent.
  - **`write_mapping`:** AC-19, idempotent when the row exists with equal values, refused when it exists with different values.
  - **AC-8:** fail each of the three engine steps through the stand-in. Afterwards there is no `tenant_databases` row, the tenant is `provisioning`, `Tenancy::enter()` refuses, and a bulk `PlanMigrationRun` skips it.
  - *(AC-5 stand-in half, AC-8, AC-15, AC-17, AC-19.)*
- [ ] **Step 11: `migrate_remainder`.**
  - This uses the shipped `ArtisanTenantMigrationApplier` on the SQLite tenant, as `QaRealApplierOnStandInTest` does.
  - **Tests:**
    - the planner is called with a named selector (spy on `PlanMigrationRun::namesTenants()`);
    - the run id is journalled before execution;
    - re-entry reuses it (exactly one `tenant_migration_runs` row);
    - a failing fixture migration fails the step with the job's error, and `seed_core` does not run;
    - after a fix and a resume, the step completes on the **same** run id (`retryFailed`);
    - `tenant_databases.status` is still `provisioning` afterwards (no override, AC-22).
  - *(AC-20 to AC-23.)*
- [ ] **Step 12: `seed_core`.**
  - **Tests:**
    - the `schema_meta` stamp, then `seedPermissions`, then `ensure(branch)` with `MAIN` and the tenant name or the overrides;
    - a refused key fails the step, and no branch is created;
    - a refused branch fails the step, and `create_owner` does not run;
    - a mapping status other than `provisioning` is refused before entry (Ruling 6).
  - *(AC-25, AC-26, AC-27, B-4.)*
- [ ] **Step 13: `create_owner`.**
  - **Tests:**
    - Ruling 7's postconditions;
    - an email collision is refused **before** any tenant write;
    - **AC-30:** inject a master failure after the tenant write. The employee row stays; the resume completes only the master half; there is exactly one employee;
    - the password appears in the command output once and in no row or log (log spy).
  - *(AC-29, AC-30, AC-31.)*
- [ ] **Step 14: `verify` and `activate`.**
  - **`verify`:** each AC-32 assertion plus the `schema_meta` stamp, each removed in turn by a fixture (a deleted migration row, a deleted `role_permissions` row, a deleted branch, an unlinked `tenant_users`, a wrong stamp). Each fails `verify` with the assertion named, and `activate` does not run.
  - **`activate`:**
    - mapping `active`, `tenant_count + 1`, one subscription (`trial`, or `active` with `--active`), tenant status `trial` or `active`, run `completed`, all in one transaction (a fault injected after the subscription rolls everything back);
    - eviction after commit;
    - **AC-35:** re-entry writes nothing (row counts and `tenant_count` unchanged).
  - **AC-36:** `Tenancy::enter($id)` (serving) now succeeds.
  - *(AC-32 to AC-36.)*
- [ ] **Step 15: end to end.**
  - `packages/platform/tests/Features/ProvisionTenant/ProvisionTenantEndToEndTest.php`:
    - fresh `--code=ten001` → `completed`;
    - **AC-38:** inside the tenant, `ActivationIssuer::issue()` returns a code whose tenant part is `TEN001`;
    - the owner logs in through `LoginHandler` with the printed password, sees the tenant as selectable, and gets `must_change_password = true`;
    - a crash-and-resume at each of the eleven steps (dataset) ends `completed` with the same row counts as an uninterrupted run.
  - *(AC-38, AC-4/AC-5 end to end.)*
- [ ] **Step 16: abandon (AC-9 as corrected by B-2).**
  - **Tests:**
    - reverse-order compensation over the `done` steps;
    - steps end `compensated` and the run `rolled_back`;
    - the stand-in records `dropUser` ×2 and **no** database drop;
    - the secrets are gone, the mapping row is gone, the `tenant_users` row is gone;
    - the tenant is tombstoned (`archived`, `deleted_at`), and its code is still taken (a fresh run with the same code is refused);
    - the output names the database left behind;
    - abandoning a `completed` run is refused; abandoning a non-stale `running` run is refused.
  - *(AC-9.)*
- [ ] **Step 17: documents.**
  - database.md §4.7 and §8 as-built notes, architecture.md §7, and the `EngineDatabase` and `MappingSource` docblocks. Describe changed vocabulary; do not quote it (memory: the gate sweeps the working tree).
  - *(No AC.)*
- [ ] **Step 18: close-out (`qa`).**
  - **Mandatory mutations,** each shown red and then reverted:
    1. drop the "skip done" check (step 7);
    2. remove the sanitiser call on the failure path (AC-18);
    3. let `reserve_identity` write the raw `--code` (AC-37 static and AC-38);
    4. make `migrate_remainder` plan a bulk run (AC-20 spy);
    5. re-plan on re-entry (AC-23);
    6. drop the compare-and-set from `activate` (AC-35);
    7. remove the bootstrap owner-exists guard;
    8. remove the secret-root confinement;
    9. make the default provisioner binding the MySQL one (binding test);
    10. remove Ruling 6's status pre-check.
  - **Then:** the diff against section 9, and the final `composer check` with the skip delta and the test-step seconds.
  - *(AC-39, AC-40.)*

## 6. Test strategy

- **Unit, pure:** `TenantDatabaseNames`, `TenantCode`, `ProvisionerStatements`, `CredentialGenerator`, `FileSecretPath`.
- **Slice:**
  - the SQLite master from the platform test fixture;
  - SQLite tenant files in a per-test temporary root that is the working directory (step 4 probe);
  - `RecordingTenantDatabaseProvisioner` bound;
  - a fixed `RunnerClock`;
  - `Artisan::call` in-process;
  - no network.
- **Host:** containment check 3, the binding test, and `TenantProvisionerEngineTest` (engine group, skipped).
- **AC map:**

  | ACs | Steps |
  |---|---|
  | AC-1 | 8 |
  | AC-2 to AC-4 | 7 |
  | AC-5 | 10 and 15 |
  | AC-6 | 7, 8 and 9 |
  | AC-7 | 9, stand-in half only |
  | AC-8 | 10 |
  | AC-9 | 16 |
  | AC-10 to AC-14 | 9 |
  | AC-15 to AC-19 | 3, 4, 5 and 10 |
  | AC-20 to AC-23 | 11 |
  | AC-25 to AC-27 | 6 and 12 |
  | AC-29 to AC-31 | 6 and 13 |
  | AC-32 to AC-36 | 14 |
  | AC-37 | 9 |
  | AC-38 | 15 |
  | AC-39 and AC-40 | 18 |

  AC-24 and AC-28 are scope notes with no tests.
- **NOT VERIFIED (G-1):** AC-16, and B-7's engine halves of AC-5, AC-7, AC-9 and AC-18.
- **Cost:** about 120 cases. FP-328 headroom is tight, so `dev` reports the test-step seconds at steps 0 and 18, and flags any step adding more than 15 s. The 11-step crash dataset in step 15 is the largest item; it may go in the toolchain-free slow group only if the measurement says so.

## 7. Risks & rollback

- **Real engine.** If the real driver is ever bound against the shared XAMPP MariaDB, it creates real users there. Mitigations: the default binding refuses, the binding is opt-in by config, the engine test requires the Docker engine, and `ENGINE_HOLD` holds.
- **Working-directory dependence of the SQLite stand-in.** The step 4 probe gates this. Fallback: stop, rather than add a test-only branch to the kernel's connection path.
- **Secret root loss.** Losing the `file://` root loses every tenant's credentials. They are recoverable by `ALTER USER` with the provisioner credential, but no rotation command exists yet. This is a question for the human (backup location).
- **Capacity overshoot.** A race between two runs can push a server one tenant past `max_tenants`. It is reported, not refused.
- **Latent:** a future `contract` or destructive tenant migration will block provisioning. `assertNothingDestructive` and `assertContractStepsUnblocked` apply to a version-0 tenant, and the contract check blocks on fleet laggards. All tenant migrations are `@phase expand` today. Filed as a follow-up.
- **Rollback:** the change is additive. Two new master tables, new classes, three bindings and one command. Reverting the branch removes it; no tenant-tree change was made.

## 8. Verification commands (Git Bash)

```
cd modules/host
/e/xampp/php/php.exe /c/ProgramData/ComposerSetup/bin/composer.phar test:fast   # inner loop only
/e/xampp/php/php.exe /c/ProgramData/ComposerSetup/bin/composer.phar check       # the gate
```

## 9. Pre-existing files legitimately edited

- `modules/host/tests/Feature/DestructiveStatementContainmentTest.php`: check 3 added; checks 1 and 2 untouched.
- `MasterSchemaBuildsTest` and the master table inventory: two new names.
- `config/platform.php`, `config/tenancy.php`, `.env.example`: new keys.
- `TenancyServiceProvider`, `AccessServiceProvider`, `OrganizationServiceProvider`, `PlatformServiceProvider`: bindings and registrations.
- `MappingSource.php`, `EngineDatabase.php`: docblocks only.
- `docs/database.md`, `docs/architecture.md`: as-built notes.

Any other red file means stop and report to `architect`.

## 10. Messages

### `architect` → lead (relay to `ba`)

- **Spec and decision changes needed before `dev` starts steps 9 to 16:** B-1 (step order), B-2 (abandon keeps the database and tombstones the tenant), B-3 (D-6 becomes `file://`), B-4 (new AC: `schema_meta` stamp and its verify assertion), B-5 (new decision: the bootstrap owner op replaces T-010 D-36's expectation), B-6 (AC-29 schema details), B-7 (`[ENGINE]` halves), B-8 (format validation up front), B-10 (references) and B-11 (option names).
- Steps 0 to 8 can start now: they are independent of every correction except B-3 and B-8, and this plan already adopts both.
- **Informational:** Ruling 9 (activate in one outer transaction, eviction after commit) and the latent contract/destructive blocking risk (a follow-up row).

### `architect` → `dev`

- Never bind `MySqlTenantDatabaseProvisioner` in a test.
- Never write `DROP DATABASE` anywhere.
- Never stub `TenantDatabaseNames` or any guard to allow.
- If the step 4 probe fails, stop.

## Questions for the human

1. **The owner's first login (B-9).** No welcome mail and no password reset exist. Should `tenant:provision` print the generated owner password once to the operator's console (this plan's interim behaviour), or should the owner be created with no usable password until a reset or invite feature exists?
2. **Where writable tenant secrets live on the VPS.** This plan uses a `file://` root, by default `storage/secrets`, outside git. Should the root be a dedicated backed-up directory? It is not a Docker secret: Docker secrets are read-only mounts fixed at compose time.
3. **An existing login as owner.** database.md §8 says "insert/attach `users`", but AC-31 refuses an existing email. Should a person who already owns one shop be attachable as the owner of a second (for example, with an explicit `--attach-existing-owner`), or is the refusal correct for now?
