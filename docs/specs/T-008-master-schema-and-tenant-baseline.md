# T-008: Master schema, tenant baseline and the per-tenant migration runner

Covers backlog rows **FP-203** (master schema), **FP-204** (tenant schema baseline) and **FP-205** (per-tenant
migration runner) as one slice: a tenant schema with no runner cannot be applied, and a runner with no schema has
nothing to run. Decisions D-1 to D-21 are in `docs/decisions/T-008-master-schema-and-tenant-baseline.md`. **No
BLOCKING questions.** Revised after `architect`'s plan (commit 2867974) and its measurements against the live
engine: decisions D-22 to D-33 (and D-34 to D-36, added after the 2026-10-14 data-loss incident: AC-61 and AC-63 reworded,
the engine hold made legible) record six corrections to criteria that were not achievable as worded (AC-13, AC-19,
AC-35(d), AC-48, the "both engines" wording, and the AC-1 table-name discrepancy), a rewording of AC-56, a new AC-68,
and the plan's four upheld rulings. D-44 to D-48 (after plan `dd863c7`) record the status authority, how AC-58's
dependency is known, the fleet-wide blocking set, the statement pin behind AC-48, and the split of AC-40 from AC-51.
Engine scope is now **one engine, the one the environment names (MariaDB 10.4)**:
MySQL 8.4 is out of scope for this task by the owner's decision of 2026-10-08, and its half of the cross-engine
comparison is deferred.

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

**Which list governs (a ninth discrepancy, found by `architect`).** `docs/database.md` section 4.10 names
`personal_access_tokens` among the master's system tables. **AC-1 names `sessions` instead, and AC-1 governs** (D-18
keeps the stock `sessions` table; nothing in this task issues API tokens, so nothing needs the other). AC-1's eight
system tables are therefore `migrations`, `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`,
`password_reset_tokens`, `sessions`. A recount against the design will differ by that one name and that is not a defect.

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

- **[E]** the criterion is answered by a real database server, not SQLite: the engine the environment names, which for
  this task is MariaDB 10.4 (MySQL 8.4 is out of scope, D-24). See AC-62 to AC-65.
- **Held / NOT VERIFIED.** While `ENGINE_HOLD` is set, an **[E]** criterion cannot be answered. It is recorded **NOT
  VERIFIED (ENGINE_HOLD, G-1)**, never PASS (AC-63, D-35). This is a status, not a defect and not a pass.
- **Refusal evidence.** Any criterion whose evidence is "the insert/statement was refused" holds only if the refusal
  was observed on a connection made **through the framework's connector** (the same path the shipped code uses), never
  a hand-built one. The engine on this machine has `@@sql_mode` = `NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION`
  (no strict mode, measured by `architect` and re-verified by the lead; not re-derived by `ba`), where an over-long
  value truncates silently instead of erroring. AC-68 pins the property.
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
  `password_reset_tokens`, `sessions` (not `personal_access_tokens`, which `docs/database.md` section 4.10 lists;
  this list governs, D-27); and nothing else. A second run changes nothing and exits 0. **Controls:** the
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
  and read back, Then the decoded value equals what was written on the engine the environment names, the table's primary
  key is an auto-incrementing `BIGINT`, and indexes exist on (`tenant_id`, `created_at`) and (`action`, `created_at`).
  A string that is not valid JSON written to that column is refused (MariaDB stores JSON as text, so this holds only if
  the migration made it so). Recorded per AC-63; the MySQL 8.4 half is deferred (D-24).
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
- **AC-13 [E]**: Given a resolved mapping, When the `TenantDatabase` object is put through every serialising
  function (`json_encode`, `serialize`, `print_r`, `var_export`, `var_dump`, and an `(array)` cast), Then none of those
  outputs contains the test password. The object has no `__toString`, so a string cast is not an output to scan (it is
  a fatal error); if it ever gains one, that output is scanned too. **Control:** the same scan, run over a fixture
  object that does hold the password **and** implements `__toString` (so the string cast is exercised here only), reports
  it in every output. (D-26)
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
- **AC-19**: Given a mapping whose `schema_version` is below `APP_REQUIRED_SCHEMA_VERSION`, When a tenant scope is
  entered for it **for the serving purpose** (a request), Then it is refused with a named exception **before any network connection is attempted** (assert no
  connection was opened), the host's exception handling renders it as HTTP 503 with a `Retry-After` header, the
  message contains no credential, and entering a *different* tenant at the required version in the same process then
  succeeds. A mapping exactly at the required version is accepted (the control). **The exemption:** entering the same behind
  tenant for the **migration** purpose (the runner's) is *not* refused by this version gate, because a tenant below the
  required version is the only kind the runner ever migrates; the test has the runner enter a tenant at the required
  version minus one and succeed. The exemption covers the version gate **only**: status rules (AC-51) still apply to
  the runner, and no other refusal in this spec is waived by purpose. (D-23)
  **The floor has three states, not two (D-41).** (a) *A number* above zero is the floor, as above. (b) *An explicit
  `0`* is a recorded opt-out meaning "no floor; `tenant_databases.status` is the only guard": the tenant **is served**,
  and a test asserts it is served (so an implementation that later refuses `0` is red). (c) *The variable absent*
  (not set at all, as distinct from `0`) is a misconfiguration: for the serving purpose the scope entry is refused
  with a named exception, **before any network connection is attempted**, rendered as HTTP **503 with no
  `Retry-After` header** (a behind tenant's 503 carries one; this one must not, so the two are distinguishable at the
  wire), and the message contains no credential. The migration purpose is **exempt** from the absent-variable refusal
  (the runner is the remedy), and the application **boots** and console commands such as `tenant:migrate` run with the
  variable absent: the refusal sits at the version gate, not at boot. Absent and `0` must be tested separately; a test
  that sets neither and expects either outcome without asserting which is not a test of this clause.
  **Verification: no real engine is needed** (D-40): the inputs are integers on a mapping record and the observables
  are a named exception, a status, a header and a log line, all provable on the SQLite stand-in.
- **AC-20**: Given a mapping whose `schema_version` is **above** the required one, When a scope is entered for the
  serving purpose, Then
  it is accepted and exactly one warning is recorded for that entry, not one per query.
- **AC-21**: Given the shipped tenant migration tree, When the highest version in the catalogue is compared with
  `APP_REQUIRED_SCHEMA_VERSION`, Then the constant is not greater (a code that requires a version nothing can
  produce would 503 every tenant). **Control (D-42, replaces "the comparison fails with a constant one higher",
  which is `highest + 1 > highest` and true of arithmetic whatever was built):** the value `.env.example` ships for
  `APP_REQUIRED_SCHEMA_VERSION` **equals** the catalogue's highest published version. The equality is proven to move
  with either side by pointing the catalogue fixture key (`schema.catalogue_fixture`) at a scratch catalogue whose
  highest version is **one higher (red)** and another **one lower (red)**; two agreeing literals would otherwise
  mask each other. The old arithmetic comparison may stay in the test **labelled as documentation, not evidence**.
  **Scope:** the equality binds **`.env.example`** only, not every deployment: a live floor may legitimately lag the
  catalogue while tenants are migrated (expand-and-contract), which is why the criterion says "not greater" and the
  floor is never derived from the catalogue.

### C. The tenant baseline (FP-204)

- **AC-22 [E]**: Given an empty master and an empty tenant database, When the master migrations run, Then no tenant
  table exists in the master; When the tenant runner then applies the baseline to the tenant database, Then no master
  table exists in it. A plain `php artisan migrate` never creates a tenant table (it would otherwise run against the
  default connection, which is `master`).
- **AC-23 [E]**: Given an empty tenant database, When the baseline is applied, Then exactly these tables exist:
  `schema_meta`, `settings_registry`, `settings`, `number_sequences`, `audit_logs`, `outbox_events` and Laravel's
  `migrations`, and nothing else. In particular **there is no projection-state table and no claim-mark column** on
  `outbox_events` (D-1). `schema_meta` is created **empty**: the baseline writes no row into it (D-28). **Controls:**
  the check fails against a database with one extra table and with one missing.
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
  reads back with `.678` on the engine the environment names (millisecond precision survives). A JSON payload
  round-trips by decoded equality, and an invalid one is refused. Recorded per AC-63; the MySQL 8.4 half is deferred
  (D-24).
- **AC-28 [E]**: Given the baseline applied, When a business row (a `settings` row) and an `outbox_events` row are
  written in one transaction and the transaction is rolled back, Then neither exists; When it is committed, Then
  both exist. This is the property that makes the outbox the seam for the read side. **Control:** the same test, run
  over a fixture table created with a non-transactional engine, fails.
- **AC-29 [E]**: Given the baseline applied, When `audit_logs` is inspected, Then its primary key is an
  auto-incrementing `BIGINT`, it has the indexes section 6.21 names (on `created_at`; on `subject_type` and
  `subject_id`; on `actor_id` and `created_at`; on `module`, `action` and `created_at`), and `before`, `after` and
  `changed_fields` round-trip JSON by decoded equality and refuse invalid JSON, on the engine the environment names
  (recorded per AC-63; the MySQL 8.4 half is deferred, D-24).
- **AC-30 [E]**: Given the baseline applied, When foreign keys are listed, Then none references a table named
  `branches` or `employees`, while the columns `number_sequences.branch_id`, `audit_logs.branch_id`,
  `outbox_events.branch_id` and `settings.updated_by` exist with `CHAR(26)` and are nullable (D-5), and
  `settings.key` does reference `settings_registry`. **Control:** inserting a `settings` row with an unknown key is
  refused. **(diff)** The baseline migration's own docblock records the four unguarded columns and which module owes
  each foreign key (`number_sequences.branch_id`, `audit_logs.branch_id`, `outbox_events.branch_id` to the branches
  module; `settings.updated_by` to the employees module), and states that nothing in the database stops a bad id there
  until that key is added (D-32).

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
  (which creates `settings` with a foreign key to `settings_registry`) is not. **(branch)** The check that enforces this
  rule is committed **before the first real (shipped) migration** on the branch, so that the later foreign keys to
  `branches` and `employees` (D-5) are forced into a contract step; `qa` or `reviewer` confirms the commit order (D-32).
- **AC-35**: Given the shipped master and tenant migrations, When their source is read, Then (a) no `decimal` call
  lacks an explicit precision **and** an explicit scale (both arguments required; a defaulted scale turns a thousandth
  into zero, D-38); (b) no column uses the native `enum` type (enumerations are `VARCHAR(30)` with
  application enums, section 3.2); (c) no `timestamp` column is declared, except in the eight Laravel system tables
  named in AC-1; (d) no migration file names a charset, a collation or a storage-engine literal, with **exactly one exemption**: the
  single shared identifier-column helper, which may name only `ascii` and `ascii_bin` (AC-2 and AC-36 require ULID
  columns to be binary ASCII, which cannot be had without naming it); and no first-party code anywhere names
  `utf8mb4_0900_*` or `utf8mb4_general_ci` (so a collation MariaDB lacks cannot be written). The table-level charset and
  collation come from the connection configuration, not from migrations. (D-22) **Controls:** a fixture migration
  violating each of (a) to (d) is reported once per rule, including one naming `utf8mb4_0900_ai_ci`; the identifier
  helper is **not** reported; a fixture migration naming `ascii_bin` outside the helper **is** reported.
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
- **AC-40 [E]**: Given tenants at different `schema_version` and `size_bytes`, When a **bulk** run (one that names no
  tenant) is planned, Then the selected tenants are exactly those with status `active` or `read_only` and a version
  below the target; a tenant already at the target is not planned; a tenant with status `archived` and an old version
  is **filtered out silently**: it is not planned, the run does not throw and does not name it (control:
  an `active` one at the same version **is** planned); and jobs are ordered by ascending `size_bytes`. This criterion
  governs the **bulk** invocation only; the invocation that names a tenant is AC-51's, and it refuses instead of
  filtering. The status read is `tenant_databases.status` (D-11).
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
  the other reports it failed. The mutation that **grants** the claim is a single statement whose `WHERE` re-checks
  status and attempts, and the verdict is that statement's affected-row count; reading candidate ids beforehand grants
  nothing. (The engine has no `SELECT ... FOR UPDATE SKIP LOCKED`: it is a syntax error on 10.4.32, so select-for-update
  is not an available design. D-25.)
  **What the evidence is (D-47).** The behavioural cases (two workers, one winner, expiry, the cap) cannot prove the
  `WHERE` re-check on their own: the candidate read repeats the same terms, so they would pass with the re-check
  deleted. The evidence for "independently proven" is therefore a **statement pin**: an assertion, read from the query
  log, on the text of the executed claiming `UPDATE`, requiring that its `WHERE` re-checks status and attempts. That
  assertion is SQL-shaped and looks like duplication beside the behavioural cases. **It is not duplication; it is the
  only evidence of the re-check, and it must not be deleted or merged away.** `qa` quotes the pin itself in its
  report, not the count of behavioural cases.
- **AC-49 [E]**: Given a second database session holding the per-tenant lock for tenant B, When the runner reaches B,
  Then it does not wait: B is reported as not run because it is locked, nothing in B's database changed, and the
  remaining tenants complete. (The holder must be a separate session, because a lock is re-entrant within one.)
- **AC-50 [E]**: Given a per-server cap of 1 and two `pending` jobs on one server and one on another, When three
  workers claim, Then the first server yields one claim and the second server's job is also claimable; when the first
  claim finishes, the second job on the first server becomes claimable.
- **AC-51 [E]**: Given a tenant for each status (read from `tenant_databases.status`, D-11), When a bulk run and a run
  **naming that single tenant** are planned, Then the two invocations behave differently, and each half is its own case:
  (a) a **bulk** run accepts only `active` and `read_only` and **silently omits** every other status (no exception, as
  AC-40); (b) a **named** run accepts those two and also `provisioning` and `failed`, and **refuses with an
  exception that names the status** for `migrating`, `suspended`, `moving`, `restoring`, `archived` and an
  unrecognised status string (D-11). A reader who expects a bulk run to throw on `archived` is reading half (b) as if
  it governed (a); it does not. In both invocations each excluded or refused tenant's database is unchanged and no job
  row is written for it.
- **AC-52 [E]**: Given FX-3 (`requires_offline`, recording the mapping status from inside `up()`), When it runs on
  an `active` tenant, Then the status seen inside is `migrating`, the status afterwards is `active`; when it
  **fails**, the status afterwards is `failed` (D-12). For a migration that is **not** `requires_offline`, the status
  seen inside is `active` and is never `migrating`.
- **AC-53 [E]**: Given a warm mapping cache for tenant B, When the runner sets B's status to `migrating`, Then in the
  same process, with no clock advance, entering B's scope for the serving purpose is refused as not served; and after the run restores
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
  **What this does and does not deliver (D-28).** This is a read-only identity check in the runner. **T-008 writes
  nothing into `schema_meta`** (AC-23 asserts it is empty after the baseline, and a completed run of any tenant leaves
  it empty), so for every tenant this task can produce the "no row" path **is the shipped path**, not an edge case, and
  the identity check has nothing to contradict. The safety interlock `docs/database.md` section 6.21 describes (assert
  `schema_meta.tenant_id` equals the request's tenant before any write) is **not delivered** here: it needs the writer,
  which belongs to FP-206's provisioning step, and the request-path assertion, a later row. The mismatch case is
  exercised by a test that plants a row; nothing in the product does.
- **AC-57 [E]**: Given tenants at several versions, on two servers, with one `archived` at an old version, When the
  behind report is run, Then it prints, per version and per server, how many `active` and `read_only` tenants are
  there, names the oldest laggard, lists every tenant below the required version, and **does not list the archived
  one**. A tenant exactly at the required version is not listed (the control).
- **AC-58 [E]**: Given FX-4 (a contract step at version V) and an `active` tenant still below the expand version it
  depends on, When a run (bulk or naming one tenant) is requested that would apply FX-4, Then the plan is refused
  with the blocking tenants named and **no job row is written for FX-4**. When every `active` tenant is at or above the
  expand version, the same run is planned. Tenants in status `provisioning` do not block. The blocking set is the
  **whole fleet** the master holds, not the run's selection: a tenant left out of a named run still blocks (D-46).
  **How the dependency is known (D-45).** Nothing in the catalogue declares it: there is no tag and no field. Today
  it is **inferred as the version immediately before the contract step (V − 1)**, so a contract step at V is refused
  while any such tenant is below V − 1. This is a reading, labelled as one in the code, and not a declared
  dependency; a declared one (an optional header tag defaulting to V − 1, with a column on `schema_versions`) is a
  backlog row to be built when the first contract step depends on a version more than one back. The inference **errs
  safe**: it refuses more runs than a declared dependency would, never fewer, and it names its blockers. **The
  deliberately strict case:** a single run that would apply several expands and then the contract step is
  **refused**, even though every tenant would pass through those expands inside the same run; the expand must reach
  and settle across the fleet before the contract touches any tenant. The remedy is a two-step run: first
  `tenant:migrate --to=<V − 1>`, then the run that includes the contract. Test that refusal as its own case so
  nobody "fixes" it. Because no input in this task can distinguish the inference from a declared dependency (the
  baseline has no contract step and FX-4 and FX-5 depend on the adjacent expand), the dependency's declaration is
  **not** claimed as delivered. **(diff)** that the refusal message names `--to=<V − 1>` as the next action is
  `architect`'s plan §14.1 request for step 13a; `ba` has not seen the code.
- **AC-59 [E]**: Given FX-5 (`is_destructive`), When a run that would apply it is requested, Then it is refused for
  every tenant with a message that names the migration and says no backup facility exists, and no tenant is altered
  (D-10). The shape of the refusal is part of the criterion (D-33): it happens at **plan time**, so **zero
  `tenant_migration_jobs` rows are written for it and zero tenant connections are opened**; it is keyed on the
  catalogue's `is_destructive` flag, never on reading the migration body at run time; and it is gated by one
  configuration key (the plan names it `schema.backups.available`) whose default is false. **Controls:** FX-4, which
  is contract but **not** destructive, is **not** refused for that reason (this catches an implementation that refuses
  on the wrong field); and with the configuration key set true, FX-5 is planned (this shows the refusal is keyed on the
  key, not hard-coded).
- **AC-60 [E]**: Given a runner job, When it connects to a tenant database, Then it uses the **migrate** username and
  credential reference, never the app one, through the single connection-construction path T-007 established
  (a test that forces the runner to use the app credential sees a DDL statement refused by the database; **control:**
  the migrate credential's grants allow it). The T-007 isolation proof tests pass unchanged.

### F. Engines, hygiene and the gate

- **AC-61 [E]**: Given any test that creates a database, When it runs, Then it creates and drops only databases whose
  names carry the reserved test prefix (the plan names it `flexipos_t8test_`), **refuses** to run against any other
  name including `flexipos_master`, `flx_t_000001`, `flx_t_000002` and the two real databases on this machine,
  `flexipos-master` and `flexipos-master-new`, and leaves no test-prefixed database behind, including after a test
  that fails. **Enumeration and cleanup must not use SQL `LIKE`** (D-30, D-34): in `LIKE`, an unescaped `_` is a
  single-character wildcard, so a pattern such as `'flexipos_%'` also matches names that merely have another character
  where the underscore is, including the owner's real databases. **The set a `LIKE` matches is a function of whatever
  the engine holds at the moment the statement runs; it is not a reviewable list of names**, and this criterion
  therefore does not enumerate it (an earlier text named two hits; the same statement was later measured returning
  five once the task's own databases existed, a figure from the brief, not re-derived by `ba`). A teardown written that
  way can drop databases that belong to the owner or to other clients. The prefix test is a literal string comparison
  in code on a name a caller supplied. **No sweep (D-34, plan section 11.2 rule 4):** no code derives a list of
  databases to drop from the engine's contents; it drops only names a caller named, one at a time. **Controls:** the
  name guard rejects `flexipos_master`, `flexipos-master` and `flexipos-master-new`; a source scan of the test-support
  code that enumerates, creates or drops databases finds no SQL `LIKE`, and reports a fixture that has one; a
  deliberately failing test is followed by a check that the prefixed database list equals the list before; and every
  non-prefixed database that existed before a test run still exists after it. **The scan matches `like` only as SQL**
  (D-36): a token is reported when it contains `like` together with a SQL keyword (`select`, `show`, `from`, `where`,
  `having`, `and`, `or`, `databases`) or when `like` is immediately followed by a quote or a placeholder. Two further
  controls: an error string containing the English word "like" and no SQL keyword (for example "looks like a
  reserved name") is **not** reported, and `"show databases like 'flexipos_%'"` **is**. The scan has **no allow-list,
  exemption or suppression mechanism of any kind**; a flagged string is reworded, never exempted (D-36). Banning the
  word from messages is rejected, because it makes enforcement depend on prose style. **(diff)** The scan, its
  fixtures and the absence of any exemption mechanism are read from the branch.
- **AC-62 [E]**: Given the master schema and the tenant baseline applied from scratch on the engine the environment
  names (MariaDB 10.4), When each table is reduced to a normalised signature (column name, nullability, data-type
  family, key columns, index columns, foreign key target), Then a signature exists for every table in AC-1 and AC-23,
  is written to the test output with the engine and version, and the comparison function accepts two equal signatures.
  The data-type families the design already names as engine-specific (JSON as `longtext` on MariaDB, collation names)
  are normalised and the normalisation is listed in the test. **Control:** the comparison reports a fixture pair where
  one side has one extra index. **Deferred (D-24):** running the comparison between MariaDB 10.4 and MySQL 8.4. The
  instrument ships and runs here; only the second engine's half does not.
- **AC-63**: Three states, not two (D-35).
  (a) **Unreachable.** Given an engine-dependent test, the environment naming an engine and **no hold in force**, When
  that engine is unreachable, Then the test **fails** with the engine named; it does not skip. An unreachable engine
  is a fault, and a skip would hide it.
  (b) **Held.** Given a **declared hold** (`ENGINE_HOLD`, gate G-1: the owner's decision, recorded in the suite's
  configuration, awaiting a verified dump of all surviving databases on `D:`), When an engine-dependent test runs,
  Then any code that tries to open an engine connection **throws a named exception** (`EngineOnHold`), and only tests
  that explicitly declare the skip are absent. The hold is not "engine tests are off".
  (c) **Cleared.** Given the hold cleared by the owner, When the engine is unreachable, Then (a) applies again.
  **The hold must be legible; all four markers are required together, because any one alone decays:** (1) the flag
  sits in `modules/host/phpunit.xml` with the incident date beside it; (2) every skip message names the exception
  class, and that class's docblock carries the date, the sixteen databases, and the single clearing condition (a skip
  message that says only "ENGINE_HOLD" fails this); (3) an **inverted** test asserts that a connection attempt throws
  `EngineOnHold` while the hold is set and itself skips when it is cleared, so in either state exactly one of the two
  sets asserts something; (4) `qa` reports as in the next paragraph. **Controls:** with the hold set, the inverted
  test passes and the declared-skip tests are skipped; with the hold cleared and the engine stopped, an engine test
  **fails** (this control can be run only after the owner clears the hold, and until then it is NOT VERIFIED itself).
  **(diff)** markers 1 to 3 are read from the branch.
  **How a held criterion is reported (binding on `qa`).** Each **[E]** criterion (and every other criterion whose
  evidence needs the engine) whose engine test was skipped under the hold is recorded as **NOT VERIFIED**, naming
  `ENGINE_HOLD` and gate **G-1**. It is **never PASS**, and a skipped test is never counted as a passing test in any
  criterion's evidence. A criterion that has both a static half and an engine half is recorded per half: the static half
  may be PASS, the engine half is NOT VERIFIED, and the criterion as a whole is **PARTIAL**, not PASS. The report opens
  with one line of totals: criteria PASS, PARTIAL, NOT VERIFIED (held), FAIL, out of 68. **A task whose report has any
  held engine criterion is not reported as verified**, and the lead is told in so many words. Each engine run writes
  `SELECT VERSION()` into the test output, and `qa`'s report records, per **[E]** criterion that did run, the engine and
  version it passed on. A criterion verified on one engine only is recorded as such and is not reported as PASS for
  both (D-7); for this task every **[E]** criterion that runs is expected to be recorded as verified on MariaDB 10.4
  only.
- **AC-64**: Given the branch, When `composer check` is run from `modules/host`, Then it exits 0 on the engine the
  environment names, with the existing tenancy and isolation tests still present and passing. **(diff)** No test that
  existed at the T-007 tip is deleted, skipped or weakened; `qa` compares the test list against the tip. **Under the
  hold (AC-63) a green gate means "nothing failed", not "the engine criteria pass":** the report states the number of
  tests skipped under the hold next to the exit code, and the engine-dependent part of this criterion is NOT VERIFIED.
  Skips that are not declared under the hold remain forbidden (the T-007 rule above).
- **AC-65 [E]**: Given the engine the environment names, When the runner's claim and lock behaviour (AC-47 to AC-50)
  is exercised, Then it passes on MariaDB 10.4 (lock names, the single-statement claim and the lock timeout of zero).
  Recorded separately from AC-62 because it is behaviour, not shape. Identical behaviour on MySQL 8.4 is deferred
  (D-24).
- **AC-68 [E]**: Given the connections the shipped configuration opens for the master and for a tenant, and the same
  connections as the tests open them (through the framework's connector, not a hand-built one), When the effective
  `@@sql_mode` of each is read, Then it contains a strict mode, and an over-long value inserted into a `VARCHAR`
  column on each is **refused with an error**, not truncated. **Control:** a deliberately non-strict connection made
  in the test truncates the same value silently, which proves the check can tell the two apart. This is what gives
  every "refused" observation in this spec its meaning on an engine whose server default is not strict (D-29).

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
- MySQL 8.4 and Docker for this task, and therefore the cross-engine comparison (D-24).
- Writing any row into a tenant's `schema_meta` and the request-path interlock that reads it (FP-206 and later, D-28).
- Rewriting `App\Models\User`, its factory or the seeder (D-18); the stock `sessions` table stays as shipped.
- Editing `docs/architecture.md`, `docs/database.md` or `CLAUDE.md`. The contradictions found are in "Open questions".

## Non-functional requirements

- **Security.** The mapping holds a credential reference and never a value (AC-6, AC-13, AC-15). The migrate credential
  never reaches the request path (AC-18). Errors stored by the runner carry no secret (AC-45). A job payload carries no
  secret (AC-41). Deny by default on tenant status (AC-51).
- **Portability.** Nothing in a migration may be MariaDB-only where the design claims parity (AC-35, AC-36). The
  cross-engine comparison itself is deferred with MySQL 8.4 (AC-62, AC-65, D-24). Stored generated columns inside unique
  keys are the one schema feature this task adds that `docs/database.md` already claims parity for; AC-25, AC-26 and
  AC-62 are where that claim is first tested rather than asserted.
- **Safety of the shared machine.** Tests never touch a database outside the test prefix (AC-61). The engine is shared
  with the owner's other work and other clients' systems; a step whose red phase could issue a destructive statement
  against it is not acceptable (plan section 11.2), and no criterion here may be answered by such a step. The engine
  work is on hold (AC-63, D-35).
- **Performance.** No numeric target. The gate's cost is recorded, not bounded (AC-67). `composer.json`'s process
  timeout headroom from T-007 must still be adequate for the larger gate; the existing T-007 headroom check is the
  instrument and this task does not change its ratio.

## Findings for `qa` and `reviewer`

Read these before recounting or before "fixing" a criterion to match code. Figures marked (brief) are the lead's and
`dev`'s measurements, not re-run by `ba`.

1. **Which document governs the master table list (D-27, D-37).** AC-1 governs. `docs/database.md` section 4.10 lists
   `personal_access_tokens`; AC-1 lists `sessions`. The divergence is **intentional**, not a defect in either the
   migrations or the spec: D-18 keeps the stock `sessions` table and nothing in this task issues API tokens. A recount
   in step 15 against section 4.10 will differ by that one name; judge against AC-1.
2. **Task scope versus design total.** The design's master is 39 application plus 8 system tables (`ba`'s reading
   count, D-2). **This task builds 17 application tables**; the other 22 are deferred to the rows that use them. A
   recount against the design's 39 is a shortfall only by that deferral, and is not a defect. Judge against AC-1's 17.
3. **Shipped counts (brief, commit `38e9537`).** 17 application tables (`users` rewritten in place plus sixteen new
   files); 7 of AC-1's 8 system tables have a migration file; the eighth, `migrations`, is created by the framework
   itself, so its absence from the tree is by design. `sessions` exists and `personal_access_tokens` does not. The
   stand-in (SQLite) holds 25 tables, asserted by exact equality. That is 17 + 7 + the framework's `migrations` = 25, so
   the 25 includes `migrations` and the "7 of 8" counts migration files only. `ba` has not seen the test or the tree;
   `qa` should confirm that arithmetic once, not assume it.
4. **AC-6 stands as worded (`architect` ruling, 2026-10-15).** The detector exempts by column **type** read from the
   schema, never by name. The criterion's governing clause is "no column can hold a credential value"; its name list is
   illustration. A flag, a counter or a moment cannot hold a password, so a type rule is faithful to the clause and
   differs only from the example. Do not reword AC-6 to describe the implementation, and do not report the type-based
   rule as a divergence.
5. **AC-35(a) is stated at the stricter level (D-38).** Both precision and scale are required. See D-38.

## Open questions

All **non-blocking**; each has a default recorded in the decisions file.

1. **Backlog row for the deferred MySQL 8.4 half (D-24).** The cross-engine signature comparison (AC-62) and claim and
   lock parity (AC-65) need a named follow-up row, ideally before the first real tenant is provisioned (`architect`'s
   advice). `ba` may not edit `docs/backlog.md`; the lead should add it. Not blocking.
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
6. **Whether the engine is available where `qa` runs.** MariaDB under XAMPP must be running for the gate. Docker and
   MySQL 8.4 are out of scope for this task (D-24). The lead owns availability; AC-63 makes an **unreachable** engine a
   failure, not a skip, and distinguishes a **declared hold** (`ENGINE_HOLD`, D-35), under which engine criteria are
   NOT VERIFIED. **Clearing the hold is the owner's decision**, after G-1 (a verified dump of all surviving databases
   on `D:`); no agent clears it, and until it is cleared this task cannot be reported as verified.

Answered and removed: the former question 1 (outbox ordering for read models) is answered by `architect` and recorded
as D-31.

## Assumptions

- XAMPP's MariaDB 10.4 is reachable locally with a user that can create and drop databases and users (the XAMPP
  default is `root` with no password). Not verified by `ba`.
- The shipped test configuration stays on SQLite for the tests that do not need an engine; only **[E]** criteria use a
  real server.
- A new `outbox_events` row defaults to status `pending` and attempts 0; section 6.21 gives no defaults.
- Command names (`tenant:migrate`, `tenant:schema-status`, `schema:publish`) are the design's; `architect` may name
  them otherwise and the criteria follow the behaviour, not the name.
- The shipped master and tenant connections are strict-mode connections, or can be made so by the framework's own
  connection option; if they are not and cannot be, AC-68 fails and `architect` decides (`ba` does not choose the
  server setting).
- The first projection or relay checkpoint will use a claim mark (a nullable mark column on the source row), never a
  cursor or a `position` column (D-31). It is not built here.
- A bulk run's maximum job attempts is configuration with a default of 3 (D-20).
- "Behind" means a schema version below the required one for an `active` or `read_only` tenant, the section 9.2 query.
- The architect, not `ba`, decides where the fixtures, the tenant tree and the runner live; no criterion depends on a
  path.
