# Plan: T-008 Master schema, tenant baseline and the per-tenant migration runner

Covers **FP-203**, **FP-204**, **FP-205** as one slice. Spec: `docs/specs/T-008-master-schema-and-tenant-baseline.md`
(67 criteria). Decisions: `docs/decisions/T-008-master-schema-and-tenant-baseline.md` (D-1 to D-21). Binding patterns:
`docs/patterns.md` (vertical slices §3, CQRS scoped §2/§5). Build order: `docs/domain-core.md` §8, which puts this
first and says nothing reaches a tenant without it.

Everything measured in this plan was measured on **2026-10-14** on this machine, by the command quoted beside it.

---

## 0. Rulings asked for, decided here

`ba` had no messaging tool and asked for four. All four are **upheld**, three with an added replacement — because a
ruling that specifies a replacement is work, not a backlog row (the rule I drew on T-007 after filing a ruling beside
six rows that nobody then implemented).

**D-1 — the baseline carries only the outbox. UPHELD, unamended.**
`docs/patterns.md` §5 is now the governing rule and it is stricter than D-1: *a projection is added when a measured
query is too slow or too coupled, with the measurement in the task's QA report.* There is no measurement, there is no
projection, and there is no read model in scope, so a projection-state table would break the pattern document the
owner pinned on 2026-10-14. `ba`'s honest cost — a later `ALTER` on the busiest append-only table in every tenant —
is bounded three ways: the add is expand-only and nullable, the table has 30-day retention so there is nothing to
backfill, and `ADD COLUMN` at the end of a table is `ALGORITHM=INSTANT` on both engines (step 0 pins that as an
assertion rather than a belief). Against that, the claim mark's *shape* is unknown — a worker id, a lease expiry, or
both — and FP-212 is the row that will know. One guessed column becoming two is a contract step across every tenant,
which is the expensive class.
Two things are decided now so nobody has to guess later:
- **Open question 1 is answered: when the first projection or relay checkpoint is designed it uses a claim mark — a
  nullable mark column on `outbox_events` — never a cursor and never a position column.** `docs/patterns.md` §5 states
  why: `outbox_events.id` is a ULID, so a reader resuming from the last id it saw misses an event committed later with
  a smaller id. Recording the ruling here means the alternative cannot be re-litigated into a `position BIGINT` column
  by someone who meets the ordering problem first.
- The relay FP-212 actually ships first does **not** need a mark: it selects `status = 'pending'` ordered by
  `occurred_at`, which the `(status, occurred_at)` index AC-27 pins already serves. Marks are needed only when several
  relay workers run, and that is the migration FP-212 opens with.

**D-5 — no foreign keys to `branches` or `employees`. UPHELD, with the replacement built in this task.**
A key to a table that does not exist cannot be created, so there is no alternative. The replacement is what stops the
columns staying unguarded by accident: step 2 ships the **expand/contract static check (AC-34)** before any real
migration exists, so the later addition of those four keys is *forced* into a contract migration, and step 4 records
the three columns and their owing rows in the migration's own docblock — `number_sequences.branch_id`,
`audit_logs.branch_id`, `outbox_events.branch_id` (branches module) and `settings.updated_by` (HR/employees module).
Until then nothing in the database stops a bad id; that is stated in the migration, not only in the decisions file.

**D-10 — a destructive migration is refused until a backup mechanism exists. UPHELD, with the replacement specified.**
Fail-closed is right. The replacement is the *shape* of the refusal, because "refuses" can be implemented in four
places and only one of them is safe:
- the refusal happens at **plan time**, before a single `tenant_migration_jobs` row is written and before any tenant
  connection is opened (same point as AC-32's catalogue refusal), so AC-59's "no tenant is altered" is structural
  rather than lucky;
- it is keyed on `schema_versions.is_destructive` from the catalogue, never on reading the migration body at run time;
- the gate is one configuration key, `schema.backups.available`, default `false`, so the row that ships backups flips
  one line instead of hunting for a thrown exception. The message names the migration and the missing facility.
- Negative control: FX-5 (destructive) is refused. Positive control: FX-4 (contract, not destructive) is **not**
  refused for that reason — which is the control that catches an implementation that refuses on `phase = contract`.

**D-14 — two copies of the schema version, not three. UPHELD.**
The tenant's own `migrations` table is the authority, `tenant_databases.schema_version` is the mirror, and
`schema_meta` holds no version row. The runner reads `schema_meta.tenant_id` and nothing else there. `docs/database.md`
§6.21 is wrong against §9.1 and the correction is a document row, not a code change (doc edits are out of scope per the
spec).
One consequence `ba` did not state and that must not be read as delivered: **T-008 writes nothing into `schema_meta`
at all**, so the safety interlock §6.21 describes ("before any write, assert `schema_meta.tenant_id` equals the request
context") is inert for every tenant this task can produce. AC-56's "a database with no such row proceeds" is therefore
the shipped path, not an edge case. The writer is FP-206's provisioning step and the interlock itself is a later row.
Flagged to `ba` so the spec does not read as if T-008 delivers an interlock.

---

## 1. Approach summary

Five artefacts, in this order, because each later one is untestable without the earlier: **an allow-list database-name
guard, proven red** · **static migration rules, proven red on fixtures before the real tree exists** · **the master
migration tree** · **the tenant migration tree** · **the runner, as vertical slices**.

- **The master tree stays where Laravel put it** (`modules/host/database/migrations/`), because the default connection
  is already `master`. The stock `users` migration is rewritten in place to the ULID master `users`; `sessions` and
  `password_reset_tokens` stay exactly as shipped (D-18). The other sixteen tables arrive as one migration file per
  concern.
- **The tenant tree is a second directory Laravel does not auto-discover** (`modules/host/database/migrations-tenant/`),
  one file per baseline table. That single choice is what makes AC-22 structural: `php artisan migrate` cannot reach it.
- **The runner is kernel code in vertical slices** (`packages/kernel/src/Schema/Features/<Feature>/`), one folder per
  feature holding its command or query, its handler and its artisan entry point. The kernel is where the tenancy seam
  already lives, it is already in the PHPStan and boundary registries, and it depends on no other layer.
- **The mapping reader is not a slice.** It is a driver behind an existing port (`TenantDatabaseRepository`), so it stays
  beside its port in `packages/kernel/src/Tenancy/`. Slices are for features; an adapter for a published contract lives
  with the contract. Saying this once stops the next twenty tasks turning `Features/` into a second `Services/`.
- **Everything that touches a real engine goes through one test-support package** (`packages/testing/src/Engine/`),
  whose first class is the name guard. Nothing else in the suite may issue `CREATE DATABASE` or `DROP DATABASE`, and a
  static test asserts that.
- **The runner enters a tenant through the T-007 choke point, not beside it.** `Tenancy::enter()` gains a *purpose*
  (`serving` or `migrating`) which selects the mapping repository; both purposes write their stanza through
  `TenantConnectionAuthority` exactly as today. That keeps AC-60's "single connection-construction path" and every
  T-007 isolation proof intact, and it is what lets AC-18 hold — the serving mapping type has no migrate fields at all.

## 2. Alternatives rejected

- **Runner in `modules/host/app/`.** Rejected: the mapping reader must be in the kernel anyway (it implements a kernel
  contract and `TenancyServiceProvider` binds it), and splitting the runner from the reader puts one feature in two
  layers — the exact smear `docs/patterns.md` §3 exists to prevent.
- **A new `packages/schema` package.** Rejected: it buys one boundary we do not need and costs a Composer path package,
  a PHPStan path, a Deptrac layer and ruleset entry, and a `QaToolchainRegistryCoverageTest` update. The kernel already
  owns tenancy.
- **Tests inside the slice folder, as `docs/patterns.md` §3 draws it.** Rejected for kernel code, and the reason is
  measured in `modules/host/phpunit.xml`'s own comment: the suites collect `packages/*/tests`, so a `*Test.php` under
  `packages/kernel/src/` is collected by nothing and never runs while the gate stays green. The slice's test is
  **mirrored** at `packages/kernel/tests/Schema/Features/<Feature>/` on the same leaf path, and step 13 adds the static
  test that a slice without its mirror is a failure. (For a *module* the §3 layout works as written — Deptrac matches
  layers by namespace, not directory — so this exception is kernel-specific and should not be copied into modules.)
- **Swapping the `TenantDatabaseRepository` binding while the runner runs**, instead of a purpose argument. Rejected:
  a mutated container binding is exactly the kind of invisible state T-007's review removed, and it would let a request
  resolve the migrate credential if the swap leaked.
- **`SELECT … FOR UPDATE SKIP LOCKED` for claiming.** Rejected because it does not exist. MEASURED on this machine:
  `SELECT 1 FROM mysql.user LIMIT 1 FOR UPDATE SKIP LOCKED` → `ERROR 1064 … near 'SKIP LOCKED'` on 10.4.32 (MariaDB
  added it in 10.6). `NOWAIT` parses, and `GET_LOCK('…',0)` returns 1. The claim is therefore the design's own
  single-statement `UPDATE … WHERE id = ? AND status IN (…) AND attempts < :max`, with the affected-row count as the
  verdict.
- **Enumerating or cleaning up test databases with SQL `LIKE`.** Rejected, and this is the most important rejection in
  the plan. MEASURED: `SHOW DATABASES LIKE 'flexipos_%'` returns **`flexipos-master` and `flexipos-master-new`** — the
  two databases the owner flagged as the near-collision hazard — because `_` is a single-character wildcard in `LIKE`.
  The escaped form `'flexipos\_%'` returns nothing. A teardown written the obvious way would have dropped them. All
  enumeration reads `information_schema.schemata` and filters in **PHP** with `str_starts_with`. Comparison in SQL is
  also refused for a second measured reason: `SELECT schema_name … COLLATE utf8mb4_bin` errors on 10.4 with
  `COLLATION 'utf8mb4_bin' is not valid for CHARACTER SET 'utf8'`.

---

## 3. Judgement on the eight findings `ba` reported

1. **Master table count, 34 against 39+8.** `ba` is right that one of them is wrong, and it is not material: AC-1 names
   the seventeen by name. `qa` recounts once, by command, against the migrated master
   (`SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()`), and the document correction is a
   backlog row. **A ninth discrepancy, found here:** §4.10 lists `personal_access_tokens` among the master system
   tables; AC-1 lists `sessions` instead. AC-1 governs — D-18 keeps the stock `sessions` table and nothing issues API
   tokens yet — but `qa`'s recount will trip over it, so `ba` should add the one-line note.
2. **Three copies of the counter.** D-14 upheld; see §0.
3. **`number_sequences` and `settings` carry the NULL-in-unique-key defect.** Agreed, and D-6 is applied here as the
   first per-tenant migration, which is what the FP-180 row demands. One guard-rail added: §3.3 says the `'-'` sentinel
   is correct **only for a ULID column**, and both sites here are `CHAR(26)`, so `'-'` is right at both. Step 4 states
   that in the migration docblock so nobody copies the sentinel onto a `VARCHAR(30)` enumeration column, where `'-'`
   is a legal value.
4. **Foreign keys to tables that do not exist.** D-5 upheld, with AC-34's check shipped first; see §0.
5. **`outbox_events.id` is a ULID, so a resuming reader can miss events.** Load-bearing and now ruled on: claim mark,
   never a cursor, never a position column (§0, D-1).
6. **`sessions.user_id` bigint against `users.id` ULID.** Agreed out of scope (FP-207). One cheap guard so it cannot
   bite silently: step 3 asserts `sessions` carries **no foreign key** to `users` (the stock migration ships none), so
   the mismatch cannot surface as an insert failure, and records in the migration that FP-207 must change both
   together. Nothing else.
7. **The host's tests run on in-memory SQLite and nothing reaches a real engine.** This is the finding T-008 exists to
   close. The engine suite is a Pest group `engine`, part of `composer check` (AC-64), and it is **not** excluded from
   `composer test:fast`: an engine test that only the slow path runs is an engine test nobody runs. If step 15's
   measurement shows the fast loop regressing past the T-007 figure by more than its existing headroom ratio, adding
   `engine` to `test:fast`'s exclusions is a separate decision **with the measurement attached** — not a judgement call
   made while tired.
8. **The owner's CQRS level against `Refference/ARCH.md` and `docs/architecture.md`.** `docs/patterns.md` §8 owns it and
   this plan touches neither document. Nothing in T-008 depends on the answer, because the only artefact involved is
   the outbox table, which both readings keep.

---

## 4. Files to change / create

Paths are as the repository is today (`modules/`, `module:make`); `CLAUDE.md`'s pre-rename vocabulary note is stale
and that is FP-202's own record, not this task's.

### Test support — the safety layer (step 0, nothing else may exist first)

- `packages/testing/src/Engine/ReservedDatabaseName.php` — **new.** The allow-list. One static
  `assert(string $name): void` that throws unless the name starts with the reserved prefix, is entirely lowercase
  (`lower_case_table_names = 1` on this machine, MEASURED, so a mixed-case request would be created folded and then
  not match its own guard), contains only `[a-z0-9_]`, and is at most 64 characters. Everything else is refused; there
  is no deny-list anywhere in the task.
- `packages/testing/src/Engine/EngineDatabase.php` — **new.** The only code in the repository permitted to issue
  `CREATE DATABASE` or `DROP DATABASE`. Calls `ReservedDatabaseName::assert()` at the point of effect on **every**
  call, including `drop()` and the sweep, not once at planning time. Enumeration is
  `SELECT schema_name FROM information_schema.schemata` filtered in PHP by `str_starts_with` — never SQL `LIKE` (§2).
- `packages/testing/src/Engine/EngineConnection.php` — **new.** Builds the engine connections from environment, fails
  (never skips) with the engine named when it is unreachable, and writes `SELECT VERSION()` into the test output
  (AC-63).
- `packages/testing/src/Engine/ThreeTenantFixture.php` — **new.** Tenants A (empty), B (baseline applied) and C
  (baseline applied, FX-2 fails on it). Creates and drops only reserved-prefix databases, through `EngineDatabase`.
- `modules/host/tools/clean-engine.php` — **new.** Sweeps leftover reserved-prefix databases, wired **first** in
  `composer check` beside `probes:clean`, for the same reason that one is first: a killed run leaves artefacts and a
  test hook never gets to run.
- `modules/host/tests/Engine/ReservedDatabaseNameGuardTest.php` — **new.** The red-first guard proof; controls in §7.
- `modules/host/tests/Engine/EngineCapabilityTest.php` — **new.** Pins the six engine facts this design rests on as
  assertions rather than beliefs: `SKIP LOCKED` absent, `NOWAIT` present, `GET_LOCK(…,0)` non-blocking,
  default storage engine InnoDB, a stored generated column is indexable inside a unique key, `ADD COLUMN` at the end
  is instant, and MariaDB's `JSON` alias behaviour for invalid JSON (which decides whether step 3/4 must add an
  explicit `CHECK (JSON_VALID(col))` — see §5).

### Master schema (steps 3)

- `modules/host/database/migrations/0001_01_01_000000_create_users_table.php` — **changed in place.** `users` becomes
  the design's master table: `id CHAR(26)` `ascii_bin`, `email VARCHAR(191)` unique, `password_hash`,
  `is_platform_staff`, `platform_role` nullable, `status`, `failed_attempts`, `locked_until`, and the rest of §4.1.
  `password` and `remember_token` are gone (AC-2). `password_reset_tokens` and `sessions` in the same file are
  untouched (D-18).
- `modules/host/database/migrations/2026_10_14_0001xx_*.php` — **new**, one file per concern, in dependency order:
  plans → plan_features / plan_limits (need `plans`) and feature_flags → tenants (FK `plans`) → tenant_users →
  subscriptions → licences → tenant_feature_flags → platform_settings → platform_audit_logs → database_servers →
  tenant_databases → schema_versions → tenant_migration_runs → tenant_migration_jobs.
- `modules/host/database/migrations/0001_01_01_000001_create_cache_table.php`,
  `…_000002_create_jobs_table.php` — **unchanged.** They supply five of AC-1's eight system tables.
- `modules/host/app/Models/User.php`, `database/factories/UserFactory.php`, `database/seeders/DatabaseSeeder.php` —
  **unchanged** (D-18). The gate must stay green with them; if the factory writes `password`, that is a fix in the
  factory's own step and is called out as a risk in §8.

### Tenant baseline (step 4)

- `modules/host/database/migrations-tenant/0001_…_create_schema_meta_table.php` through
  `0006_…_create_outbox_events_table.php` — **new**, one file per table, in the order `schema_meta`,
  `settings_registry`, `settings`, `number_sequences`, `audit_logs`, `outbox_events`. One table per file so that each
  gets its own catalogue version and AC-31's "strictly increasing in file-name order" has something to be true of.
- `modules/host/database/catalogue/tenant_schema_versions.json` — **new.** The committed catalogue fixture AC-31
  compares against.

### Kernel — the mapping reader and the serving gate (steps 6, 7)

- `packages/kernel/src/Tenancy/TenantDatabase.php` — **changed.** Gains `schemaVersion` (int) and `credentialVersion`
  (int) as the last two constructor parameters, with defaults so no existing call site changes (AC-12, AC-19). Still
  no migrate username, no migrate credential reference, no password (AC-18).
- `packages/kernel/src/Tenancy/MasterTenantDatabaseRepository.php` — **new.** The single joined read of
  `tenant_databases` + `database_servers` on the `master` connection. Returns null — never a default — for an unknown
  tenant, a missing mapping, or a mapping whose server row is absent (AC-12).
- `packages/kernel/src/Tenancy/CachingTenantDatabaseRepository.php` — **new.** The 300-second decorator, key
  `tenant:conn:<tenant_id>` with the credential version as a *value* inside, plus `evict(tenantId)` (AC-14, AC-17).
  Caches the mapping, never a resolved secret (AC-15).
- `packages/kernel/src/Tenancy/TenantConnectionPurpose.php` — **new** enum: `Serving`, `Migrating`.
- `packages/kernel/src/Tenancy/Contracts/TenantMigrationTargetRepository.php` and
  `packages/kernel/src/Tenancy/TenantMigrationTarget.php` — **new.** The runner's own mapping type, which *does* carry
  `migrateUsername` and `migrateCredentialRef`, and still no password. This is AC-18's control object.
- `packages/kernel/src/Tenancy/Tenancy.php` — **changed.** `enter()` takes a purpose, selects the repository from it,
  and inserts the schema-version gate between the existing status check (step 4) and the credential dereference
  (step 6) — before any network connection, as AC-19 requires. The gate applies to `Serving` only; see §9.
- `packages/kernel/src/Tenancy/Exceptions/TenantSchemaBehind.php`, `TenantSchemaAhead.php` — **new**, named
  exceptions carrying no credential.
- `packages/kernel/src/Tenancy/TenancyServiceProvider.php` — **changed.** Binds the master-backed reader behind the
  cache decorator when `tenancy.mapping.source` is `master` (default), falling back to the in-memory one when it is
  `config` — so T-003's and T-007's tests keep their fixture path and AC-12's "shipped configuration, no test
  override" has a real default to exercise.
- `modules/host/bootstrap/app.php` — **changed.** `withExceptions` renders `TenantSchemaBehind` as HTTP 503 with
  `Retry-After` (AC-19).
- `modules/host/config/tenancy.php` — **changed.** Adds `mapping.source`, `mapping.ttl` (300), and
  `required_schema_version` read from `APP_REQUIRED_SCHEMA_VERSION`.
- `modules/host/.env.example` — **changed.** Documents `APP_REQUIRED_SCHEMA_VERSION` and the engine test variables.

### Kernel — the runner, as vertical slices (steps 2, 5, 8 to 13)

One folder per feature, each holding the command or query, its handler and its artisan entry point. Mirrored test
folders under `packages/kernel/tests/Schema/…` (see §2 for why they cannot live in the slice folder).

```
packages/kernel/src/Schema/
  Support/                         shared, and each item is here because three callers need it
    MigrationSet.php               a named set of migration paths + its catalogue (the FX seam)
    MigrationCatalogue.php         version, migration, phase, checksum, is_destructive, requires_offline
    MigrationPhase.php             expand | backfill | contract
    SourceRules.php                the static rules of AC-33/34/35/37, as one readable pass over a file
    IdentifierColumn.php           the ONE site that names ascii / ascii_bin (see §5 and §9)
    PerTenantLock.php              GET_LOCK(…, 0) on the tenant connection
    RunnerClock.php                injectable now(), so AC-47's expiry has an input that can move
  Features/PublishCatalogue/       PublishCatalogue, …Handler, PublishCatalogueCommand   (schema:publish)
  Features/PlanMigrationRun/       PlanMigrationRun, …Handler
  Features/ClaimMigrationJob/      ClaimMigrationJob, …Handler
  Features/MigrateTenant/          MigrateTenant, …Handler, MigrateTenantQueueJob
  Features/RunTenantMigrations/    RunTenantMigrations, …Handler, TenantMigrateCommand    (tenant:migrate)
  Features/RetryFailedJobs/        RetryFailedJobs, …Handler
  Features/RepairSchemaVersion/    RepairSchemaVersion, …Handler         (AC-54)
  Features/DetectSchemaDrift/      DetectSchemaDrift (query), …Handler   (AC-55)
  Features/ReportSchemaStatus/     ReportSchemaStatus (query), …Handler, SchemaStatusCommand  (tenant:schema-status)
  SchemaServiceProvider.php        registers the three commands and the queue job
```

- `modules/host/bootstrap/providers.php` — **changed.** Registers `SchemaServiceProvider`.
- `modules/host/config/schema.php` — **new.** `required_version`, `max_attempts` (3, D-20), `claim_ttl_minutes` (30),
  `per_server_limit`, `queue` (`migrations`), `backups.available` (false, D-10), `tenant_migration_path`,
  `catalogue_fixture`, and the reserved test prefix (read by the guard so the prefix has exactly one home).

### Fixtures and tests

- `modules/host/tests/Fixtures/TenantMigrations/FX1…FX6/` — **new.** The six fixture migrations the spec defines, plus
  the rule-violating fixtures AC-33/34/35/37 need as controls. Never under `database/migrations-tenant`, and step 13
  asserts that by path.
- `modules/host/tests/Engine/…` — **new.** Every `[E]` criterion. Grouped `engine`.
- `modules/host/tests/Feature/MigrationSourceRulesTest.php` — **new.** AC-33 to AC-37, source-only, no engine.
- `modules/host/tests/Feature/VerticalSliceLayoutTest.php` — **new.** Every `Schema/Features/<X>/` has a handler and a
  mirrored test folder (the first executable form of `docs/patterns.md` §3, which §8 of that document records as not
  yet enforced anywhere).
- `packages/kernel/tests/Schema/Features/**` and `packages/kernel/tests/Unit/TenantDatabaseTest.php` — **changed**:
  the exact-property-list assertion grows by `credentialVersion` and `schemaVersion`. This is a *pin following a
  deliberate contract change*, not a weakening, and the sibling test that forbids any password-shaped property name
  stays untouched and still covers both new names. Called out because AC-64 forbids weakening a T-007 test and `qa`
  will see the diff.
- `modules/host/phpunit.xml` — **changed.** Adds the engine environment variables. The SQLite default for everything
  else is untouched (Assumption in the spec, and T-003's tests depend on it).
- `modules/host/composer.json` — **changed.** `clean-engine` in `check`; no new dependency.
- `modules/host/phpstan.neon.dist`, `modules/host/deptrac.yaml` — **changed** only if a new path appears. With the
  layout above, none does: `packages/kernel/src`, `packages/kernel/tests`, `packages/testing/src`,
  `modules/host/database`, `tests` and `tools` are all already registered. `QaToolchainRegistryCoverageTest` is the
  instrument that will say otherwise; believe it over this paragraph.

---

## 5. Data / API changes

### The reserved test prefix, and why it is what it is

**`flexipos_t8test_`.** MEASURED on this machine, 2026-10-14: `SHOW DATABASES LIKE 'flexipos_t8_%'`,
`'flxtest_%'`, `'fpt_%'` and `'flexipos_test_%'` all return zero rows, *even unescaped*, while
`'flexipos_%'` returns `flexipos-master` and `flexipos-master-new`. The engine holds **56** schemas, not the 31 in the
brief — four `untouched_*`, six `u_<date>x<time>` and three `kokobeach_*` dated copies have appeared, which is a
working machine, not a static one, so the guard must be a rule and not a reviewed list. Step 0 re-measures the chosen
prefix's wildcard expansion as its first assertion and `qa` records the output.

### Master schema, the shapes that are not obvious

- **Types come from the connection, not from the migration.** Laravel's MySQL/MariaDB grammar appends
  `default character set … collate …` to every `CREATE TABLE` from the **connection** configuration, and
  `config/database.php` already pins `utf8mb4` / `utf8mb4_unicode_ci` there. That is what satisfies AC-36's `utf8mb4`,
  AC-8(a)'s case-insensitive email uniqueness and AC-35(d)'s "no collation named in a migration" **simultaneously**.
  Nothing in a migration file names a charset, a collation or an engine; `@@default_storage_engine` is InnoDB
  (MEASURED).
- **ULID identifier columns are the one exception, and they need one.** `$table->ulid()` yields `CHAR(26)` in the
  *table's* charset — `utf8mb4_unicode_ci`, which is four bytes per character and compares case-insensitively — while
  AC-2 and AC-36 demand `ascii_bin`. So `ascii`/`ascii_bin` must be named somewhere. It is named in exactly one place,
  `Schema\Support\IdentifierColumn`, and AC-35(d)'s check exempts that one symbol and nothing else. See §9: AC-35(d)
  is **unachievable as worded** and this is the replacement.
- **`[TS]` is `dateTime('created_at', 3)` and `dateTime('updated_at', 3)`, never `$table->timestamps()`**, because
  AC-35(c) forbids a `timestamp` column outside the eight stock system tables and AC-36 requires precision 3.
- **Enumerations are `string(…, 30)`**, never the native `enum` type (AC-35(b), §3.2).
- **`tenants.sequence` needs one raw statement.** It is `INT UNSIGNED AUTO_INCREMENT UNIQUE` on a table whose primary
  key is a ULID, and Laravel's MySQL grammar emits `auto_increment primary key` together for `autoIncrement()`, which
  would declare a second primary key. The column is created plain with its unique index and then one
  `ALTER TABLE tenants MODIFY sequence INT UNSIGNED NOT NULL AUTO_INCREMENT` statement follows in the same migration.
  This is what makes AC-8(b)'s "two tenants inserted without a sequence get two different ones" true at the database
  level rather than in a service nobody tested.
- **JSON validity has to be checked, not assumed.** MariaDB's `JSON` is a `LONGTEXT` alias. AC-10, AC-27 and AC-29
  require invalid JSON to be *refused*. Step 0's capability test measures whether the alias carries an automatic
  `CHECK (json_valid(col))` on 10.4.32; if it does not, the migration adds the check explicitly with one raw statement
  per JSON column. Either way the refusal is asserted against a real insert, not inferred from the type name.
- **Strict mode comes from the connection too.** `@@sql_mode` on this server is
  `NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION` — **no strict mode** (MEASURED). Laravel's `'strict' => true`
  sets it per session, so every engine test must connect **through the Laravel connector**, never a bare PDO, or a
  refusal criterion will silently pass as a truncation.
- **Keys.** `tenant_databases`: PK `tenant_id`; unique `database_identifier`; unique (`server_id`, `database_name`);
  indexes on `schema_version`, `status`, (`server_id`, `status`). `tenant_migration_jobs`: unique (`run_id`,
  `tenant_id`), index (`run_id`, `status`), index (`status`, `lock_expires_at`) — the last one is what the claim
  statement rides.
- **Deletes.** Foreign keys are left at the engine default `RESTRICT` (AC-7). `CASCADE` is named only on
  `tenant_migration_jobs.run_id`, which is a pure child.

### Tenant baseline, the shapes that are not obvious

- **No `tenant_id` column anywhere** (AC-24). `schema_meta` holds it as a row and is created **empty** (D-14).
- **Two stored generated sentinels** (D-6, §3.3):
  `settings.scope_id_key CHAR(26) GENERATED ALWAYS AS (COALESCE(scope_id, '-')) STORED`, unique
  (`scope_type`, `scope_id_key`, `key`); and
  `number_sequences.branch_id_key CHAR(26) … (COALESCE(branch_id, '-')) STORED`, unique
  (`branch_id_key`, `document_type`). `'-'` is correct **only** because both source columns are `CHAR(26)` ULIDs; the
  migration says so, because the next person to copy the pattern onto a `VARCHAR(30)` enumeration will be wrong.
- `outbox_events`: `id CHAR(26)` `ascii_bin`, `occurred_at DATETIME(3)`, `status` default `pending`, `attempts`
  default 0, indexes (`status`, `occurred_at`) and (`aggregate_type`, `aggregate_id`). Exactly §6.21's columns and no
  claim mark (D-1).
- No foreign key to `branches` or `employees`; the four columns exist, `CHAR(26)`, nullable (D-5).
  `settings.key` **does** reference `settings_registry` (AC-30's control depends on it).

### API / commands / configuration

- `php artisan schema:publish` — writes `schema_versions` from the tenant tree and the committed fixture; idempotent;
  refuses a new file that sorts before an already-published one. **Checksums are computed over LF-normalised file
  content**, because this repository is developed on Windows and a CRLF checkin would otherwise make the committed
  fixture machine-dependent.
- `php artisan tenant:migrate [--tenant=|--all] [--to=] [--run= --retry-failed] [--concurrency=] [--per-server=]`
- `php artisan tenant:schema-status` — the behind report (AC-57) and the drift report (AC-55).
- `APP_REQUIRED_SCHEMA_VERSION` — the constant AC-19 and AC-21 turn on. Ships at the baseline's highest version.
- No migration ever rolls back; there is no `down()` (D-8).

---

## 6. Implementation steps

Seventeen steps, against T-007's nine. Owner in brackets. **Where the order is load-bearing it says so and why** —
elsewhere steps may be reordered or run together.

- [ ] **Step 0 — the allow-list name guard, proven red, with a positive control beside it.** [dev]
      `ReservedDatabaseName`, `EngineDatabase`, `EngineConnection`, `tools/clean-engine.php` wired first in
      `composer check`, and `ReservedDatabaseNameGuardTest`.
      **Red first, explicitly:** the test is written against a stub `ReservedDatabaseName` that returns true for every
      name; `dev` records the red output in the step's commit message before replacing the stub. Without that, the
      guard's test is one of the many here that would pass against an empty implementation.
      **Negative controls (must be refused):** `flexipos_master`, `flexipos-master`, `flexipos-master-new`,
      `flx_t_000001`, `flx_t_000002`, `pms`, `demo_pos`, `flexhms_glow`, `mysql`, the empty string,
      `FLEXIPOS_T8TEST_X` (uppercase), `flexipos_t8test_../x`, and a 70-character reserved-prefix name.
      **Positive controls (must be allowed, or a guard that refuses everything passes the absence test):**
      `flexipos_t8test_a`, `flexipos_t8test_master`, `flexipos_t8test_tenant_a`; and the sweep, pointed at a fixture
      list of `flexipos_t8test_a`, `flexipos-master`, `pms`, returns exactly `flexipos_t8test_a`.
      **One more assertion that is the whole reason this step exists:** the sweep's own enumeration, written the wrong
      way as a `SHOW DATABASES LIKE` with an unescaped underscore, returns `flexipos-master` — asserted as a *fact
      about the engine*, so the reason for the `str_starts_with` rule is pinned in a test and cannot be optimised away
      by a later reader who thinks `LIKE` is tidier.
      Covers AC-61, and AC-63's fail-not-skip half.
      **Order is load-bearing: nothing in any later step may create or drop a database, and no migration, runner or
      fixture code may exist in the branch, until this step is on disk and green.** This is the one place in T-008
      where a mistake destroys something that is not ours and that this team cannot restore.
- [ ] **Step 1 — engine capability probes as assertions.** [dev] `EngineCapabilityTest`: `SKIP LOCKED` is a syntax
      error, `NOWAIT` parses, `GET_LOCK(name, 0)` returns immediately, default engine InnoDB, a stored generated
      column is usable inside a unique key, `ADD COLUMN` at the end of a table is instant, and what MariaDB's `JSON`
      alias does with invalid JSON. Each is a design assumption of steps 4, 10 and 11; measuring them here is cheaper
      than discovering one at step 11. Covers nothing on its own; de-risks AC-10, AC-25 to AC-27, AC-29, AC-48 to AC-50.
      *Order: after step 0, because it needs a reserved-prefix database to create a table in.*
- [ ] **Step 2 — the static migration rules, proven red on fixtures, before a single real migration exists.**
      [dev] `Schema\Support\SourceRules` + `MigrationSourceRulesTest` + the violating fixtures.
      AC-33 (an expand step must not drop, rename or change), AC-34 (no foreign key onto an existing table outside a
      contract step), AC-35(a) bare `decimal()`, (b) native `enum`, (c) `timestamp`, (d) charset, collation or engine
      literal, AC-37 (cross-connection and schema-qualified names), AC-24's source half.
      Each rule is asserted **red against its own fixture first and only then green against the tree**. This step is
      before steps 3 and 4 deliberately: run the other way round, every rule passes against a tree of zero tenant
      migrations and proves nothing, which is exactly this project's named failure mode.
      *Order load-bearing: before steps 3 and 4.*
- [ ] **Step 3 — the master tree.** [dev] Rewrite the stock `users` migration; add the sixteen others; the
      `sequence` auto-increment statement; the JSON check if step 1 says it is needed. Covers AC-1 to AC-11, and
      AC-22's "no tenant table in master" half.
- [ ] **Step 4 — the tenant baseline.** [dev] Six files under the tenant tree, the two sentinels, and the four
      key-less columns with a docblock naming the module that owes each foreign key (D-5's replacement). Covers
      AC-22 to AC-30 and AC-36.
- [ ] **Step 5 — `schema:publish` and the catalogue.** [dev] The `PublishCatalogue` slice, the committed fixture,
      LF-normalised checksums, refusal of a file that sorts before a published one. Covers AC-31, AC-32's catalogue
      half, and AC-21 (the constant is not ahead of the catalogue).
      *Order load-bearing: before step 9 — the runner refuses to start without a verified catalogue, so planning
      cannot be tested before publishing exists.*
- [ ] **Step 6 — the master-backed mapping reader and its cache.** [dev] `MasterTenantDatabaseRepository`,
      `CachingTenantDatabaseRepository`, `TenantDatabase` gains two properties, `TenantDatabaseTest`'s list grows,
      and the binding switches to the master source by default. Covers AC-12 to AC-17.
      *Order load-bearing: before step 7, which needs `schemaVersion` on the record, and before steps 9 to 12, which
      need a real mapping to plan from.*
- [ ] **Step 7 — purpose-scoped entry and the serving version gate.** [dev] `TenantConnectionPurpose`,
      `TenantMigrationTarget` and its repository, the gate between the status check and the credential dereference,
      the 503 and `Retry-After` rendering, and the one-warning-per-entry path for a tenant ahead of the code. Covers
      AC-18 to AC-21, and the serving halves of AC-53 and AC-55.
- [ ] **Step 8 — the migration-set seam and the FX fixtures.** [dev] `MigrationSet`, and FX-1 to FX-6 under the test
      fixtures. Without this the runner can only ever be tested against the one real baseline, which proves nothing
      about upgrades. Covers the seam AC-38 rides on.
      *Order load-bearing: before steps 9 to 12.*
- [ ] **Step 9 — the `PlanMigrationRun` slice.** [dev] One run row, one job row per selected tenant, all `pending`,
      written before any tenant database is touched; selection by status and version; ordering by `size_bytes`; the
      contract-step guard; the destructive refusal; the catalogue refusal. Covers AC-39, AC-40, AC-51, AC-58, AC-59
      and AC-32's refusal half.
- [ ] **Step 10 — `ClaimMigrationJob` and the per-tenant lock.** [dev] The single-statement claim with
      `attempts < :max`, claim expiry through the injectable clock, the per-server cap as one statement over a derived
      table (MariaDB 10.4 refuses a subquery on the table being updated unless it is wrapped), `GET_LOCK(name, 0)` on
      the tenant connection, and the queue payload with no secret in it. Covers AC-41, AC-42 and AC-46 to AC-50.
      *Order load-bearing: before step 11 — execution runs inside a claim.*
- [ ] **Step 11 — `MigrateTenant`: the execution path.** [dev] Migrate through the migrating purpose with the migrate
      credential; write the tenant's own `migrations` table and the master mirror in one step; clear a previous error;
      set `migrating` for a `requires_offline` plan only, and `failed` after an offline failure (D-12); evict the
      cached mapping in the same step as every status or version change (D-19); sanitise every stored error. Covers
      AC-43, AC-44, AC-45, AC-52, AC-53, AC-60, and AC-38 end to end.
- [ ] **Step 12 — repair, drift and the behind report.** [dev] `RepairSchemaVersion` (a mapping behind its own
      `migrations` table is corrected and nothing is re-applied), `DetectSchemaDrift` (both directions, and a tenant
      whose mapping is *ahead* is refused for serving), the `schema_meta.tenant_id` interlock refusal, and
      `ReportSchemaStatus`. Covers AC-54 to AC-57.
- [ ] **Step 13 — layout and hygiene checks.** [dev] `VerticalSliceLayoutTest` (every slice has a handler and a
      mirrored test folder — the first executable form of `docs/patterns.md` §3); no FX migration under the shipped
      tenant tree; no database creation or dropping outside `EngineDatabase`; `sessions` carries no foreign key to
      `users` (finding 6). Covers AC-37's remainder and the hygiene non-functional requirements.
- [ ] **Step 14 — gate wiring and headroom.** [dev] `clean-engine` first in `check`; toolchain registry coverage;
      confirm the T-007 process-timeout headroom ratio still holds for the larger gate, using the existing instrument
      unchanged. Covers AC-64.
- [ ] **Step 15 — QA: the engine matrix and the calibration counts.** [qa] Every `[E]` criterion run and recorded with
      the engine and version it passed on; the master table recount; the counts and observations AC-66 and AC-67 name,
      each with its command; and every criterion found unachievable as worded, with its replacement. Covers AC-62 as
      narrowed (one engine, per the owner's 2026-10-08 decision), AC-63, AC-65 as narrowed, AC-66 and AC-67.
- [ ] **Step 16 — review, and the fixes it names.** [reviewer, then dev]

---

## 7. Test strategy

### The problem specific to this task

**Almost everything here passes against an empty schema.** "No projection table exists", "no `tenant_id` column
exists", "no foreign key to `branches` exists", "no column holds a credential" are all true of a database with no
tables at all. So every absence assertion below names the positive control that proves the check can see what it
claims to see, and every red-first claim names the mutation and **the input at which the mutation becomes visible** —
the T-007 failure was a mutation that passed because at the chosen input the mutated term never bound.

### Proof grades

Graded as this project requires: **independently proven** when a mutation of ours moves the observable,
**corroborating** when no mutation we can make isolates it, **covered by reading** when no affordable input exists.

| Group | Criteria | Grade | Why |
|---|---|---|---|
| The name guard | AC-61 | **independently proven** | Mutating the prefix constant, or replacing the guard with `return true`, turns the negative controls red; mutating it to `return false` turns the positive controls red. Both directions move. |
| Engine capabilities | step 1 | **independently proven** | They are direct observations of the engine, re-run every gate. |
| Schema shape from `information_schema` | AC-1 to AC-10, AC-23, AC-24 live, AC-27, AC-29, AC-30, AC-36 | **independently proven** | Each has a fixture mutation: an extra table, a missing table, a MyISAM table, a `tenant_id` column, an `app_password` column. The mutation is a scratch migration run inside a reserved-prefix database, so it moves the observable without touching the shipped tree. |
| Key and constraint behaviour | AC-5, AC-7, AC-8, AC-9, AC-25, AC-26, AC-28, AC-30 | **independently proven** | Each is a pair: a refused insert and an accepted one. Dropping the sentinel column makes AC-25 and AC-26 go green-on-the-wrong-answer — the duplicate is *accepted* — so the accepted-case assertion is the one carrying the proof, not the refused one. |
| Static source rules | AC-33, AC-34, AC-35, AC-37, AC-24 source | **independently proven, but only because of step 2's order** | The fixtures are the mutation. Written after the real tree, these would be corroborating at best. |
| The mapping reader and the cache | AC-12 to AC-17 | **independently proven** | Mutation inputs are stated below. |
| The serving gate | AC-19, AC-20, AC-21 | **independently proven** | Input that makes the term bind: a mapping at `required - 1` (refused), at `required` (accepted), at `required + 1` (accepted with exactly one warning). A gate written as `>` instead of `>=` is only visible at *exactly* `required`, so that input is mandatory, not optional. |
| Credential absence | AC-6, AC-13, AC-15, AC-18, AC-41, AC-45 | **independently proven** | Every one has a planted-password control object or fixture text. |
| Claiming, locks, concurrency | AC-46 to AC-50, AC-65 | **independently proven** | Two real sessions, and the clock is injected so claim expiry has an input that can move. A second *connection* is mandatory for AC-49: `GET_LOCK` is re-entrant within a session, so a same-session test passes whatever the code does. |
| Failure isolation and status | AC-44, AC-45, AC-52 | **independently proven** | FX-2 and FX-3 are the mutation, and FX-3 observes the status *from inside* `up()`, which is the only input at which "the status was `migrating` during the migration" is distinguishable from "the status is `active` afterwards". |
| Resumability and drift | AC-43, AC-46, AC-54, AC-55 | **independently proven** | The observable is the tenant's own `migrations` table row count and batch numbers; re-applying moves it. |
| Engine parity | AC-62 | **corroborating, and partial** | Only one engine is in scope (owner, 2026-10-08). The signature reduction is built and run, so the *instrument* is proven on MariaDB; the comparison it exists for cannot run. See §9. |
| Gate hygiene and calibration | AC-64, AC-66, AC-67 | **covered by reading plus command output** | They are measurements of the branch, not behaviours of it. |
| The `schema_meta` interlock | AC-56 | **corroborating** | T-008 never writes the row, so the mismatch case is constructed entirely by the fixture. Nothing we can mutate in production code makes a real mismatch appear; the refusal is proven, the interlock in service is FP-206's. |

### Unit tests

- `Schema\Support`: `SourceRules` (one test per rule, each with its violating fixture), `MigrationCatalogue`
  (ordering, checksum normalisation, refusal of an out-of-order file), `MigrationPhase`, `ReservedDatabaseName`.
- Each slice handler, with its collaborators faked where they are not the subject: planning, claiming, retry
  selection, drift detection, the behind report's grouping.
- Kernel tenancy: `TenantDatabase`'s property list, `TenantMigrationTarget`'s opposite check (AC-18's control), the
  cache key shape and TTL, the purpose selection.

### Integration and engine tests (group `engine`)

- The master applied from scratch, twice (idempotence), with the extra-table and missing-table controls.
- The baseline applied from scratch to an empty reserved-prefix database, with the same two controls.
- The three-tenant fixture, built once per test file and dropped afterwards, plus one deliberately failing test
  followed by an assertion that the reserved-prefix database list is identical to the list taken before it (AC-61's
  "leaves nothing behind, including after a failure").
- The whole runner, against the fixture, for every criterion in section E.
- Two concurrent sessions for AC-48, AC-49 and AC-50.

### Which tests prove which criteria

AC-1 to AC-11 → the master engine tests of step 3 · AC-12 to AC-17 → step 6's reader tests, with the master read
counted by a query log and the clock moved past 300 s · AC-18 to AC-21 → step 7 · AC-22 to AC-30 → step 4's baseline
engine tests, plus the `php artisan migrate` assertion for AC-22 · AC-31, AC-32 → step 5 · AC-33 to AC-37 → step 2's
source tests · AC-38 to AC-60 → steps 9 to 12, every one against the three-tenant fixture · AC-61 to AC-63 → steps 0,
1 and 15 · AC-64 → step 14 · AC-65 → step 10's two-session tests, on the one engine in scope · AC-66, AC-67 → step 15.

### Mutation inputs worth stating, because the obvious input does not bind

- **AC-14 (cache):** the second `find` must be for the **same** tenant inside the window. A test that calls `find`
  twice for *different* tenants passes with no cache at all.
- **AC-16 (rotation retry):** the mapping must be cached *before* the reference is rotated, and the first attempt must
  fail on authentication specifically. A test where the first attempt succeeds proves nothing, and a test where the
  server is simply unreachable exercises the wrong branch — assert **exactly two** attempts, not "at least one retry".
- **AC-40 (selection):** the dataset needs a tenant *at* the target and an `archived` tenant *below* it in the same
  run, or a selector that ignores status passes.
- **AC-46 (retry):** assert the already-`done` tenants' `migrations` tables by **row count and batch number**, not by
  "the table still exists".
- **AC-50 (per-server cap):** the two servers must be in one run and one claim attempt, or a serial implementation
  passes.
- **AC-54 (repair):** the tenant's own `migrations` table must already show the target. If it does not, the repair
  path and the ordinary migrate path are indistinguishable.
- **AC-21 (constant not ahead):** the control is the comparison run with a constant one higher, which must fail.
  Against the shipped constant alone the assertion is vacuous.

### A standing rule for every absence assertion in this task

Collapse whitespace **and** strip markdown emphasis before asserting a phrase is present or absent, and assert over a
parsed structure rather than a raw grep wherever one exists (`information_schema` for shape, a tokenised read for
source). On this project a bold marker has already defeated a literal search, after a line wrap had defeated one, and
a naive grep for `tenant_id` in a migration would match a comment that merely mentions the rule.

---

## 8. Risks and rollback

| # | Risk | Likelihood | What it costs | Mitigation |
|---|---|---|---|---|
| R-1 | **A test drops one of the 56 databases on this engine, six of which look like live client systems.** | low, catastrophic | unrecoverable by this team | Step 0 before anything else; allow-list only; re-asserted at the point of effect on every call; enumeration in PHP, never SQL `LIKE` (the measured trap in §2); one sweep tool that is itself guarded; a static test that no other code issues a create or drop. |
| R-2 | The reserved prefix is one character from a real name, as `flexipos-master` already is from `flexipos_master`. | medium | same as R-1 | The prefix contains `t8test`, which no existing name does; step 0 re-measures its wildcard expansion and `qa` records the output. Uppercase and non-`[a-z0-9_]` names are refused outright because `lower_case_table_names = 1` would fold them. |
| R-3 | **The gate grows a lot.** Seventeen steps of new tests, many of them creating and dropping databases. | high | a slow or timing-out gate | Step 14 re-checks the T-007 headroom instrument without changing its ratio; AC-67 records the before and after with the cold-or-warm state stated. If the fast loop regresses, excluding the `engine` group from `test:fast` only — never from `check` — is the lever, with the measurement attached. |
| R-4 | A migration is written correctly and the *rule* that was supposed to catch its class is written afterwards, so it never goes red. | high, and it is this project's named failure | a hollow green that survives for years | Step 2 is ordered before steps 3 and 4 for exactly this reason, and each rule is red against its fixture before the tree exists. |
| R-5 | `ascii_bin` forces a collation literal, which AC-35(d) forbids. | certain | a criterion that cannot be met as worded | One helper owns the literal; AC-35(d) is re-worded (§9, finding 1). |
| R-6 | The stock `UserFactory` or `DatabaseSeeder` writes `password`, which step 3 removes. | medium | a red gate on code D-18 says not to rewrite | D-18 forbids *rewriting* the model; it does not forbid a one-line factory fix. If the gate goes red there, `dev` fixes the factory in step 3 and records it, rather than restoring the column. |
| R-7 | `tenants.sequence` needs a raw `ALTER` because Laravel couples `autoIncrement()` to `primary key`. | certain | an unexpected raw statement in a migration | Stated in §5 so it arrives as a design decision instead of a surprise; the statement names no collation, charset or engine, so AC-35(d) still holds. |
| R-8 | The run continues past a failed tenant (D-13), so a structural bug reaches every tenant before anyone reads the first failure. | medium | a bad migration on 200 shops | Out of scope by D-9, and it is the one risk in this task with no mitigation inside it. Escalated in §10 as a question for the owner, because the mitigation (canary halt) is cheapest to add before FP-206 provisions real tenants. |
| R-9 | `TenantDatabase` gains two properties and a T-007 test pins its exact property list. | certain | reads as a weakened test in review | The pin is *amended*, not deleted or skipped; the sibling test forbidding password-shaped names is untouched and covers both new names. Flagged here so `qa` and `reviewer` see it as planned rather than found. |
| R-10 | MariaDB's `JSON` is a `LONGTEXT` alias, so "invalid JSON is refused" may not hold without an explicit check. | medium | three criteria quietly passing on the wrong artefact | Step 1 measures it; step 3 and step 4 add `CHECK (JSON_VALID(…))` if the measurement says so. The criterion is asserted against a real insert either way. |
| R-11 | Engine tests connect with a bare PDO and get the server's non-strict `sql_mode` (MEASURED: no strict mode on this server). | medium | refusal criteria passing as truncations | Every engine test connects through the Laravel connector, whose stanza sets `strict`. Asserted once in step 1. |

**Rollback.** Nothing in this task is irreversible in the repository: the branch is `ai/T-008-…`, no tenant database
exists anywhere yet, and no migration here runs against anything but a reserved-prefix test database. The only
irreversible act available is dropping someone else's database, which is what step 0 exists to make impossible. There
is no `down()` and no runner rollback (D-8); a mistake in the baseline before FP-206 provisions a tenant is corrected
by editing the baseline, which is the one window in this project's life where that is free — which is also the
argument for pinning the outbox's own columns now (D-1).

---

## 9. Criteria that are not achievable as worded

`ba` asked to hear these before QA rather than after. Five, and the replacement for each. All need a spec edit by
`ba`; none changes this plan.

1. **AC-35(d) against AC-2 and AC-36.** AC-35(d) forbids a migration naming a collation or charset; AC-2 and AC-36
   require every ULID id column to be `CHAR(26)` `ascii_bin`. Laravel's `ulid()` yields the *table's* charset
   (`utf8mb4_unicode_ci` here), so `ascii_bin` must be named somewhere.
   **Replacement:** "no migration file names a charset, a collation or a storage-engine literal, with exactly one
   exemption — the shared identifier-column helper, which may name only `ascii` and `ascii_bin`. No first-party code
   anywhere names a version-specific collation (`utf8mb4_0900_*`) or `utf8mb4_general_ci`." Control unchanged: a
   fixture migration naming `utf8mb4_0900_ai_ci` is reported; the helper is not.
2. **AC-19's scope.** As worded the version gate fires "when a tenant scope is entered for it", which would also
   refuse the runner — and a tenant below the required version is the only kind of tenant the runner ever migrates.
   **Replacement:** the gate applies to the **serving** purpose. Entering for migration is exempt, and the exemption
   is itself asserted (the runner enters a tenant at `required - 1` and succeeds).
3. **"On both engines" in AC-10, AC-27, AC-29, AC-62 and AC-65.** The owner's decision of 2026-10-08 puts MySQL 8.4
   and Docker out of scope for T-008, so the second half of each cannot run.
   **Replacement:** "on the engine the environment names, recorded per AC-63", with the MySQL 8.4 comparison deferred
   to a named follow-up row. For AC-62 specifically: the signature reduction is **built and run on MariaDB**, so the
   instrument ships and is proven; the cross-engine comparison is the deferred half. AC-63's rule that a criterion
   verified on one engine is recorded as such already covers the reporting.
4. **AC-48's "the claim is one statement, not read-then-write".** MEASURED: `SKIP LOCKED` is a syntax error on
   10.4.32, so a worker cannot select-and-lock its candidate atomically.
   **Replacement:** "the mutation that *grants* the claim is a single statement whose `WHERE` re-checks status and
   attempts, and the verdict is its affected-row count. Reading candidate ids beforehand grants nothing." Two workers
   racing on one `pending` job still yields exactly one success, which is what the criterion is for.
5. **AC-13's `(string)` cast.** `TenantDatabase` has no `__toString`, so casting it is a fatal `Error`, not an output
   to scan.
   **Replacement:** scan `json_encode`, `serialize`, `print_r`, `var_export`, `var_dump` and `(array)` output; keep
   the string cast only for the **control** object, which does implement `__toString` and must be reported.

**Not a spec change, but `ba` should know:** AC-1 lists `sessions` among the master system tables where
`docs/database.md` §4.10 lists `personal_access_tokens`. AC-1 governs (D-18), and `qa`'s recount of finding 1 will
otherwise look like a defect.

---

## 10. Questions for the human

Two, and neither blocks a step. Both are for the lead to relay, not for `ba`.

1. **Canary halt before FP-206 (risk R-8, `ba`'s open question 3).** The design continues past a failed tenant
   (D-13), so a structural migration bug is applied to every tenant before anyone reads the first failure. The
   mitigation is a canary halt and it is out of scope by D-9. It is **cheapest to add now**, while the runner is being
   written and no tenant database exists; after FP-206 it is a change to a running operation. The owner may want it
   before FP-206 rather than after. No step here depends on the answer.
2. **MySQL 8.4 parity (finding 3 in §9).** The owner's 2026-10-08 decision defers it, which the plan follows. What it
   leaves open is *when*: AC-62's whole purpose is to catch an engine-specific shape before tenants hold data, and the
   longer it waits the more schema there is to compare. A named follow-up row before the first real tenant is
   provisioned would keep the instrument honest; after FP-206 it is a comparison against production shapes.

Everything else in this task is decided in §0, §3 and §9 and logged there.
