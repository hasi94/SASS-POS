# T-026: Tenant provisioning

Backlog: FP-206, "Create database, record mapping, run migrations, seed defaults, activate; one
resumable job so a half-provisioned tenant can be completed." Must store `tenants.code` upper-case
(T-014 D-40 / `packages/organization/src/Support/ActivationIssuer.php`, which uses it verbatim as the
tenant part of a terminal activation code and refuses to issue one otherwise). Decisions D-1 to D-30
are in `docs/decisions/T-026-tenant-provisioning.md`. **No BLOCKING questions** — this is autonomous/
team-mode work; every open design question below was decided and logged rather than left for the
human, except the two the lead ruled on directly (D-26, D-29 — logged here, attributed there).

**Review note.** This revision folds in architect review round 1's corrections B-1 to B-11 against
real source (full detail in `docs/plans/T-026-tenant-provisioning.md`), plus the lead's own rulings on
the two questions the architect routed up rather than having `ba` guess, plus `ba`'s own resolution of
the AC-31/`docs/database.md` conflict the architect flagged. Every correction is traceable below by its
`B-n` tag.

Convention, following T-008/T-009/T-020/T-023/T-024: every criterion is provable today — on the
master connection's real-engine-free stand-in, on a fixture tenant database, or by static/structural
reading of source — unless marked **[ENGINE]**. `ENGINE_HOLD`/gate G-1 (owner-controlled) blocks
nothing in this spec from being *designed* or *built*; it blocks only the small set of criteria whose
subject is literally "a real `CREATE DATABASE`/`CREATE USER`/`GRANT` succeeded against a live MySQL or
MariaDB server, or a real two-process race ran against one", which stay **NOT VERIFIED** until G-1
lifts, exactly as T-008's AC-22, T-009's AC-22, and T-023's AC-18/AC-19 were left — see AC-40 (B-7) for
the complete, corrected list of which criteria that actually covers.

## Problem / goal

FP-206 gives FlexiPOS the one operation that turns a row of intent ("a new shop wants in") into a
real, usable tenant: a database exists, the master knows where it lives, the tenant's schema is at the
current version, the handful of rows a brand-new tenant genuinely needs today exist, and the tenant is
marked active. `docs/architecture.md` §7 and `docs/database.md` §8 already describe this in detail —
a step sequence, each step recorded, retryable and resumable — but **no orchestration code exists
today**: no `tenant_provisioning_runs`/`tenant_provisioning_steps` table and no `tenant:provision`
command. (One `tenants` row *has* been inserted outside a fixture before this task — see Current
behaviour, B-10 — but by a one-off CI SQL script, not by any application code.) This task builds that
operation, reusing the per-tenant migration runner T-008 already shipped rather than re-implementing
it, and reusing the commercial-status machinery T-016 already shipped rather than racing it.

**What this task deliberately does not attempt.** `docs/database.md` §8's `seed_core` step lists a
chart of accounts, pay components, print templates, payment methods and a `company_profile` row —
none of those tables exist yet (Finance, Payroll, Printing, and the Sales payment-methods table are all
unbuilt). Seeding them here would be inventing schema and data for modules that do not exist, which is
out of this task's reach (D-5). What this task seeds is exactly what the shipped tree already has a
working, idempotent, per-tenant mechanism for: the permission registry and the system `owner` role
(`packages/access`'s `SyncPermissionRegistry`), one default branch with its `branch_store` location
(`packages/organization`'s `CreateBranch`), and the `schema_meta` tenant-identity row T-008 left this
task to write (B-4).

## Users affected

- **Platform operators**, who today have no way to bring a new tenant into existence at all except by
  hand-editing the master database or running a one-off SQL script (the way CI's own throwaway tenant
  is registered, B-10) — a path nothing general-purpose supports or tests. This task gives them `php
  artisan tenant:provision` and `--resume=<run_id>`.
- **A new tenant's owner**, whose first login today cannot exist: `LoginHandler`/`SelectTenantHandler`
  (T-009) assume a `users` row, a `tenant_users` row and a tenant database already exist. This task is
  what creates all three for the first time for a given shop (or attaches an existing login to a second
  shop — see AC-31, the resolved database.md conflict below).
- **Every module built so far that reads `tenants.status` or `tenant_databases.status`**
  (`TenantStatusSync`, `Tenancy::enter()`, `PlanMigrationRunHandler`, `ActivationIssuer`) — none of them
  changes behaviour, but this task is the first caller to actually drive a tenant through the
  `provisioning → active` transition those modules were written anticipating (the `PROTECTED` list in
  `packages/platform/src/Support/TenantCommercialStatus.php` names FP-206 by number as the reason
  `provisioning` is excluded from `TenantStatusSync`'s reach).
- **Database capacity planning** (`docs/database.md` §10): this task is what increments
  `database_servers.tenant_count` and is therefore the first real consumer of the `accepts_new_tenants`/
  `max_tenants` placement rule.
- **`packages/access`'s escalation guard**, whose own docblock names FP-206 by number as "the one real
  caller that is coming" that needs a named system actor rather than a null one for the very first role
  grant in a tenant (B-5) — this task is that caller.
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
- **One `tenants` row has in fact been inserted outside a test fixture already** (B-10, correcting the
  earlier draft of this spec): `.github/ci/register-ci-tenant.sql` (T-024) inserts a `database_servers`
  row, a `tenants` row and a `tenant_databases` row directly by SQL, registered `provisioning`, purely
  so CI's migration jobs have a throwaway tenant to migrate. It is a one-off, hand-written script for a
  disposable CI container, not application code, and it provisions nothing this task's own definition
  of "provisioned" requires (no users, no credentials via a resolver, no schema beyond what the CI job
  itself migrates, no seed, no owner, never activated to `active`). It does, usefully, double-confirm
  several of this task's own facts directly from a file T-024 already got reviewed and merged: the exact
  NOT-NULL column list `reserve_identity`/`write_mapping` must supply, and that `provisioning` is both a
  valid `tenants.status` and the one status `TenantStatusPolicy::NAMED_ONLY_STATUSES`/
  `PlanMigrationRunHandler::BLOCKING_EXEMPT_STATUS` already carve out for exactly this situation.
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
- **T-008 also left this task one more writer to build** (B-4): `SchemaMetaIdentity::of()`
  (`packages/kernel/src/Schema/Features/MigrateTenant/SchemaMetaIdentity.php`) already *reads*
  `schema_meta`'s `tenant_id` row and `MigrateTenantHandler` already *refuses* a migration against a
  database claiming to be a different tenant (`TenantIdentityMismatch`) — but nothing writes that row.
  Its own docblock says so in terms: *"the writer belongs to FP-206's provisioning step (D-28)."* The
  tenant baseline (T-008) creates `schema_meta` empty on purpose, specifically so this task's writer has
  somewhere to write to.
- `TenantStatusSync` (T-016, `packages/platform/src/Support/TenantStatusSync.php`) is "the only writer
  of `tenants.status`" **within T-016's own scope**, and `TenantCommercialStatus::PROTECTED` explicitly
  lists `pending` and `provisioning` as statuses the reconciler "never touches in either direction", with
  the comment citing FP-206 by number. So starting a subscription for a `provisioning` tenant does
  **not** itself flip `tenants.status` — the reconciler no-ops on a protected status — and the explicit
  transition out of `provisioning` is this task's to make (D-8).
- `SyncPermissionRegistry` (T-010, `packages/access/src/Features/SyncPermissionRegistry/
  SyncPermissionRegistryHandler.php`) already reconciles a tenant database's `permissions` table against
  every module's declared keys and seeds/tops-up the system `owner` role, idempotently, inside one
  transaction. Nothing in the codebase currently calls it at tenant-creation time (measured: no caller
  exists outside its own tests).
- **Granting the `owner` role to the very first employee in a tenant cannot go through the ordinary
  `AssignRole` handler** (B-5, new finding): `AssignRoleHandler::handle()` calls
  `Escalation::requireRoleHeld($connection, $command->actorId, ...)`, which for the `owner` role means
  the actor must already hold *every* declared permission key — exactly what only `owner` itself holds.
  For a brand-new tenant nobody holds anything yet, so this is unsatisfiable by construction, and a
  `null` actor is refused outright (`Escalation::requireHeld()`'s `actor_required`, logged at error
  level). `Escalation`'s own docblock names this exact situation: *"the one real caller that is coming,
  FP-206's provisioning of the first owner ... Provisioning is expected to supply a **named system
  actor, not a null one**."* No such system-actor path exists yet — this task builds it (D-23).
- `CreateBranch` (T-014, `packages/organization/src/Features/CreateBranch/CreateBranchHandler.php`)
  creates a branch, its `branch_store` location and the link between them as one transaction, but takes
  `timezone` and `currency` with **no default** ("inventing one would be a guess about the business",
  per its own docblock) — so a caller must supply them, which this task's `reserve_identity` step is the
  first place in the sequence to have collected.
- **`employees` has no `branch_id` column at all** (B-6, correcting the earlier draft): branch
  membership is exclusively `employee_branches` (T-014 D-9/D-14 trimmed `employees` to "identity and
  access only"). `employees.code` is `VARCHAR(20)` `NOT NULL`, unique over the whole table including
  soft-deleted rows, with no default — a caller must supply it. `AttachBranch`
  (`packages/access/src/Features/AttachBranch/AttachBranchHandler.php`) is the existing, unchanged
  mechanism for attaching an employee to a branch (optionally as their default); unlike `AssignRole` it
  carries no `Escalation` check at all ("AC-36 does not [apply]" per its own docblock — attaching confers
  no permission), so `create_owner` can call it as-is with a `null` actor.
- `SecretResolver::resolve()` (T-003/T-008, `packages/kernel/src/Tenancy/Secrets/`) is **read-only**,
  and **only `env://` is registered today** (B-3, correcting the earlier draft): `TenancyServiceProvider`
  binds `SchemeSecretResolver` with exactly one driver, `EnvSecretResolver::SCHEME => new
  EnvSecretResolver($app)`. `docs/database.md` §4.4 documents a `file://` scheme as "the default for
  this project today", but **no `file://` driver exists in the shipped code at all** — not read, not
  write. Nothing in the codebase writes a new secret into any driver (measured: no `store`/`put`/`write`
  method exists on `SecretResolver` or on `EnvSecretResolver`). Generating and persisting the two new
  per-tenant database credentials (`app`, `migrate`) this task's `store_credentials` step needs is new
  ground on both axes: this task builds the `file://` driver itself, for both reading and writing (D-6).
- **No *production* code issues `CREATE DATABASE`, `DROP DATABASE`, `CREATE USER` or `GRANT`** (B-10,
  correcting the earlier draft, which claimed no code anywhere does). `packages/testing/src/Engine/
  EngineDatabase.php` issues `CREATE DATABASE`/`DROP DATABASE` — but it is test-only infrastructure
  (the `packages/testing` path package, used by the engine-fixture suites to build and tear down
  throwaway databases), and `modules/host/tests/Feature/DestructiveStatementContainmentTest.php`
  exists specifically to keep it the **only** file in the repository permitted to issue a database drop
  at all — a rule written after a real incident (2026-10-14, sixteen of the owner's databases dropped by
  a permissively-stubbed guard handed to an engine-wide sweep). Measured directly from that test's own
  scanner (`containmentDatabaseDropsInCode()`): it matches `DROP DATABASE`/`DROP SCHEMA` only — `CREATE
  DATABASE`, `CREATE USER` and `GRANT` are **not** restricted to any one file by this test. So this
  task's real-engine port may issue `CREATE DATABASE`/`CREATE USER`/`GRANT` from new production code,
  but **must never issue a `DROP DATABASE`** anywhere outside `EngineDatabase` (which production code
  cannot reach anyway — `packages/testing` is a test-only path package) — directly shaping how `abandon`
  can and cannot compensate (B-2, AC-9).
- `ActivationCodeFormat::TENANT_PART_PATTERN` (`packages/contracts/src/Organization/
  ActivationCodeFormat.php:36`) is `/\A[A-Z0-9](?:[A-Z0-9-]{0,28}[A-Z0-9])?\z/` — the format
  `tenants.code` must already satisfy for `ActivationIssuer` to ever issue a code for this tenant, and
  the only place in the shipped code that currently states the upper-case requirement in prose (its own
  docblock: *"Tenant codes must be stored upper-case; FP-206 must store them so"*).
- `OrgCode::normalise()` (T-014, `packages/organization/src/Support/OrgCode.php`) is the shipped
  precedent for "trim, fold to upper case, validate against a fixed character class, refuse rather than
  silently coerce" — the shape this task's own `code` handling follows (D-1), though `tenants.code`'s
  own length and pattern (30 chars, `ActivationCodeFormat::TENANT_PART_PATTERN`) differ from
  `OrgCode::MAX`'s 10.
- `database_servers` (T-008) already carries `accepts_new_tenants`, `status`, `tenant_count` and
  `max_tenants` exactly as `docs/database.md` §4.4 and §8 step 2 describe; nothing populates or reads
  them today outside its own migration and model (measured: no feature queries this table).
- `StartSubscriptionHandler` (T-016, `packages/platform/src/Features/StartSubscription/
  StartSubscriptionHandler.php`) exists, accepts exactly `trial` or `active` as a starting subscription
  status, and already refuses a `plan_id` that is missing, soft-deleted or `is_active = false` with a
  `not_found` error — the exact query this task's own upfront plan validation reuses (B-8, AC-1).
  Nothing currently calls `StartSubscriptionHandler` from a tenant-creation path.
- Every existing console command in this codebase that names an acting user does so with an optional,
  nullable `--actor=` option ("the acting user id, recorded on the audit row" —
  `ChangeSubscriptionStatusCommand`, and every other platform-operator command) — the convention this
  task's own command adopts (B-11).

## User stories

- As a platform operator, I want to run one command that creates a new tenant end to end — database,
  mapping, schema, the handful of rows a brand-new shop needs, and an owner login — so that bringing on
  a customer is not a sequence of manual, error-prone steps against production infrastructure.
- As a platform operator, I want a provisioning run that fails partway through (a network blip, a
  server at capacity, a migration that reveals a real bug) to be resumable from where it stopped, so
  that a transient failure does not strand a half-created tenant or force me to clean up and start over.
- As a platform operator, I want to see exactly which step a stalled or failed run is on and why, so
  that I am not guessing whether it is safe to resume.
- As a platform operator, I want a run I decide is not worth resuming to be abandoned safely — without
  the tool silently issuing a database drop or reusing a sequence number a leftover orphaned database
  still occupies — so that cleaning up a bad attempt does not create a worse problem than the one it
  solves.
- As the owner of a newly provisioned shop, I want a working login the moment provisioning finishes, so
  that I can sign in and start setting up my business without a second manual step.
- As someone who already owns one shop on this platform, I want provisioning a second one to attach my
  existing login rather than refuse or duplicate it, so that "one person, several shops"
  (`docs/architecture.md` §5) is something provisioning actually supports, not something it blocks.
- As a developer of `TenantStatusSync`/`Tenancy`/`PlanMigrationRunHandler`/`ActivationIssuer`/
  `Escalation`, I want provisioning to use the exact seams my own code already left for it
  (`TenantCommercialStatus::PROTECTED`'s carve-out, `SchemaMetaIdentity`'s missing writer, the
  named-system-actor escalation path), so that this task does not quietly invent a second mechanism
  alongside the one already shipped anticipating it.

## Acceptance criteria

### A. The command and the resumable run (FP-206's "one resumable job")

- **AC-1**: `php artisan tenant:provision` accepts `--code=`, `--name=`, `--country=`, `--timezone=`,
  `--currency=`, `--business-type=`, `--owner-email=`, `--owner-name=` (optional), `--plan=` (the
  plan's **id**, matching the convention `ChangeSubscriptionPlanCommand` already uses — B-11, not a
  plan code), `--active` (flag, optional — see AC-34), `--branch-code=`/`--branch-name=` (optional,
  defaults per AC-26), `--server=` (optional override), `--actor=` (optional, nullable — the acting
  platform user id recorded on this run's audit rows, the same convention every other platform command
  already follows, B-11) and `--resume=<run_id>`. A fresh invocation (no `--resume`) with any of
  `--code`, `--name`, `--country`, `--timezone`, `--currency`, `--business-type`, `--owner-email` or
  `--plan` missing, blank, or **failing format/existence validation** refuses with a validation message
  naming the field and the problem, **before any row is written** — not merely checked for presence
  (B-8, correcting the earlier draft, which only checked presence). Specifically, and all before
  `reserve_identity` runs: `--code` against `ActivationCodeFormat::TENANT_PART_PATTERN` (AC-11);
  `--country` is exactly two upper-case letters; `--currency` is exactly three upper-case letters;
  `--timezone` is a value `DateTimeZone::listIdentifiers()` recognises; `--business-type` is one of
  `retail`, `restaurant`, `both` (the enum `docs/database.md` §4.1 already names for `tenants.
  business_type`); `--plan` identifies a `plans` row that is not soft-deleted and has `is_active = true`
  — the exact lookup `StartSubscriptionHandler` already performs, read directly here rather than a
  second rule invented for what counts as a valid plan (B-8); `--branch-name` (if supplied, else the
  tenant's own `name`) is at most 150 characters and `--branch-code` (if supplied) passes
  `OrgCode::normalise()` — both are `branches`' own column/validation limits, read directly rather than
  discovered for the first time when `CreateBranch` itself refuses, several steps and a real database
  later. (D-15: `--code`, `--country`, `--timezone`, `--currency`, `--business-type` and `--plan` are
  five required inputs the backlog's one-line description does not enumerate individually, but
  `tenants`' own non-nullable columns and `CreateBranch`'s own "no default, inventing one would be a
  guess" rule both require them to come from somewhere, and an operator is the only source for them.)
- **AC-2**: Given a fresh invocation, Then one `tenant_provisioning_runs` row is written before any
  other step starts, with `type = 'create'`, `status = 'running'`, `current_step` naming the first
  step, and one `tenant_provisioning_steps` row per step of the sequence — **`reserve_identity`,
  `select_server`, `create_database`, `store_credentials`, `create_users`, `write_mapping`,
  `migrate_remainder`, `seed_core`, `create_owner`, `verify`, `activate`** (B-1: `store_credentials`
  moved ahead of `create_users` — see AC-15/AC-17) — **all `pending`** — the plan is written whole, the
  same shape T-008's `PlanMigrationRunHandler` already uses for its own run/job rows. (D-3: this task
  has no `import_template` step — see Out of scope.)
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
  refused with an explicit error naming the conflict, never silently adopted. **[ENGINE] fencing (B-7):**
  the *design* of this detection (what query distinguishes "already exists, mine" from "already exists,
  foreign") is provable today against the stand-in; whether `create_database`'s real MySQL/MariaDB
  implementation actually observes an existing database correctly on a live server is NOT VERIFIED until
  G-1, the same fencing as AC-16.
- **AC-6**: `--resume=` naming a run that is already `status = 'completed'` refuses with a message
  saying so and makes no write. `--resume=` naming a run id that does not exist refuses with `not
  found`. A fresh invocation (no `--resume`) whose `--code` collides with an existing `tenants.code` —
  whether that tenant's own run completed or is itself mid-flight — refuses at `reserve_identity`
  (AC-10, corrected citation, B-10) rather than starting a second, parallel run for the same shop, and
  the refusal names the existing run id so the operator can `--resume` it instead (D-12).
- **AC-7**: Two concurrent invocations racing on the same `--code` result in exactly one `tenants` row:
  `reserve_identity`'s insert relies on `tenants.code`'s unique index as the backstop, and the losing
  invocation's run is marked `failed` at that step with a message pointing at the winner's run id, not
  left half-written. **[ENGINE] fencing (B-7):** this is provable today on the stand-in only as *one
  process, two sequential attempts* (the second sees the first's committed row). A genuine **two-process
  race** — both transactions committing concurrently against a real engine's unique-index enforcement —
  is NOT VERIFIED until G-1; the single-process sequential case is not a substitute proof for the
  concurrent one, only for the "eventually refused" half of this criterion.
- **AC-8** (negative/edge case): A run that fails at `create_database`, `store_credentials` or
  `create_users` — before `write_mapping` — leaves **no** `tenant_databases` row and **no** `tenants`
  row visible as anything but `provisioning`; the tenant is not servable (`Tenancy::enter()` already
  refuses any status outside `active`, unchanged by this task) and not migratable by a **bulk** run
  (`TenantStatusPolicy::NAMED_ONLY_STATUSES` already excludes `provisioning` from a bulk `--all`,
  unchanged by this task).
- **AC-9** (abandon — rewritten per B-2, correcting the earlier draft entirely): `tenant:provision
  --abandon=<run_id>` is the only path that compensates a failed run, but it does **not** drop the
  tenant's database and does **not** delete the `tenants` row, for two reasons measured directly rather
  than assumed: (1) `modules/host/tests/Feature/DestructiveStatementContainmentTest.php` fails the whole
  gate on any `DROP DATABASE`/`DROP SCHEMA` outside `packages/testing/src/Engine/EngineDatabase.php`
  (test-only infrastructure, enforced after a real incident — see Current behaviour) — production
  `abandon` code issuing one would never pass the gate; (2) deleting the `tenants` row would free its
  `sequence` for reuse (`sequence` is `AUTO_INCREMENT`-backed and not reclaimed on delete in general,
  but a later row could still be assigned a *different* sequence that collides with the still-existing,
  orphaned `flx_t_<old sequence>` database on the server, since nothing would then know that name is
  taken). So `abandon` instead: marks `tenants.status → 'archived'` and `tenant_databases.status →
  'archived'` (both already-designed statuses, `docs/database.md` §4.1/§4.4 — unused by any other task
  today, confirmed by grep) so the tenant is permanently unservable and unmigratable by any run, bulk or
  named; drops the `GRANT`s and issues `DROP USER` for the two created database users (a user-level
  statement, not a database-level one — outside `DestructiveStatementContainmentTest`'s scope, and
  itself still only proven against the stand-in, NOT VERIFIED on a real engine until G-1, B-7); deletes
  the stored secret versions; marks the `tenant_databases` row — not drops it, so the `database_name` it
  recorded stays readable. The command's own output **prints the database name and server** and an
  explicit instruction: *"The database itself was not dropped — drop `<name>` on `<server>` by hand if
  you are certain nothing else needs it."* A plain failed/resumable run is never auto-abandoned: retry is
  the default, abandonment is an explicit, separate, operator-invoked act, and even then it stops short
  of the one statement the gate itself forbids outside test code.

### B. `reserve_identity` and `select_server` (master-only, no tenant connection opened yet)

- **AC-10**: `reserve_identity` inserts one `tenants` row with `status = 'provisioning'`, an
  auto-assigned unique `sequence`, `code` equal to the normalised, upper-cased `--code` (AC-11),
  `slug` defaulted to the lower-cased `code` when `--slug` is not separately supplied (D-2), and
  `country`/`timezone`/`currency`/`business_type`/`name` taken verbatim from the corresponding options
  (already validated for shape, AC-1). `plan_id` is left null here — `--plan` is consumed later, by
  `activate` (AC-34, corrected citation — the earlier draft cited AC-23, which is `migrate_remainder`'s
  retry criterion and names no plan at all, B-10), because starting a subscription is a commercial act
  and this step only reserves the identity.
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
  incremented only at `activate` (AC-33, corrected citation — the earlier draft cited AC-25, which is
  `seed_core`'s `SyncPermissionRegistry` criterion and touches no server row, B-10), not at selection —
  a run that fails after selecting a server but before completing must not have reserved capacity
  nobody can see was ever used.

### C. The real-engine steps — `create_database`, `store_credentials`, `create_users` — new ground, gated by `ENGINE_HOLD` (B-1 reorders this group)

- **AC-15**: One published contract this task adds (the exact interface and class names are the
  architect's/developer's to design, not restated here) exposes two independent capabilities:
  `createDatabase(name, charset, collation)` and `createUser(username, password, grants)` scoped to
  that one database only. It does **not** generate passwords itself — that is `store_credentials`'
  job (AC-17), deliberately split out so a password exists and is durably stored *before* anything
  needs it (B-1). A deployment binds the real MySQL/MariaDB implementation of this contract; the test
  suite binds a stand-in that records what it was asked to do without opening a socket — the same split
  T-008's `PerTenantLock`/connection layer and T-023's Docker-compose work used.
- **AC-16 [ENGINE]**: Given the real MySQL/MariaDB-backed implementation of the contract and a real
  server reachable at the configured host/port, When `create_database` and `create_users` run, Then a
  database exists with the computed name and configured charset/collation, two users exist scoped to
  only that database with exactly the grants `docs/database.md` §2's table names (`app`: `SELECT,
  INSERT, UPDATE, DELETE, EXECUTE`; `migrate`: the same plus `CREATE, ALTER, DROP, INDEX, REFERENCES,
  CREATE VIEW, TRIGGER`), and neither password appears anywhere in clear — not in a log line, not in an
  exception message, not in the master database. **NOT VERIFIED in this environment**: `ENGINE_HOLD`
  forbids any connection to a real engine until gate G-1 (a verified dump of the existing databases) is
  cleared by the human; this criterion is proven by the contract's own unit tests against the stand-in
  today and stays explicitly unverified-on-a-real-engine until then, the same fencing T-008's AC-22,
  T-009's AC-22/AC-50 and T-023's AC-18/AC-19 already carry.
- **AC-17** (**now the step immediately after `create_database`, before `create_users` — B-1**):
  `store_credentials` generates two random, high-entropy passwords and writes each through the new,
  **writable** `file://` driver this task builds (D-6; `SecretResolver` as shipped is read-only and
  registers only `env://`, which cannot be written at all — B-3, correcting the earlier draft's
  assumption that `file://` already existed for reading) — confined to one configured directory — before
  `create_users` or any later step can read them back. If the process dies after `store_credentials`
  commits but before `create_users` runs, a resumed run finds the stored secret already written and
  proceeds straight to creating the database user with it; if it dies before `store_credentials`
  commits, nothing was ever created that needed a password nobody can now produce (B-1's whole point:
  the previous draft's order — `create_users` before `store_credentials` — could lose a password for a
  user that already exists on the server, forcing a user to be re-created with a new password it had no
  way of knowing to generate idempotently; this order cannot). **Negative case**: a resumed run whose
  `store_credentials` step is re-entered after the secrets were already written does not write a second
  version or rotate the password — it is found idempotently, by the reference `reserve_identity` already
  computed, not regenerated.
- **AC-18**: No step, log line, exception message or stored row — including a `tenant_provisioning_
  steps.error` column on a failed step — ever contains either generated password in clear. **Control**:
  a deliberately-failing stand-in implementation that raises an exception embedding the password is
  caught by a test asserting the stored `error` text is sanitised, the same `needles`-redaction idiom
  `MigrateTenantHandler::needlesFor()` already uses for the migrate credential. **[ENGINE] fencing
  (B-7):** this control proves the application-side redaction. Whether the password additionally never
  reaches a server-side surface this task does not control — a real MySQL/MariaDB general query log, or
  `SHOW PROCESSLIST`/`information_schema.PROCESSLIST` capturing a `CREATE USER ... IDENTIFIED BY
  '<password>'` statement in cleartext while it runs — is a fact about the real engine's own logging
  configuration, not about this task's code, and is NOT VERIFIED until G-1; the real implementation's own
  design note (restated at build time) must say explicitly that it issues `CREATE USER` with a
  bind/parameterised value wherever the driver allows one, precisely so that this server-side exposure
  is minimised when it is finally checked.
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
- **AC-25a [new, B-4]**: `seed_core` writes `schema_meta`'s `tenant_id` row (key `tenant_id`, value the
  tenant's own master id) — the writer T-008 deliberately left this task to build (see Current
  behaviour; `SchemaMetaIdentity`/`TenantIdentityMismatch` already exist as the **reader** and the
  **refusal**, with the writer named as FP-206's by the reader's own docblock, D-28). This is the first
  write `schema_meta` receives for this tenant — the baseline creates the table empty, by design.
- **AC-26**: `seed_core` then creates exactly one branch via the existing `CreateBranch` handler, using
  the tenant's own `country`/`timezone`/`currency`/`business_type` (captured at `reserve_identity`) and
  a branch `code`/`name` taken from `--branch-code`/`--branch-name` if supplied (already validated for
  shape at AC-1), defaulting to `MAIN`/the tenant's own `name` otherwise. `CreateBranch`'s own
  transaction (branch + its `branch_store` location + the link between them) is unchanged by this task.
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

### F. `create_owner` (B-5, B-6 — substantially rewritten)

- **AC-29** (rewritten per B-5/B-6, correcting the earlier draft's wrong schema assumptions):
  `create_owner` performs, in order: (1) the tenant `employees` row, with `code = 'OWNER'` **fixed, not
  operator-supplied** — the one literal value this step always uses, and what makes a resumed
  `create_owner` findable (AC-30) — `display_name` defaulted from the local part of `--owner-email`
  unless `--owner-name` is supplied, `status` active, `pin_hash` null. `employees` has **no `branch_id`
  column at all** (B-6) — there is nothing to set here. (2) The employee is attached to the branch
  `seed_core` created via the existing, unchanged `AttachBranch` handler (`employee_branches`,
  `is_default = true`) — `AttachBranch` carries no `Escalation` check (current behaviour), so this call
  needs no system actor. (3) The employee is granted the `owner` role — **not** through the ordinary
  `AssignRole` handler, which would refuse for exactly the reason `Escalation`'s own docblock names
  (current behaviour, B-5) — but through the new, narrowly-gated access-bootstrap operation this task
  adds (D-23): callable only when no live employee in this tenant already holds a system role keyed
  `owner`, checked inside the same transaction as the grant. (4) Finally, the master-side write: either
  a new `users` row (when `--owner-email` names nobody yet — AC-29 proper) or an attachment to an
  **existing** `users` row (when it does — AC-31, resolved below), and a `tenant_users` row
  (`access_level = 'owner'`, `status = 'active'`, `employee_id` = the tenant employee just created),
  with `employees.user_id` back-filled afterwards.
- **AC-30** (negative/edge case — the compensating delete this step's own failure needs): If the
  master-side write fails after the tenant `employees` row committed, the step is marked `failed`
  without deleting the tenant employee row (a committed tenant-side write is not silently undone by a
  different step's failure); a resumed `create_owner` finds the existing employee row **by `code =
  'OWNER'`** (B-6, correcting the earlier draft's "provisioning-run reference", which named a column
  this schema does not carry) and completes only the remaining half, never inserting a second employee
  row for the same owner.
- **AC-31** (**resolved per the architect's flagged conflict with `docs/database.md` §8 step 10's
  "insert/attach" wording — D-30, `ba`'s own call, not the lead's**): `--owner-email` colliding with an
  existing master `users.email` (globally unique, per `docs/database.md` §4.1) does **not** refuse.
  `docs/architecture.md` §5 states outright, as a deliberate design goal: *"One person, several shops
  ... a user who owns three pharmacies signs in once and switches between them without separate
  accounts."* Refusing here would make provisioning structurally unable to add a second shop for an
  existing owner — contradicting a documented, explicitly intended capability, not merely an
  unspecified edge case. So `create_owner`'s master-side write **attaches**: it inserts no new `users`
  row, mutates no field of the existing one (no new password, no `must_change_password` reset, no
  status change to a login that may currently be mid-session elsewhere), and inserts a `tenant_users`
  row for the **new** tenant only (`unique(tenant_id, user_id)` already permits the same `user_id`
  across many tenants, confirmed directly against the master schema, T-008). The tenant-side `employees`
  row is still created fresh for this tenant (an employee is per-tenant data; sharing a login does not
  mean sharing a payroll/PIN identity), and `--owner-name` is ignored when attaching (the existing
  user's own name is immaterial to a tenant-scoped employee's `display_name`, which still defaults from
  the email's local part). **Negative case, preserved from the earlier draft's safer half**: nothing
  about attaching ever creates a second `users` row for the same email — the uniqueness this task
  originally protected is intact, only the *response* to a match changes from refusal to attachment.

### G. `verify` and `activate`

- **AC-32**: `verify` asserts, against the tenant connection: the migrated schema's `migrations` table
  lists exactly the set `migrate_remainder`'s target set named (no more, no fewer); the `permissions`
  table holds the `owner` role with every declared key (re-reading what `seed_core` wrote, not trusting
  its own report); `schema_meta`'s `tenant_id` row matches this tenant's own master id, read through
  `SchemaMetaIdentity::of()` (B-4, closing the loop this task's own `seed_core` write opened — AC-25a);
  the branch `seed_core` created exists; the owner employee `create_owner` created (`code = 'OWNER'`)
  exists, is attached to that branch, and is linked to the master `tenant_users` row (whether newly
  created or attached, AC-29/AC-31). Any mismatch fails `verify` with the specific assertion named, and
  `activate` never runs against an unverified tenant.
- **AC-33**: `activate` writes `tenant_databases.status: 'provisioning' → 'active'` using the same
  write-then-evict pattern `MigrateTenantHandler::writeMapping()` already uses (one update, followed by
  the mapping cache eviction, never separated), and increments the selected server's `tenant_count` by
  one in the same step.
- **AC-34** (D-7, D-8 — the explicit transition `TenantStatusSync` declines to make): `activate` starts
  a subscription for the tenant via the existing `StartSubscriptionHandler`, using `--plan` (already
  validated to exist and be active at AC-1) and defaulting the subscription's starting status to
  `trial` (the `--active` flag starts it `active` instead, for an operator who is provisioning an
  already-paid account). Because `TenantCommercialStatus::PROTECTED` excludes `provisioning` from
  `TenantStatusSync`'s own reach by design, `StartSubscriptionHandler`'s own reconciliation call is a
  no-op here — so `activate` then writes `tenants.status: 'provisioning' → 'trial'` (or `'active'`)
  **itself**, directly, in the same transaction as the subscription start, exactly once per run. Every
  reconciliation after this point — suspension on non-payment, licence lapses, plan changes — is
  `TenantStatusSync`'s job again, unchanged by this task, because the tenant's status is no longer
  `provisioning`.
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
  every prior task's provisioning-adjacent code already follows. The new access-bootstrap operation
  (D-23) lives in `packages/access` and is reached from provisioning only through a published contract,
  never `packages/access`'s internals — the same boundary rule, applied to the one new intentional
  escape hatch this task adds to the escalation guard. **A static test asserts `DestructiveStatement
  ContainmentTest`'s drop-owner rule is unaffected by this task** — no new file issues `DROP DATABASE`/
  `DROP SCHEMA`, confirming `abandon` (AC-9) actually honours the constraint its own design claims to.
- **AC-40** (**B-7, substantially corrected — the earlier draft overclaimed what a real-engine-free
  suite proves**): `composer test:fast` and `composer test` both pass, proving every criterion above
  **except the following, which stay explicitly NOT VERIFIED until gate G-1**, each already individually
  fenced at its own criterion above and collected here so the scope of the real-engine hold is stated
  once, completely, rather than trusted to be "AC-16 and nothing else":
  - **AC-16**: the real database/user-creation contract actually working against a live server.
  - **AC-5**'s engine-side half: whether the real implementation's own "does this already exist"
    detection behaves correctly against a live server (the stand-in's equivalent check is proven; the
    real one is not).
  - **AC-7**'s two-*process* race (only the single-process sequential case is proven here).
  - **AC-9**'s `DROP USER`/revoked-grant half actually taking effect against a live server (the
    `tenants`/`tenant_databases` archival writes themselves are ordinary master-connection writes and
    are fully proven today).
  - **AC-18**'s server-side half: a real engine's own query log or process list never exposing a
    password this task's own code already redacts from its side.

## Out of scope

- The `import_template` fast-path (`docs/database.md` §8 step 7) — D-3/AC-24.
- `company_profile`, chart of accounts, payment methods, tax groups, print templates, number-sequence
  defaults, and every system role besides `owner` — D-5/AC-28, each the responsibility of the task that
  ships its owning module.
- Suspend, deprovision, move-to-another-server and clone lifecycles (`docs/database.md` §8's "related
  lifecycles, same machinery" table) — D-14, separate backlog rows.
- The real MySQL/MariaDB implementation of the database/user-creation contract actually running against
  a live server, and every other item AC-40 lists — gated by `ENGINE_HOLD` until G-1.
- A writable credential driver for `vault://`/`awssm://`/`azkv://` — only `file://` (built fresh by this
  task, for both reading and writing, D-6) is built; the cloud drivers stay unbuilt exactly as they are
  today, and `env://` stays read-only.
- Actually dropping an orphaned tenant database after `abandon` — printed as an instruction for the
  operator to run by hand (AC-9), never automated, because the gate itself forbids a second file issuing
  `DROP DATABASE` (D-21).
- A real invite/reset-email flow for a newly provisioned owner — D-26 (the lead's own ruling): this task
  prints the generated password to the console once, as an interim measure, pending a real notification
  capability.
- Deciding the real VPS directory the writable `file://` secrets live in, or its backup strategy —
  D-29 (the lead's own ruling): deferred to FP-221 (VPS pre-flight), which stands up a real environment
  to decide it against; this task only makes the directory configurable with a sensible local default.
- Any HTTP/admin-UI surface for provisioning — console-only, matching every prior platform-operator
  command in this codebase (T-016 D-21, T-017 D-7, T-018 D-9, T-019 D-10, T-020 D-10).
- Billing/invoicing side effects of starting a subscription (card capture, an invoice row) — T-016's
  own `StartSubscriptionHandler` already excludes these, and this task calls it unchanged.

## Non-functional requirements

- **Credential handling**: generated passwords are high-entropy and never appear in clear in a log,
  exception message or stored row (AC-18) — the same bar `docs/database.md` §4.4 sets for every
  existing credential path in this codebase. The one deliberate exception is the owner's own login
  password, printed once to the console per D-26 — an interim, explicitly-documented departure from
  that bar, not a silent one.
- **Resumability and idempotency** are the central non-functional property of this whole task (the
  backlog's own "one resumable job" line) — every step must be safely re-enterable, per AC-5.
- **Isolation from other tenants**: nothing this task does while provisioning tenant A may read or
  write tenant B's database, mirroring the cross-tenant isolation guarantees T-022's permanent sweep
  already enforces structurally (no new route, queued job, cache key or plugin hook is introduced by
  this task that would need a new sweep entry).
- **Data-loss safety**: no new code path issues `DROP DATABASE`/`DROP SCHEMA` outside the one file the
  gate already restricts that to (AC-9, AC-39) — a hard constraint, not a style preference, written
  after a real incident this project has already suffered once.

## Assumptions

- `--code` is operator-supplied, never derived from `--name` (D-1): deriving a short code from a free-
  text business name is a slugification policy nobody has specified (collision handling, truncation
  length, transliteration), and `OrgCode`'s own precedent for branches/locations/terminals is likewise
  an explicit, required code rather than a derived one.
- `--plan` is required, with no default plan silently chosen (D-15): which plan a new paying customer
  starts on is a commercial decision, not a technical default this task should invent.
- One default branch, timezone/currency from the tenant's own master-level fields: a shop with more than
  one branch at day one adds the rest itself, after first login, through `CreateBranch` — unchanged and
  already sufficient.
- Attaching an existing owner (AC-31) never changes anything about their existing login — no new
  password, no forced password change, no session impact — only a new `tenant_users` row for the new
  tenant; anything else would be a surprising side effect of provisioning somebody else's shop.

## Open questions

None — every design question this task raised was either decided and logged in
`docs/decisions/T-026-tenant-provisioning.md` (`ba`'s own calls, including the AC-31/`docs/database.md`
conflict), or routed to and ruled on by the lead directly (the owner's first-login UX and the writable
secrets directory's real placement — both logged there too, attributed to the lead), per the team's
autonomous-mode protocol.
