# Plan: T-026 Tenant provisioning

Spec: `docs/specs/T-026-tenant-provisioning.md` (AC-1 to AC-40, plus AC-25a). Decisions: `docs/decisions/T-026-tenant-provisioning.md` (D-1 to D-30), as corrected in `2a4ad0b`.
Backlog: FP-206. Branch: `ai/T-026-tenant-provisioning`. Gate directory: `modules/host`.

Revision 2. This plan is aligned with the corrected spec: D-20 to D-30 replace this plan's earlier B-1 to B-11 proposals wherever the two differ.

## 0. Verification and rulings

### 0.1 `ba`'s original findings, re-read in source

| Claim | Verdict | Evidence |
|---|---|---|
| Runner docblock: "`provisioning` restores to `provisioning` - FP-206 owns when that becomes `active`" | **Holds** | `MigrateTenantHandler.php:366`. A non-offline plan writes no status at all. |
| `TenantStatusPolicy::NAMED_ONLY_STATUSES = ['provisioning', 'failed']` | **Holds** | `TenantStatusPolicy.php:57`. |
| `TenantCommercialStatus::PROTECTED` excludes `pending` and `provisioning`, citing FP-206 | **Holds** | `TenantCommercialStatus.php:25-28`. |
| `SecretResolver` is read-only | **Holds** | Only `env://` is registered (`TenancyServiceProvider.php:99-100`); see D-6 and B-3. |
| `ActivationIssuer` needs an upper-case `tenants.code`, checked against `TENANT_PART_PATTERN` | **Holds** | `ActivationIssuer.php:32` and `:65`. |
| `SyncPermissionRegistry` and `CreateBranch` are reusable | **Holds** | `CreateBranch` passes with no plan, because `MasterEntitlementSource` returns uncapped `Entitlements` when `plan_id` is null. |
| D-15: `--plan` is required, with no default | **Holds** | No default-plan marker exists in `plans` or in config. |

### 0.2 The corrected spec, re-read in source

The corrected spec (D-20 to D-30) was checked against source.
- **D-24's `AttachBranch` claim is correct.** `AttachBranchHandler` has no `Escalation` call, and `SelfChange::refuse()` is a no-op for a null actor. But it **refuses a re-attach** with `validation` ("already attached"), so the resume path must check `employee_branches` before calling it (Ruling 5).
- **One residual error remains (B-12, minor).** AC-1 says `--branch-name` is "at most 150 characters", calling that `branches`' own column limit. It is not. The column is `string('name', 100)` (`…000501_create_branches_table.php:52`), and `CreateBranchHandler.php:67` validates 100. A tenant name of 101 to 150 characters, which is the default branch name, would pass AC-1 as written and then fail at `seed_core`. **This plan validates through organization's own rule (100)**, via `DefaultBranch::validate()`. `ba` should correct the number; nothing else changes.
- **One consequence of D-21 is recorded as a risk (section 7) and passed to `lead`.** An archived (abandoned) tenant whose mapping row sits below a future `contract` migration's version **blocks that contract step fleet-wide**. `PlanMigrationRunHandler::tenantsBelow()` exempts exactly one status, `provisioning`, and "an unrecognised status blocks" by design (BUG-7). Every tenant migration is `@phase expand` today, so nothing breaks now. The fix belongs to T-008's runner, not here: it would be a follow-up row such as "archived tenants with no data do not block contract steps".

### 0.3 Rulings

**Ruling 1: where the code lives.** No new layer, and `deptrac.yaml` is not edited.

| Concern | Home |
|---|---|
| Orchestrator, steps, run/step repository, `tenant:provision`, tenant code normaliser | `packages/platform`: `Flexipos\Platform\Provisioning\…` and slice `Features/ProvisionTenant`. Platform already owns the master-side tenant lifecycle, including `TenantStatusSync`, `StartSubscription`, `MasterConnection` and `PlatformAudit`. |
| Engine port, naming, mapping writer, `schema_meta` writer, writable secrets | `packages/kernel`. The mapping writer reuses `EvictsTenantMappings` and the `TenantDatabase` status constants, which platform is deliberately not granted. |
| Owner bootstrap (D-23), registry sync, owner verification | `packages/access`, behind `Contracts\Access\TenantAccessBootstrap`. |
| Default branch, and branch-field validation | `packages/organization`, behind `Contracts\Organization\DefaultBranch`. |

**Ruling 2: the resumable run.**
- **Planning.** A fresh invocation validates every field (AC-1, D-25). It then writes one run row and eleven step rows in AC-2's order, all `pending`, in one master transaction. This is the shape `PlanMigrationRunHandler::write()` already uses.
- **Claiming.**
  - A run is claimed with a compare-and-set lease: `UPDATE … SET status='running', claimed_by=?, heartbeat_at=now WHERE id=? AND (status='failed' OR (status='running' AND heartbeat_at < now - window))`. One affected row means the run is this process's.
  - `--resume` of a non-stale `running` run is refused, naming its last heartbeat.
  - The window is `platform.provisioning.stale_after_minutes`, default 30. Nothing auto-resumes (AC-4).
- **Intent first, then effect, then done.** Each step follows the same order:
  - The step is marked `running`, with `attempts + 1`, and that is committed.
  - Any identity the step chooses is written to `output` through `StepJournal` and committed **before** the effect. Examples: the migration run id, the owner `user_id`, an "about to create" marker.
  - On re-entry the step reads its own journal (AC-5).
  - The heartbeat is refreshed at every step transition.
- **Failure.**
  - The error goes through `MigrationErrorText::sanitise()`. Its needles are every credential reference in the run's outputs, plus each one's resolved value (the `needlesFor()` idiom).
  - The step becomes `failed`, and the run becomes `failed` with `current_step` left on that step.
  - No later step runs (AC-3, AC-18).
- **The run row carries `input` JSON**: the validated options, with no secrets. A resume therefore needs no options.

**Ruling 3: writable secrets (D-6, D-29).**
- **The write port.** `Kernel\Tenancy\Secrets\WritableSecretStore`:
  - `put(ref, Secret)`: a no-op when the stored value is equal, refused when it is different;
  - `exists(ref)`;
  - `forget(ref)`.
- **Dispatch.** `SchemeSecretStore` dispatches by scheme and refuses an unknown one. `vault://` later is one driver plus one map entry.
- **The `file://` drivers.**
  - `FileSecretResolver` (read) and `FileSecretStore` (write) share `FileSecretPath`.
  - Every reference must resolve under `tenancy.secrets.file_root`, after `..` and symlinks are resolved. This is configurable and defaults to `storage_path('secrets')` (D-29); real placement is FP-221's.
  - Writes go to a temporary file and are then renamed, with mode 0600. Windows ignores the mode; this is recorded.
- **References** look like `file://<root>/tenant_<seq6>_{app|mig}`. They are computed at `reserve_identity` and never recomputed (AC-12, AC-17).
- **Registration.** `SchemeSecretResolver` gains `file`. `env://` stays read-only, and `put` to it is refused.
- **Passwords** are 40 characters of `[A-Za-z0-9]` from `random_bytes`. `store_credentials` generates them, never the engine port (AC-15).

**Ruling 4: the engine port (AC-15, AC-16) and the `ENGINE_HOLD` line.**
- **The port.** `Kernel\Tenancy\Provisioning\Contracts\TenantDatabaseProvisioner`. Each method takes a `ProvisioningServer`, built from the `database_servers` row: engine, host, port, tls and `provisioner_credential_ref`.
  - `databaseState(name): DatabaseState` (`Absent | Empty | Occupied`)
  - `createDatabase(name, charset, collation)`
  - `userExists(user): bool`
  - `createUser(user, Secret, TenantGrant)`, where `TenantGrant` is `App | Migrate`. It runs `CREATE USER IF NOT EXISTS`, `ALTER USER … IDENTIFIED BY` and `GRANT … ON \`db\`.*`, all idempotent.
  - `dropUser(user)`, for abandon.
- **Three implementations.**
  - **`MySqlTenantDatabaseProvisioner`, the real one.**
    - Its statements come from a pure `ProvisionerStatements` builder. The grant lists are exactly AC-16's.
    - Every identifier passes a two-check guard at the point of effect, the shape `EngineDatabase` uses: `TenantDatabaseNames::assert*()` plus an independent `flx_t_` literal.
    - Password-bearing statements are a `SecretStatement` type, and every string form of it is redacted.
    - Those statements run on the raw PDO, so no `QueryExecuted` listener ever sees them. Where the driver allows it they are prepared with a bound password (AC-18's design note).
  - **`RecordingTenantDatabaseProvisioner`, the suite stand-in.** It records each call with the password replaced by a marker. For a `sqlite` server it creates an empty SQLite file named exactly `database_name` in the fixture's working directory (see the step 4 probe).
  - **`RefusingTenantDatabaseProvisioner`, the default binding.** It throws "no provisioner configured". The MySQL implementation is bound only when `tenancy.provisioning.driver=mysql` is set explicitly, and `phpunit.xml` never sets it. A test that forgets the stand-in therefore fails closed instead of reaching the shared MariaDB on `127.0.0.1:3306`.
- **Proven today:** the exact SQL, the grants, the guards, the redaction, the binding, and every step against the stand-in.
- **NOT VERIFIED until G-1** (AC-40's list):
  - the statements succeeding on MySQL 8.4 and MariaDB 10.4;
  - the `information_schema` detection;
  - `DROP USER`;
  - server-side leakage;
  - the two-process race.
- **The engine test.** `modules/host/tests/Engine/TenantProvisionerEngineTest.php` is written and skipped under `ENGINE_HOLD`. Its docblock requires the T-023 Docker MySQL and never the shared XAMPP engine, because `flx_t_*` is not a reserved test prefix.

**Ruling 5: the cross-package contracts (D-23, D-24).**
- **`Contracts\Access\TenantAccessBootstrap`**, implemented by `Access\Features\BootstrapOwner\BootstrapOwnerHandler`:
  - **`seedPermissions(): list<string>`** runs `SyncPermissionRegistryHandler` and returns its `refused` keys.
  - **`ensureOwnerEmployee(OwnerSeed): string`** finds the employee by `code = 'OWNER'` or creates it through the unchanged `CreateEmployeeHandler`. Both `first_name` and `display_name` come from `OwnerSeed`; the status is active and `pin_hash` is null.
  - **`attachOwnerBranch(employeeId, branchId): void`** reads `employee_branches` first. If the row is absent, it calls the unchanged `AttachBranchHandler` with `isDefault: true` and `actorId: null`. A present row is a no-op, because `AttachBranch` itself refuses a re-attach.
  - **`grantOwner(employeeId): void`** is **the D-23 operation**. In one tenant transaction it:
    - re-checks that no **other** live (non-soft-deleted) employee holds the system role keyed `owner`, and refuses with `TenantBootstrapRefused` if one does;
    - inserts the `employee_roles` row with `granted_by` null, unless this employee already holds it;
    - bumps `perm_version`;
    - writes an audit row.

    It never calls `Escalation`. Its precondition is its guard.
  - **`linkUser(employeeId, userId): void`** is idempotent.
  - **`ownerMissingKeys(): list<string>`** re-reads the database against the registry, for `verify`.
  - **`ownerState(): ?array`** returns `{employee_id, user_id, branch_ids, holds_owner}` for the `OWNER` employee, also for `verify`.
- **`Contracts\Organization\DefaultBranch`**, implemented by `Organization\Support\DatabaseDefaultBranch`:
  - **`validate(BranchSeed)`** needs no connection. It runs `OrgCode::normalise` and the `Fields::*` checks, including the 100-character name (B-12). It is used by AC-1.
  - **`ensure(BranchSeed): string`** returns the id of the existing branch with that code, or calls `CreateBranchHandler`.
  - **`exists(branchId): bool`** is for `verify`.
- **Value objects** `OwnerSeed`, `BranchSeed` and `TenantBootstrapRefused` live in `packages/contracts`.

**Ruling 6: tenant-database scope for `seed_core`, `create_owner` and `verify`.**
- These three steps run in `Tenancy::run($tenantId, …, TenantConnectionPurpose::Migrating)`. That is the only purpose that enters a `provisioning` tenant.
- Each step first re-reads `tenant_databases.status` from the master and refuses unless it is `provisioning`. Provisioning therefore never writes into a live tenant.
- Rejected: a new `Provisioning` purpose. It would change the security-critical `TenantStatusPolicy::accepted()` match for a property this one pre-check already gives.

**Ruling 7: `create_owner` (AC-29 to AC-31, D-24, D-26, D-30).** The order below is AC-29's, and every crash point resumes.
1. Journal a pre-generated `user_id` (a ULID), unless one is already journalled.
2. **Tenant.** `ensureOwnerEmployee()`. The display name is `--owner-name` or the email's local part; when attaching, `--owner-name` is ignored (AC-31).
3. **Tenant.** `attachOwnerBranch()`, using the branch id from `seed_core`'s output.
4. **Tenant.** `grantOwner()`.
5. **Master, one transaction.**
   - Look up `users` by `email_lower`. The journalled `owner_user_mode` decides what happens:
     - **No row:** insert a `users` row with the journalled id. The name is `--owner-name` or the email local part. The password hash is argon (`Hash::make`) of a fresh random password, with `must_change_password = true` and `status = 'active'`. Journal `owner_user_mode = created`.
     - **A row whose id equals the journalled id:** an earlier attempt created it, so nothing is written.
     - **A row with a different id:** **attach** (D-30). Nothing on that row is modified. Journal `owner_user_mode = attached` and the existing id.
   - Insert the `tenant_users` row if it is absent: `access_level = 'owner'`, `status = 'active'`, `employee_id`, and `is_default` true only when `created`.
6. **Tenant.** `linkUser()`.
7. **Console (D-26).** Only in the attempt that *inserted* the user, the command prints the initial password once, from an in-memory result. It is never journalled or logged.
   - On an attach, nothing is printed except "attached to the existing login".
   - On a resume that finds the user already created, the command prints "the initial password was shown when it was created".

**Ruling 8: `migrate_remainder` drives T-008, it does not rebuild it (AC-20 to AC-23).**
1. If no `migration_run_id` is journalled, call `PlanMigrationRunHandler`:
   - with `tenantIds: [$tenantId]`, a named selector, never bulk;
   - with the set `MigrationSet::baseline(base_path(config('schema.tenant_migration_path')))`, exactly as `TenantMigrateCommand` builds it.
   - Journal the id **before** executing anything.
2. Call `RunTenantMigrationsHandler` with:
   - `runId`;
   - `tenantIds: [$tenantId]`;
   - `retryFailed: true`;
   - `workerId: 'provision-<runId>'`.

   It runs synchronously, in-process.
3. Read this tenant's job:
   - `done`: the step is done, with `output` `{migration_run_id, schema_version}`;
   - `failed`: the step fails with the job's already-sanitised `error_message`;
   - `skipped` (the lock was held): the step fails with "resume later".
   - The mapping status is never written here.
4. A `MigrationRunRefused` is a step failure carrying its own message.

**Ruling 9: `activate`, exactly once (AC-33 to AC-36).**
- **One master transaction, in this order:**
  1. `UPDATE tenant_databases SET status='active', provisioned_at=now WHERE tenant_id=? AND status='provisioning'`.
  2. Only if that statement affected one row: `tenant_count = tenant_count + 1`.
  3. `StartSubscriptionHandler`, skipped when a subscription row for the tenant already exists. Its transaction nests as a savepoint.
  4. `UPDATE tenants SET status=?, activated_at=now WHERE id=? AND status='provisioning'`, with `trial`, or `active` under `--active`.
  5. The step is marked done and the run completed.
- **After commit** (`$connection->afterCommit`), the mapping eviction and the entitlement eviction run again. `StartSubscription`'s own eviction runs inside the transaction and is premature by itself.
- **Re-entry is a no-op.** Every write is compare-and-set (AC-35).

**Ruling 10: abandon (AC-9, D-21).**
- **Allowed runs.** Only a `failed` run, or a stale `running` one, claimed through the same lease. A `completed` run is refused.
- **What it does, master side.**
  - It never drops a database.
  - It deletes no `tenants` row and no `tenant_databases` row.
  - It writes `tenants.status = 'archived'` and, if the mapping row exists, `tenant_databases.status = 'archived'`. The mapping write goes through `TenantMappingWriter::archive()`, with eviction.
- **What it does, engine side.**
  - It runs `dropUser()` for each user, if `create_users` reached it. `DROP USER` removes that user's grants with it.
  - It runs `forget()` on both secret references.
- **What it leaves.** It leaves the `employees`, `users` and `tenant_users` rows alone. The spec does not ask for them to be removed. An archived tenant is not in `TenantSelectionPolicy::SELECTABLE` and not in either `MIGRATING_STATUSES` or `SERVING_STATUSES`. An attached owner's existing login must not be touched (D-30).
- **Records.** Each compensated step is marked `compensated`, and the run ends `rolled_back`.
- **Output.** It prints the spec's message verbatim, with the database name and the server code.

## 1. Approach summary

1. Two master tables, `tenant_provisioning_runs` and `tenant_provisioning_steps`. They follow database.md §4.7, plus three extra columns: `input`, `claimed_by` and `heartbeat_at`.
2. The kernel gains:
   - the engine port, in three implementations: the real MySQL one, a recording stand-in, and a refusing default;
   - the naming rule;
   - a mapping writer (`insert`, `activate`, `archive`), with eviction after each write;
   - the `schema_meta` `tenant_id` writer;
   - a root-confined `file://` read and write store, behind a scheme-dispatching port.
3. Access and organization each publish one contract. The first-owner grant is D-23's precondition-gated bootstrap operation. Branch attachment reuses the unchanged `AttachBranch`.
4. Platform holds the orchestrator:
   - eleven step classes, in D-20's order;
   - intent-first journalling;
   - a compare-and-set lease;
   - sanitised errors;
   - `tenant:provision` with `--resume` and `--abandon`.
5. `migrate_remainder` calls T-008's planner and runner with a named selector. `activate` makes the single explicit `provisioning → trial|active` transition. Abandon archives the tenant and keeps its database.
6. Everything is proven on the SQLite master and tenant stand-ins with the recording provisioner. AC-40's listed halves stay NOT VERIFIED behind `ENGINE_HOLD`.

## 2. Alternatives rejected

- **A new `packages/provisioning`:** it would need a new layer, a Composer path package, PHPStan paths and a third contract, all for one slice.
- **The orchestrator in `modules/host/app`:** `HostApp` reaches every package's internals, so deptrac would prove nothing.
- **`AssignRole` with a "system actor":** the actor would have to hold `owner`, and `granted_by` is a foreign key to `employees` (D-23).
- **`env://` write support:** a process cannot persist its own environment.
- **Auto-compensation:** rejected by D-11. A production `DROP DATABASE` is forbidden by the gate (D-21).
- **One `RunTenantMigrations` call to plan and run:** a crash loses the run id (D-13).
- **A `Provisioning` connection purpose:** see Ruling 6.

## 3. Files to change / create

### `modules/host`
- `database/migrations/2026_11_07_002601_create_tenant_provisioning_runs_table.php` (new)
- `database/migrations/2026_11_07_002602_create_tenant_provisioning_steps_table.php` (new)
- `config/platform.php`: `provisioning.stale_after_minutes`.
- `config/tenancy.php`: `provisioning.driver`, `provisioning.user_host` (default `%`), `secrets.file_root`.
- `.env.example`: the matching keys.
- `tests/Engine/TenantProvisionerEngineTest.php` (new; skipped under the hold).
- `tests/Feature/DestructiveStatementContainmentTest.php`: **check 3**.
  - The rule: `create database`, `create user`, `alter user`, `grant … on` and `drop user` appear in code only in `ProvisionerStatements` and `MySqlTenantDatabaseProvisioner`, plus `EngineDatabase`'s create and the measured test-literal sites (`CiWorkflowShapeTest`).
  - The expected map is exact, with concatenated controls.
  - Checks 1 and 2 are untouched. Check 1 is what proves AC-39's "no new `DROP DATABASE`/`DROP SCHEMA`".
- Predicted test-literal edits: `MasterSchemaBuildsTest` and the master table inventory (measured at step 1).

### `packages/kernel/src`
- `Tenancy/Provisioning/Contracts/TenantDatabaseProvisioner.php`, `ProvisioningServer.php`, `DatabaseState.php`, `TenantGrant.php`
- `Tenancy/Provisioning/TenantDatabaseNames.php`
- `Tenancy/Provisioning/ProvisionerStatements.php`, `SecretStatement.php`
- `Tenancy/Provisioning/MySqlTenantDatabaseProvisioner.php`, `RecordingTenantDatabaseProvisioner.php`, `RefusingTenantDatabaseProvisioner.php`
- `Tenancy/Provisioning/TenantMappingWriter.php`: `insert`, `activate` (compare-and-set) and `archive`, each followed by eviction.
- `Tenancy/Provisioning/SchemaMetaStamp.php`: writes the one `tenant_id` row (AC-25a), using `SchemaMetaIdentity::TABLE` and `::KEY`. It is:
  - a write when absent;
  - a no-op when equal;
  - `TenantIdentityMismatch` when the row names another tenant.
- `Tenancy/Secrets/WritableSecretStore.php`, `SchemeSecretStore.php`, `FileSecretPath.php`, `FileSecretResolver.php`, `FileSecretStore.php`, `CredentialGenerator.php`
- `Tenancy/TenancyServiceProvider.php`: registrations and bindings.
- `Tenancy/MappingSource.php`: docblock only. The line "today every reference … is `env://`" stops being true.

### `packages/contracts/src`
- `Access/TenantAccessBootstrap.php`, `Access/OwnerSeed.php`, `Access/TenantBootstrapRefused.php`
- `Organization/DefaultBranch.php`, `Organization/BranchSeed.php`

### `packages/access/src`
- `Features/BootstrapOwner/BootstrapOwnerHandler.php` (new)
- `AccessServiceProvider.php`: the binding.

### `packages/organization/src`
- `Support/DatabaseDefaultBranch.php` (new)
- `OrganizationServiceProvider.php`: the binding.

### `packages/platform/src`
- `Provisioning/StepName.php`: an enum in D-20's order.
- `Provisioning/ProvisioningStep.php`: `run(RunContext, StepJournal): array` and `compensate(RunContext): ?string`.
- `Provisioning/RunContext.php`, `StepJournal.php`, `ProvisioningRuns.php`, `ProvisioningRunner.php`, `ProvisioningErrors.php`, `TenantCode.php`
- `Provisioning/Steps/{ReserveIdentity,SelectServer,CreateDatabase,StoreCredentials,CreateUsers,WriteMapping,MigrateRemainder,SeedCore,CreateOwner,Verify,Activate}.php`
- `Features/ProvisionTenant/{ProvisionTenant,ProvisionTenantInput,ProvisionTenantHandler,ProvisionTenantResult,ProvisionTenantCommand}.php`
- `Features/AbandonProvisioning/{AbandonProvisioning,AbandonProvisioningHandler,AbandonProvisioningResult}.php`
- `PlatformServiceProvider.php`: the command and the bindings.

### `packages/testing/src`
- `Engine/EngineDatabase.php`: docblock only. "The only code permitted to issue `CREATE DATABASE`" becomes "the only test code…". The production provisioner is now the other site, governed by check 3.

### `docs`
- `database.md` §4.7 and §8, "As built (T-026)":
  - the new columns;
  - D-20's order;
  - no `import_template` step;
  - `seed_core`'s scope;
  - abandon archiving rather than deleting or dropping;
  - `file://` as the write scheme.
- `architecture.md` §7: one paragraph.

## 4. Data / API changes

**`tenant_provisioning_runs`**

| Column | Type / notes |
|---|---|
| `id` | ULID primary key |
| `tenant_id` | nullable FK to `tenants`; filled at `reserve_identity` |
| `type` | string(30); only `create` is written |
| `source_tenant_id` | nullable |
| `target_server_id` | nullable FK to `database_servers` |
| `status` | string(30): `running`, `completed`, `failed` or `rolled_back` |
| `current_step` | string(40), nullable |
| `input` | JSON (`JsonValidity`); holds no secrets |
| `claimed_by` | string(100), nullable |
| `heartbeat_at`, `started_at`, `finished_at` | datetime(3) |
| `error` | text, nullable |
| `initiated_by` | nullable FK to `users`; filled from `--actor` |
| `[TS]` | |

Indexes: `(status)` and `(tenant_id)`.

**`tenant_provisioning_steps`**

| Column | Type / notes |
|---|---|
| `id` | |
| `run_id` | FK, cascade |
| `step` | string(40) |
| `sequence` | smallint |
| `status` | string(30): `pending`, `running`, `done`, `failed`, `skipped` or `compensated` |
| `attempts` | smallint, default 0 |
| `started_at`, `finished_at` | |
| `output` | JSON, nullable |
| `error` | text, nullable |
| `[TS]` | |

Unique: `(run_id, step)`.

**Other changes**
- Master migrations only: the tenant tree is unchanged, and the catalogue is not republished.
- **CLI:** `tenant:provision`, with the options AC-1 lists, plus `--slug` (D-2) and `--abandon=<run id>`.
  - `--resume` and `--abandon` exclude each other and exclude the fresh-run options.
  - The exit code is 0 only for `completed` or for a clean abandon.
- **Config:** see section 3. No new dependency is added.

## 5. Implementation steps

**Every step:**
- Each step ends with `composer check` green from `modules/host`. Compared with the gate at the step's start, the change must be zero or explained.
- **Red-first:** show red by leaving something out, or with a fixture. **Never stub a guard to allow.**
- No child processes; use `Artisan::call`.
- Test file basenames stay unique.
- List any foreign `php.exe` processes before each gate run.

- [x] **Step 0: baseline.** *(Partial - see `dev`'s handback message for the counts actually recorded;
  this machine's full `composer test` run did not complete inside this session because of heavy,
  unrelated process contention, so the FP-328 headroom line was not re-measured.)*

- [x] **Step 1: the master tables.**
  - **`ProvisioningSchemaTest`:**
    - exact column lists and nullability;
    - unique `(run_id, step)` (a raw double insert);
    - the cascade;
    - invalid JSON refused.
  - **Predicted red:** `MasterSchemaBuildsTest` and the inventory. Anything else means stop.
  - *(AC-2, schema half.)*

- [x] **Step 2: naming and the tenant code.**
  - **`TenantDatabaseNamesTest`:**
    - sequence 1 gives `flx_t_000001`, `flx_t000001_app` and `flx_t000001_mig`;
    - 999999 still has 6 digits, and 1000000 widens to 7;
    - the guards refuse a quote, a backtick, upper case and the wrong prefix;
    - every name fits within 32 characters.
  - **`TenantCodeTest`:**
    - ` ten-001 ` becomes `TEN-001`;
    - `-TEN001`, `TEN001-`, a blank, 31 characters and `TEN_001` are each refused;
    - the check runs against `ActivationCodeFormat::TENANT_PART_PATTERN` itself.
  - *(AC-11 unit half, AC-12.)*

- [x] **Step 3: writable secrets.**
  - **Tests:**
    - put, exists and resolve round-trip;
    - an equal `put` is a no-op and a different `put` is refused (AC-17 negative);
    - `forget` removes the file;
    - an unknown scheme is refused;
    - `put` to `env://` is refused;
    - root confinement holds for read **and** write: `../`, an absolute path outside the root, and (on non-Windows) a symlink out are each refused;
    - the generator gives 40 characters from the alphabet, and two values differ.
  - **Mutation:** removing confinement turns the traversal cases red.
  - *(AC-17, D-6.)*

- [x] **Step 4: the engine port.**
  - **Probe first.**
    - Prove that a mapping with a bare `database_name` of `flx_t_000001` opens the SQLite file of that name when the test's working directory is the fixture root. `SQLiteConnector` tries `realpath()` and then `base_path()`.
    - The working directory is restored in `finally`.
    - **If the probe fails, stop and report to `architect`. Never change the kernel's connection path.**
  - **`ProvisionerStatementsTest`:**
    - the exact `CREATE DATABASE`, with the configured charset and collation;
    - the exact `GRANT` lists for `App` and `Migrate`;
    - `CREATE USER IF NOT EXISTS` and `ALTER USER` are `SecretStatement`s, and their `__toString`, `var_export` and JSON forms show no password.
  - **`MySqlTenantDatabaseProvisionerTest`**, through a recording statement-runner seam:
    - secret statements take only the raw path;
    - a bad identifier never reaches the runner.
  - **`ProvisionerBindingTest`:**
    - the default resolves `Refusing…`, which throws;
    - `driver=mysql` resolves the MySQL implementation;
    - the suite fixture resolves the stand-in.
  - **Also:** containment check 3 and its controls, and the engine test file (skipped).
  - **Mutation:** drop one guard check; the other still refuses.
  - *(AC-15, AC-16 stand-in half, AC-18 statement half, AC-39.)*

- [x] **Step 5: the mapping writer and the `schema_meta` stamp.**
  - **`TenantMappingWriterTest`:**
    - `insert` writes exactly AC-19's values;
    - every write is followed by an eviction (spy on `EvictsTenantMappings`);
    - `activate` is compare-and-set: a second call affects 0 rows;
    - `archive` writes `archived` and evicts.
  - **`SchemaMetaStampTest`:** absent means written, equal means a no-op, and another tenant means `TenantIdentityMismatch` with nothing written. The test reads back through `SchemaMetaIdentity::of()`.
  - *(AC-19, AC-25a, groundwork for AC-33.)*

- [ ] **Step 6: the contracts and their implementations.**
  - **Access, `BootstrapOwnerTest`** (on `AccessTenantFixture`):
    - `ensureOwnerEmployee` creates `OWNER` once; a second call returns the same id.
    - `attachOwnerBranch` writes one `employee_branches` row with `is_default`; a second call is a no-op and is **not** refused.
    - `grantOwner` grants the system `owner` with `granted_by` null and an audit row. A second call for the same employee is a no-op.
    - **The D-23 guard:** when another live employee holds `owner`, the call is refused with `TenantBootstrapRefused` and nothing is written. Control: a soft-deleted holder does not trigger the refusal.
    - `seedPermissions()` returns refused keys from a conflicting `is_branch_scoped` fixture.
    - `ownerMissingKeys()` is empty after the seed, and non-empty after one `role_permissions` row is deleted.
  - **Organization, `DatabaseDefaultBranchTest`:**
    - `validate()` refuses a non-IANA zone, an unknown business type, a 101-character name (B-12) and a bad code, all without a connection;
    - `ensure()` creates the branch, its store and the link; a second call returns the same id.
  - **Mutation:** remove the D-23 precondition; the guard case goes red.
  - *(AC-25, AC-26, AC-27, AC-29 tenant half, D-23, D-24, D-25.)*

- [ ] **Step 7: the runner core, with three in-test fake steps.**
  - **The plan:** written whole (one run, every step `pending`, `current_step` first), and steps run in order.
  - **On failure:** the step is `failed` with its error, the run is `failed` with `current_step` on that step, and later steps are untouched.
  - **Resume:** `done` steps are skipped, and the journal survives a thrown step.
  - **The lease:** a non-stale `running` run is refused, and a stale one is claimable.
    - Two sequential claims produce one winner.
    - `--resume` of a `completed` run is refused with no write, and an unknown id gives `not found`.
  - **AC-18 control:** a fake step throws with the password in its message, and its reference is in the outputs. The stored `error` contains neither the password nor the reference.
  - **Mutation:** drop "skip done"; the execution counter goes red.
  - *(AC-2, AC-3, AC-4, AC-6 resume half, AC-18.)*

- [ ] **Step 8: the command and AC-1's validation (D-25, D-28).**
  - **`ProvisionTenantCommandTest`:** each required option, missing or blank, is refused with the field named.
  - Each AC-1 format rule is refused when broken:
    - code pattern;
    - country `[A-Z]{2}`, with no case folding;
    - currency `[A-Z]{3}`;
    - IANA timezone;
    - business type in `retail`, `restaurant`, `both`;
    - an unknown, soft-deleted or inactive `--plan` id (the lookup `StartSubscriptionHandler` itself does);
    - branch name or code through `DefaultBranch::validate()`.
  - `--resume` and `--abandon` exclusivity.
  - After each refusal, **zero** run, step or tenant rows exist.
  - *(AC-1, AC-6, AC-11 refusal half, D-15, D-28.)*

- [ ] **Step 9: `reserve_identity` and `select_server`.**
  - **`reserve_identity`.**
    - The tenant row matches AC-10, with `provisioning` status and a null `plan_id`.
    - `sequence` is `MAX + 1` inside the transaction. This works on both drivers; SQLite has no AUTO_INCREMENT on the column.
    - Names and references are journalled once.
  - **Code collision.** It is refused, and the message names the existing run.
  - **AC-7, stand-in half.** A unique violation is re-read to tell a code collision (refused, naming the winner) from a sequence collision (a bounded retry). A test hook inserts the conflicting row between the read and the insert.
  - **AC-37.** A static test tokenises `ReserveIdentity.php`: the only value written to `code` comes from `TenantCode::normalise()`.
  - **`select_server`.**
    - The lowest `tenant_count` wins.
    - When nothing qualifies, each excluding condition is named.
    - A failing override is refused, with no fallback.
    - `database_servers` is unchanged.
  - *(AC-6, AC-7 stand-in half, AC-10, AC-11, AC-13, AC-14, AC-37.)*

- [ ] **Step 10: `create_database`, `store_credentials`, `create_users`, `write_mapping` (D-20 order).**
  - **`create_database`.**
    - On the first attempt, `Absent` means create.
    - `Empty` or `Occupied` on the first attempt means refuse as pre-existing (AC-5 negative). `preexisting: true` is journalled, so a resume refuses again.
    - On re-entry after an "about to create" journal entry, `Empty` means adopt.
  - **`store_credentials`.** Two `put`s. A re-entry neither rotates nor writes a second version (AC-17 negative).
    - **Crash between this step and `create_users`:** the resume reads the stored passwords and creates the users. The passwords match what the stand-in recorded, compared by hash marker (D-20).
  - **`create_users`.**
    - Passwords are read back through the resolver.
    - A user that existed before this run's first attempt is refused.
    - Re-entry is idempotent.
  - **`write_mapping`.** It writes AC-19's row. It is idempotent when the row exists with equal values, and refused when the values differ.
  - **AC-8.** Fail each of the three engine steps through the stand-in. Afterwards:
    - there is no mapping row and the tenant is still `provisioning`;
    - `Tenancy::enter()` refuses;
    - a bulk `PlanMigrationRun` skips the tenant.
  - *(AC-5 stand-in half, AC-8, AC-15, AC-17, AC-19.)*

- [ ] **Step 11: `migrate_remainder`.**
  - This uses the shipped `ArtisanTenantMigrationApplier` on the SQLite tenant, as `QaRealApplierOnStandInTest` does.
  - The plan is named: spy on `namesTenants()`.
  - The run id is journalled before execution, and re-entry reuses it: exactly one `tenant_migration_runs` row.
  - A failing fixture migration fails the step with the job's error, and `seed_core` does not run.
  - After the fix, a resume completes on the **same** run id.
  - The mapping status is still `provisioning` (AC-22).
  - *(AC-20 to AC-23.)*

- [ ] **Step 12: `seed_core`.**
  - **Order:** the `schema_meta` stamp (AC-25a), then `seedPermissions()`, then `DefaultBranch::ensure()` with `MAIN` and the tenant name, or the overrides.
  - **Refusals:**
    - a refused key fails the step, and no branch exists;
    - a refused branch fails the step, and `create_owner` does not run;
    - a mapping status other than `provisioning` is refused before entry (Ruling 6).
  - **Re-entry** is idempotent: one stamp, one branch.
  - *(AC-25, AC-25a, AC-26, AC-27.)*

- [ ] **Step 13: `create_owner`.**
  - **New email (`created`).** Ruling 7's postconditions hold: one `OWNER`, its branch link, the `owner` grant, one `users` row with `must_change_password = true`, `tenant_users`, and the back-filled `employees.user_id`.
  - **Password output.** The password appears in the command output exactly once, and in no row and no log (log spy).
  - **Existing email (`attached`, D-30).**
    - No new `users` row.
    - The existing row is byte-identical before and after: hash, `must_change_password`, `status`, `name`.
    - One new `tenant_users` row.
    - Nothing printed but the attach message.
    - `--owner-name` is ignored for the employee display name.
  - **AC-30.** Inject a master failure after the tenant writes. The resume finds `OWNER` by code and completes only the master half. Exactly one employee row exists, one `employee_branches` row, and one `employee_roles` row.
  - **Crash after the user was created, then resume.** The user row is reused (the id matches the journal), and the password is not re-printed.
  - *(AC-29, AC-30, AC-31, D-26.)*

- [ ] **Step 14: `verify` and `activate`.**
  - **`verify`.** Each AC-32 assertion is broken in turn by a fixture, and each failure names its assertion:
    - a migration row deleted;
    - a `role_permissions` row deleted;
    - a wrong `schema_meta` stamp (read through `SchemaMetaIdentity::of()`);
    - the branch deleted;
    - `OWNER` detached from the branch;
    - `tenant_users` unlinked.

    In each case `activate` does not run.
  - **`activate`.**
    - In one transaction: mapping `active`, `tenant_count + 1`, one subscription (`trial`, or `active` under `--active`), tenant status `trial` or `active`, and the run `completed`.
    - A fault injected after the subscription start rolls all of it back.
    - Eviction happens after commit.
    - **AC-35:** re-entry writes nothing.
  - **AC-36:** a serving `Tenancy::enter()` now succeeds.
  - *(AC-32 to AC-36.)*

- [ ] **Step 15: end to end.**
  - **`ProvisionTenantEndToEndTest`:** a fresh `--code=ten001` run ends `completed`.
  - **AC-38:** `ActivationIssuer::issue()` inside the tenant returns tenant part `TEN001`.
  - **Login:** the owner logs in through `LoginHandler` with the printed password, sees the tenant as selectable, and gets `must_change_password = true`.
  - **A second shop for the same email (D-30).**
    - The run ends `completed`.
    - The login now lists both tenants.
    - The first tenant's `tenant_users` row is unchanged.
  - **Crash-and-resume dataset:** a crash at each of the 11 steps, then a resume, ends `completed` with row counts equal to an uninterrupted run.
  - *(AC-38, AC-4 and AC-5 end to end, AC-31 end to end.)*

- [ ] **Step 16: abandon (Ruling 10).**
  - **Compensation:** reverse order over the `done` steps. Steps end `compensated` and the run ends `rolled_back`.
  - **Engine side:** the stand-in records `dropUser` twice and **no** database drop. The secret files are gone.
  - **Master side.**
    - `tenants.status` and `tenant_databases.status` are both `archived`.
    - Neither row was deleted, and the `database_name` is still readable.
    - A fresh run with the same code is refused.
    - A bulk and a named migration plan each skip or refuse the tenant.
    - The serving entry refuses it.
  - **Abandon before `write_mapping`:** only the tenant is archived.
  - **Attached owner:** the existing `users` row is untouched.
  - **Output:** the exact "was not dropped" sentence, with the name and the server.
  - **Refusals:** a `completed` run and a non-stale `running` run are both refused.
  - *(AC-9, D-21; AC-39's "no drop" via check 1.)*

- [ ] **Step 17: documents.**
  - Section 3's documentation edits.
  - Describe vocabulary rather than quote it (the gate sweeps the working tree).
  - *(No AC.)*

- [ ] **Step 18: close-out (`qa`).**
  - **Mandatory mutations,** each shown red and then reverted:
    1. drop "skip done";
    2. remove the sanitiser on the failure path;
    3. `reserve_identity` writes the raw `--code`;
    4. `migrate_remainder` plans a bulk run;
    5. re-plan on re-entry;
    6. drop `activate`'s compare-and-set;
    7. remove the D-23 precondition;
    8. remove secret-root confinement;
    9. bind MySQL as the default provisioner;
    10. remove Ruling 6's status pre-check;
    11. store before create reversed, i.e. `create_users` ahead of `store_credentials`: the D-20 crash case goes red;
    12. abandon deletes the `tenants` row instead of archiving it.
  - **Then:** the diff against section 9, and the final `composer check` with the skip delta and the test-step seconds.
  - *(AC-39, AC-40.)*

## 6. Test strategy

- **Unit, pure:** `TenantDatabaseNames`, `TenantCode`, `ProvisionerStatements`, `CredentialGenerator`, `FileSecretPath`.
- **Slice:**
  - the SQLite master from the platform fixture;
  - SQLite tenant files in a per-test temporary root that is the working directory (step 4 probe);
  - `RecordingTenantDatabaseProvisioner` bound;
  - a fixed `RunnerClock`;
  - `Artisan::call` in-process;
  - no network.
- **Host:** containment check 3, the binding test, and `TenantProvisionerEngineTest` (engine group, skipped).

**AC map**

| ACs | Steps |
|---|---|
| AC-1 | 8 |
| AC-2 to AC-4 | 7 |
| AC-5 | 10 and 15 |
| AC-6 | 7, 8 and 9 |
| AC-7 | 9 |
| AC-8 | 10 |
| AC-9 | 16 |
| AC-10 to AC-14 | 9 |
| AC-15 to AC-19 | 3, 4, 5 and 10 |
| AC-20 to AC-23 | 11 |
| AC-25, AC-25a, AC-26, AC-27 | 5, 6 and 12 |
| AC-29 to AC-31 | 6, 13 and 15 |
| AC-32 to AC-36 | 14 |
| AC-37 | 9 |
| AC-38 | 15 |
| AC-39 and AC-40 | 4 and 18 |

- AC-24 and AC-28 are scope notes.
- **NOT VERIFIED (G-1), exactly AC-40's list:** AC-16, AC-5's engine half, AC-7's two-process race, AC-9's `DROP USER` half, and AC-18's server-side half.
- **Cost:** about 130 cases. `dev` reports the test-step seconds at steps 0 and 18, and flags any step that adds more than 15 s. Step 15's 11-case crash dataset is the largest single item.

## 7. Risks & rollback

- **Real driver against the shared XAMPP engine.** If the real driver is ever bound there, it would create real users. Mitigations:
  - the default binding refuses;
  - the real driver is opt-in by config;
  - the engine test requires the Docker engine;
  - `ENGINE_HOLD`.
- **The working directory dependence of the SQLite stand-in.** The step 4 probe gates it. If the probe fails, stop.
- **Archived tenants and future contract migrations** (section 0.2). An abandoned tenant blocks a future `contract` step fleet-wide. This is a follow-up row for T-008's runner. It is latent today, because every migration is `expand`. A future `contract` or destructive migration would also block *provisioning itself*, because a version-0 tenant applies every version.
- **Secret root loss.** Losing the root loses tenant credentials. They can be recovered with `ALTER USER` through the provisioner credential, but no rotation command exists yet. Placement is FP-221's (D-29).
- **Capacity overshoot.** Concurrent runs can push a server one past `max_tenants`. This is reported, not refused.
- **Rollback.** Everything is additive: two master tables, new classes, bindings and one command. Reverting the branch removes it. The tenant tree is unchanged.

## 8. Verification commands (Git Bash)

```
cd modules/host
/e/xampp/php/php.exe /c/ProgramData/ComposerSetup/bin/composer.phar test:fast   # inner loop only
/e/xampp/php/php.exe /c/ProgramData/ComposerSetup/bin/composer.phar check       # the gate
```

## 9. Pre-existing files legitimately edited

- `modules/host/tests/Feature/DestructiveStatementContainmentTest.php`: check 3 added; checks 1 and 2 untouched.
- `MasterSchemaBuildsTest` and the master table inventory: two names added.
- `config/platform.php`, `config/tenancy.php`, `.env.example`.
- `TenancyServiceProvider`, `AccessServiceProvider`, `OrganizationServiceProvider`, `PlatformServiceProvider`.
- `MappingSource.php` and `EngineDatabase.php`: docblocks only.
- `docs/database.md`, `docs/architecture.md`.

Any other red file means stop and report to `architect`.

## 10. Messages

### `architect` → lead

- The plan is consistent with `2a4ad0b`, D-20 to D-30.
- **For `ba`, minor (B-12):** AC-1's "at most 150 characters" should read **100**. That is `branches.name`'s length and `CreateBranch`'s own validation. The plan already validates at 100 through organization's validator, so `dev` is not blocked.
- **For a follow-up row:** archived (abandoned) tenants, and a version-0 provisioning tenant, both meet T-008's contract and destructive planner guards once a non-`expand` tenant migration exists (section 7).

### `architect` → `dev`

- Never bind `MySqlTenantDatabaseProvisioner` in a test.
- Never write `DROP DATABASE` or `DROP SCHEMA` anywhere.
- Never stub a guard (`TenantDatabaseNames`, the D-23 precondition) to allow.
- If the step 4 probe fails, stop.
- `AttachBranch` refuses a re-attach: check first.

### `dev` → lead

- Steps 0-5 are implemented and tested (kernel-layer foundation: the two master tables, the naming
  rule, writable `file://` secrets, the engine port in all three implementations, containment check 3,
  the skipped engine test, the mapping writer and the `schema_meta` stamp). Steps 6-18 - the
  `packages/access`/`packages/organization` bootstrap contracts, the platform orchestrator (`RunContext`,
  `StepJournal`, the eleven step classes, the lease, `tenant:provision`), and the end-to-end/abandon
  proofs - are **not built**. This plan's 19 steps and ~130 cases are a multi-session scope; stopping at
  a clean, fully-tested layer boundary (everything steps 6+ are specified to call) seemed better than a
  partial, unverifiable attempt at the cross-package orchestrator in the same pass. See the handback
  message for the real file list and test/lint/stan/deptrac results.

## Questions for the human

None. The open questions were ruled: D-26 (print the password once), D-29 (the secrets path is configurable and its placement is FP-221's), D-30 (attach).
