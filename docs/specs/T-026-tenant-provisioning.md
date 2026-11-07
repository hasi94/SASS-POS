# T-026: Tenant provisioning

Backlog: FP-206, "Create database, record mapping, run migrations, seed defaults, activate; one
resumable job so a half-provisioned tenant can be completed." Must store `tenants.code` upper-case
(T-014 D-40 / `packages/organization/src/Support/ActivationIssuer.php`, which uses it verbatim as the
tenant part of a terminal activation code and refuses to issue one otherwise). Decisions D-1 to D-19
are in `docs/decisions/T-026-tenant-provisioning.md`. **No BLOCKING questions** — this is autonomous/
team-mode work; every open design question below was decided and logged rather than left for the
human.

Convention, following T-008/T-009/T-020/T-023/T-024: every criterion is provable today — on the
master connection's real-engine-free stand-in, on a fixture tenant database, or by static/structural
reading of source — unless marked **[ENGINE]**. `ENGINE_HOLD`/gate G-1 (owner-controlled) blocks
nothing in this spec from being *designed* or *built*; it blocks only the small set of criteria whose
subject is literally "a real `CREATE DATABASE`/`CREATE USER`/`GRANT` succeeded against a live MySQL or
MariaDB server", which stay **NOT VERIFIED** until G-1 lifts, exactly as T-008's AC-22, T-009's AC-22,
and T-023's AC-18/AC-19 were left.

## Problem / goal

FP-206 gives FlexiPOS the one operation that turns a row of intent ("a new shop wants in") into a
real, usable tenant: a database exists, the master knows where it lives, the tenant's schema is at the
current version, the handful of rows a brand-new tenant genuinely needs today exist, and the tenant is
marked active. `docs/architecture.md` §7 and `docs/database.md` §8 already describe this in detail —
a twelve-step sequence (`reserve_identity`, `select_server`, `create_database`, `create_users`,
`store_credentials`, `write_mapping`, `import_template`, `migrate_remainder`, `seed_core`,
`create_owner`, `verify`, `activate`), each step recorded, retryable and resumable — but **none of it
has any code today**: no `tenant_provisioning_runs`/`tenant_provisioning_steps` table, no
`tenant:provision` command, and nothing anywhere in the repository has ever inserted a `tenants` row
outside a test fixture (measured: every existing feature that reads `tenants` — `TenantStatusSync`,
`PlanMigrationRunHandler`, `Tenancy`, the activation-code issuer — assumes the row already exists).
This task builds that operation, reusing the per-tenant migration runner T-008 already shipped rather
than re-implementing it, and reusing the commercial-status machinery T-016 already shipped rather than
racing it.

**What this task deliberately does not attempt.** `docs/database.md` §8's `seed_core` step lists a
chart of accounts, pay components, print templates, payment methods and a `company_profile` row —
none of those tables exist yet (Finance, Payroll, Printing, and the Sales payment-methods table are all
unbuilt). Seeding them here would be inventing schema and data for modules that do not exist, which is
out of this task's reach (D-5). What this task seeds is exactly what the shipped tree already has a
working, idempotent, per-tenant mechanism for: the permission registry and the system `owner` role
(`packages/access`'s `SyncPermissionRegistry`), and one default branch with its `branch_store` location
(`packages/organization`'s `CreateBranch`, which already creates the location as part of the same
transaction, T-014).

## Users affected

- **Platform operators**, who today have no way to bring a new tenant into existence at all except by
  hand-editing the master database — a path nothing in this codebase supports or tests. This task gives
  them `php artisan tenant:provision` and `--resume=<run_id>`.
- **A new tenant's owner**, whose first login today cannot exist: `LoginHandler`/`SelectTenantHandler`
  (T-009) assume a `users` row, a `tenant_users` row and a tenant database already exist. This task is
  what creates all three for the first time for a given shop.
- **Every module built so far that reads `tenants.status` or `tenant_databases.status`**
  (`TenantStatusSync`, `Tenancy::enter()`, `PlanMigrationRunHandler`, `ActivationIssuer`) — none of them
  changes behaviour, but this task is the first caller to actually drive a tenant through the
  `provisioning → active` transition those modules were written anticipating (the `PROTECTED` list in
  `packages/platform/src/Support/TenantCommercialStatus.php` names FP-206 by number as the reason
  `provisioning` is excluded from `TenantStatusSync`'s reach).
- **Database capacity planning** (`docs/database.md` §10): this task is what increments
  `database_servers.tenant_count` and is therefore the first real consumer of the `accepts_new_tenants`/
  `max_tenants` placement rule.
- Not served by this task: moving a tenant to another server, cloning a tenant into a sandbox, and
  deprovisioning/closing a tenant — `docs/database.md` §8 describes all three as "related lifecycles,
  same machinery" but they are separate backlog rows and are explicitly out of scope (D-14).

## Current behaviour

Re-derived from the files, not assumed:

- `tenants`, `tenant_databases`, `database_servers`, `schema_versions`, `tenant_migration_runs` and
  `tenant_migration_jobs` all exist as master migrations (T-008). `tenant_provisioning_runs` and
  `tenant_provisioning_steps` — the two tables `docs/database.md` §4.7 designs for this task — **do
  not exist anywhere** (measured: no migration file or model references either name outside
  `docs/database.md` itself and this task's own planning documents).
- The per-tenant migration runner is fully built and shipped (T-008, `packages/kernel/src/Schema/
  Features/{PlanMigrationRun,ClaimMigrationJob,MigrateTenant,RunTenantMigrations,RetryFailedJobs,
  ReportSchemaStatus}`): it plans a run, claims jobs one tenant at a time with a non-blocking per-tenant
  lock, isolates one tenant's failure from the rest, mirrors the resulting `schema_version`, and —
  load-bearing for this task — a run that **names** one tenant (rather than a bulk `--all`) already
  accepts a tenant whose `tenant_databases.status` is `provisioning` or `failed`
  (`TenantStatusPolicy::NAMED_ONLY_STATUSES = ['provisioning', 'failed']`,
  `packages/kernel/src/Tenancy/TenantStatusPolicy.php:57`), and `MigrateTenantHandler`'s own success
  path already knows to restore a `provisioning` entry status to `provisioning` rather than promoting it
  to `active` on its own, with a docblock stating outright: *"`provisioning` restores to `provisioning`
  - FP-206 owns when that becomes `active`"* (`packages/kernel/src/Schema/Features/MigrateTenant/
  MigrateTenantHandler.php:366`). This task is that owner.
- `TenantStatusSync` (T-016, `packages/platform/src/Support/TenantStatusSync.php`) is "the only writer
  of `tenants.status`" **within T-016's own scope**, and `TenantCommercialStatus::PROTECTED` explicitly
  lists `pending` and `provisioning` as statuses the reconciler "never touches in either direction", with
  the comment citing FP-206 by number. So starting a subscription for a `provisioning` tenant does
  **not** itself flip `tenants.status` — the reconciler no-ops on a protected status — and the explicit
  transition out of `provisioning` is this task's to make (D-8).
- `SyncPermissionRegistry` (T-010, `packages/access/src/Features/SyncPermissionRegistry/
  SyncPermissionRegistryHandler.php`) already reconciles a tenant database's `permissions` table against
  every module's declared keys and seeds/tops-up the system `owner` role, idempotently, inside one
  transaction. It is written to be run "the moment a new key comes into existence" and nothing in the
  codebase currently calls it at tenant-creation time (measured: no caller exists outside its own
  tests).
- `CreateBranch` (T-014, `packages/organization/src/Features/CreateBranch/CreateBranchHandler.php`)
  creates a branch, its `branch_store` location and the link between them as one transaction, but takes
  `timezone` and `currency` with **no default** ("inventing one would be a guess about the business",
  per its own docblock) — so a caller must supply them, which this task's `reserve_identity` step is the
  first place in the sequence to have collected.
- `SecretResolver::resolve()` (T-003/T-008, `packages/kernel/src/Tenancy/Secrets/`) is **read-only**:
  it dereferences an existing reference to a secret. Nothing in the codebase writes a new secret into
  any driver (`file://`, `env://`, `enc://`) — measured: no `store`/`put`/`write` method exists on
  `SecretResolver` or on any shipped driver. Generating and persisting the two new per-tenant database
  credentials (`app`, `migrate`) this task's `store_credentials` step needs is new ground.
- No code anywhere issues `CREATE DATABASE`, `CREATE USER` or `GRANT` (measured: no occurrence of any
  of those three strings in `packages/` or `modules/host/src`). `ActivationCodeFormat::
  TENANT_PART_PATTERN` (`packages/contracts/src/Organization/ActivationCodeFormat.php:36`) is
  `/\A[A-Z0-9](?:[A-Z0-9-]{0,28}[A-Z0-9])?\z/` — the format `tenants.code` must already satisfy for
  `ActivationIssuer` to ever issue a code for this tenant, and the only place in the shipped code that
  currently states the upper-case requirement in prose (its own docblock: *"Tenant codes must be stored
  upper-case; FP-206 must store them so"*).
- `OrgCode::normalise()` (T-014, `packages/organization/src/Support/OrgCode.php`) is the shipped
  precedent for "trim, fold to upper case, validate against a fixed character class, refuse rather than
  silently coerce" — the shape this task's own `code` handling follows (D-1), though `tenants.code`'s
  own length and pattern (30 chars, `ActivationCodeFormat::TENANT_PART_PATTERN`) differ from
  `OrgCode::MAX`'s 10.
- `database_servers` (T-008) already carries `accepts_new_tenants`, `status`, `tenant_count` and
  `max_tenants` exactly as `docs/database.md` §4.4 and §8 step 2 describe; nothing populates or reads
  them today outside its own migration and model (measured: no feature queries this table).
- `StartSubscriptionHandler` (T-016, `packages/platform/src/Features/StartSubscription/
  StartSubscriptionHandler.php`) exists and accepts exactly `trial` or `active` as a starting
  subscription status; nothing currently calls it from a tenant-creation path.

## User stories

- As a platform operator, I want to run one command that creates a new tenant end to end — database,
  mapping, schema, the handful of rows a brand-new shop needs, and an owner login — so that bringing on
  a customer is not a sequence of manual, error-prone steps against production infrastructure.
- As a platform operator, I want a provisioning run that fails partway through (a network blip, a
  server at capacity, a migration that reveals a real bug) to be resumable from where it stopped, so
  that a transient failure does not strand a half-created tenant or force me to clean up and start over.
- As a platform operator, I want to see exactly which step a stalled or failed run is on and why, so
  that I am not guessing whether it is safe to resume.
- As the owner of a newly provisioned shop, I want a working login the moment provisioning finishes, so
  that I can sign in and start setting up my business without a second manual step.
- As a developer of `TenantStatusSync`/`Tenancy`/`PlanMigrationRunHandler`/`ActivationIssuer`, I want
  provisioning to use the `tenants.status`/`tenant_databases.status` values exactly as my own code
  already expects them (`provisioning` while incomplete, the documented transition out of it on
  success), so that this task does not quietly invent a second status lifecycle alongside the one T-008/
  T-016 already shipped.

## Acceptance criteria

### A. The command and the resumable run (FP-206's "one resumable job")

- **AC-1**: `php artisan tenant:provision` accepts `--code=`, `--name=`, `--country=`, `--timezone=`,
  `--currency=`, `--business-type=`, `--owner-email=`, `--plan=`, `--server=` (optional override) and
  `--resume=<run_id>`. A fresh invocation (no `--resume`) with any of `--code`, `--name`, `--country`,
  `--timezone`, `--currency`, `--business-type`, `--owner-email` or `--plan` missing or blank refuses
  with a validation message naming the missing field(s), before any row is written (D-15: these five
  are required inputs the one-line backlog description does not enumerate individually, but
  `CreateBranch`'s own "no default, inventing one would be a guess" rule and `tenants`' non-nullable
  columns both require them to come from somewhere, and an operator is the only one who can supply
  them).
- **AC-2**: Given a fresh invocation, Then one `tenant_provisioning_runs` row is written before any
  other step starts, with `type = 'create'`, `status = 'running'`, `current_step` naming the first
  step, and one `tenant_provisioning_steps` row per step of the sequence (`reserve_identity`,
  `select_server`, `create_database`, `create_users`, `store_credentials`, `write_mapping`,
  `migrate_remainder`, `seed_core`, `create_owner`, `verify`, `activate`) **all `pending`** — the plan is
  written whole, the same shape T-008's `PlanMigrationRunHandler` already uses for its own run/job
  rows. (D-3: this task has no `import_template` step — see Out of scope.)
- **AC-3**: Steps execute in the fixed order of AC-2. Each step, on success, is marked `done` with
  `finished_at` and (where the step produces one) an `output` payload; on failure it is marked `failed`
  with `error` and the whole run's `status` becomes `failed` with `current_step` left on the failed
  step — later steps are never attempted after an earlier one fails.
- **AC-4** (resumability, the backlog's own wording): Given a run whose status is `failed` or whose
  process died mid-step (the run is still `running` but no step has moved in longer than a configured
  staleness window — reported, not auto-resumed), When `tenant:provision --resume=<run_id>` is invoked,
  Then every step already `done` is skipped without being re-executed, and execution resumes at the
  first step that is not `done`.
- **AC-5** (idempotent-by-step, mirroring T-008's migration-job idempotency): Given a resumed run whose
  failed step's real-world effect **did** land before the failure was recorded (e.g. the database was
  created but the row update that marked the step `done` never committed), When that step runs again,
  Then it detects the existing effect (the database already exists, the mapping row already exists)
  and advances rather than erroring or attempting to create a duplicate. **Negative case**: a step whose
  effect is found in a state this task did not create (a same-named database that predates this run) is
  refused with an explicit error naming the conflict, never silently adopted.
- **AC-6**: `--resume=` naming a run that is already `status = 'completed'` refuses with a message
  saying so and makes no write. `--resume=` naming a run id that does not exist refuses with `not
  found`. A fresh invocation (no `--resume`) whose `--code` collides with an existing `tenants.code` —
  whether that tenant's own run completed or is itself mid-flight — refuses at `reserve_identity`
  (AC-9) rather than starting a second, parallel run for the same shop, and the refusal names the
  existing run id so the operator can `--resume` it instead (D-12).
- **AC-7**: Two concurrent invocations racing on the same `--code` result in exactly one `tenants` row:
  `reserve_identity`'s insert relies on `tenants.code`'s unique index as the backstop, and the losing
  invocation's run is marked `failed` at that step with a message pointing at the winner's run id, not
  left half-written.
- **AC-8** (negative/edge case): A run that fails at `create_database`, `create_users` or
  `store_credentials` — before `write_mapping` — leaves **no** `tenant_databases` row and **no**
  `tenants` row visible as anything but `provisioning`; the tenant is not servable (`Tenancy::enter()`
  already refuses any status outside `active`, unchanged by this task) and not migratable by a **bulk**
  run (`TenantStatusPolicy::NAMED_ONLY_STATUSES` already excludes `provisioning` from a bulk `--all`,
  unchanged by this task).
- **AC-9** (abandon, not silent rollback — D-11): `tenant:provision --abandon=<run_id>` is the only path
  that reverses a failed run's completed steps, in reverse order, using exactly the compensations
  `docs/database.md` §8 names for the steps this task builds (delete the tenant row; drop the `GRANT`s
  and `DROP USER` the two created users; delete the stored secret versions; drop the mapping row; drop
  the database — only when this run's own `create_database` step created it, never an operator-supplied
  `--server` override's existing database). A plain failed/resumable run is never auto-abandoned: retry
  is the default, abandonment is an explicit, separate, operator-invoked act.

### B. `reserve_identity` and `select_server` (master-only, no tenant connection opened yet)

- **AC-10**: `reserve_identity` inserts one `tenants` row with `status = 'provisioning'`, an
  auto-assigned unique `sequence`, `code` equal to the normalised, upper-cased `--code` (AC-11),
  `slug` defaulted to the lower-cased `code` when `--slug` is not separately supplied (D-2), and
  `country`/`timezone`/`currency`/`business_type`/`name` taken verbatim from the corresponding options.
  `plan_id` is left null here — `--plan` is consumed later, by `activate` (AC-23), because starting a
  subscription is a commercial act and this step only reserves the identity.
- **AC-11** (the backlog's named requirement): `--code` is trimmed and folded to upper case before any
  comparison or write (mirroring `OrgCode::normalise()`'s shape, D-1), then validated against
  `ActivationCodeFormat::TENANT_PART_PATTERN` (1 to 30 characters of `A-Z0-9-`, never starting or
  ending with a hyphen). A `--code` that does not fit refuses with `validation`, naming the pattern,
  before any row is written. **Negative case**: `--code=ten-001` is accepted and stored as `TEN-001`;
  `--code=-TEN001` and `--code=` (blank) are both refused. **Control**: the stored value, read back, is
  byte-identical to what `ActivationIssuer`'s own pattern check and `tenants.code`'s unique index would
  accept — so an activation code can always be issued for a tenant this step created (closing the gap
  the backlog row names: without this, T-014's `ActivationIssuer` would refuse to issue any code at all
  for a tenant provisioned lower-case).
- **AC-12**: `database_name` (`flx_t_` + the tenant's `sequence`, zero-padded to 6 digits, widening past
  1,000,000 per `docs/database.md` §2), `app_username` (`flx_t<sequence>_app`) and `migrate_username`
  (`flx_t<sequence>_mig`) are derived mechanically from `sequence` alone, computed once at
  `reserve_identity` and never recomputed from a later-read `sequence` — so a retried step reproduces
  the exact same names.
- **AC-13**: `select_server` reads `database_servers` and picks one row where `accepts_new_tenants =
  true`, `status = 'active'` and `tenant_count < max_tenants`, breaking ties by lowest `tenant_count`
  (lowest utilisation, per `docs/database.md` §8 step 2); `--server=<code>` overrides the selection
  with that exact row, refusing if it does not satisfy the three conditions (the operator is explicit,
  so the refusal is explicit too, never a silent fallback to automatic selection). **Negative case**: no
  server satisfies the conditions (every server full, draining, or capacity-closed) — the step fails
  with a message naming the condition that excluded every candidate, and the run stops there,
  resumable once a server is opened up or added.
- **AC-14**: `select_server` writes nothing to `database_servers` itself; `tenant_count` is
  incremented only at `activate` (AC-25), not at selection — a run that fails after selecting a server
  but before completing must not have reserved capacity nobody can see was ever used.

### C. The real-engine steps (`create_database`, `create_users`, `store_credentials`) — new ground, gated by `ENGINE_HOLD`

- **AC-15**: The three steps are driven through one published contract this task adds (the exact
  interface and class names are the architect's/developer's to design, not restated here) whose
  responsibility is exactly: create the tenant's database with the connection's configured charset/
  collation; create the `app` and `migrate` database users with the grants `docs/database.md` §2's
  table names (`app`: `SELECT, INSERT, UPDATE, DELETE, EXECUTE` on its own database only; `migrate`:
  the same plus `CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, TRIGGER`, also scoped to its own
  database only); generate two random, high-entropy passwords. A deployment binds the real MySQL/
  MariaDB implementation of this contract; the test suite binds a stand-in that records what it was
  asked to do without opening a socket — the same split T-008's `PerTenantLock`/connection layer and
  T-023's Docker-compose work used.
- **AC-16 [ENGINE]**: Given the real MySQL/MariaDB-backed implementation of the contract and a real
  server reachable at the configured host/port, When these three steps run, Then a database exists
  with the computed name and configured charset/collation, two users exist scoped to only that
  database with exactly the grants AC-15 names, and neither password appears anywhere in clear — not
  in a log line, not in an exception message, not in the master database. **NOT VERIFIED in this
  environment**: `ENGINE_HOLD` forbids any connection to a real engine until gate G-1 (a verified dump
  of the existing databases) is cleared by the human; this criterion is proven by the contract's own
  unit tests against the stand-in today and stays explicitly unverified-on-a-real-engine until then,
  the same fencing T-008's AC-22, T-009's AC-22/AC-50 and T-023's AC-18/AC-19 already carry.
- **AC-17**: `store_credentials` generates both passwords and writes each through a **writable**
  credential driver (this task's own new ground: `SecretResolver` as shipped is read-only, D-6) before
  `create_users` or any later step can read them back; the chosen driver for this environment is the
  same `file://`/`env://` family `docs/database.md` §4.4 already documents for local/VPS use, never a
  new scheme. **Negative case**: a resumed run whose `store_credentials` step is re-entered after the
  secrets were already written does not write a second version or rotate the password — it is found
  idempotently, by the reference `reserve_identity` already computed, not regenerated.
- **AC-18**: No step, log line, exception message or stored row — including a `tenant_provisioning_
  steps.error` column on a failed step — ever contains either generated password in clear. **Control**:
  a deliberately-failing stand-in implementation that raises an exception embedding the password is
  caught by a test asserting the stored `error` text is sanitised, the same `needles`-redaction idiom
  `MigrateTenantHandler::needlesFor()` already uses for the migrate credential.
- **AC-19**: `write_mapping` inserts one `tenant_databases` row (`tenant_id` = the reserved tenant,
  `server_id` from `select_server`, `database_identifier` = the tenant's `code`, `database_name`/
  `app_username`/`migrate_username` from AC-12, `app_credential_ref`/`migrate_credential_ref` from
  AC-17, `status = 'provisioning'`, `schema_version = 0`, `charset`/`collation` mirroring what
  `create_database` actually created) — mirroring T-008's own `tenant_databases` shape exactly, adding
  no column it does not already define.

### D. `migrate_remainder` — driving the existing runner, not re-building it

- **AC-20**: `migrate_remainder` plans and runs a **named** migration run for exactly this one tenant,
  through the existing `PlanMigrationRunHandler`/`ClaimMigrationJobHandler`/`MigrateTenantHandler`
  machinery (T-008) — this task writes no new migration-execution code. A named run is used, never a
  bulk one, specifically because `TenantStatusPolicy::NAMED_ONLY_STATUSES` is what makes a
  `provisioning`-status tenant enterable at all (current behaviour, above); this task's own code
  invokes the planner with a selector naming the one tenant, never relies on `--all` ever reaching a
  `provisioning` row by accident.
- **AC-21**: The provisioning step blocks until the migration run it started reaches a terminal state
  (`completed` or `completed_with_failures`) for this one tenant, polling/claiming synchronously within
  the step rather than leaving a queued run for something else to finish — because the rest of
  provisioning (`seed_core` onward) needs the schema to exist before it can do anything. The migration
  run's own id is recorded on this provisioning step's `output`, so a resumed `migrate_remainder` can
  find and continue an in-flight migration run rather than planning a second one for the same tenant.
- **AC-22**: If the migration run for this tenant ends `completed_with_failures` (i.e. the one job for
  this tenant failed), `migrate_remainder` is marked `failed` with the job's own `error_message`
  (already sanitised by `MigrateTenantHandler`), and the provisioning run stops there — `seed_core`
  never runs against a half-migrated database. `tenant_databases.status` is left exactly as
  `MigrateTenantHandler`'s own failure path leaves it (`failed`, per its documented behaviour for an
  offline-requiring plan; unchanged for an ordinary expand-only one) — this task does not override that
  write.
- **AC-23**: `migrate_remainder`'s own retry on resume is simply re-entering this step: because the
  underlying runner is itself resumable and idempotent-by-job, a resumed provisioning run that reaches
  `migrate_remainder` again re-plans only if no migration run id is recorded yet, or re-claims the
  existing one otherwise — it never plans a second migration run for a tenant that already has one
  in flight or completed.
- **AC-24** (D-3, scope note, not a criterion to test but a boundary this spec draws): This task does
  **not** build `docs/database.md` §8 step 7's `import_template` optimisation (importing a pre-built
  SQL dump instead of running every migration from scratch). `migrate_remainder` always runs the full
  set from version 0. The ~90-second-versus-~2-second difference `docs/database.md` describes is a
  genuine future optimisation, filed as a follow-up, not built here — the backlog's own line ("run
  migrations") is satisfied either way, and building the template-generation CI machinery first would
  be a second, larger task smuggled into this one.

### E. `seed_core`, scoped to what the shipped tree actually has (D-5)

- **AC-25**: `seed_core` calls the existing `SyncPermissionRegistry` handler against the newly-migrated
  tenant connection. On success, the tenant's `permissions` table holds every key every booted module
  declares and its `roles` table holds exactly one system role, `owner`, holding every one of those
  keys — the same postcondition `SyncPermissionRegistryTest`/`OwnerRoleSyncTest` already prove for the
  handler in isolation; this task adds no new assertion about what the handler does, only that
  provisioning calls it.
- **AC-26**: `seed_core` then creates exactly one branch via the existing `CreateBranch` handler, using
  the tenant's own `country`/`timezone`/`currency`/`business_type` (captured at `reserve_identity`) and
  a branch `code`/`name` taken from `--branch-code`/`--branch-name` if supplied, defaulting to `MAIN`/
  the tenant's own `name` otherwise. `CreateBranch`'s own transaction (branch + its `branch_store`
  location + the link between them) is unchanged by this task.
- **AC-27** (negative/edge case): If `SyncPermissionRegistry` reports any `refused` key (a declared
  key whose `is_branch_scoped` flag conflicts with an already-stored row — the handler's own documented
  refusal case) or if `CreateBranch` refuses (a duplicate code, a plan-limit refusal), `seed_core` is
  marked `failed` with that refusal's message and the run stops — it does not partially seed and
  proceed to `create_owner`.
- **AC-28** (scope boundary, stated for the reader rather than tested, mirroring T-020 AC-24's "no
  modules exist yet" framing): `seed_core` does **not** create a `company_profile` row, a chart of
  accounts, payment methods, tax groups, print templates, number-sequence defaults for any document
  type, or any system role besides `owner` (manager, cashier, accountant, storekeeper) — none of their
  owning tables or modules exist in the shipped tree today. Each is the responsibility of whichever
  future task ships the owning module, following the same `SyncPermissionRegistry`-style idempotent
  per-tenant seed shape this task establishes, not a gap this task leaves silently.

### F. `create_owner`

- **AC-29**: `create_owner` performs the two-step `docs/database.md` §5 rule 3 already names for a
  cross-database write: the tenant `employees` row is written first (name defaulted from the local part
  of `--owner-email` unless `--owner-name` is supplied, `pin_hash` left null, `branch_id` the branch
  `seed_core` just created), then the master `users` row (`email = --owner-email`, a random password
  requiring change on first use, `must_change_password = true`) and `tenant_users` row (`access_level =
  'owner'`, `status = 'active'`, `employee_id` = the tenant employee just created) are written together
  in one master transaction, and finally `employees.user_id` is back-filled with the new `users.id`.
  `role_id`/`employee_roles` grants the employee the `owner` role `seed_core` seeded.
- **AC-30** (negative/edge case — the compensating delete this step's own failure needs): If the
  master-side write fails after the tenant `employees` row committed, the step is marked `failed`
  without deleting the tenant employee row (a committed tenant-side write is not silently undone by a
  different step's failure); a resumed `create_owner` finds the existing employee row by its own
  provisioning-run reference and completes only the master-side half, never inserting a second employee
  row for the same owner.
- **AC-31** (negative/edge case): `--owner-email` colliding with an existing master `users.email`
  (globally unique, per `docs/database.md` §4.1) refuses at this step with the existing collision,
  before any tenant-side write — mirroring how every other master-side uniqueness violation in this
  codebase is surfaced, never silently attaching the new tenant to somebody else's login.

### G. `verify` and `activate`

- **AC-32**: `verify` asserts, against the tenant connection: the migrated schema's `migrations` table
  lists exactly the set `migrate_remainder`'s target set named (no more, no fewer); the `permissions`
  table holds the `owner` role with every declared key (re-reading what `seed_core` wrote, not trusting
  its own report); the branch `seed_core` created exists; the owner employee `create_owner` created
  exists and is linked to the master `tenant_users` row. Any mismatch fails `verify` with the specific
  assertion named, and `activate` never runs against an unverified tenant.
- **AC-33**: `activate` writes `tenant_databases.status: 'provisioning' → 'active'` using the same
  write-then-evict pattern `MigrateTenantHandler::writeMapping()` already uses (one update, followed by
  the mapping cache eviction, never separated), and increments the selected server's `tenant_count` by
  one in the same step.
- **AC-34** (D-7, D-8 — the explicit transition `TenantStatusSync` declines to make): `activate` starts
  a subscription for the tenant via the existing `StartSubscriptionHandler`, using `--plan` and
  defaulting the subscription's starting status to `trial` (an `--active` flag starts it `active`
  instead, for an operator who is provisioning an already-paid account). Because
  `TenantCommercialStatus::PROTECTED` excludes `provisioning` from `TenantStatusSync`'s own reach by
  design, `StartSubscriptionHandler`'s own reconciliation call is a no-op here — so `activate` then
  writes `tenants.status: 'provisioning' → 'trial'` (or `'active'`) **itself**, directly, in the same
  transaction as the subscription start, exactly once per run. Every reconciliation after this point —
  suspension on non-payment, licence lapses, plan changes — is `TenantStatusSync`'s job again,
  unchanged by this task, because the tenant's status is no longer `provisioning`.
- **AC-35** (negative/edge case): `activate` re-entered on a resumed run that already completed it
  (status already `active`, subscription already started) detects both postconditions and marks the
  step `done` without writing a second subscription or incrementing `tenant_count` twice.
- **AC-36**: On `activate`'s success the whole `tenant_provisioning_runs` row becomes `status =
  'completed'`, `finished_at` is recorded, and the tenant is servable: `Tenancy::enter()` (unchanged by
  this task) now accepts it because `tenant_databases.status = 'active'` is exactly what
  `TenantConnectionPurpose::SERVING_STATUSES` already names.

### H. `tenants.code` is upper-case wherever it is read (closing the backlog's named requirement)

- **AC-37**: A static test asserts that the single site this task adds which writes `tenants.code`
  (`reserve_identity`) folds the value to upper case before the insert — mirroring the static-assertion
  idiom T-008/T-014 already use for similar invariants, rather than trusting a runtime test alone to
  catch a future regression.
- **AC-38**: Given a tenant provisioned by this task with `--code=ten001`, When `ActivationIssuer::
  issue()` (T-014, unchanged) is subsequently called for that tenant, Then it succeeds and returns a
  code whose tenant part is `TEN001` — the concrete, end-to-end proof that the backlog's named
  requirement is actually met, not merely declared.

### I. Framework integrity and the gate

- **AC-39**: `composer lint`, `composer stan` and `composer deptrac` pass with no new suppressions. The
  new real-engine port (AC-15) is reached only through its published contract from outside
  `packages/kernel`, never its concrete MySQL implementation's internals, matching the boundary rule
  every prior task's provisioning-adjacent code already follows.
- **AC-40**: `composer test:fast` and `composer test` both pass, with every criterion above except
  AC-16 proven on the master/tenant SQLite stand-ins and the recording stand-in implementation of the
  real-engine contract — the same split T-008, T-009 and T-023 already established and that this spec
  inherits rather than re-argues.

## Out of scope

- The `import_template` fast-path (`docs/database.md` §8 step 7) — D-3/AC-24.
- `company_profile`, chart of accounts, payment methods, tax groups, print templates, number-sequence
  defaults, and every system role besides `owner` — D-5/AC-28, each the responsibility of the task that
  ships its owning module.
- Suspend, deprovision, move-to-another-server and clone lifecycles (`docs/database.md` §8's "related
  lifecycles, same machinery" table) — D-14, separate backlog rows.
- The real MySQL/MariaDB implementation of the database/user-creation contract actually running against
  a live server — gated by `ENGINE_HOLD` until G-1 (AC-16).
- A writable credential driver for `vault://`/`awssm://`/`azkv://` — only the `file://`/`env://` family
  `docs/database.md` §4.4 already documents for this environment is built (AC-17); the cloud drivers
  stay read-only stubs exactly as they are today.
- Any HTTP/admin-UI surface for provisioning — console-only, matching every prior platform-operator
  command in this codebase (T-016 D-21, T-017 D-7, T-018 D-9, T-019 D-10, T-020 D-10).
- Billing/invoicing side effects of starting a subscription (card capture, an invoice row) — T-016's
  own `StartSubscriptionHandler` already excludes these, and this task calls it unchanged.

## Non-functional requirements

- **Credential handling**: generated passwords are high-entropy and never appear in clear in a log,
  exception message or stored row (AC-18) — the same bar `docs/database.md` §4.4 sets for every
  existing credential path in this codebase.
- **Resumability and idempotency** are the central non-functional property of this whole task (the
  backlog's own "one resumable job" line) — every step must be safely re-enterable, per AC-5.
- **Isolation from other tenants**: nothing this task does while provisioning tenant A may read or
  write tenant B's database, mirroring the cross-tenant isolation guarantees T-022's permanent sweep
  already enforces structurally (no new route, queued job, cache key or plugin hook is introduced by
  this task that would need a new sweep entry).

## Assumptions

- `--code` is operator-supplied, never derived from `--name` (D-1): deriving a short code from a free-
  text business name is a slugification policy nobody has specified (collision handling, truncation
  length, transliteration), and `OrgCode`'s own precedent for branches/locations/terminals is likewise
  an explicit, required code rather than a derived one.
- `--plan` is required, with no default plan silently chosen (D-15): which plan a new paying customer
  starts on is a commercial decision, not a technical default this task should invent.
- A new owner's initial password is a random, must-change-on-first-use credential communicated out of
  band (e.g. a welcome email) rather than a predictable or operator-chosen one — the conventional,
  safest default for a login nobody has used yet; no welcome-email mechanism is built by this task
  (no notification module exists yet to build it on).
- One default branch, timezone/currency from the tenant's own master-level fields: a shop with more than
  one branch at day one adds the rest itself, after first login, through `CreateBranch` — unchanged and
  already sufficient.

## Open questions

None — every design question this task raised was decided and logged in
`docs/decisions/T-026-tenant-provisioning.md` rather than left open, per the team's autonomous-mode
protocol.
