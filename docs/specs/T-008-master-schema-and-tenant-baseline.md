# T-008: Master schema, tenant baseline and the per-tenant migration runner

Covers backlog rows **FP-203** (master schema), **FP-204** (tenant schema baseline) and **FP-205** (per-tenant
migration runner) as one slice: a tenant schema with no runner cannot be applied, and a runner with no schema has
nothing to run. Decisions D-1 to D-21 are in `docs/decisions/T-008-master-schema-and-tenant-baseline.md`. **No
BLOCKING questions.**

**This task is code and it is the first to write real tables.** Every criterion is checked by running something.
Where it needs the diff or the branch it is marked **(diff)** or **(branch)**: `ba` has no shell and no git, so those
are for `qa` or `reviewer`. Criteria that need a real database engine are marked **[E]** and are explained under
"Test vocabulary".

## Problem / goal

The product owner decided on one master database (identity, tenants, plans, licences, and the mapping from tenant to
database) plus one database per shop (all of that shop's business data, with no tenant column, because the database is
the boundary). `docs/database.md` v0.5 designs both. **None of it exists as an artefact.** The only migrations in the
repository are Laravel's stock `users`, `password_reset_tokens`, `sessions`, `cache` and `jobs` ones, and the only
mapping source is T-003's `InMemoryTenantDatabaseRepository`. Every later Phase 0 row (provisioning FP-206,
authentication FP-207, plans and licensing FP-210, audit FP-211, the event bus FP-212) is blocked on this.

The standing rule from `CLAUDE.md` is what makes this slice hard: **every schema change runs against every tenant
database.** A schema that works on one database is not done. The runner is therefore the part that matters most, and
the criteria are written so that "it worked on one database" cannot pass.

This is also the project's **calibration slice**. Twelve of 106 backlog rows are done and all were toolchain, tenancy
or documents, so there is no measured implementation rate for anything. See "Calibration".

## Users affected

- `dev` and `architect` of every later module: this is the schema, the migration tree and the runner they build on.
- Operators: the runner is how a schema change reaches 200 shops, and its refusals are what stop a bad one.
- Shop owners, indirectly: a mapping that is wrong, or a tenant served on a schema older than the code, is a shop that
  cannot sell.
- `qa` and `reviewer`: this adds the first criteria that only a real engine can answer.

## Current behaviour

Re-derived from the files, not taken from the brief, except where marked.

- `modules/host/database/migrations/` holds three stock migrations: `0001_01_01_000000_create_users_table.php`
  (integer-id `users`, `password_reset_tokens`, `sessions`), `..._000001_create_cache_table.php` and
  `..._000002_create_jobs_table.php`. `modules/host/config/database.php` sets the **default connection to `master`**, so
  a plain `php artisan migrate` writes to the master database. The tenant connection is a sentinel stanza with no host
  and no database (T-003).
- `modules/host/phpunit.xml` runs the suite on **in-memory SQLite** (`DB_CONNECTION=sqlite`,
  `DB_MASTER_DRIVER=sqlite`), with `SESSION_DRIVER=array`; the shipped `.env.example` has `SESSION_DRIVER=database`
  and a test pins that. No test in `modules/host/tests` refers to MariaDB or MySQL.
- `packages/kernel/src/Tenancy/Contracts/TenantDatabaseRepository.php` is the mapping seam: `find(tenantId)` returns
  one `TenantDatabase` or null. `TenantDatabase` carries tenant id, driver, host, port, database, username,
  credential reference and status, **and deliberately no schema version, no credential version and no migrate
  credential** (its own docblock says those belong to FP-205, FP-206 and FP-210). The only implementation is
  `InMemoryTenantDatabaseRepository`, bound from configuration in `TenancyServiceProvider`. The backlog records
  "Carried into FP-203": the real reader must return a credential *reference* never a password, must never cache the
  resolved secret, and needs a re-read-and-retry path for a credential rotation that moved the reference.
- Nothing refuses a tenant for having an older schema than the code expects, and nothing runs any migration against
  any tenant database.
- `docs/database.md` section 4 designs the master; section 6.21 and section 6.1 design the tenant tables this task
  needs; section 9 designs the runner. `docs/architecture.md` v0.5 has no CQRS content: the revision the owner's
  2026-10-06 directive requires has not been written.

**Figures.** From the brief and not re-derived: 12 of 106 rows done. Re-derived from `docs/database.md`: about 193
tables per tenant (its own header); the master has **39** application tables and **8** Laravel system tables by `ba`'s
count of section 4, not the **34** section 4.10 states. That is a reading count, not a command, so `qa` should recount
it once. The seventeen master tables this task creates are listed in AC-1.

## User stories

- As a developer of a later module, I want one tenant migration tree and one runner, so that my table reaches every
  shop the same way and I never script it by hand.
- As an operator, I want one failing tenant to fail alone, so that a deploy is not blocked by one broken shop, and I
  want to see who is behind, so that I know what to fix.
- As an operator, I want a run that died halfway to be finishable without redoing or double-applying anything, so that
  I can recover under pressure.
- As the owner of tenant isolation, I want the mapping to carry a reference and never a password, and the migrate
  credential never to reach a request, so that a leak in the serving path cannot alter a schema.
- As `architect`, I want the serving path to refuse a tenant whose schema is older than the code, so that a missed
  migration is a 503 for that shop and not a query against a column that does not exist.
- As the product owner, I want the outbox present and correct from the first tenant, so that the read side I chose
  (CQRS, level 2) has its seam, without anyone guessing the projection tables before they are needed.

## Test vocabulary

Defined once so that criteria can use the terms.

- **[E]** the criterion is answered by a real MariaDB 10.4 or MySQL 8.4 server, not SQLite. See AC-62 to AC-65.
- **The three-tenant fixture:** tenant **A**, an empty database (schema version 0); tenant **B**, the baseline already
  applied; tenant **C**, whose mapping is valid and whose database also holds the baseline, but which a fixture
  migration is written to fail on. All three are mapped on one `database_servers` row unless a criterion says
  otherwise.
- **Fixture migrations (FX):** migrations that live only under `tests/`, never in the shipped tree, and are supplied to
  the runner as an alternative migration set. FX-1 adds a new table (expand). FX-2 throws when run against tenant C
  only. FX-3 is `requires_offline` and records the tenant's mapping status from inside `up()`. FX-4 is a contract
  step. FX-5 is flagged `is_destructive`. FX-6 is an expand step that calls a drop. Without a way to run FX the runner
  cannot be tested against anything but the one real baseline, which would prove nothing about upgrades.
- **Control:** a case that proves the check can see what it claims to see. Every absence assertion below names one.

## Acceptance criteria

### A. Master schema (FP-203)

- **AC-1 [E]**: Given an empty master database, When the master migrations run, Then exactly these seventeen
  application tables exist: `tenants`, `users`, `tenant_users`, `plans`, `plan_features`, `plan_limits`,
  `subscriptions`, `licences`, `feature_flags`, `tenant_feature_flags`, `platform_settings`, `platform_audit_logs`,
  `database_servers`, `tenant_databases`, `schema_versions`, `tenant_migration_runs`, `tenant_migration_jobs`; plus
  only these Laravel system tables: `migrations`, `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`,
  `password_reset_tokens`, `sessions`; and nothing else. A second run changes nothing and exits 0. **Controls:** the
  same check fails against a master with one extra table and against one with a table missing.
- **AC-2 [E]**: Given the master after migration, When `users` is inspected, Then its `id` is `CHAR(26)` with
  `ascii_bin`, it has `password_hash`, `is_platform_staff`, `status`, `failed_attempts`, `locked_until` and
  `email`, and it has **no** `password` and no `remember_token` column (the stock integer-id table is gone).
- **AC-3 [E]**: Given the master after migration, When `tenant_databases` is inspected, Then it has every column
  `docs/database.md` section 4.4 lists for it (tenant id as primary key, server id, read server id, database
  identifier, database name, app username, app credential reference, migrate username, migrate credential
  reference, credential version, credential rotated at, charset, collation, status, status reason, schema version,
  target schema version, last migrated at, last migration error, profile, size, table count, row estimate, measured
  at, provisioned at, last opened at, archived at, created and updated), with `schema_version` and
  `target_schema_version` as `BIGINT` and the two credential-reference columns as `VARCHAR(255)`.
- **AC-4 [E]**: Given the master after migration, When `database_servers` is inspected, Then it carries host
  (`VARCHAR(191)`), port (unsigned `SMALLINT`, default 3306), engine, TLS mode, provisioner credential reference,
  maximum tenants, tenant count and status, per section 4.4, and `tenant_databases.server_id` references it.
- **AC-5 [E]**: Given the master after migration, When keys are inspected, Then `tenant_databases` has one row per
  tenant (primary key `tenant_id`), a unique `database_identifier`, a unique (`server_id`, `database_name`), and
  indexes on `schema_version`, on `status` and on (`server_id`, `status`). Inserting two mappings for one tenant is
  refused; inserting the same database name on two different servers is **accepted** (the control).
- **AC-6 [E]**: Given the master after migration, When every table's columns are listed, Then no column in any
  master table can hold a credential value: no column is named `password` other than `users.password_hash`, nor is
  any named like `*_password`, `*_secret` (other than `private_key_ref`-style reference columns) or `credential`
  without the `_ref` suffix. **Control:** the same check, run over a fixture table with a column called
  `app_password`, reports that column.
- **AC-7 [E]**: Given a tenant that has a `tenant_databases` row, When its `tenants` row is deleted, or when a
  `database_servers` row with mappings is deleted, Then both deletes are refused by the database. Deleting a tenant
  with no dependent rows **succeeds** (the control).
- **AC-8 [E]**: Given the master after migration, When identities are inserted, Then (a) two `users` whose emails
  differ only in letter case are refused as duplicates (D-17); (b) a second `tenants` row with an existing `code`,
  `slug` or `sequence` is refused; two tenants inserted without a `sequence` get two different ones; (c) a second
  `tenant_users` row for the same (`tenant_id`, `user_id`) is refused, and a duplicate non-null (`tenant_id`,
  `employee_id`) is refused, **while two rows with the same tenant and a NULL `employee_id` are accepted**. Part (c)'s
  last clause is the mirror case `docs/database.md` section 3.3 names, where NULL rows are meant to escape the key.
- **AC-9 [E]**: Given the master after migration, When commercial rows are inserted, Then a duplicate `plans.code`
  and a duplicate `licences.licence_key` are refused; `plan_features` and `tenant_feature_flags` refuse a duplicate
  key pair and refuse a feature or flag key that is not in `feature_flags`; `plan_limits` refuses a duplicate
  (`plan_id`, `limit_key`). Each has a valid insert beside it that is accepted.
- **AC-10 [E]**: Given the master after migration, When a JSON payload is written to `platform_audit_logs.before`
  and read back, Then the decoded value equals what was written on both engines, the table's primary key is an
  auto-incrementing `BIGINT`, and indexes exist on (`tenant_id`, `created_at`) and (`action`, `created_at`). A string
  that is not valid JSON written to that column is refused on **both** engines (MariaDB stores JSON as text, so this
  holds only if the migration made it so).
- **AC-11 [E]**: Given a freshly migrated master, When every application table in AC-1 is counted, Then each holds
  zero rows. Seeding plans, flags and the first administrator belongs to FP-206, FP-207 and FP-210. **Control:** the
  check fails against a fixture migration that inserts one row.

### B. The mapping reader and the serving gate (FP-203 carry-over, FP-205)

- **AC-12 [E]**: Given a tenant with a `tenants`, `tenant_databases` and `database_servers` row, When the shipped
  configuration (no test override) resolves `TenantDatabaseRepository::find(tenantId)`, Then the result carries the
  server row's host, port and engine, the mapping's database name, app username, app credential reference, status,
  schema version and credential version. For an unknown tenant, a tenant with no mapping row, and a mapping whose
  server row is absent, it returns null in each case and never a default. The T-003 and T-007 tenancy tests pass
  unchanged.
- **AC-13 [E]**: Given a resolved mapping, When the `TenantDatabase` object is serialised, cast to a string and
  printed with `var_export`, Then none of those outputs contains the test password. **Control:** the same scan, run
  over a fixture object that does hold the password, reports it.
- **AC-14**: Given a warm cache, When `find` is called a second time inside 300 seconds for the same tenant, Then the
  master receives **no** query for it, and the cache key is the tenant id alone (`tenant:conn:<tenant_id>`) with the
  credential version carried as a value inside. When the clock is advanced past 300 seconds and `find` is called,
  Then the master is read again.
- **AC-15**: Given a tenant whose connection has just been opened using a secret resolved from a reference, When the
  whole cache store (including every key and value) is dumped, Then the resolved password appears nowhere in it.
  **Control:** the dump check reports a fixture value that holds the password.
- **AC-16 [E]**: Given a tenant whose credential reference was rotated in master after a mapping was cached, and a
  server that rejects the cached credential, When the connection is opened, Then the cached entries for that tenant
  are dropped, the mapping is re-read and the connection is retried **exactly once**, succeeding with the new
  reference. If the retry fails too, a `TenantConnectionFailed` surfaces after exactly two attempts and its text
  contains no password.
- **AC-17**: Given a cached mapping, When the evict call for that tenant is made, Then the next `find` reads the
  master. It is the call the runner uses (AC-54).
- **AC-18**: Given a tenant resolved for a request, When its `TenantDatabase` is inspected, Then it carries no
  migrate username and no migrate credential reference. The migrate credential is reachable only through the
  runner's own mapping type, which does carry them. **Control:** the runner's mapping type passes the opposite check.
- **AC-19 [E]**: Given a mapping whose `schema_version` is below `APP_REQUIRED_SCHEMA_VERSION`, When a tenant scope is
  entered for it, Then it is refused with a named exception **before any network connection is attempted** (assert no
  connection was opened), the host's exception handling renders it as HTTP 503 with a `Retry-After` header, the
  message contains no credential, and entering a *different* tenant at the required version in the same process then
  succeeds. A mapping exactly at the required version is accepted (the control).
- **AC-20 [E]**: Given a mapping whose `schema_version` is **above** the required one, When a scope is entered, Then
  it is accepted and exactly one warning is recorded for that entry, not one per query.
- **AC-21**: Given the shipped tenant migration tree, When the highest version in the catalogue is compared with
  `APP_REQUIRED_SCHEMA_VERSION`, Then the constant is not greater (a code that requires a version nothing can
  produce would 503 every tenant). **Control:** the comparison fails when run with a constant one higher.

### C. The tenant baseline (FP-204)

- **AC-22 [E]**: Given an empty master and an empty tenant database, When the master migrations run, Then no tenant
  table exists in the master; When the tenant runner then applies the baseline to the tenant database, Then no master
  table exists in it. A plain `php artisan migrate` never creates a tenant table (it would otherwise run against the
  default connection, which is `master`).
- **AC-23 [E]**: Given an empty tenant database, When the baseline is applied, Then exactly these tables exist:
  `schema_meta`, `settings_registry`, `settings`, `number_sequences`, `audit_logs`, `outbox_events` and Laravel's
  `migrations`, and nothing else. In particular **there is no projection-state table and no claim-mark column** on
  `outbox_events` (D-1). **Controls:** the check fails against a database with one extra table and with one missing.
- **AC-24 [E]**: Given the baseline applied, When every column of every table is listed (live, from the engine) and
  when every tenant migration file is read (source), Then no column named `tenant_id` exists in either. `schema_meta`
  holds the tenant id as a **row** keyed `tenant_id`, not as a column, and needs no exemption. **Controls:** the
  source check reports a fixture migration that declares `tenant_id`; the live check reports a fixture table that
  has one.
- **AC-25 [E]**: Given the baseline applied, When two `settings` rows with scope `company`, a NULL `scope_id` and the
  same key are inserted, Then the second is refused; two company rows with **different** keys are accepted; two rows
  for the same branch scope id and key are refused (D-6; the sentinel works for the case that matters).
- **AC-26 [E]**: Given the baseline applied, When two `number_sequences` rows with a NULL `branch_id` and the same
  `document_type` are inserted, Then the second is refused; a company-wide row and a branch row for the same type are
  both accepted; two rows for the same branch and type are refused.
- **AC-27 [E]**: Given the baseline applied, When `outbox_events` is inspected, Then it has exactly the columns
  `docs/database.md` section 6.21 lists (id as `CHAR(26)` `ascii_bin`, type, version, branch id, aggregate type,
  aggregate id, payload, occurred at, correlation id, causation id, actor, status, published at, attempts, last
  error), indexes on (`status`, `occurred_at`) and (`aggregate_type`, `aggregate_id`), `occurred_at` as `DATETIME(3)`,
  and a new row defaults to status `pending` and attempts 0. A row written with `occurred_at` = `2026-01-02 03:04:05.678`
  reads back with `.678` on **both** engines (millisecond precision survives). A JSON payload round-trips by decoded
  equality, and an invalid one is refused on both engines.
- **AC-28 [E]**: Given the baseline applied, When a business row (a `settings` row) and an `outbox_events` row are
  written in one transaction and the transaction is rolled back, Then neither exists; When it is committed, Then
  both exist. This is the property that makes the outbox the seam for the read side. **Control:** the same test, run
  over a fixture table created with a non-transactional engine, fails.
- **AC-29 [E]**: Given the baseline applied, When `audit_logs` is inspected, Then its primary key is an
  auto-incrementing `BIGINT`, it has the indexes section 6.21 names (on `created_at`; on `subject_type` and
  `subject_id`; on `actor_id` and `created_at`; on `module`, `action` and `created_at`), and `before`, `after` and
  `changed_fields` round-trip JSON by decoded equality and refuse invalid JSON on both engines.
- **AC-30 [E]**: Given the baseline applied, When foreign keys are listed, Then none references a table named
  `branches` or `employees`, while the columns `number_sequences.branch_id`, `audit_logs.branch_id`,
  `outbox_events.branch_id` and `settings.updated_by` exist with `CHAR(26)` and are nullable (D-5), and
  `settings.key` does reference `settings_registry`. **Control:** inserting a `settings` row with an unknown key is
  refused.

### D. The catalogue and the rules that make expand-and-contract enforceable (FP-205)

- **AC-31 [E]**: Given the shipped tenant migration tree, When `schema:publish` (or whatever the plan names the
  publishing step) is run twice, Then `schema_versions` holds exactly one row per tenant migration file, with
  versions that are strictly increasing in file-name order, and the second run changes nothing. Each row records
  phase (expand, backfill or contract), a checksum of the file, `is_destructive` and `requires_offline`. A new
  migration file whose name sorts **before** an already-published one is refused by the publish step and nothing is
  written. The committed catalogue fixture equals the table after publishing.
- **AC-32 [E]**: Given a published catalogue, When a **released** migration file's content is changed, or a tenant
  migration exists that is absent from the catalogue, Then the catalogue test fails and the **runner refuses to start**
  with a message naming the migration, before it plans a run or touches any tenant (assert zero job rows and zero
  connections opened). **Control:** the unchanged tree starts normally.
- **AC-33**: Given the shipped tenant migrations and a set of fixture ones, When their `up()` bodies are read, Then no
  migration whose phase is `expand` calls a drop, a rename or a column `change`. **Control:** FX-6 (an expand step that
  calls a drop) is reported; a contract migration that drops is not.
- **AC-34**: Given the same, When `up()` bodies are read, Then no migration alters an **existing** table to add a
  foreign key unless its phase is `contract`; creating a new table with foreign keys in an expand migration is allowed.
  **Controls:** a fixture expand step that adds a foreign key to an existing table is reported; the baseline itself
  (which creates `settings` with a foreign key to `settings_registry`) is not.
- **AC-35**: Given the shipped master and tenant migrations, When their source is read, Then (a) no `decimal` call
  lacks a precision argument; (b) no column uses the native `enum` type (enumerations are `VARCHAR(30)` with
  application enums, section 3.2); (c) no `timestamp` column is declared, except in the eight Laravel system tables
  named in AC-1; (d) no migration names a collation, a charset or a storage engine literal (so `utf8mb4_0900_ai_ci`,
  which MariaDB lacks, cannot be written). **Control:** a fixture migration violating each of (a) to (d) is reported
  once per rule.
- **AC-36 [E]**: Given both schemas applied, When `information_schema` is read, Then every table created by this task
  is InnoDB and `utf8mb4`, every `id` column that holds a ULID is `CHAR(26)` with `ascii_bin`, and every `DATETIME`
  business column has precision 3. **Control:** the check reports a fixture table created as MyISAM.
- **AC-37**: Given the shipped migrations, When their source is read, Then no tenant migration names the `master`
  connection or any schema-qualified table (`name.table`) and no master migration names the `tenant` connection.
  **Control:** a fixture migration doing each is reported.

### E. The per-tenant runner (FP-205)

Every criterion in this section runs against **at least the three-tenant fixture** on a real engine, so one database
can never satisfy it.

- **AC-38 [E]**: Given the three-tenant fixture and FX-1 (a new table, supplied as a later migration), When the runner
  is run for the target that includes FX-1, Then the new table exists in A, B and C (C after its separate failure
  is cleared, see AC-46) and the runner's own code was not changed to allow it. This is the reversal path for D-1: a
  projection table added after the baseline reaches every tenant by this route.
- **AC-39 [E]**: Given the three-tenant fixture and a run requested for a target, When the plan is written, Then
  exactly one run row and exactly one job row per selected tenant exist, every job `pending`, **before any tenant
  database has been touched** (FX-1's `up()` for the first tenant reads the job table and records the count it sees,
  which must equal the number of tenants).
- **AC-40 [E]**: Given tenants at different `schema_version` and `size_bytes`, When a bulk run is planned, Then the
  selected tenants are exactly those with status `active` or `read_only` and a version below the target; a tenant
  already at the target is not planned; a tenant with status `archived` and an old version is not planned (control:
  an `active` one at the same version **is**); and jobs are ordered by ascending `size_bytes`.
- **AC-41**: Given a planned run, When the work is dispatched, Then each tenant is one queue job on the `migrations`
  queue, and the serialised job payload contains the run, job and tenant identifiers and contains no credential,
  no reference and no password (scan the payload; **control:** the scan reports a payload with a planted password).
- **AC-42 [E]**: Given two jobs for two different tenants processed one after the other in one worker process, When
  each job reports the database name its connection is using, Then the first reports A's and the second reports B's;
  and after each job has finished, a query on the `tenant` connection fails with the no-tenant-resolved error (no
  tenant scope is left open). The T-003 and T-007 isolation tests pass unchanged.
- **AC-43 [E]**: Given a tenant that migrates successfully, When the job finishes, Then (a) the tenant's own
  `migrations` table lists every migration applied, (b) `tenant_databases.schema_version` equals the highest applied
  version, (c) `last_migrated_at` is set, (d) a previous `last_migration_error` is cleared to NULL, and (e) the job
  row is `done` with `from_version`, `to_version` and `duration_ms`.
- **AC-44 [E]**: Given the three-tenant fixture and FX-2 (fails on tenant C only), When a run is executed, Then A and B
  end `done` at the target, C ends `failed` with `error_class`, `error_message` and `error_migration` populated and its
  `schema_version` unchanged, the run ends `completed_with_failures`, and the command exits non-zero. A run in which
  every tenant succeeds ends `completed` and exits 0 (the control).
- **AC-45 [E]**: Given a tenant whose mapped credential is rejected by the server, and another whose host is
  unreachable, When a run is executed, Then each ends `failed` while the healthy tenants still complete, and the
  stored `error_message` and `last_migration_error` contain neither the password nor the full credential reference
  **value** that was resolved. **Control:** the leak scan reports a fixture error text containing the password.
- **AC-46 [E]**: Given a run with a failed job, When the cause is removed and a retry is requested, Then only the
  failed tenant's job is claimed; the tenants already `done` have unchanged `migrations` tables (assert identical row
  counts and batch numbers, so nothing ran twice); and a job whose `attempts` has reached the configured maximum is
  not claimable (default 3, D-20).
- **AC-47 [E]**: Given a run in which a worker died after claiming one job and finishing another, When the run is
  resumed, Then the `done` job is not re-applied, a job whose claim has **expired** is claimed again, and a job whose
  claim has **not** expired is not claimable by a second worker. All three observations in one dataset.
- **AC-48 [E]**: Given one `pending` job, When two workers attempt to claim it, Then exactly one claim succeeds and
  the other reports it failed (the claim is one statement, not read-then-write).
- **AC-49 [E]**: Given a second database session holding the per-tenant lock for tenant B, When the runner reaches B,
  Then it does not wait: B is reported as not run because it is locked, nothing in B's database changed, and the
  remaining tenants complete. (The holder must be a separate session, because a lock is re-entrant within one.)
- **AC-50 [E]**: Given a per-server cap of 1 and two `pending` jobs on one server and one on another, When three
  workers claim, Then the first server yields one claim and the second server's job is also claimable; when the first
  claim finishes, the second job on the first server becomes claimable.
- **AC-51 [E]**: Given a tenant for each status, When a bulk run and a run naming that single tenant are planned, Then
  bulk accepts only `active` and `read_only`; a named run also accepts `provisioning` and `failed`; and `migrating`,
  `suspended`, `moving`, `restoring`, `archived` and an unrecognised status string are refused with the status named
  (D-11). Each refused tenant's database is unchanged.
- **AC-52 [E]**: Given FX-3 (`requires_offline`, recording the mapping status from inside `up()`), When it runs on
  an `active` tenant, Then the status seen inside is `migrating`, the status afterwards is `active`; when it
  **fails**, the status afterwards is `failed` (D-12). For a migration that is **not** `requires_offline`, the status
  seen inside is `active` and is never `migrating`.
- **AC-53 [E]**: Given a warm mapping cache for tenant B, When the runner sets B's status to `migrating`, Then in the
  same process, with no clock advance, entering B's scope is refused as not served; and after the run restores
  `active` and advances the version, entering B's scope is accepted without a stale refusal (D-19).
- **AC-54 [E]**: Given a tenant whose own `migrations` table shows the target applied but whose mapping still shows an
  older `schema_version` (a crash between the two writes), When a run is executed for it, Then the mapping is
  corrected to the applied version and **no migration is re-applied** (assert the tenant's `migrations` table is
  unchanged).
- **AC-55 [E]**: Given one tenant whose mapping is behind its own `migrations` table, and one whose mapping is
  **ahead** of it, When the drift check runs, Then both are reported with their direction and the tenant at its
  correct version is not (the control). The tenant whose mapping is ahead is also refused for serving until corrected,
  because its mirror claims columns the database lacks.
- **AC-56 [E]**: Given a tenant database whose `schema_meta` row `tenant_id` names a **different** tenant, When the
  runner reaches it, Then it refuses that tenant, alters nothing in that database and records the mismatch. A
  matching row proceeds; a database with no `schema_meta` table or no such row also proceeds (nothing to contradict,
  D-14).
- **AC-57 [E]**: Given tenants at several versions, on two servers, with one `archived` at an old version, When the
  behind report is run, Then it prints, per version and per server, how many `active` and `read_only` tenants are
  there, names the oldest laggard, lists every tenant below the required version, and **does not list the archived
  one**. A tenant exactly at the required version is not listed (the control).
- **AC-58 [E]**: Given FX-4 (a contract step that depends on an expand version) and an `active` tenant still below that
  expand version, When a run (bulk or naming one tenant) is requested that would apply FX-4, Then the plan is refused
  with the blocking tenants named and **no job row is written for FX-4**. When every `active` tenant is at or above the
  expand version, the same run is planned. Tenants in status `provisioning` do not block.
- **AC-59 [E]**: Given FX-5 (`is_destructive`), When a run that would apply it is requested, Then it is refused for
  every tenant with a message saying no backup facility exists, and no tenant is altered (D-10). FX-4, which is
  contract but **not** destructive, is not refused for that reason.
- **AC-60 [E]**: Given a runner job, When it connects to a tenant database, Then it uses the **migrate** username and
  credential reference, never the app one, through the single connection-construction path T-007 established
  (a test that forces the runner to use the app credential sees a DDL statement refused by the database; **control:**
  the migrate credential's grants allow it). The T-007 isolation proof tests pass unchanged.

### F. Engines, hygiene and the gate

- **AC-61 [E]**: Given any test that creates a database, When it runs, Then it creates and drops only databases whose
  names carry the reserved test prefix, **refuses** to run against any other name including `flexipos_master`,
  `flx_t_000001` and `flx_t_000002`, and leaves no test-prefixed database behind, including after a test that fails.
  **Control:** the name guard rejects `flexipos_master`; a deliberately failing test is followed by a check that the
  prefixed database list equals the list before.
- **AC-62 [E]**: Given the master schema and the tenant baseline applied from scratch on MariaDB 10.4 and on MySQL 8.4,
  When each table is reduced to a normalised signature (column name, nullability, data-type family, key columns,
  index columns, foreign key target), Then the two signatures are equal. The data-type families the design already
  names as engine-specific (JSON as `longtext` on MariaDB, collation names) are normalised and the normalisation is
  listed in the test. **Control:** the comparison reports a fixture where one engine has one extra index.
- **AC-63**: Given an engine-dependent test and the environment naming an engine, When that engine is unreachable,
  Then the test **fails** with the engine named; it does not skip. Each engine run writes `SELECT VERSION()` into the
  test output, and `qa`'s report records, per **[E]** criterion, the engine and version it passed on. A criterion
  verified on one engine only is recorded as such and is not reported as PASS for both (D-7).
- **AC-64**: Given the branch, When `composer check` is run from `modules/host`, Then it exits 0 on the engine the
  environment names, with the existing tenancy and isolation tests still present and passing. **(diff)** No test that
  existed at the T-007 tip is deleted, skipped or weakened; `qa` compares the test list against the tip.
- **AC-65 [E]**: Given the real engines, When the runner's claim and lock behaviour (AC-47 to AC-50) is exercised, Then
  it passes on both MariaDB 10.4 and MySQL 8.4 (lock names, the single-statement claim and the lock timeout of zero
  behave identically). Recorded separately from AC-62 because it is behaviour, not shape.

### Calibration

These make the cost of the work legible without a schedule. None contains a duration.

- **AC-66 (diff)**: Given the finished branch, When `qa` writes its report, Then it includes a table of counts, each
  taken by a stated command or by `git diff --stat` against the T-007 tip: master tables created; tenant tables
  created; migration files added (master and tenant); production source files added and changed; test files added;
  tests and assertions added; the count of **[E]** criteria and, for each, the engines it ran on.
- **AC-67 (branch)**: Given the finished branch, When `qa` writes its report, Then it also records: the number of QA
  rounds, the number of defects by severity, the number of review rounds and findings by severity, the gate's wall
  time on the same machine **before** (the T-007 tip) and **after**, with the cold-or-warm state of each stated, and
  any criterion in this spec that was found unachievable as worded, with the replacement. No figure is a pass
  threshold. A figure with no stated measurement is not accepted.

## Out of scope

- Provisioning (create database, create users, seed defaults, activate): FP-206. This task creates no database and no
  database user outside the test fixtures.
- Authentication, tokens, tenant switching, login history, MFA, lockout: FP-207. The master tables for them are deferred
  (D-2). `sessions.user_id` is a bigint and `users.id` is a ULID; the mismatch is for FP-207.
- Plan limits being enforced, subscription gating, billing tables, usage metrics: FP-210.
- Any business table: catalogue, inventory, sales, finance, HR. The baseline has six tables, not 193.
- The event bus, the relay, consumers, retries, dead letter, and the `tenant.mapping.changed` event: FP-212. Only the
  outbox **table** is here.
- The audit writer: FP-211. Only the `audit_logs` table is here. Number allocation and the settings read service are
  later rows.
- Any CQRS read model, projection-state table, checkpoint or rebuild mechanism (D-1).
- Canary mode, `--pretend`, abort, pre-migration backups, plugin migrations, template import, `tenant:doctor`,
  `tenant_backfill_jobs`, run notifications (D-9). Rollback of any kind (D-8).
- Module-contributed tenant migrations (D-15).
- Rewriting `App\Models\User`, its factory or the seeder (D-18); the stock `sessions` table stays as shipped.
- Editing `docs/architecture.md`, `docs/database.md` or `CLAUDE.md`. The contradictions found are in "Open questions".

## Non-functional requirements

- **Security.** The mapping holds a credential reference and never a value (AC-6, AC-13, AC-15). The migrate credential
  never reaches the request path (AC-18). Errors stored by the runner carry no secret (AC-45). A job payload carries no
  secret (AC-41). Deny by default on tenant status (AC-51).
- **Portability.** Both engines or it is a defect (AC-35, AC-36, AC-62, AC-65). Stored generated columns inside unique
  keys are the one schema feature this task adds that `docs/database.md` already claims parity for; AC-25, AC-26 and
  AC-62 are where that claim is first tested rather than asserted.
- **Safety of the shared machine.** Tests never touch a database outside the test prefix (AC-61).
- **Performance.** No numeric target. The gate's cost is recorded, not bounded (AC-67). `composer.json`'s process
  timeout headroom from T-007 must still be adequate for the larger gate; the existing T-007 headroom check is the
  instrument and this task does not change its ratio.

## Open questions

All **non-blocking**; each has a default recorded in the decisions file.

1. **Outbox ordering for read models.** `outbox_events.id` is a ULID. A reader that resumes from "the last id I saw"
   can miss an event committed later with a smaller id (two transactions, one slow). A relay that selects `pending`
   rows is unaffected, which is the design's own model. A **projection** that checkpoints by position would be
   affected. The baseline does not add a position column (D-1); `architect` should decide when the first projection is
   designed whether one is needed. Not blocking.
2. **Owner's CQRS level 2 versus `Refference/ARCH.md` section 16** ("normal business reports should run against the
   tenant database"). The owner's directive of 2026-10-06 outranks, but `docs/architecture.md` has not been revised
   and still says projections are deleted. The architecture revision is owed and is not this task.
3. **Canary and rollout halt (D-13).** The design continues past a failed tenant. A migration bug found on the first,
   smallest tenant is therefore applied to every other tenant before anyone reads the failure. Canary mode is the
   mitigation and is out of scope. Flag for `architect`; the owner may want it before FP-206.
4. **`docs/database.md` section 6.21 versus section 9.1 (D-14).** `schema_meta` is listed with a `schema_version` row, and
   the tenant's `migrations` table is declared the authority with master as the mirror. Three copies of one counter.
   `architect` to confirm that two is right and the row should be removed from the design.
5. **`docs/database.md` section 4.10 says "Master table count: 34".** `ba` counts 39 application tables and 8 system
   tables from section 4. One of them is wrong. Not material to this task (AC-1 lists the seventeen by name).
6. **Whether the engines are available where `qa` runs.** MariaDB under XAMPP must be running for the gate, and Docker
   Desktop (currently stopped, per `CLAUDE.md`) for the MySQL 8.4 run. The lead owns that; AC-63 makes an absent engine
   a failure, not a skip.

## Assumptions

- XAMPP's MariaDB 10.4 is reachable locally with a user that can create and drop databases and users (the XAMPP
  default is `root` with no password). Not verified by `ba`.
- The shipped test configuration stays on SQLite for the tests that do not need an engine; only **[E]** criteria use a
  real server.
- A new `outbox_events` row defaults to status `pending` and attempts 0; section 6.21 gives no defaults.
- Command names (`tenant:migrate`, `tenant:schema-status`, `schema:publish`) are the design's; `architect` may name
  them otherwise and the criteria follow the behaviour, not the name.
- A bulk run's maximum job attempts is configuration with a default of 3 (D-20).
- "Behind" means a schema version below the required one for an `active` or `read_only` tenant, the section 9.2 query.
- The architect, not `ba`, decides where the fixtures, the tenant tree and the runner live; no criterion depends on a
  path.
