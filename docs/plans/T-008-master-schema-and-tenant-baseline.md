# Plan: T-008 Master schema, tenant baseline and the per-tenant migration runner

Covers **FP-203**, **FP-204**, **FP-205** as one slice. Spec: `docs/specs/T-008-master-schema-and-tenant-baseline.md`
(67 criteria). Decisions: `docs/decisions/T-008-master-schema-and-tenant-baseline.md` (D-1 to D-21). Binding patterns:
`docs/patterns.md` (vertical slices §3, CQRS scoped §2/§5). Build order: `docs/domain-core.md` §8, which puts this
first and says nothing reaches a tenant without it.

Everything measured in this plan was measured on **2026-10-14** on this machine, by the command quoted beside it.

> **Read §11 before doing anything in this plan.** On 2026-10-14 the red phase of step 0 dropped sixteen of the
> owner's databases on the shared engine, none of them backed up. §11 carries the constraint that replaces the
> instruction which caused it, the gate the engine steps now wait behind, and the per-step ruling on how each
> remaining red-first step proves red. It overrides anything earlier in this document that conflicts with it.

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
backfill, and `ADD COLUMN` at the end of a table is believed to be `ALGORITHM=INSTANT` here (step 1 was to pin it as
an assertion rather than a belief; step 1 is **blocked** behind §11.4, so that third bound is currently **unverified**
and this ruling rests on the first two, which are enough). Against that, the claim mark's *shape* is unknown — a worker id, a lease expiry, or
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
  whose first class is the name guard. Nothing else in the suite may issue `CREATE DATABASE` or `DROP DATABASE`, a
  static test asserts that, and **nothing anywhere derives a list of databases to drop from the engine's contents**
  (§11.2 rule 4). Every connection to that engine is additionally suspended by `ENGINE_HOLD` until the gate in §11.4
  clears.
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
  the plan. MEASURED: `SHOW DATABASES LIKE 'flexipos_%'` returns `flexipos-master` and `flexipos-master-new` — the
  two databases the owner flagged as the near-collision hazard — because `_` is a single-character wildcard in `LIKE`.
  **Corrected 2026-10-14, after the incident:** the same statement matched **five** names once `dev`'s own databases
  existed, not two. The trap is therefore *wider than a list of two near-collisions*, and that is the point: the set of
  names the wildcard reaches is a function of whatever is on the engine at the moment the statement runs, so it can
  never be reviewed, only avoided. AC-61 and the plan text that quoted "two" are corrected to "at least five, and the
  count is not a property of our code" (spec correction requested of `ba`, §9 finding 6).
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
  `CREATE DATABASE` or `DROP DATABASE`. **It has no sweep and must never gain one** (§11): it drops only names its
  caller names, one at a time, and `ReservedDatabaseName::assert()` runs at the point of effect on **every** call —
  plus a second, independent prefix check against this class's own literal, so two rules have to be wrong before
  anything is lost. Enumeration (`SELECT schema_name FROM information_schema.schemata`, filtered in PHP by
  `str_starts_with`, never SQL `LIKE` — §2) is **read-only**: no code path turns that list into drops.
- `packages/testing/src/Engine/EngineConnection.php` — **new.** Builds the engine connections from environment through
  the framework's connector (never a bare PDO, AC-68), fails (never skips) with the engine named when it is
  unreachable, writes `SELECT VERSION()` into the test output (AC-63), and refuses to open any connection at all while
  `ENGINE_HOLD` is set (§11).
- `packages/testing/src/Engine/EngineOnHold.php`, `EngineUnreachable.php`, `ReservedDatabaseNameViolation.php` —
  **new.** The three named refusals. `EngineOnHold` is *thrown*, not skipped: the hold is fail-closed in code, and only
  the handful of tests that declare the skip are absent from a held run (§11, ruling 2.1).
- `modules/host/tools/clean-engine.php` — **new, and report-only** (§11). It lists leftover reserved-prefix databases
  and drops nothing. Wired **first** in `composer check` beside `probes:clean`, for the same reason that one is first:
  a killed run leaves artefacts and a test hook never gets to run. It honours `ENGINE_HOLD` and opens no connection
  while the hold is set.
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
- `modules/host/database/migrations/2026_10_14_0001xx_*.php` — **new**, one file per concern, in dependency order.
  **Corrected 2026-10-15 against the shipped tree (`38e9537`):** `feature_flags` comes **before** `plan_features`,
  because `plan_features` keys onto it; the earlier reading of this line put the two the other way round and could not
  have built. The order is:
  plans → feature_flags → plan_features → plan_limits → tenants (FK `plans`, **nullable**) → tenant_users →
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
- `packages/kernel/src/Tenancy/Exceptions/TenantSchemaBehind.php` — **new**, a named exception carrying no credential.
  **`TenantSchemaAhead.php` is struck from this step and moves to step 12** (§13.5): AC-20 serves an ahead tenant, so
  step 7 has no site that throws it.
  `RequiredSchemaVersionNotConfigured.php` — **new in step 7a** (§13.1): the absent-floor refusal, naming the
  environment variable and nothing else.
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
  `per_server_limit`, `queue` (`migrations`), `backups.available` (false, D-10), `tenant_migration_path` and
  `catalogue_fixture`. **It does not hold the reserved test prefix** (ruling 2.3, `dev` confirmed): the prefix lives in
  `ReservedDatabaseName::PREFIX`, because `packages/testing` may not read Laravel configuration without a dependency on
  the host that the boundary check forbids, and a guard that depends on configuration is a guard an environment
  variable can widen. Any step that needs the prefix — step 5's config file included — **reads the constant** and never
  repeats the literal. The two deliberate exceptions are `EngineDatabase`, whose second independent check is at the
  point of effect, and `tools/clean-engine.php`, whose second literal has had no statement in front of it since
  `5e2e708` and instead fails the gate when the two rules disagree (§11.3 and §11.2 rule 4a). Each must carry its
  **own** literal: reading the constant there would make it a second call to one rule instead of a second rule (§11).

### Fixtures and tests

- `packages/testing/src/Engine/ThreeTenantFixture.php` — **new, in step 8, not step 0** (ruling 2.2, confirmed against
  `dev`). Tenants A (empty), B (baseline applied) and C (baseline applied, FX-2 fails on it). It cannot exist in step 0:
  step 0's bullet names four artefacts, and the fixture needs `tenant_databases` and `database_servers` mappings that
  do not exist until step 6 and the FX seam that does not exist until step 8. It creates and drops only
  reserved-prefix databases, through `EngineDatabase`, by name, and it is behind the §11 engine gate like everything
  else that connects.
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
- `modules/host/composer.json` — **changed.** `engine:clean` first in `check` (shipped in step 0, confirmed still
  first on 2026-10-14, so step 14 needs no wiring); no new dependency. Its **script description** was also changed in
  `5e2e708`, outside the boundary the lead drew for that commit, because it was the fifth copy of the retracted
  "the tool sweeps" claim. Accepted by the lead and by me; it is not an undeclared change.
- `modules/host/tests/Feature/DestructiveStatementContainmentTest.php` — **new, step 13** (shipped early in
  `5e2e708`). The two teeth of §11.2: one drop-issuing file, and no double of `ReservedDatabaseName`.
- `modules/host/phpstan.neon.dist`, `modules/host/deptrac.yaml` — **changed** only if a new path appears. With the
  layout above, none does: `packages/kernel/src`, `packages/kernel/tests`, `packages/testing/src`,
  `modules/host/database`, `tests` and `tools` are all already registered. `QaToolchainRegistryCoverageTest` is the
  instrument that will say otherwise; believe it over this paragraph.

---

## 5. Data / API changes

### The reserved test prefix, and why it is what it is

**`flexipos_t8test_`.** MEASURED on this machine, 2026-10-14: `SHOW DATABASES LIKE 'flexipos_t8_%'`,
`'flxtest_%'`, `'fpt_%'` and `'flexipos_test_%'` all return zero rows, *even unescaped*, while
`'flexipos_%'` returned `flexipos-master` and `flexipos-master-new` — and **five** names later the same day, once
`dev`'s own databases existed. The engine held **56** schemas that morning, not the 31 in the brief — four
`untouched_*`, six `u_<date>x<time>` and three `kokobeach_*` dated copies had appeared — and **43** that evening, after
the incident of §11. That is the whole argument in one line: the engine's contents are not a reviewable list, so the
guard is a rule about *our* names and nothing else. The prefix is a constant in code
(`ReservedDatabaseName::PREFIX`), not configuration. The wildcard expansion is pinned as an assertion against the
**recorded** 56-name snapshot, offline, because sixteen of those names can never be measured again; the live
`SHOW … LIKE` observation is an engine test and sits behind the §11 gate.

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
- `APP_REQUIRED_SCHEMA_VERSION` — the constant AC-19 and AC-21 turn on. `.env.example` ships it at the baseline's
  highest version, and a test pins that equality against the catalogue (§9 finding 11). **Three states, not two**
  (§13.1): a number is the serving floor; an explicit `0` is the documented "no floor, the status gate is the only
  guard" opt-out; **absent is a misconfiguration and refuses to serve** — the serving purpose only, so the runner can
  still migrate the tenant out of the condition. It is read in exactly one place, `tenancy.required_schema_version`.
- **`schema:publish` refuses a published migration whose checksum moved, or that has left the tree.** Beyond AC-31's
  out-of-order refusal, and forced by AC-32: the alternative is for `publish` to **update** a published row to stay
  idempotent, which destroys the evidence AC-32 is about. The workflow consequence someone will meet (§13.4):
  **editing an already-published tenant migration during development is refused until its `schema_versions` row is
  gone.** That is correct for expand-and-contract — a released migration is immutable — and while no tenant exists the
  escape is deliberate and visible: delete the row in the development master and republish. No `--force` and no
  `--forget` flag is added; a flag here is a flag that will be used in production.
- No migration ever rolls back; there is no `down()` (D-8).

---

## 6. Implementation steps

Eighteen steps (seventeen plus step 13a, split out on 2026-10-15), against T-007's nine. Owner in brackets. **Where the order is load-bearing it says so and why** —
elsewhere steps may be reordered or run together.

- [x] **Step 0 — the allow-list name guard, proven red, with a positive control beside it.** [dev] **DONE**, commit
      `a349208`: gate exit 0 at **510 passed, 3 skipped, 2545 assertions**, with the engine's schema count unchanged
      before and after the run.
      `ReservedDatabaseName`, `EngineDatabase`, `EngineConnection`, `tools/clean-engine.php` wired first in
      `composer check`, and `ReservedDatabaseNameGuardTest`.
      **The red-first instruction in this bullet was wrong and it destroyed data.** It read: *"the test is written
      against a stub `ReservedDatabaseName` that returns true for every name"*. That stub was handed to the sweep this
      bullet also asked for, and sixteen of the owner's databases were dropped. The instruction is **withdrawn** and
      replaced by §11.2: red is proven against the recorded engine snapshot offline and at the point of effect on the
      suite's sqlite connection; a guard is never stubbed permissively; there is no sweep. As shipped, step 0 is
      correct — the sweep is gone from `EngineDatabase` and `tools/clean-engine.php` is report-only (§11.2 rule 4).
      **Negative controls (must be refused):** `flexipos_master`, `flexipos-master`, `flexipos-master-new`,
      `flx_t_000001`, `flx_t_000002`, `pms`, `demo_pos`, `flexhms_glow`, `mysql`, the empty string,
      `FLEXIPOS_T8TEST_X` (uppercase), `flexipos_t8test_../x`, and a 70-character reserved-prefix name.
      **Positive controls (must be allowed, or a guard that refuses everything passes the absence test):**
      `flexipos_t8test_a`, `flexipos_t8test_master`, `flexipos_t8test_tenant_a`; and the **read-only filter**
      (`ReservedDatabaseName::reservedAmong()`, which is a pure function and not a "sweep control" — this bullet's
      original wording is what `dev` read as a licence to build `dropAllReserved()`), pointed at a fixture list of
      `flexipos_t8test_a`, `flexipos-master`, `pms`, returns exactly `flexipos_t8test_a`, by exact equality and never
      `toContain`.
      **One more assertion that is the whole reason this step exists:** the wrong enumeration, written as a
      `SHOW DATABASES LIKE` with an unescaped underscore, returns `flexipos-master` — asserted as a *fact about the
      engine*, so the reason for the `str_starts_with` rule is pinned in a test and cannot be optimised away by a later
      reader who thinks `LIKE` is tidier. It is a `SHOW` and never a `DROP`, it is the only place in this task allowed
      to say `LIKE` at all, and it is `[gated]`; the offline half of the same proof runs against the recorded 56-name
      snapshot, which is the input the permissive stub was actually given and the only form in which the sixteen lost
      names still exist.
      Covers AC-61, and AC-63's fail-not-skip half as §11.6 narrows it.
      **Order is load-bearing: nothing in any later step may create or drop a database, and no migration, runner or
      fixture code may exist in the branch, until this step is on disk and green.** This is the one place in T-008
      where a mistake destroys something that is not ours and that this team cannot restore.
- [ ] **Step 1 — engine capability probes as assertions. BLOCKED on G-1, not skipped. `[gated]`** [dev] One of the
      five open items is **closed read-only**: `@@default_storage_engine` is **InnoDB** (`dev`, 2026-10-14). The other
      four need DDL on the engine — `GET_LOCK(name, 0)`, a stored generated column inside a unique key, `ADD COLUMN`
      instant, and MariaDB's `JSON` alias on invalid JSON — so they wait behind §11.4's dump. Steps 3, 4, 10 and 11
      depend on the answers; where a step cannot proceed without one, it assumes the conservative branch (an explicit
      `CHECK (JSON_VALID(col))` is written, and the instant-`ADD COLUMN` claim in §0's D-1 reasoning is downgraded to
      unverified) and step 1 confirms or corrects it later.
      `EngineCapabilityTest`: `SKIP LOCKED` is a syntax
      error, `NOWAIT` parses, `GET_LOCK(name, 0)` returns immediately, default engine InnoDB, a stored generated
      column is usable inside a unique key, `ADD COLUMN` at the end of a table is instant, and what MariaDB's `JSON`
      alias does with invalid JSON. Each is a design assumption of steps 4, 10 and 11; measuring them here is cheaper
      than discovering one at step 11. Covers nothing on its own; de-risks AC-10, AC-25 to AC-27, AC-29, AC-48 to AC-50.
      *Order: after step 0, because it needs a reserved-prefix database to create a table in.*
- [x] **Step 2 — the static migration rules, proven red on fixtures, before a single real migration exists.**
      [dev] **DONE**, commit `e749aea`. `SourceRules` is red on **fourteen** fixtures, each caught by the rule meant to
      catch it, and that is the step's own proof: the rules existed on the branch before a single shipped migration did,
      which is what AC-34's `(branch)` half asks `qa` to confirm by commit order.
      **Declared deviation (accepted):** step 2 ships **no tree-silence assertion** — it lands in step 3. At `e749aea`
      the tenant tree did not exist and the stock `users` migration still called `timestamps()`, a true violation of
      AC-35(c), so the only ways to be green were to do step 3's work inside step 2's commit or to narrow a rule.
      Neither is acceptable and `dev` chose the third option, which this plan's own wording permits ("red against its
      own fixture first and only then green against the tree"): the fixture half here, the tree half in step 3. The
      reason is in the test file, where the next reader meets it. **Two further narrowings, both accepted and both
      recorded in §12.4:** AC-33 and AC-34 do not treat a table the same migration created as "existing", and AC-37's
      schema-qualified half is applied to **both** trees rather than only the tenant one.
      `Schema\Support\SourceRules` + `MigrationSourceRulesTest` + the violating fixtures.
      AC-33 (an expand step must not drop, rename or change), AC-34 (no foreign key onto an existing table outside a
      contract step), AC-35(a) bare `decimal()`, (b) native `enum`, (c) `timestamp`, (d) charset, collation or engine
      literal, AC-37 (cross-connection and schema-qualified names), AC-24's source half.
      **Red phase: fixture only, no engine in the loop** (§11.5). The mutation is the violating fixture file; the
      checker is never weakened. This step is the model the others are bent towards.
      Each rule is asserted **red against its own fixture first and only then green against the tree**. This step is
      before steps 3 and 4 deliberately: run the other way round, every rule passes against a tree of zero tenant
      migrations and proves nothing, which is exactly this project's named failure mode.
      *Order load-bearing: before steps 3 and 4.*
- [x] **Step 3 — the master tree.** [dev] **DONE**, commit `38e9537`. Seventeen application tables plus seven of
      AC-1's eight system tables (the framework creates `migrations` itself, so the eighth needs no file); the stock
      `users` migration rewritten in place; the `sequence` auto-increment statement; `JsonValidity` writing the
      `CHECK (JSON_VALID(…))` unconditionally while step 1 is blocked. Covers AC-1 to AC-11 on the source and
      stand-in; the `information_schema` half stays `[gated]`.
      **Correction to this plan, not a deviation:** §4's arrow order listed `plan_features` / `plan_limits` before
      `feature_flags`. That order cannot build — `plan_features` keys onto `feature_flags` — so the shipped order is
      `plans` → `feature_flags` → `plan_features` → `plan_limits` → `tenants` → … `dev` is right and the plan text
      was wrong; §4 is corrected. `tenants.plan_id` is **nullable**, because a tenant exists as pending before a plan
      is chosen (accepted; §12.4).
- [x] **Step 4 — the tenant baseline.** [dev] **DONE**, commit `52aca02`. Six files under
      `database/migrations-tenant/`, the two stored generated sentinels (both working on the stand-in), `schema_meta`
      created **empty**, no projection table and no claim mark (D-1, D-14), and the four key-less columns with a
      docblock naming the module that owes each foreign key (D-5's replacement). **The boundary rule is verified
      directly: no `tenant_id` column in any tenant migration** (AC-24's source half). Covers AC-22 to AC-30 and
      AC-36 on the source and stand-in; the `information_schema` half stays `[gated]`.
      **Gate after steps 2 to 4, verified by the lead:** exit 0, **551 passed, 3 skipped, 2705 assertions**, engine
      schema count unchanged at 40 across the run.
      **Three driver-guarded statements on SQLite, accepted and load-bearing** (§12.4): the ASCII collation, the
      JSON constraint and the second auto-increment column each skip on the stand-in and say so where they skip.
      Without them no later step could test anything at all while the hold is on, which is the whole gated period.
- [x] **Step 5 — `schema:publish` and the catalogue.** [dev] **DONE**, commit `7b30da4`. The `PublishCatalogue` slice,
      the committed fixture, LF-normalised checksums, the out-of-order refusal, and `config/schema.php` as the tree's
      one authority — **proven by moving the key**, which is the part worth keeping: a test points
      `schema.tenant_migration_path` at a scratch tree and asserts that both tree-discovery helpers *and* the publish
      command follow it. A literal that happened to match the configured value would have passed any weaker assertion,
      so this is the form every later "one authority" claim in this task must take.
      Two refusals beyond AC-31 shipped with it and they are a recorded consequence, not scope creep: see §13.4.
      AC-31 and AC-32's publish half stay **NOT VERIFIED** under the hold (§11.6).
      The superseded instruction follows, unchanged, because its ordering argument still binds step 9:
      The `PublishCatalogue` slice, the committed fixture,
      LF-normalised checksums, refusal of a file that sorts before a published one. Covers AC-31, AC-32's catalogue
      half, and AC-21 (the constant is not ahead of the catalogue). It reads the reserved prefix from
      `ReservedDatabaseName::PREFIX` and never repeats the literal (ruling 2.3).
      **It also closes the second literal `dev` flagged, and this is part of the step, not a tidy-up** (§12.5):
      `config/schema.php`'s `tenant_migration_path` becomes the **one authority for applying or publishing the tenant
      tree**, and the four sites that currently hold the literal `database/migrations-tenant` are reduced to it —
      the two `Artisan::call('migrate', ['--path' => …])` calls in `TenantBaselineBuildsTest` and the two
      tree-discovery helpers (`migrationTreeSources()`, `migrationRuleTreeSources()`), which move to one shared helper
      in `tests/Helpers.php` reading the same key. A discovery helper that can disagree with the applied path is a scan
      over a tree nobody runs. **`MigrationTree::forPath()` keeps its own literal, deliberately**: it *classifies* an
      arbitrary path, including fixture paths that are in no tree, and a classifier that asked configuration which
      directory is "tenant" would let one environment variable reclassify every rule in §11.3's sense. So the two rules
      stay independent and step 5 adds the tripwire for their disagreeing —
      `MigrationTree::forPath(config('schema.tenant_migration_path').'/x.php')` is asserted to be `Tenant`, and the
      configured directory is asserted to exist and to be **absent from the framework's auto-discovered paths**, which
      is AC-22's structural half said once in configuration instead of believed per call site.
      **Red phase: omit the mechanism, on the suite's sqlite master connection** (§11.5). The refusal is never stubbed
      permissively; the assertion goes red because the branch is absent. No engine.
      *Order load-bearing: before step 9 — the runner refuses to start without a verified catalogue, so planning
      cannot be tested before publishing exists.*
- [x] **Step 6 — the master-backed mapping reader and its cache.** [dev] **DONE**, commit `5899008`.
      `MasterTenantDatabaseRepository`, `CachingTenantDatabaseRepository`, `TenantDatabase` gains two properties,
      `TenantDatabaseTest`'s list grows, and the shipped configuration default becomes the master source. Covers
      AC-12 to AC-17; all six stay **NOT VERIFIED** under the hold (§11.6).
      **The suite's mapping source is overridden to `config` in `phpunit.xml` and that override is accepted** — the
      reason, the tooth it costs and the tooth it is replaced by are §13.4.
      *Order load-bearing: before step 7, which needs `schemaVersion` on the record, and before steps 9 to 12, which
      need a real mapping to plan from.*
- [x] **Step 7 — purpose-scoped entry and the serving version gate.** [dev] **DONE**, commit `23b3beb`.
      `TenantConnectionPurpose`, `TenantMigrationTarget` and its repository, the gate between the status check and the
      credential dereference, the 503 and `Retry-After` rendering, and the one-warning-per-entry path for a tenant
      ahead of the code — with the three mandatory inputs (`required - 1`, exactly `required`, `required + 1`) and the
      migrating purpose asserted exempt. Covers AC-18 to AC-21; AC-19 and AC-20 are recorded NOT VERIFIED only because
      the spec marks them `[E]`, which §13.6 rules is the wrong marker.
      **`TenantSchemaAhead` is deliberately not written here and moves to step 12** (§13.5): AC-20 serves an ahead
      tenant with a warning, so step 7 has no site that throws it, and an exception nothing throws is dead code with a
      misleading name.
      **The version floor as shipped fails open and step 7a closes it** (§13.1).
      **Gate after steps 5 to 7, reported by `dev`:** exit 0, **598 passed, 3 skipped, 2948 assertions** (+47 tests,
      +243 assertions against the step-4 figure; skips unchanged at 3, because nothing added here needs a connection).
- [x] **Step 7a — the version floor fails closed when the variable is absent.** [dev] **DONE**, commit `cf685e2`.
      The three states as ruled, `RequiredSchemaVersionNotConfigured`, the 503 without `Retry-After`, the duplicate
      `config/schema.php` key removed, and both §13.3's and §13.4's teeth landed with it: AC-21's equality proven by a
      scratch catalogue moved one version each way, and one test that resolves the mapping reader **from the shipped
      configuration** and asserts it is the caching decorator over `MasterTenantDatabaseRepository`.
      **Verified by the lead:** absence and explicit zero are separated by `is_numeric($configured)`, so "nobody chose"
      and "somebody chose no floor" can no longer collapse into each other, and the second read of the variable is gone.
      Red phase on sqlite: the absent-floor case failed with "Exception … not thrown" — the request being served *is*
      the hole — and the config case with "Failed asserting that 0 is null".
      The instruction follows, unchanged, because §13.1's four properties still bind anything that touches the floor:
      Ruled in §13.1. Four changes and
      one test file: `config/tenancy.php`'s `required_schema_version` stops casting an absent variable to `0` and
      yields **null** instead; `Tenancy::assertSchemaIsServable()` refuses a **serving** entry on null with a new named
      exception (`RequiredSchemaVersionNotConfigured`, no credential, carrying the variable's name) while an explicit
      `0` stays the documented "no floor" opt-out; `bootstrap/app.php` renders it 503 **without** `Retry-After`, so a
      misconfiguration is distinguishable from a behind tenant at the wire; `phpunit.xml` sets the variable with its
      reason beside it; and `config/schema.php`'s duplicate `required_version` key is **removed** (§13.1, last
      paragraph). The migrating purpose stays exempt — that is what keeps `tenant:migrate` able to fix the condition.
      **Red phase: omit the mechanism, on sqlite** (§11.5 row 5's shape). The input that makes the term bind is a
      mapping at schema version **0** with the variable unset in all three places PHP keeps it: today that request is
      served, which is the hole. Also assert that an explicit `APP_REQUIRED_SCHEMA_VERSION=0` is served (or the fix
      becomes "the floor is mandatory", which is a different decision), and that the migrating purpose is unaffected
      by either state.
      Covers AC-19's absent-configuration case, which the criterion does not currently name — §9 finding 11 asks `ba`
      to add it.
      *Order: before step 11, because step 11 is the first code that enters a tenant for migration in anger and must
      not inherit a floor whose absence is silent. It may run beside steps 8 to 10.*
- [x] **Step 8 — the migration-set seam and the FX fixtures.** [dev] **DONE**, commit `0afd6a6`. `MigrationSet`,
      FX-1 to FX-6, and `ThreeTenantFixture`'s mapping half (its engine half waits behind G-1 and the inverted test
      asserts `build()` and `drop()` both throw `EngineOnHold`).
      **The seam's load-bearing property, stated by `dev` and accepted:** a version is a **position** in the set,
      numbered from one across the whole set in the order the directories were named and in file-name order inside
      each — deliberately **not** merged-sorted, because a fixture named `0001_fx1_…` sorted against the baseline
      would renumber the six versions every tenant already carries. The mutation is stated and measured: adding a
      cross-set sort to `paths()` turns two cases red with the ordering diff as the message. A missing or repeated
      directory refuses; an **empty** one does not, because an empty directory and a missing one say different things
      about whether anybody meant it.
      **No FX file names another connection**, so FX-3 and FX-1 declare *when* a reading is taken and the test
      installs the probe that takes it — see §14.2, which rules on that split.
      Covers the seam AC-38 rides on. No `[E]` criterion is verified here.
      *Order load-bearing: before steps 9 to 12.*
- [x] **Step 9 — the `PlanMigrationRun` slice.** [dev] **DONE**, commit `88c04ce`. Every refusal is decided before
      the first insert — catalogue, then the selection read, then the destructive refusal, then the contract guard,
      then the two writes — so "no tenant was altered" and "zero job rows" are properties of the handler's order and
      not of a rollback. FX-4 (contract, not destructive) is planned: the control that catches a refusal keyed on the
      phase. D-11's two status sets are separate halves because AC-40 wants a bulk run to pass an `archived` tenant
      silently while AC-51 wants a named run to say why. `RunnerClock` landed here, early, because the run row needs a
      `now()` a test can move. The connection instrument asserts the queried connection list is exactly `[master]`.
      **AC-58 is implemented as a reading and §14.1 confirms it**; the status authority is `tenant_databases.status`
      and §14.3 confirms that too. **The destructive refusal's red phase is the family's third instance (§7)**: the
      first attempt stayed green because the contract guard refused the same run with the same exception class, and
      the fix was the input — the tenant at version 7, where that guard has nothing to say.
      The instruction follows, unchanged:
      One run row, one job row per selected tenant, all `pending`,
      written before any tenant database is touched; selection by status and version; ordering by `size_bytes`; the
      contract-step guard; the destructive refusal; the catalogue refusal. Covers AC-39, AC-40, AC-51, AC-58, AC-59
      and AC-32's refusal half.
      **Red phase: omit the mechanism; no engine, no tenant connection** (§11.5). The destructive refusal is proven red
      by asserting zero job rows and zero connections against a handler that lacks the branch — **never** by letting
      FX-5 run to see what happens.
- [x] **Step 10 — `ClaimMigrationJob` and the per-tenant lock.** [dev] **DONE**, commit `6663377`, for the claim,
      the clock-driven expiry, the per-server cap and `PerTenantLock`. Covers AC-41, AC-47, AC-48, AC-50 and AC-46's
      attempt cap on the stand-in; AC-49 and AC-65 are **NOT VERIFIED** behind G-1 and correctly so — see §14.4.
      **This step produced §7's fourth mask instance and the statement pin that answers it**: the candidate read
      duplicates the claim's terms, so the seven behavioural cases were evidence about the pair, and the executed
      `UPDATE`'s `WHERE` is now asserted from the query log. Any later statement that re-checks what its own read
      already filtered is held to the same proof.
      **Two pieces of step 10's nominal scope move to step 11**, because neither has an input that binds until the
      execution path exists: the **`RetryFailedJobs` slice** (AC-46's retry-selection half — a retry that selects jobs
      nothing then executes cannot be told from one that selects the wrong jobs) and **AC-42** (two jobs for two
      tenants in one worker process, which needs two executions). They are listed in step 11 and not lost here.
      The instruction follows, unchanged:
      The single-statement claim with
      `attempts < :max`, claim expiry through the injectable clock, the per-server cap as one statement over a derived
      table (MariaDB 10.4 refuses a subquery on the table being updated unless it is wrapped), `GET_LOCK(name, 0)` on
      the tenant connection, and the queue payload with no secret in it. Covers AC-41, AC-42 and AC-46 to AC-50.
      **Red phase, split** (§11.5): the claim's `WHERE` is mutated on sqlite — a legitimate mutation, because the
      statement is an `UPDATE` on a master row and not destructive. The lock and two-session tests are `[gated]`;
      their only engine traffic is `GET_LOCK` inside a reserved database.
      *Order load-bearing: before step 11 — execution runs inside a claim.*
- [x] **Step 11 — `MigrateTenant`: the execution path.** [dev] **DONE**, commit `733dcca`. `MigrateTenant`,
      `RetryFailedJobs` and `RunTenantMigrations` with `tenant:migrate` as the entry point, plus the two criteria that
      moved here from step 10 (AC-42, AC-46's retry-selection half). Both of §7's fourth-shape constraints were met:
      the completion statement and the retry reset are each pinned from the query log, and the measured signature of
      deleting the retry statement's two terms is five behavioural cases green with exactly the pin red — the correct
      signature.
      **The order of entry and the status write is a ruling, not a detail: see §15.1.** The shipped order is
      **enter (on `active`) → write `migrating` → apply → restore**, because `Tenancy::enter()` refuses any status but
      `active` for *both* purposes. `migrating` is written only for a `requires_offline` plan (D-12, AC-52).
      **AC-53 is NOT VERIFIED and §15.2 rules on why the in-process form is weaker than its words**; the eviction half
      of D-19 is proven with the mask control beside it — the same warm read, after a status change written *around*
      the mapping writer, still answers `active`.
      The instruction follows, unchanged:
      Migrate through the migrating purpose with the migrate
      credential; write the tenant's own `migrations` table and the master mirror in one step; clear a previous error;
      set `migrating` for a `requires_offline` plan only, and `failed` after an offline failure (D-12); evict the
      cached mapping in the same step as every status or version change (D-19); sanitise every stored error. Covers
      AC-43, AC-44, AC-45, AC-52, AC-53, AC-60, and AC-38 end to end.
      **Moved here from step 10** (see there for why): the **`RetryFailedJobs` slice** (AC-46's retry-selection half)
      and **AC-42**, the two-tenants-one-worker case — `dev` may ship either as a separate commit inside this step.
      **Two constraints from §7's fourth instance, and they bind this step specifically.** (i) `MigrateTenant` and
      `RetryFailedJobs` both have the read-then-write shape: wherever a statement re-checks terms its own feeding read
      already filtered on — the job status, the attempt count, the claim expiry — the statement's `WHERE` is pinned
      from the query log in the same commit, because no behavioural case in one process can separate the two sites.
      (ii) AC-52's observation through `FixtureMigrationLog` carries a positive control in the same test file: a run
      that *proceeds* and records a non-zero reading. Without it, "the fixture saw nothing" and "the fixture never
      ran" are the same observable, which is this family's shape in miniature (§14.2).
      **`[gated]`, and the only genuinely destructive step in the task** (§11.5). Everything provable without the
      engine — status transitions, the mirror write, the cleared error, eviction, error sanitising — is red on sqlite
      against a fake migrator. The engine half is red by **omitting the apply call**, and every database it touches was
      created by `ThreeTenantFixture` through `EngineDatabase`, by name, in the same run.
- [x] **Step 12 — repair, drift and the behind report.** [dev] **DONE**, commit `d00ff55`. `RepairSchemaVersion`
      (no migrator in the slice, so "nothing is re-applied" is a property of the slice rather than of a flag, and the
      assertion is the tenant's own `migrations` table by row **and batch**), `DetectSchemaDrift` in both directions,
      the `schema_meta.tenant_id` interlock refusal — checked **first inside the migrating scope**, before the status
      write and before the apply, so "that database is unchanged" is structural — and `ReportSchemaStatus`.
      **The ahead-mapping refusal keeps its throw site and writes the unservable status before it throws** (§15.3,
      deviation 1), so AC-55's "refused for serving until corrected" does not depend on anyone catching the exception.
      `Support/TenantSchemaVersion` is the one site stating D-14; its caller count is corrected in §15.4.
      The instruction follows, unchanged:
      `RepairSchemaVersion` (a mapping behind its own
      `migrations` table is corrected and nothing is re-applied), `DetectSchemaDrift` (both directions, and a tenant
      whose mapping is *ahead* is refused for serving), the `schema_meta.tenant_id` interlock refusal, and
      `ReportSchemaStatus`. Covers AC-54 to AC-57.
      **Red phase: omit the mechanism** (§11.5). Nothing here issues a destructive statement; the observables are a row
      count in the tenant's own `migrations` table and a column in `tenant_databases`. `[gated]` only for the reads
      that need the three-tenant fixture.
- [x] **Step 13 — layout and hygiene checks (the three the lead scoped).** [dev] **DONE**, commits `5e2e708`
      (checks (a) and (b)) and `18f03dd` (`VerticalSliceLayoutTest`, no FX migration under either shipped tree, and
      `sessions` carries no foreign key to `users`). **Checks (c), (d) and (e) are not done and are step 13a** — the
      step is ticked for the scope it was given, and the remainder is a step of its own so it cannot be read as
      shipped. Three properties worth keeping from `dev`'s report: the layout rule has four fixture slices as controls
      (one complete, one missing each part); the FX scan matches **two ways**, by LF-normalised checksum (survives a
      rename) and by the FX naming convention (survives an edit), because a copy is renamed more often than it is
      rewritten; and the `sessions` check reads the **applied** schema rather than the migration source, because a key
      can arrive by a later `ALTER`, with `tenant_databases` as the control proving the same reader does find keys in
      the same database.
      The instruction follows, unchanged, because 13a inherits its controls and its wording:
      `VerticalSliceLayoutTest` (every slice has a handler and a
      mirrored test folder — the first executable form of `docs/patterns.md` §3); no FX migration under the shipped
      tenant tree; no database creation or dropping outside `EngineDatabase`; `sessions` carries no foreign key to
      `users` (finding 6). Covers AC-37's remainder and the hygiene non-functional requirements.
      **Two static checks added by §11.2 and they are the ones that make the constraint enforceable rather than
      advisory:** (a) **no source file but `EngineDatabase` issues a database drop at all** — `tools/clean-engine.php`
      is **not** exempt, because the tool is report-only (§11.2 rule 4) and an exemption for it is exactly the hole
      through which the sweep could be restored with the gate still green; the owning class is additionally asserted to
      still issue **exactly one** such statement, so the scan cannot pass by matching nothing;
      (b) no test double, stub, fake, subclass or alias of `ReservedDatabaseName` exists anywhere in the branch — the
      permissive stub is forbidden by a test, not by a memory of October.
      *This wording was corrected on 2026-10-14 after `dev` implemented (a) with the single exemption and said so.*
      `dev` was right and the earlier two-file wording was wrong: §11.2 rule 4 leaves the tool nothing to drop, so the
      only file that may name a drop is the one that drops a caller-named database. Both checks carry a positive
      control (a forbidden fixture, reported) and a negative one (the same words in prose, ignored), scan code with
      comments and docblocks removed, and **scan their own file** — a scanner that exempts itself can be used to hide
      what it looks for.
      **Two more checks added 2026-10-15 by ruling §12.1 — the rules follow a statement into a shared helper:**
      (c) **every file under `packages/kernel/src/Schema/Support/` is scanned under AC-33's and AC-34's statement
      rules with the created-table set empty and no phase** — a helper has no phase comment, so it can never be the
      contract step that licenses a drop, a rename, a `change` or a foreign key onto an existing table, and AC-37's
      connection-name and schema-qualified rules apply to it unchanged. The existing raw-SQL patterns already cover
      `JsonValidity`'s `DB::statement` shape, so the rule is reused rather than rewritten;
      (d) **a migration file imports only `Illuminate\…` and `Flexipos\Kernel\Schema\Support\…`** (MEASURED
      2026-10-15: the 25 shipped migrations import exactly six symbols, all inside those two namespaces), which is
      what makes (c) complete — without it a helper placed in a third namespace is invisible again, and with it there
      is no third place a statement can hide. One further tooth, because it is the real failure mode of a text scan:
      in a scanned helper the first argument of `DB::statement()` / `DB::unprepared()` must **begin with a string
      literal**, so the statement's verb cannot arrive in a variable the scan cannot read.
      Both carry the usual controls: a fixture helper that drops, and a fixture migration importing outside the two
      namespaces, are **reported**; the same words in prose are not; and because the real Support directory and both
      shipped trees are **silent today**, red must be proven on the fixtures first or these two checks prove nothing —
      the R-4 shape, in this step.
      This replaces "additive by construction" as the *proof*. `dev` is right that both helpers are additive today and
      right that the docblocks say so; the ruling is that a property of today's implementation is not a property of
      the type, and this project has twice been bitten by a claim that was true when it was written. The docblocks
      stay as explanation; the scan becomes the authority. Scanning helper sources has its own failure mode — a scan
      over a *set of files* is only as good as the set — which is exactly why (d) ships with (c) and not after it.
- [x] **Step 13a — the three rule changes step 13 did not carry.** [dev] **DONE**, commit `8c9a5b1`, all three in one
      commit over one file's rules as this bullet asked. Two shipped teeth are **stricter than the wording and say so
      in `SourceRules`**: the scanned helper form covers the method call as well as the static one, and **both**
      connection names are forbidden in a helper rather than the one a tree would pick — a helper is in no tree, so
      there is no "other" connection for it to name. Check (d)'s scope rule carries the argument as a file: the (d)
      fixture holds the *same* import FX-3 does and differs only in where it lives, which is §14.2's scope-not-
      exemption ruling made executable. Red was proven on planted fixtures first, as the R-4 shape requires, and the
      prose control goes red when the helper scan is made to read raw text instead of stripped code — the control's
      whole job. §14.1's tooth landed with it: `MigrationRunRefused::contractStepBlocked()` now names
      `tenant:migrate --to=<version − 1>` as the next action, with a mutation proving that exact case red.
      The instruction follows, unchanged:
      Checks **(c)** the `Schema/Support/`
      statement scan and **(d)** the migration-import rule, both as worded in step 13 above and with its controls,
      plus **(e) AC-35(a) tightened to require both of `decimal()`'s arguments** (§9 finding 10, ruling §12.3) — a
      three-line change in `SourceRules::decimalStatesPrecision()` plus one fixture. All three land in **one commit**
      with one `qa` pass over them, rather than reopening the ticked step 2, because they are the post-step-4 changes
      to one file's rules.
      **§14.2 scopes (d) and the scope is part of the check, not an exemption:** (d) is applied to the files under
      the **two shipped trees** (`database/migrations` and `database/migrations-tenant`, both read from
      `config('schema.*')`, never from a literal), and fixture migrations under `tests/Fixtures/` are **out of its
      subject** — which is why FX-3 may import `FixtureMigrationLog`. An exemption list would rot (§7); a scope rule
      stated once does not. The tooth that keeps a scope rule honest: the check asserts the set of files it scanned
      is **non-empty and equal to the two configured trees' contents**, so a scope that silently becomes empty is red
      rather than green.
      Red is proven on planted fixtures first, because the real `Support/` directory and both shipped trees are
      silent today (the R-4 shape).
- [x] **Step 13b — one authority for "which statuses may be migrated", and the restore that follows from it.
      [FIX] BUG-1.** [dev] Ruled in **§16.1**. Four changes, one new file, one test file:
      `packages/kernel/src/Tenancy/TenantConnectionPurpose.php` gains `acceptedStatuses(): array` — `Serving` returns
      `[active]`, `Migrating` returns D-11's four — and `permits(string $status): bool`, deny-by-default over that
      list; `Tenancy::enter()` step 4 stops calling `$database->isActive()` and calls `$purpose->permits($database->status)`,
      throwing the same `TenantDatabaseNotActive` with the status named; `PlanMigrationRunHandler`'s two constants are
      **derived** from `TenantConnectionPurpose::Migrating->acceptedStatuses()` rather than written out, so planner and
      gate cannot disagree by construction; and `MigrateTenantHandler` restores **the status it entered on** instead of
      the literal `active`, with one documented exception (`failed` → `active`, §16.1).
      `TenantDatabase::isActive()` and `TenantMigrationTarget::isActive()` stay — the serving path and AC-18's control
      both use them — but `TenantMigrationTarget::isActive()`'s docblock, which currently *states the defect as the
      design* ("the one status that permits a migration connection"), is corrected in the same commit.
      **Red first, on sqlite, no engine:** `qa`'s `QaPlannedStatusReachabilityTest` is already the red test and must go
      green unweakened; its `active` row is the control. Add the gate's own dataset beside `TenancyResolutionTest`'s:
      a **serving** entry on `read_only`, `provisioning` and `failed` is still refused (if those turn green too, the
      waiver has leaked into serving, which is the one way this fix can be worse than the defect).
      **The single-authority tooth, in the shape step 5 set:** one test asserts
      `PlanMigrationRunHandler::NAMED_ACCEPTED === TenantConnectionPurpose::Migrating->acceptedStatuses()` by exact
      equality and `BULK_STATUSES ⊂` it, and a second **moves the key** — a scratch purpose-policy list with one status
      added makes a planned-and-then-refused case impossible to construct, i.e. the reachability test is red only if
      the two lists are read from two places.
      **The refused-lock path interacts with the restore rule and is stated here, once, because this is the step that
      owns "which status is written back"** (`dev`, fix round 1, confirmed against `MigrateTenantHandler`): a tenant
      whose per-tenant lock is held elsewhere ends its job `skipped` — a status the run already counts — writes **no
      mapping at all**, never calls the migrator, and the run carries on. It needs no restore *because the lock is
      taken before the `migrating` write* (§15.1's order), so there is nothing to restore; that ordering is the
      precondition of this paragraph and a later edit that moves the lock after the status write turns "no restore
      needed" into a silent status leak. One test asserts the pair together: a skipped job leaves
      `tenant_databases.status` at the entry status **and** `schema_version` untouched.
      Covers AC-40, AC-46, AC-51, AC-52's retry path. *Order: before step 14a; it changes what an engine test of the
      execution path would assert.*
- [ ] **Step 13c — the contract-step blocking set is the fleet. [FIX] BUG-7.** [dev] Ruled in **§16.2**: the
      implementation is wrong, not the ruling. `PlanMigrationRunHandler::tenantsBelow()`'s blocking read stops reading
      `whereIn('status', BULK_STATUSES)` and reads **every `tenant_databases` row whose status is not `provisioning`**
      — an exclusion of exactly one value, deny-by-default in the opposite direction to 13b's allow-list, so an
      unrecognised status **blocks**. **It needs a test and it is cheap**: a bulk run with one `active` tenant at
      V − 1 and a second tenant at 0 in each of `suspended`, `failed`, `migrating`, `moving`, `restoring`, `archived`
      and one unknown value is refused and names the blocker, with `provisioning` as the control that does **not**
      block. No engine, no fixture database.
      **Stated so a later tidy-up cannot undo it:** 13b's set and 13c's set answer different questions and must not be
      merged. One test asserts they differ — a status the planner refuses (`suspended`) still blocks a contract step —
      because the day they are "unified" the contract guard silently stops seeing six statuses again.
      Covers AC-58. *Order: independent of 13b, may share its commit.*
- [ ] **Step 13d — a mechanism nothing calls is not shipped: the reachability check.** [dev] §16.3 item 2, and `qa`
      is right that §7 is where it belongs. `VerticalSliceLayoutTest` proves a slice has its parts; the sibling it
      needs proves something **calls** them.
      **The wording in this bullet's first draft — "named as a call in at least one production file outside
      `Support/`" — is withdrawn: `dev` measured it red on 27 public methods of which 3 are defects, and §17.1
      replaces it.** Build the check as §17.1 specifies: each `Support/` class declares **one consumer kind**
      (`Mechanism`, `SchemaVocabulary`, `RuleEvaluator`), the kind names the caller set the check searches, the
      default when nothing is declared is `Mechanism` (the strictest), there is **no per-method exemption anywhere**,
      and the per-kind census is pinned as exact counts so that reclassifying a method to silence it is itself red.
      Controls both ways: a planted fixture Support class with an uncalled public method is **reported**; the same
      name in prose and, for a `Mechanism`, in a test file is **not**. The scan asserts its own scanned set is
      non-empty **per kind**, as 13a's scope rule requires, and the call detection runs over `SourceRules::codeOnly()`
      with the evasion set §17.1 enumerates (BUG-5's standing rule applies to this rule too).
      Two known reds it must produce on day one, and they are [FIX] BUG-3 and [FIX] BUG-4's real content:
      `PerTenantLock::acquire()` has no caller (so `MigrateTenantHandler` takes the lock and reports a locked tenant as
      not run), and the credential-rotation retry **does not exist** in `src/Tenancy` (AC-16: evict, re-read, retry
      exactly once, two attempts and no more). Both pass every structure check today, which is the whole argument for
      this step. Covers AC-49's wiring half and AC-16's wiring half; neither becomes PASS by being wired — AC-49 and
      AC-65 stay NOT VERIFIED behind G-1 (§14.4) and AC-16's behavioural half needs an authentication failure the
      stand-in cannot produce.
      **Changed by fix round 1, and it changes how this step proves itself.** BUG-3's lock wiring (`9305dcf`) and
      BUG-4's evict-re-read-retry (`c43c219`) are on the branch, so the two day-one reds **no longer exist**: a check
      written now is green the moment it is written, which is the state §7 warns about. Its proof is therefore a
      **mutation, stated and run**: delete `MigrateTenantHandler`'s `PerTenantLock::acquire()` call and the check goes
      red naming that method; delete the second scope-opening call in the rotation retry and the check stays green
      (it is reachability, not arity) while the behavioural cases go red — exactly the division `dev` already
      measured and reported on its own test. Both mutations are written into the test file as the record of what it
      does and does not see.
- [ ] **Step 14a — the engine matrix: the 42 criteria with no engine test.** [dev, graded by `qa`] §16.3 item 1.
      `qa` measured that 10 of the 52 `[E]` criteria have an engine test and 42 have none. This step writes them,
      grouped `engine`, against `ThreeTenantFixture`, reusing `QaSchemaSignatureInstrumentTest`'s instrument (it takes
      any `Connection`) for every shape criterion rather than hand-rolling a second reducer. It may be written **while
      the hold is on** — the tests are red-or-skipped by `EngineOnHold` and that is the declared third state (§11.6) —
      and it must be on disk before step 15 can mean anything. *Order load-bearing: after 13b and 13c, because both
      change what the execution path asserts; before step 15.*
- [ ] **Step 14 — gate wiring and headroom.** [dev] `engine:clean` (report-only, §11.2 rule 4) first in `check`
      — **already true since step 0 and re-confirmed by `dev` on 2026-10-14; this half of the step needs no work**;
      toolchain registry coverage (`DestructiveStatementContainmentTest` spawns no child process, so it stays in
      `test:fast` and must **not** be registered in the toolchain group);
      confirm the T-007 process-timeout headroom ratio still holds for the larger gate, using the existing instrument
      unchanged. Covers AC-64.
- [ ] **Step 15 — QA: the engine matrix and the calibration counts.** [qa] Every `[E]` criterion run and recorded with
      the engine and version it passed on; the master table recount; the counts and observations AC-66 and AC-67 name,
      each with its command; and every criterion found unachievable as worded, with its replacement.
      **Corrected 2026-10-15 after QA run 1, and this correction is the point of §16.3: clearing G-1 verifies ten
      criteria, not fifty-two.** `qa` measured that only **10 of the 52 `[E]` criteria have an engine test of any
      kind** (AC-38, 43, 44, 49, 52, 54, 55, 61, 63's version line, 65), all currently skipped; **the other 42 have
      none**, so lifting the hold leaves them exactly as unverified as they are today. Writing those 42 is **step 14a**
      and it is [dev]'s work with `qa` grading it, not a line inside this step. Step 15 begins when 14a is on disk.
      Nobody is to read "the dump landed" as "the engine criteria pass"; the two are separated by a test-writing step
      whose size is 42 criteria.
      **`[gated]`. Under the hold, `qa` records every `[E]` criterion as NOT VERIFIED with `ENGINE_HOLD` and gate G-1
      named — never PASS, and never silently absent** (§11.6). The report also carries the incident: sixteen databases
      lost, the mechanism, and the two mitigations it invalidated (§8 R-12). Covers AC-62 as
      narrowed (one engine, per the owner's 2026-10-08 decision), AC-63, AC-65 as narrowed, AC-66 and AC-67.
      **Three measurements from steps 11 to 13a belong in this step's own report, because AC-66's and AC-67's numbers
      are read against them** (`dev`, 2026-10-15, one fixture slice as the subject):
      - **SQLite fsyncs every bare statement**, so fixture cost is dominated by statement count, not by data volume:
        eight unwrapped calls per tenant cost ~0.9 s, and **one transaction around the fixture writes took a slice
        from 8.78 s to 2.27 s**. This is the input for the headroom judgement §3's finding 7 defers to this step: the
        question "does the engine matrix fit the gate's budget" must be asked of *transaction-wrapped* fixtures, or
        the answer measures the fixture's bookkeeping instead of the matrix. Any later fixture that writes per-tenant
        rows one statement at a time is a regression with a number attached.
      - **The gate now runs 90–91 s with the process-timeout ratio at ~9.9**, inside the band §8's timing note calls
        normal — so step 14's half of AC-64 is a confirmation, not an investigation.
      - The baseline these are read against is the step-13a gate: **exit 0, 720 passed, 11 skipped, 3682
        assertions**, eleven skips of which every one is a declared `[gated]` engine case.
- [ ] **Step 16 — review, and the fixes it names.** [reviewer, then dev]

---

## 7. Test strategy

### The problem specific to this task

**Almost everything here passes against an empty schema.** "No projection table exists", "no `tenant_id` column
exists", "no foreign key to `branches` exists", "no column holds a credential" are all true of a database with no
tables at all. So every absence assertion below names the positive control that proves the check can see what it
claims to see, and every red-first claim names the mutation and **the input at which the mutation becomes visible** —
the T-007 failure was a mutation that passed because at the chosen input the mutated term never bound.

**And since 2026-10-14, one rule above all of them.** A red phase must be **structurally inert**, not merely expected
to be safe: it may not be capable of a destructive statement against a shared resource, and a safety check is never
the thing that is weakened to make a test fail. The constraint, its four rules and the per-step ruling are in §11; they
bind every step in §6 and every step added to this plan later.

**And since 2026-10-15, one rule beside the mutation rule. It now has four instances, and the fourth is a different
shape from the first three.**

> **When a mechanism's absence is masked by something else that produces the same observable — a constraint, a key,
> a type, an earlier gate, or a duplicate of the mechanism's own terms in the read that feeds it — a red phase is not
> evidence until the assertion pins something only that mechanism produces.** Remove the branch, run the test, and
> *read the failure*. If the message came from the engine, the framework or another guard rather than from our code,
> the test is measuring the mask and will keep passing after the mechanism is deleted. Two remedies, and they are not
> interchangeable: **pin something only this mechanism produces** (the exception class **and** a fact only our branch
> knows), or **change the input until only this mechanism can fire**. Which one applies is decided by what the mask
> is — see the four instances below.

**The four instances, because two in two tasks was a pattern and four in one task is the shape of this codebase.**

1. **An earlier gate absorbs it.** T-007's mutation passed because the version floor refused first. Remedy: an input
   below the earlier gate's threshold — *change the input*.
2. **A key or constraint absorbs it.** Step 5's out-of-order refusal: deleting the branch still produces
   `SQLSTATE[23000] … UNIQUE constraint failed` from `schema_versions.version`'s primary key, because renumbering
   always collides with the number already published (`dev`, measured 2026-10-15). Remedy: *pin* the exception class
   **and** the name of the *published* migration — a fact the key's own error cannot contain, because it only knows
   the arriving row. The branch is not redundant with the key: the key refuses at write time naming a column, the
   branch refuses at plan time naming the migration an operator must look at.
3. **Another guard refuses the same run with the same exception class.** Step 9, measured 2026-10-15: with the
   destructive branch deleted, the FX-5 case stayed green, because FX-5 is *also* a contract step and the contract
   guard (AC-58) refused the same run throwing `MigrationRunRefused` — "zero job rows" and "it threw
   `MigrationRunRefused`" both stayed true, and only the message pin saw the difference. **The fix was the input, not
   the assertion:** the tenant was moved to version 7, where the contract guard has nothing to say, and the deleted
   branch then writes a plan — the red the step needed. Worth separating from instance 2: when the mask is a sibling
   guard of the same class, pinning harder is a weaker fix than removing the sibling's reason to fire, because the
   message is the only thing left distinguishing them and a reworded message re-masks it.
4. **Your own pre-filter carries the same terms — and this is the most dangerous, because nothing about it looks
   like a guard.** Step 10, measured 2026-10-15: `attempts < :max` and the status terms were removed from the
   production `WHERE` of the granting `UPDATE` and **all seven behavioural cases stayed green**, because the
   candidate read that feeds the claim re-checks the same terms. In one process the pre-filter never offers a row the
   update would wrongly grant, so every behavioural case in that file was evidence about **the pair**, while AC-48 is
   a claim about **the statement**. Remedy: *pin the statement itself* — `dev` captures the executed SQL from the
   query log and asserts the `WHERE` contains each term. Under the update-only mutation that one case is red and the
   six behavioural ones green, which is the correct signature of an update-only mutation; with both sites mutated all
   seven are red.
   **The warning, and it binds the steps that are left:** a read-then-write pair — a query that selects candidates
   followed by a statement that re-checks them — is the normal shape of this runner, not an accident of step 10, and
   **`MigrateTenant` (step 11) and `RetryFailedJobs` have exactly it**. Wherever a statement's terms are duplicated in
   the read that feeds it, no behavioural test in one process can distinguish the two sites: **pin the statement**,
   by the query log or an equivalent, in the same commit as the statement.

Every absence-and-refusal criterion in §9's table is now expected to say which of the four it pins, and
`reviewer` should treat a behavioural-only proof of a re-checking statement as a finding, not a style note.

### Three suite rules `dev` paid for, binding on every new test file in this repository

Added 2026-10-15 from steps 10, 11 and 13. Each cost a gate run to discover and none is discoverable by reading.

- **An ad-hoc `$this->foo` in a Pest test is a static-analysis error, and the gate runs analysis before the suite.**
  So the cost of that line is not a red test, it is a **full gate run** that never reaches the suite at all —
  the most expensive way in this repository to learn something trivial. State in a test only what the file declares:
  per-test state goes in a closure variable or a named helper, not on `$this`. This rule is beside the other two
  because all three have the same shape: the suite's own rules are enforced by tools that run *before* the thing you
  were trying to measure.

- **Two collected test files may not share a basename.** Naming the gated lock test `PerTenantLockTest.php` beside
  the kernel's `PerTenantLockTest.php` was refused by `ToolchainGroupBindingTest`. The engine-side file is now
  `PerTenantLockEngineTest.php`; the convention for this task is that a gated engine mirror of a unit test takes the
  `…EngineTest.php` suffix, so the collision cannot recur by accident.
- **`*/` inside a file-header block comment is a parse error**, so a glob-shaped path (`packages/*/tests`) cannot go
  in a banner. Use a line comment, or name the path without the glob. This matters because every file in this task is
  expected to carry its reason in a header, and the reasons are full of paths.

### Three things `dev` measured writing the containment checks, binding on the next repository-wide scan

Added 2026-10-14 from `dev`'s report on `5e2e708`. All three are about *static scans over the whole branch*, which
this plan asks for again at step 13 and which FP-2xx tasks will ask for after it.

- **Prune the walk; do not filter it, and do not trust a Finder `exclude()`.** A framework finder's `exclude()` matches
  only at the root it was given, so excluding the dependency directory at the repository root still walks the one
  inside `modules/host` and the one inside each path package — tens of thousands of third-party files, every one of
  them a candidate hit for both detectors. The failure mode is not a crash, it is **a false red whose obvious "fix" is
  to narrow the scan until it is green**, and the narrowed scan is then blind to our own code. `dev` wrote an explicit
  prune list applied during the directory walk (`containmentPrunedDirectories()`); copy that shape, and keep the list
  of pruned directory *names* rather than paths.
- **A containment check is never repaired with an allow-list entry.** `dev`'s own check reported the forbidden phrase
  inside a **string literal** — the refusal message of `ReservedDatabaseNameViolation`. It reworded the message
  instead of exempting the file. That is the precedent: an exemption list is how a containment check rots, and the
  first exemption is always for a file that "obviously" does not mean it. The only standing exemptions are the one
  owning class and the mechanical ones (comments stripped, dependencies pruned).
- **A retracted claim lives in more copies than the one you found.** The sentence "the sweep still lives in the tool"
  was false in **five** places: the live code, three docblocks, and the `engine:clean` script description — *including
  inside the guard test that exists to document the incident*. This project has hit the correct-it-in-every-copy shape
  before; this is its worst instance. When a ruling retracts a claim, grep the branch for the claim, not for the file.

### Proof grades

Graded as this project requires: **independently proven** when a mutation of ours moves the observable,
**corroborating** when no mutation we can make isolates it, **covered by reading** when no affordable input exists.

| Group | Criteria | Grade | Why |
|---|---|---|---|
| The name guard | AC-61 | **independently proven** | Both directions move, and **neither mutation may be executable** (§11.2 rule 2): the permissive direction is proven by *inputs* — thirteen forbidden names handed to the real guard, red if the prefix constant is mutated — and the restrictive direction by mutating the guard to refuse everything, which turns the three positive controls red. A `return true` guard is never built, because during the incident exactly that object was handed to something that could act. |
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
| Reachability of a mechanism | AC-16, AC-49, AC-65's lock half | **covered by structure only, until step 13d** | Added 2026-10-15 after QA run 1. `PerTenantLock` has a unit test, an engine test and a layout check, and **no caller**; the rotation retry has a decision, a plan line and **no code**. Both pass every structure check in the branch. A layout check proves a slice has its parts; only a **reachability** check proves anything calls them, and a mechanism nothing calls is a mechanism that does not ship. The grade of any criterion whose mechanism has no production caller is **not** "independently proven" however good the unit test is. **Updated 2026-10-15 (§17.1):** the check's own grade is **independently proven only by mutation** — both of BUG-3's and BUG-4's day-one reds are now fixed, so a check written today is green on arrival and its evidence is deleting a known call site, not observing a red. Its failure mode is tuning, and the census counts are what make tuning visible. |
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
- The three-tenant fixture, built once per test file and dropped **by name** afterwards (never by a sweep), plus one
  deliberately failing test followed by an assertion that the reserved-prefix database list is identical to the list
  taken before it (AC-61's "leaves nothing behind, including after a failure"). The same comparison over the
  **non**-prefixed names is kept as an **alarm, not a proof**: the engine is shared, so another project can move that
  list without us (R-12).
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

### A standing rule for every source rule in this task (added 2026-10-15, BUG-5's lesson)

`qa` found that `$table->foreignUlid('x')->references('id')->on('employees')` creates a foreign key and that
`SourceRules` does not see it, because the rule matches `->foreign(`, `->constrained(` and `add foreign key`. That is
the spelling **D-5's four deferred keys will most naturally take**, so the rule is blind in exactly the case it was
written for. The same shape defeats AC-24's and AC-35(c)'s rules through raw SQL (BUG-6).

**The lesson is general and it binds every rule in `SourceRules` from here: a rule proven only against the spelling
its author had in mind is proven against one spelling.** So each rule carries, beside its violating fixture, an
**evasion set** — every other spelling of the same effect that the framework supports — and `qa`'s
`QaSourceRuleEvasionShapesTest` is the model and must not be deleted when `dev` turns it green. For a builder rule
the evasion set is enumerated from the vendored `Blueprint` / `ForeignIdColumnDefinition` API, not from memory; for
anything raw, the rule's subject is the statement text and the fixture is a `DB::statement()`. Where a spelling
genuinely cannot be caught in source, the rule says so in its docblock and names the live `information_schema` check
that catches it instead — which is the honest form, and the one that stops a reader believing in a defence that is
not there.

### A standing rule for every absence assertion in this task

Collapse whitespace **and** strip markdown emphasis before asserting a phrase is present or absent, and assert over a
parsed structure rather than a raw grep wherever one exists (`information_schema` for shape, a tokenised read for
source). On this project a bold marker has already defeated a literal search, after a line wrap had defeated one, and
a naive grep for `tenant_id` in a migration would match a comment that merely mentions the rule.

---

## 8. Risks and rollback

| # | Risk | Likelihood | What it costs | Mitigation |
|---|---|---|---|---|
| R-1 | **A test drops one of the owner's databases on this engine, several of which are live client systems.** **This risk has occurred.** On 2026-10-14 sixteen of the 56 were dropped, unrecoverable (§11.1). Likelihood and cost are no longer estimates. | **occurred** | sixteen databases, no backup | What is in place now: no sweep anywhere (§11.2 rule 4), and **exactly one file in the branch issues a drop at all**, enforced by a static test (`DestructiveStatementContainmentTest`, shipped `5e2e708`); two independent prefix rules, the second of them at the point of effect in `EngineDatabase` and in `engine:clean` as a gate-stopping tripwire on the two rules disagreeing (rule 4a); drops by name only; `ReservedDatabaseName` may never be stubbed or doubled, enforced by the same static test; enumeration in PHP, never SQL `LIKE`; the §11.4 gate; `ENGINE_HOLD` on. What was in place before and did not help: step 0 first, a reviewed bullet, and a guard class that a stub replaced. |
| R-12 | **The engine is shared.** Another project ran migrations against `127.0.0.1:3306` during our run (MEASURED, §11.1), and the owner's own data lives there. Schema counts, locks and concurrency are all shared with software this team does not control. | certain | a destructive mistake lands on someone else's production data; a flaky lock or count test that is not our bug | **Two mitigations named elsewhere in this plan are invalidated and withdrawn:** (i) *"notice it and kill the process"* — `taskkill /F` on the PHP client does **not** abort an in-flight `DROP DATABASE`; the server completes it; (ii) *any step assuming exclusive use of the engine* — including "the schema list before equals the schema list after" as a *proof of our own correctness*; it is still a useful alarm but another project can move it. What replaces them: structural inertness of the red phase (§11.2), drops by name only, the G-1 dump, and treating every statement against that engine as a production change. |
| R-13 | The hold blocks the engine steps, and the pressure to clear it comes from wanting the task finished. | high | a safety measure removed for schedule reasons | Only the owner clears it (G-2). The task is sequenced so that steps 2 to 9, 12's logic and 13's checks all proceed under the hold, so the hold costs days of *engine* work rather than the slice. `qa` records held criteria as NOT VERIFIED, which keeps the cost visible instead of making it a reason to remove the hold. |
| R-2 | The reserved prefix is one character from a real name, as `flexipos-master` already is from `flexipos_master`. | medium | same as R-1 | The prefix contains `t8test`, which no existing name does; step 0 re-measures its wildcard expansion and `qa` records the output. Uppercase and non-`[a-z0-9_]` names are refused outright because `lower_case_table_names = 1` would fold them. |
| R-3 | **The gate grows a lot.** Seventeen steps of new tests, many of them creating and dropping databases. | high | a slow or timing-out gate | Step 14 re-checks the T-007 headroom instrument without changing its ratio; AC-67 records the before and after with the cold-or-warm state stated. If the fast loop regresses, excluding the `engine` group from `test:fast` only — never from `check` — is the lever, with the measurement attached. |
| R-4 | A migration is written correctly and the *rule* that was supposed to catch its class is written afterwards, so it never goes red. | high, and it is this project's named failure | a hollow green that survives for years | Step 2 is ordered before steps 3 and 4 for exactly this reason, and each rule is red against its fixture before the tree exists. |
| R-5 | `ascii_bin` forces a collation literal, which AC-35(d) forbids. | certain | a criterion that cannot be met as worded | One helper owns the literal; AC-35(d) is re-worded (§9, finding 1). |
| R-6 | The stock `UserFactory` or `DatabaseSeeder` writes `password`, which step 3 removes. | medium | a red gate on code D-18 says not to rewrite | D-18 forbids *rewriting* the model; it does not forbid a one-line factory fix. If the gate goes red there, `dev` fixes the factory in step 3 and records it, rather than restoring the column. |
| R-7 | `tenants.sequence` needs a raw `ALTER` because Laravel couples `autoIncrement()` to `primary key`. | certain | an unexpected raw statement in a migration | Stated in §5 so it arrives as a design decision instead of a surprise; the statement names no collation, charset or engine, so AC-35(d) still holds. |
| R-8 | The run continues past a failed tenant (D-13), so a structural bug reaches every tenant before anyone reads the first failure. | medium | a bad migration on 200 shops | Out of scope by D-9, and it is the one risk in this task with no mitigation inside it. Escalated in §10 as a question for the owner, because the mitigation (canary halt) is cheapest to add before FP-206 provisions real tenants. |
| R-9 | `TenantDatabase` gains two properties and a T-007 test pins its exact property list. | certain | reads as a weakened test in review | The pin is *amended*, not deleted or skipped; the sibling test forbidding password-shaped names is untouched and covers both new names. Flagged here so `qa` and `reviewer` see it as planned rather than found. |
| R-10 | MariaDB's `JSON` is a `LONGTEXT` alias, so "invalid JSON is refused" may not hold without an explicit check. | medium | three criteria quietly passing on the wrong artefact | Step 1 was to measure it and is now blocked (§11.4), so steps 3 and 4 write `CHECK (JSON_VALID(…))` **unconditionally** — the conservative branch costs a redundant check if the alias already carries one, and the measurement can remove it later. The criterion is asserted against a real insert either way, once the gate clears. |
| R-11 | Engine tests connect with a bare PDO and get the server's non-strict `sql_mode` (MEASURED: no strict mode on this server). | medium | refusal criteria passing as truncations | Every engine test connects through the Laravel connector, whose stanza sets `strict`. Asserted once in step 1. |

| R-14 | **The serving version gate fails open when `APP_REQUIRED_SCHEMA_VERSION` is unset.** As shipped at `23b3beb` both config keys default to `0`, zero means no floor, and a deployment that forgets the variable serves a tenant at schema version 0 with only `tenant_databases.status` in the way — against `docs/domain-core.md`'s rule that a half-migrated tenant never serves. | medium (it is one forgotten line in a deployment nobody reviews) | money taken on a half-migrated tenant, which is the harm the whole gate exists to prevent | Step 7a: absence becomes a named refusal on the serving purpose, an explicit `0` stays the recorded opt-out, and the refusal is rendered 503 without `Retry-After` so it cannot be read as "a migration is running". Verified by the mapping-at-0-with-the-variable-unset input, which is red today. Ruled in §13.1. |

**Rollback.** Nothing in this task is irreversible *in the repository*: the branch is `ai/T-008-…`, no tenant database
exists anywhere yet, and no migration here runs against anything but a reserved-prefix test database. The only
irreversible act available is dropping someone else's database — which step 0 was supposed to make impossible and did
not, because the step that proved the guard was itself allowed to drop things (§11). The correction is structural: the
red phase cannot reach a shared resource, there is no sweep, and the engine steps wait behind a verified dump. There
is no `down()` and no runner rollback (D-8); a mistake in the baseline before FP-206 provisions a tenant is corrected
by editing the baseline, which is the one window in this project's life where that is free — which is also the
argument for pinning the outbox's own columns now (D-1).

---

## 9. Criteria that are not achievable as worded

`ba` asked to hear these before QA rather than after. **Twelve**, and the replacement for each. Findings 1 to 8 and
11 to 12 need a spec edit by `ba`; **findings 9 and 10 explicitly do not** — they are recorded here so `qa` reads a known, ruled
divergence rather than a defect. Findings 1 to 5 change nothing in this plan; 6 to 8 were found after the incident of
§11 and findings 7 and 8 are already implemented in the plan's own terms (§11.6 and step 13), so the spec edit is a
catch-up, not a decision.

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

6. **AC-61's "returns `flexipos-master` and `flexipos-master-new`".** MEASURED again on 2026-10-14, after `dev`'s own
   databases existed: `SHOW DATABASES LIKE 'flexipos_%'` matched **five** names, not two.
   **Replacement:** "returns the owner's real databases among others — the matched set is whatever the engine happens
   to hold when the statement runs, which is why it may not be enumerated in SQL at all." The criterion's control stays
   as it is; only the claim that the trap is a known pair of names is withdrawn, because it invites a reviewer to
   believe the hazard is reviewable.
7. **AC-63 against a declared hold.** As worded, an engine that is not reachable must fail. A deliberate,
   owner-declared suspension (`ENGINE_HOLD`) is a third state and the criterion does not distinguish it; read
   literally, it requires a permanently red gate for a condition no agent is allowed to clear.
   **Replacement:** "an engine that is *unreachable* fails with the engine named. A *declared suspension* — one
   recorded in the suite's configuration, raised as a named exception by any code that tries to connect, and asserted
   by an inverted test while it is in force — may skip the tests that require a connection, and `qa` records each
   affected `[E]` criterion as NOT VERIFIED with the suspension and the condition that clears it named. A suspended
   criterion is never reported as PASS." Reasoning and the four decay markers are in §11.6.
8. **AC-61's source scan for SQL `LIKE` has a real false positive.** `dev` found one: the English word "like" inside an
   error string. A tokenised scan correctly ignores comments and docblocks, but a string token is code, and prose in a
   message is not SQL.
   **Replacement:** the rule is about SQL, so the scan matches `like` only in a token that *also* carries a SQL
   keyword (`select`, `show`, `from`, `where`, `having`, `and`, `or`, `databases`) or is immediately followed by a
   quote or placeholder. Controls unchanged, plus one more: an error string containing the English word "like" is
   **not** reported, and `"show databases like 'flexipos_%'"` **is**. (Rejected alternative: ban the word from
   messages. It makes the rule's enforcement depend on prose style, and the next person writing an error message does
   not know the rule exists.)

9. **AC-6's name list against AC-6's own governing clause. No spec edit, and the detector stands as `dev` built it**
   (ruling §12.2). Read as a list of names, AC-6 reports three columns `docs/database.md` requires:
   `platform_settings.is_secret` (a flag), `tenant_databases.credential_version` (a counter) and
   `credential_rotated_at` (a moment). But the criterion's governing clause is its first one — *no column in any
   master table **can hold a credential value*** — and the names that follow are the heuristic by which that is
   detected, not the requirement. A `tinyint`, an `int` and a `datetime` cannot hold a password, so exempting **by
   column type, read from the schema** is faithful to the clause that governs and only diverges from the illustration.
   A name-based exemption list would not be: this project has already ruled that shape rots, and its first entry is
   always for a column that "obviously" does not mean it. The control is what makes the distinction real and it
   exists: `credential_version_text` as a **string** *is* reported. The rejected alternative was rewording AC-6 in
   `ba`'s file; rejected because the wording is not wrong — it names a property and then a heuristic, and the
   implementation keeps the property.
10. **AC-35(a)'s gap, closed by making the rule stricter than the criterion.** AC-35(a) says "no `decimal` call lacks
   a precision argument", so `decimal('x', 18)` — precision given, scale defaulted by the framework to **2** —
   passes, and `dev` implemented it exactly as worded and declined to go further unasked, which is the right
   discipline and the reason it asked. Ruled: **tighten the rule to require both arguments.** The harm class is the
   criterion's own — a silent truncation decided by a default nobody wrote — and only its magnitude differs from a
   bare `decimal()`: this design's money and quantity columns are 18,4 and 18,6, and a scale that defaults to 2
   rounds a 0.001 quantity to zero exactly as `DECIMAL(10,0)` rounds 0.5 kg. Every column in both shipped trees is
   fully specified, so **nothing is wrong today and the tightening is green on the tree** — which means the fixture
   control is the whole proof: `decimal('x', 18)` must be **reported**, `decimal('x', 18, 4)` must not. The rule is
   therefore **strictly stronger than AC-35(a) as worded**, in the same way step 2's AC-37 half is, and like that one
   it is recorded rather than spec-edited: a test stricter than its criterion is safe when it is asserted both ways
   and written down; it is only dangerous when it is undeclared.

11. **AC-21's named control is arithmetic, not a property of our code.** The criterion names "the comparison fails
   when run with a constant one higher" as its control. That is `highest + 1 > highest`: it would pass against an
   empty repository, and `dev` kept it only because the criterion names it (ruling §13.3).
   **Replacement:** "the value `.env.example` ships for `APP_REQUIRED_SCHEMA_VERSION` **equals** the catalogue's
   highest published version, and the assertion moves when either side moves — proven by pointing
   `schema.catalogue_fixture` at a scratch catalogue whose highest version is one higher (red) and one lower (red),
   which is available now that step 5 proved the configuration key movable. The arithmetic comparison may stay as
   documentation and is not the control." Note the scope: equality binds **`.env.example`**, the value a deployment
   starts from, and deliberately not every deployment — §13.1 explains why a live floor is allowed to lag the
   catalogue during a rollout, which is the same reason the floor is not derived from the catalogue.
12. **AC-19 and AC-20 are marked `[E]`, and their own content says they need no engine.** AC-19's requirement is that
   the refusal happens *before any network connection is attempted*; a criterion whose subject is the absence of a
   connection cannot have "connect to a real engine" as its means of verification. Both are fully provable on the
   suite's stand-in — the inputs are three integers on a mapping record and the observables are a named exception, an
   HTTP status, a header and one log line — and they **are** proven there today, at `23b3beb`.
   **Replacement:** drop `[E]` from AC-19 and AC-20, so `qa` may record them PASS from the stand-in; an engine run
   after G-1 is corroborating, not the proof. **Why this is worth a spec edit rather than a shrug:** recording a
   safety gate that demonstrably holds as NOT VERIFIED spends the hold's credibility on the wrong criteria, and
   `docs/domain-core.md`'s "a half-migrated tenant must never serve" is exactly the property a reader needs to be able
   to see is enforced. AC-12 and AC-13 argue the same way but **keep** `[E]`: the joined read's correctness depends on
   real column types and collations, which the stand-in does not have.
   AC-19 also needs one case it does not currently name — what happens when `APP_REQUIRED_SCHEMA_VERSION` is
   **absent**, as opposed to zero. §13.1 rules it; the spec should carry it as part of AC-19.
13. **AC-58 names a dependency the catalogue cannot express.** "A contract step that depends on an expand version"
   reads as if the dependency were declared; no tag and no field carries it (§14.1). **Replacement:** AC-58 should
   say the dependency is *the version immediately before the contract step, until a declared dependency exists in the
   catalogue*. Behaviour and dataset unchanged — this stops the criterion implying a field, and stops the next reader
   treating the shipped inference as a bug.
14. **D-11's status list spans two tables' vocabularies.** The runner must select on one column and it selects on
   `tenant_databases.status`, the column serving is gated on (§14.3). **Replacement:** `ba` records the authority in
   D-11 and states what the values belonging to `tenants.status` were meant to mean there. This is a decision-file
   correction, not a criterion change; nothing in the behaviour moves.

**Not a spec change, but `ba` should know:** AC-1 lists `sessions` among the master system tables where
`docs/database.md` §4.10 lists `personal_access_tokens`. AC-1 governs (D-18), and `qa`'s recount of finding 1 will
otherwise look like a defect.

---

## 10. Questions for the human

Three, and none blocks a step. All are for the lead to relay, not for `ba`.

1. **Canary halt before FP-206 (risk R-8, `ba`'s open question 3).** The design continues past a failed tenant
   (D-13), so a structural migration bug is applied to every tenant before anyone reads the first failure. The
   mitigation is a canary halt and it is out of scope by D-9. It is **cheapest to add now**, while the runner is being
   written and no tenant database exists; after FP-206 it is a change to a running operation. The owner may want it
   before FP-206 rather than after. No step here depends on the answer.
2. **MySQL 8.4 parity (finding 3 in §9).** The owner's 2026-10-08 decision defers it, which the plan follows. What it
   leaves open is *when*: AC-62's whole purpose is to catch an engine-specific shape before tenants hold data, and the
   longer it waits the more schema there is to compare. A named follow-up row before the first real tenant is
   provisioned would keep the instrument honest; after FP-206 it is a comparison against production shapes.

3. **A separate engine for the test suite, once the dump exists (new, after §11).** The owner has decided FlexiPOS
   continues on the local engine and the plan now carries that decision with its three conditions (§11.4). The
   architectural observation to put in front of the owner anyway: every structural protection in §11 exists to make one
   shared server survivable, and all of it becomes redundant the moment the suite points at an engine that holds
   nothing else — the Docker MySQL 8.4 that FP-194 needs for the cross-engine comparison is *already* owed before
   FP-206. One instance would discharge FP-194 and R-12 together. Not blocking: nothing in T-008 waits on it, and the
   §11 protections are wanted regardless, because the next agent will still be a `127.0.0.1` away from the owner's
   data.

Everything else in this task is decided in §0, §3 and §9 and logged there.

---

## 11. The engine gate and the red-first constraint

Added 2026-10-14, after the incident described below. **This section overrides anything earlier in this plan that
conflicts with it**, including the parts of §6 and §8 written before it.

### 11.1 What happened, in the terms that change the design

While proving step 0's guard red, `dev` ran an `EngineDatabase::dropAllReserved()` sweep with
`ReservedDatabaseName` **stubbed to return true for every name**. The filter returned all 56 schemas on the engine and
the sweep dropped them alphabetically until it was stopped at `f`: **sixteen of the owner's databases, several of them
other clients' systems, none backed up.** Two facts `dev` then measured, and both of them invalidate reasoning that was
in this plan:

- **`taskkill /F` on the PHP client does not abort an in-flight `DROP DATABASE`.** The server completes it. Every
  "notice it and kill it" line of reasoning anywhere in this plan is not a mitigation and is withdrawn.
- **The engine is not ours.** Another project was running migrations against `127.0.0.1:3306` during the run. Any step
  that assumes exclusive use of that server is wrong; concurrency, locks and schema counts are all shared with software
  this team does not control.

`dev`'s own conclusion, which I adopt as the governing rule of this task: *"I believed the red phase was safe because
only the guard was wrong and nothing dropped anything yet — both halves were false."*

### 11.2 The constraint (binds every step, including steps nobody has written yet)

> **A step whose red phase can issue a destructive statement against a shared resource is not an acceptable step,
> however carefully it is reviewed.** Such a step is rewritten, not reviewed harder. "Destructive" means any `DROP`,
> `TRUNCATE`, `ALTER … DROP`, `DELETE`/`UPDATE` without a key, or any statement at all against a name the step did not
> itself create. "Shared" means the engine at `127.0.0.1:3306`, or anything else on this machine that another project
> or the owner also uses.

Four rules follow, and they are what a reviewer checks a step against:

1. **A red phase proves that the assertion can fail. It does not have to run the real effect against the real
   resource.** The two are routinely confused and the confusion is what cost the owner sixteen databases.
2. **A guard is never stubbed permissively.** The only mutation permitted to a guard is one that makes it refuse
   *more* (`return false`-shaped), which is proven red by the **positive** controls. The permissive direction is proven
   by the *inputs* — a forbidden name handed to the real guard — never by a weakened guard handed real inputs.
3. **Prefer, in this order:** (a) red against a fixture or in-memory double; (b) red by **omitting the mechanism**,
   so the assertion fails because the feature is absent rather than because a safety check was removed; (c) where the
   mechanism is itself destructive, red on a resource that cannot matter — the suite's sqlite connection, a temporary
   table, a scratch directory, or a reserved-prefix database **the same test created in the same test**.
4. **No sweep, ever.** No code in this repository derives a list of things to drop from the *engine's* contents. It
   drops names a caller named, one at a time. `EngineDatabase` has no sweep and must not gain one; `recreate()` is what
   makes a leftover harmless, because it drops *that one name* before creating it. Consequently
   `tools/clean-engine.php` is **report-only**: it lists leftover reserved databases, drops nothing, and its
   leftover-report path always exits 0 — the correctness it used to provide is already provided by `recreate()`, so
   the dropping version bought nothing and risked the whole engine. (Alternative rejected: keep the dropping sweep
   behind a count ceiling of 32 and two prefix rules, which is **what the tool carried on 2026-10-14 before this
   ruling landed and no longer carries anywhere** — the sweep, the `exec()` and the ceiling were deleted in commit
   `5e2e708`. It is much safer than what ran — the ceiling *would* have stopped
   the incident's 56-name filter, and the second literal would have stopped it too — but it is still a list of drops
   computed from the engine's contents, and the ceiling is a guess: a filter that wrongly returns twenty foreign names
   passes it. We are not obliged to make that mechanism safe, because `recreate()` already does the only job it has.
   Delete the capability instead of bounding it.)

   **Rule 4a, ruled 2026-10-14 on `dev`'s question: the tripwire exit is required, and it is not a deviation.**
   Rule 4's "exits 0" binds the **leftover report** — a leftover reserved database is information, never a failure,
   because `recreate()` makes it harmless. It does **not** bind the one other thing the tool still does.
   `CLEAN_ENGINE_PREFIX_LITERAL` survives in `tools/clean-engine.php` with no destructive statement left to stand in
   front of, and its remaining job is to be a **tripwire for the two prefix rules disagreeing**: if
   `ReservedDatabaseName` calls a name reserved that the independent literal does not, the tool prints both names and
   **exits 1**. That condition is the exact signature of the permissive guard that destroyed sixteen databases, and it
   is the only automatic detector of it anywhere in the branch — a stubbed or widened guard reaches the gate's *first*
   line and stops it. Exiting 0 on a detected rule disagreement would mean printing the incident's signature into a
   log nobody reads. So: **leftover report → exit 0; rule disagreement → exit 1; a drop → does not exist.** Nobody may
   later "align" this with rule 4 by making the disagreement path exit 0, and nobody may remove the literal on the
   grounds that it no longer guards a statement. If the tool is ever made to exit 1 for anything *else*, that is the
   change rule 4 forbids, because it turns a leftover back into a failure and invites a sweep as the fix.

### 11.3 The two independent prefix rules, and what the measurement actually shows

`EngineDatabase` re-checks the prefix **at the point of effect** against **its own literal**, deliberately not reading
`ReservedDatabaseName::PREFIX`: reading the constant would make it a second *call* to one rule, and the requirement is
two *rules*. `tools/clean-engine.php` carries the same independent literal, but **since `5e2e708` it has no statement
left to stand in front of**; there it compares the two rules and fails the gate when they disagree (rule 4a above).
`dev` measured the value — with the guard class stubbed out,
11 of 13 forbidden names were still refused by the second rule alone.

The ratio understates it and the plan should record the stronger reading: the two names the second rule misses are
`flexipos_t8test_../x` and a 70-character reserved name — both **inside our own prefix**. The second rule alone catches
**every foreign name**, which is the entire class that can destroy data; what it does not catch is malformed names that
could only ever damage our own test schemas. That is the correct division of labour between the two rules and it should
not be "improved" by making the second rule a copy of the first.

### 11.4 The gate on the engine steps (the owner's three conditions)

The owner has decided FlexiPOS continues on the local engine. The lead's three conditions are carried here as a gate,
and **no step marked `[gated]` in §6 may begin until all three hold**:

- **G-1.** A full dump of all **43 surviving databases** to `D:` exists and has been verified non-empty.
- **G-2.** `ENGINE_HOLD` stays on, in `modules/host/phpunit.xml`, until G-1 holds. It is on by default. Clearing it is
  the owner's decision, never an agent's.
- **G-3.** No sweep operation, ever (§11.2 rule 4).

Gated steps: **1** (DDL probes), and the engine halves of **3, 4, 10, 11, 12, 15**. Everything else in this task —
every static rule, every planning refusal, every master-table write in the suite's sqlite connection — is reachable
with the hold on, which is why the hold costs the team days rather than the task.

### 11.5 How each remaining red-first step proves red now

The plan required stub-based red-first proofs at steps 2, 5 and 9 to 12. Ruled per step, per §11.2 rule 3:

| Step | How red is proven now | Can it reach the engine? |
|---|---|---|
| **0** (done) | **(a) fixture.** The rule is proven against the *recorded* 56-name snapshot offline, and point-of-effect refusal against the suite's **sqlite** connection: a forbidden name throws before any statement, a reserved name reaches the driver and sqlite rejects `CREATE DATABASE` — which is what proves the guard is a guard and not a dead method. The live `SHOW … LIKE` expansion stays an engine test, gated. | No. The red proof opens no engine connection. |
| **1** | **(c) narrow resource**, and **gated**. Every probe runs inside one reserved-prefix database the test created in the same test; the only DDL is `CREATE`/`ALTER` of its own tables. `GET_LOCK`, `NOWAIT` and `SKIP LOCKED` are read-only probes. Red is by omitting the probe, not by widening anything. | Yes — hence `[gated]`. |
| **2** | **(a) fixture**, unchanged and already correct: the mutation is the *violating fixture file*, and the checker is never weakened. `SourceRules` is a pure function over file text. This is the model the other steps are bent towards. | No. |
| **5** | **(b) omit the mechanism.** `schema:publish` writes `schema_versions` on the **master** connection, which in the suite is in-memory sqlite. Red for ordering, idempotence and the out-of-order refusal is proven by writing the assertion before the branch exists — never by stubbing the refusal to allow everything. The catalogue fixture comparison is red against a deliberately stale committed fixture. | No. |
| **9** | **(b) omit the mechanism.** Planning touches no tenant database *by definition* — that is AC-39 and AC-59's whole content — so the red phase for the destructive refusal, the catalogue refusal, the contract guard and the status filter is "the handler without that branch plans the run", observed as rows in sqlite. The destructive-refusal red phase must **not** be proven by letting FX-5 run; it is proven by asserting zero job rows and zero connections, which fails when the branch is absent. | No. |
| **10** | **Split.** The claim statement is an `UPDATE` on a master table: red on sqlite, by **omitting the `attempts < :max` and status terms from the production `WHERE`** — a mutation of the real statement, which is legitimate because the statement is not destructive and the resource is the suite's own. `GET_LOCK(name, 0)` and the two-session tests need the engine and are `[gated]`; they issue no DDL and no DML outside reserved databases, so the gated half is lock traffic only. | Partly — lock probes only. |
| **11** | **Split, and this is the only genuinely destructive step.** Everything provable without the engine is proven there: status transitions, the mirror write, clearing `last_migration_error`, cache eviction and error sanitising are red on sqlite against a fake migrator. The engine half — "FX-1's table exists in A, B and C" — is `[gated]`, and red is proven by **omitting the apply call**, never by pointing a working migrator at a database it did not create. Every tenant database it touches is created by `ThreeTenantFixture` through `EngineDatabase`, by name, in the same run. | Yes — hence `[gated]`. |
| **12** | **(b) omit the mechanism**, on sqlite plus a reserved scratch database. Repair, drift and the behind report issue no destructive statement at all: the observable is a row count in the tenant's own `migrations` table and a column in `tenant_databases`. Red for repair is "the branch is absent, so the mapping is not corrected". | Barely — reads only, gated with step 11's fixture. |

Two consequences worth stating once. **`dev` may not prove a red phase by making the suite's own safety code wrong** —
that is now a review-blocking finding, not a style note. And **a red phase that cannot be proven under the hold is
deferred, not downgraded**: it waits behind G-1 and `qa` records the criterion as *not verified*, per §11.6.

### 11.6 `ENGINE_HOLD` as a declared third state (ruling 2.1)

AC-63 says an absent engine **fails** rather than skips, and it is right: an unreachable engine is an environmental
fault that a skip would hide, and hiding it is how a suite becomes decorative. **The hold is a different condition and
AC-63 does not bind it.** Three reasons, in descending order of weight:

1. An unreachable engine is a fault *to be fixed*; a hold is a decision *already taken*, by the owner, for a reason the
   gate cannot discharge. A red gate for a condition no one on the team is allowed to clear does not inform anyone — it
   just blocks every other row in the project until the owner is free, and the only way out of it is to clear the
   hold. A safety measure whose cost is a stopped team is a safety measure that gets removed.
2. The hold is **fail-closed in code, and skipped only where it is declared.** `EngineConnection::assertNotOnHold()`
   *throws* `EngineOnHold`, so any code that reaches the engine while the hold is on errors loudly. Only the handful of
   tests that explicitly declare `->skip(… EngineConnection::onHold() …)` are absent. The distinction matters: the hold
   is not "engine tests are off", it is "a connection is an error, and the tests that need one say so".
3. An unreachable engine still **fails** once the hold is cleared — `EngineUnreachable` is untouched by this ruling, and
   AC-63's control is a cleared hold with the engine stopped.

**How a reader tells a hold from a silently skipped engine suite six months from now.** Four markers, and the plan
requires all four, because any one of them alone decays:

- **The flag is in `modules/host/phpunit.xml`** with the incident date beside it, so the state is in the suite's own
  configuration and shows up in a diff rather than in a developer's shell.
- **Every skip message names the class**, and the class
  (`Flexipos\Testing\Engine\EngineOnHold`) carries the date, the sixteen databases, the reason (file-level recovery
  reuses the dropped files' blocks) and the single condition that clears it. A skip reason that says "ENGINE_HOLD" and
  nothing else is not acceptable.
- **An inverted test asserts the hold is in force while it is set** — a connection attempt must throw `EngineOnHold` —
  and that test skips when the hold is cleared. So in *either* state exactly one of the two sets of tests is asserting
  something, and a suite in which nothing asserts anything is not reachable.
- **`qa` records every `[E]` criterion under the hold as NOT VERIFIED, never PASS**, naming `ENGINE_HOLD` and the
  gate G-1 it waits on (AC-63's recording rule already carries this; the spec correction in §9 finding 7 makes it
  explicit). A skip that is reported as a pass is the failure mode this whole marker list exists to prevent.

A spec correction is requested of `ba` (§9 finding 7) so AC-63 distinguishes *unreachable* (fail) from *declared hold*
(skip, recorded unverified). Until that lands, this section is the governing reading and `dev`'s deviation is
**upheld**.

---

## 12. Rulings asked for after step 4, and the deviations on record

Three questions `dev` asked rather than assumed, after steps 2, 3 and 4 landed (`e749aea`, `38e9537`, `52aca02`;
gate exit 0, 551 passed, 3 skipped, 2705 assertions, verified by the lead). Asking was right in all three cases, and
in two of them `dev`'s implementation stands unchanged.

### 12.1 The rules read files, so a statement inside a shared helper is invisible — ruled: follow the statement, in two bounded rules

`SourceRules` inspects migration *files*. `IdentifierColumn` and `JsonValidity` issue statements of their own
(column modifiers; exactly one `ADD CONSTRAINT` per column), so AC-33 and AC-34 cannot see them, and what currently
stands in for the rule is a sentence in each docblock: *additive by construction*.

**Ruled: the scan is extended, as step 13 checks (c) and (d).** "Additive by construction" is true today, and it is a
property of the implementation rather than of the type — a later edit to either helper, or a third helper, needs no
approval from any rule. This project has twice shipped a claim that was true when it was written.

The shape matters more than the decision, because a scan over helper sources has a real failure mode of its own: a
scan over a *set of files* is only as good as the set, and the obvious version ("follow the call") needs a call graph
nobody wants to maintain. So the ruling is two rules that bound the set instead:

- **(c)** every file under `packages/kernel/src/Schema/Support/` is scanned under AC-33's and AC-34's existing
  statement rules with the created-table set **empty** and no phase — a helper has no phase comment, so it can never
  be the contract step that licenses a drop, a rename, a `change` or a foreign key onto an existing table. AC-37's
  connection-name and schema-qualified rules apply to it unchanged. The raw-SQL patterns already in `SourceRules`
  cover `JsonValidity`'s `DB::statement` shape, and the first argument of such a call must begin with a string
  literal so the verb cannot arrive in a variable the scan cannot read.
- **(d)** a migration file imports only `Illuminate\…` and `Flexipos\Kernel\Schema\Support\…`. MEASURED 2026-10-15:
  the 25 shipped migrations import exactly six symbols and all six are inside those two namespaces, so the rule is
  green on the tree today. **(d) is what makes (c) complete** — without it a helper in a third namespace is invisible
  again — and it ships with it rather than after it.

Both helpers are silent under (c) today, which is why the fixture controls are the whole proof, and why a green check
without them would be the R-4 shape in this very step. The docblocks keep their prose; the scan becomes the authority.

### 12.2 One criterion reports three columns the design requires — ruled: the detector stands, AC-6 is not reworded

`dev` exempted by **column type, read from the schema, never by name**, with `credential_version_text` as a string
reported as the control. **Upheld, and no spec edit.** AC-6's governing clause is "no column … **can hold a credential
value**"; the name list that follows is the heuristic by which that is detected. A flag, a counter and a moment cannot
hold a password, so a type-based exemption serves the clause that governs and diverges only from the illustration —
while a name-based exemption list would diverge from the clause itself, and is the shape this project has already
ruled rots, its first entry always for a column that "obviously" does not mean it. Recorded as §9 finding 9 so `qa`
meets a ruled divergence rather than a defect. The alternative — rewording AC-6 in `ba`'s file — is rejected: the
wording is not wrong, and spending `ba`'s file on it would make the criterion describe our implementation instead of
the requirement.

### 12.3 A rule implemented exactly as worded, with a gap — ruled: tighten it, and say that it is stricter

AC-35(a) as worded asks only for a precision, so `decimal('x', 18)` passes with the framework defaulting scale to
**2**. `dev` declined to exceed the criterion unasked, which is correct, and asked. **Ruled: require both arguments**
(step 13 check (e), §9 finding 10). The harm is the criterion's own class — a silent truncation decided by a default
nobody wrote — and differs from a bare `decimal()` only in magnitude: at 18,6 a 0.001 quantity becomes zero under a
defaulted scale of 2, which is the same sentence as "0.5 kg truncates to zero". Every shipped column is fully
specified at 18,4 or 18,6, so the tightening is green on both trees and the fixture control carries the proof:
`decimal('x', 18)` reported, `decimal('x', 18, 4)` not. The rule is **strictly stronger than its criterion**, which is
acceptable because it is asserted both ways and written down here; an undeclared stricter rule is what is not.

### 12.4 The deviations worth carrying in this plan

Eleven were declared; these four change what a criterion means, so they belong here and not only in `dev`'s report.
All four are **accepted**.

1. **Step 2 ships no tree-silence assertion; it lands in step 3.** At `e749aea` the tenant tree did not exist and the
   stock `users` migration still called `timestamps()` — a true violation of AC-35(c) — so the only ways to be green
   were to pull step 3's work into step 2's commit or to narrow a rule. `dev` did neither, split the proof on this
   plan's own wording ("red against its own fixture first and only then green against the tree"), and put the reason
   in the test file. That is the correct reading of a step whose whole point is to exist before the tree.
2. **Three statements are driver-guarded on SQLite** — the ASCII collation, the JSON constraint and the second
   auto-increment column — each saying so where it skips. The framework's SQLite grammar *does* emit `collate`, so an
   unguarded collation is an error at `CREATE TABLE` and not a no-op. **These guards are load-bearing for the whole
   gated period**: without them nothing in steps 3 to 13 could be tested on the stand-in while the hold is on. Every
   criterion they stand in for is `[E]` and is recorded NOT VERIFIED until G-1 clears (§11.6), so none of them can be
   mistaken for a pass.
3. **AC-33 and AC-34 do not treat a table the same migration created as "existing".** Accepted: the alternative is
   enforcement by syntax accident — the shipped tree would pass by choosing `MODIFY sequence` over
   `MODIFY COLUMN sequence` — and nothing can be lost by altering a table that had no rows a moment earlier. The
   narrowing is in the rule's own docblock, where the next reader will suspect exactly this.
4. **AC-37's schema-qualified half applies to both trees**, not only the tenant one, and is asserted both ways.
   Strictly stronger than the criterion, declared, and the same shape §12.3 now adopts for AC-35(a).

Two further shipped facts, neither a deviation from a criterion: **`feature_flags` is created before the two tables
that key onto it** — §4's arrow order was wrong and is corrected; `dev` is right — and **`tenants.plan_id` is
nullable**, because a tenant exists as pending before a plan is chosen, which the master's own lifecycle requires.

### 12.5 The residual for step 5: `database/migrations-tenant` has four literals

`dev` flagged the path as hardcoded in the tenant test. Ruled into step 5 explicitly so it is not left as a second
literal: `config('schema.tenant_migration_path')` becomes the **one authority for applying or publishing** the tenant
tree, and the four current sites collapse onto it — the two `Artisan::call('migrate', ['--path' => …])` calls in
`TenantBaselineBuildsTest` and the two tree-discovery helpers (`migrationTreeSources()` in
`MigrationTreeDisciplineTest`, `migrationRuleTreeSources()` in `MigrationSourceRulesTest`), which become one shared
helper in `tests/Helpers.php` reading the same key. A discovery helper able to disagree with the applied path is a
scan over a tree nobody runs.

**`MigrationTree::forPath()` keeps its own literal, and that is not an oversight.** It classifies an arbitrary path,
including fixture paths that belong to no tree; a classifier that asked configuration which directory is "tenant"
would let one environment variable reclassify every rule — §11.3's two-independent-rules argument, applied to the
tree name instead of the database prefix. So step 5 adds the tripwire for the two rules disagreeing:
`MigrationTree::forPath()` over the configured directory must classify as `Tenant`, the directory must exist, and it
must be **absent from the framework's auto-discovered migration paths** — AC-22's structural half stated once in
configuration rather than believed at each call site.

---

## 13. Rulings after step 7, and the consequences on record

Steps 5, 6 and 7 landed (`7b30da4`, `5899008`, `23b3beb`, plus `c8f2c6d` for `dev`'s memory); gate exit 0, 598 passed,
3 skipped, 2948 assertions. Three rulings were asked for and all three are decided here. The first one is a safety
hole in shipped code and takes precedence over everything else outstanding in this task.

### 13.1 The version floor fails open when the variable is absent — ruled: fail closed on the serving purpose, keep an explicit zero as the opt-out

**Verified directly, 2026-10-15.** `modules/host/config/tenancy.php` line 83 and `modules/host/config/schema.php`
line 62 both read `(int) env('APP_REQUIRED_SCHEMA_VERSION', 0)`; `.env.example` line 73 ships `6`;
`Tenancy::assertSchemaIsServable()` returns early on `$required <= 0`. So **with the variable unset there is no
serving version gate at all**, and a tenant whose mapping says schema version 0 — a database that may hold nothing
but `migrations` — is served. `tenant_databases.status` is then the only thing between a half-provisioned shop and a
sale. That is a direct conflict with `docs/domain-core.md`'s rule that a half-migrated tenant must never serve, and it
is reached by *omitting* a line rather than by writing a wrong one, which is the failure mode that actually happens.

**`dev`'s reasoning for not hardcoding `6` as the default is upheld and is not the question.** Writing the number into
the config default duplicates the catalogue's count and the two diverge the first time a migration is added; a test
already pins the shipped value against the catalogue's highest version, so the number has one authority. The question
is **what absence means**, and absence currently means "no gate".

**Ruled: absence is a misconfiguration and refuses — on the serving purpose only.** The floor has three states:

| State | Meaning | Behaviour |
|---|---|---|
| a number | the serving floor this deployment chose | AC-19/AC-20 as built |
| explicit `0` | "no floor; `status` is the only guard" — a choice someone wrote down | served, unchanged |
| **absent** | nobody chose anything | **refused for serving**, named exception, 503 **without** `Retry-After` |

Step 7a implements it. Four properties of the ruling, each of which is why an alternative was rejected:

- **It refuses at the gate, not at boot.** A boot-time refusal is louder, and it would also stop
  `php artisan tenant:migrate` — the one command that fixes the condition — along with every queue worker and every
  other module's console entry point. A safety gate that disables its own remedy is not fail-closed, it is
  fail-stuck. The refusal therefore sits exactly where the floor is consulted, which is the serving purpose, and the
  migrating purpose stays exempt for the same reason it is exempt from the floor itself (§9 finding 2).
- **`0` keeps meaning "no floor".** Otherwise the fix silently converts a configuration key into a mandatory one and
  removes a state a deployment may legitimately want (the first tenant-less installs, and FP-206's provisioning
  window). The opt-out is asserted, so a later implementation that refuses `0` as well is red.
- **The 503 carries no `Retry-After`.** A behind tenant's 503 promises that waiting helps; a misconfiguration's does
  not, and a client that retries forever against a missing environment variable is a second incident. Distinguishable
  at the wire, not only in the log.
- **One key, not two.** `config/schema.php`'s `required_version` is the same variable read a second time and nothing
  but `SchemaCataloguePublishTest`'s `toBeInt()` consumes it — the shape D-14 already ruled against, now reappearing
  inside one task. It is **removed** in step 7a; `tenancy.required_schema_version` is the serving floor's only site,
  and anything in steps 9 to 12 that needs "the highest version the tree can produce" reads the **catalogue**, which
  is a different fact from "the floor this deployment chose".

**Alternatives rejected, one line each:**

- **Fail closed on boot.** Rejected above: it disables `tenant:migrate`, the remedy.
- **Default the floor to the catalogue's highest version.** Rejected, and on domain grounds rather than because
  config would read a file (which it would, and which `config:cache` would then freeze). A self-maintaining floor
  makes every deployment of new migrations refuse every tenant not yet migrated — an outage for the whole duration of
  the rollout — whereas expand-and-contract exists precisely so that code at version 7 serves tenants at 6. AC-21
  requires the floor to be **not greater than** the catalogue, which already says the floor is allowed to lag. A floor
  the catalogue sets is a floor nobody can stage.
- **Keep `0` and record it as a decision.** Rejected. It is defence-in-depth minus one layer, documented in a file the
  person deploying will not read, and the layer it drops is the one that enforces a rule `docs/domain-core.md` states
  absolutely. A recorded decision is the right instrument for a trade-off; this is a default nobody chose.

`ba` is asked for one spec line, not a rewrite: AC-19 should name the absent-configuration case (§9 finding 11's
sibling, finding 12's last paragraph). The criterion's existing content is unchanged by this ruling.

### 13.2 A refusal whose absence is absorbed by a primary key — ruled: the rule generalises, and it is in §7 now

`dev` measured that deleting step 5's out-of-order branch does **not** produce a clean red: the insert is refused by
`schema_versions.version`'s primary key, because a renumbered file always collides with the published number, and the
test would have passed on `SQLSTATE[23000] … UNIQUE constraint failed`. `dev` pinned the exception class **and** the
name of the *published* migration, which is a fact only the plan-time branch holds — the key's error can only know the
row that arrived.

**Ruled: correct, and it is now a standing rule of this task rather than a note on one step.** The wording is in §7
beside the mutation rule, because this is the second instance of the shape (T-007's mutation absorbed by the version
floor was the first) and two instances in two tasks is a property of the codebase. The operational form is three
words: **read the failure.** A red phase that nobody read the message of is a red phase that may be measuring the
engine. `reviewer` should treat an absence-and-refusal test that pins only an exception class as a finding when a
constraint could have produced it.

Worth stating once so it is not mistaken for a weakening: the branch is **not** redundant with the key. The key
refuses at write time, inside a transaction, with a message naming a column; the branch refuses at **plan time**,
before anything is written, naming the migration an operator has to look at. AC-31 is about the second one.

### 13.3 AC-21's control is vacuous as worded — ruled: the equality assertion is the control, and it needs a moving catalogue

`highest + 1 > highest` is arithmetic and would pass against an empty repository. `dev` kept it because the criterion
names it and added the assertion with teeth — the shipped value **equals** the catalogue's highest version. **Ruled:
the equality assertion is the control; the arithmetic stays as documentation only**, and `ba` is asked to reword AC-21
(§9 finding 11). Rejected alternative: deleting the arithmetic from the test as well — harmless, and leaving it with
"this is not the control" beside it is a better instruction to the next reader than its absence.

**One tooth added, because the equality assertion has its own version of §13.2's problem:** two literals that agree
prove nothing about which one is authoritative. The assertion is only independently proven if a side can move, so it
is pointed at a scratch catalogue fixture whose highest version is one higher (red) and one lower (red) — the same
move-the-key technique step 5 used for `schema.tenant_migration_path`, which is why this is affordable now and was
not before. That belongs to step 7a's commit, with the floor change it is about.

### 13.4 Two consequences recorded, not ruled

- **`publish` refuses a published migration whose checksum moved or that has left the tree.** Accepted as part of
  step 5 rather than scope creep: without them `publish` would have to *update* a published row to stay idempotent,
  which destroys exactly the evidence AC-32 depends on. The workflow consequence is in §5 so someone meets it in the
  plan instead of in a terminal: **editing an already-published tenant migration is refused until its
  `schema_versions` row is gone**, the escape is to delete the row in the development master and republish, and no
  `--force` or `--forget` flag is added. If FP-206 ever needs one, it is a decision with a canary, not a flag added
  under time pressure.
- **`phpunit.xml` sets the mapping source to the configuration stand-in, and that stands.** It was forced: with the
  shipped `master` default, 34 cases in two `Qa*` files set the connection stanza directly with no master schema
  behind them, and those files belong to `qa`. I am **not** directing `qa` to change them — their subject is tenant
  isolation, not mapping resolution, and rewriting a proof to accommodate a default that arrived later is how a proof
  loses its meaning. `dev`'s replacement for AC-12's "shipped configuration, no test override" is better than the
  suite-wide default was: it asserts the default **from `config/tenancy.php` itself**, unsetting the variable in all
  three places PHP keeps it (`putenv`, `$_ENV`, `$_SERVER`) and re-requiring the file. **`dev`'s finding that
  phpunit's own `<env>` writes only two of the three is the load-bearing detail here** — it is why every later
  tenancy test resolved the master reader, and it is a fact the next person to override an env-driven default in this
  suite needs; it is in `dev`'s memory file and should stay there.
  **One tooth to add in step 7a, because the override costs one:** with a suite-wide `config` default, no test
  exercises the container actually binding the master-backed reader from the shipped configuration. One test must
  restore the default and assert the resolved object is the caching decorator over `MasterTenantDatabaseRepository` —
  otherwise `config/tenancy.php` can say `master` while nothing in any run ever builds it.

### 13.5 `TenantSchemaAhead` moves to step 12 — confirmed

`dev` declined to write it and is right. AC-20 **serves** an ahead tenant with one warning, which is what step 7
implements, so step 7 has no site that throws such an exception; the refusal of an ahead tenant belongs to step 12's
drift path (AC-55). An exception nothing throws is dead code whose name tells the next reader that serving an ahead
tenant is refused — the opposite of the behaviour. §4's file list is amended: step 7 ships `TenantSchemaBehind` only.
**Constraint on step 12:** whatever it writes is named for the site that throws it (a drift refusal), not for AC-20,
and it is written in the same commit as that site.

### 13.6 What I judge wrong in the step 5 to 7 account

One thing, and it is a reporting defect rather than a code defect. **AC-19 and AC-20 are reported NOT VERIFIED, and
they are in fact proven** — on the stand-in, at `23b3beb`, with the three mandatory inputs. They are reported that way
because the spec marks them `[E]`, and that marker is wrong: AC-19's own requirement is that the refusal happens
*before any network connection is attempted*, so an engine cannot be the means of verifying it. §9 finding 12 asks
`ba` to drop `[E]` from both; until that lands, `qa` records them NOT VERIFIED **with this ruling cited**, so the
report does not read as if the serving gate is unproven. Everything else in the account matches what I verified in the
tree: the two config defaults, `.env.example`'s `6`, the early return on `$required <= 0`, the absence of
`TenantSchemaAhead`, and `TENANCY_MAPPING_SOURCE=config` in `phpunit.xml`.

---

## 14. Rulings after steps 7a to 13, and the consequences on record

Steps 7a, 8, 9, 10 and 13's scoped three landed (`cf685e2`, `0afd6a6`, `88c04ce`, `6663377`, `18f03dd`, plus
`671993e` for `dev`'s memory). **Gate verified by the lead: exit 0, 649 passed, 5 skipped, 3279 assertions**, no
sentinel, tree clean. The largest consequence of this round is not a ruling: §7's mask family gained a **fourth
shape** and it is recorded there, with the warning it carries for `MigrateTenant` and `RetryFailedJobs`. Two rulings
were asked for; both are decided here.

### 14.1 AC-58 has no derivable dependency — ruled: the V − 1 reading stands for T-008, and the tag is specified but not built

The catalogue carries version, migration, phase, checksum, `is_destructive` and `requires_offline`, and **nothing
declares which expand version a contract step depends on**. AC-58 is worded as if the dependency were a known fact.
`dev` implemented the strictest computable reading: *a contract step at version V is refused while any tenant a bulk
run would touch is below V − 1*, blockers named, the blocking set read fresh from the master rather than from the
run's selection, `provisioning` not blocking.

**Ruled: the reading is confirmed for T-008, as a reading, and it is to stay labelled as one in the handler
docblock.** Three reasons, and the third is why this is not simply deferred:

1. **A tag built now would have no input that binds.** Every catalogue this task can produce has an *adjacent*
   dependency: the shipped baseline is six expands and no contract step, and the only contract fixtures, FX-4 and
   FX-5, depend on the expand immediately before them. A declared dependency that always equals V − 1 is
   indistinguishable from V − 1, so §7 would grade it *covered by reading* — a mechanism with no binding input, which
   is the thing this plan exists to refuse.
2. **The reading errs in the safe direction.** It refuses more runs, never fewer, and it names the blockers, so a
   false refusal is self-diagnosing in a way a false permission never is.
3. **It is wrong only for a non-adjacent dependency**, which is a real case that will arrive — and arriving is the
   tag's trigger, stated below so the next person does not re-derive it.

**The known false refusal, and its remedy is operational rather than code.** A single run that would apply several
expands *and then* the contract is refused, even though every tenant would pass through those expands inside that
same run. `dev` believes that is right for expand-and-contract and I agree: the discipline is that the expand reaches
the whole fleet and is *allowed to settle* before the contract reaches any tenant, and a run that collapses both into
one pass defeats the reason the two phases exist. The remedy already ships: `tenant:migrate --to=<the expand
version>` first, then the run that includes the contract step. **One tooth, cheap, and it lands with step 13a's
commit:** `MigrationRunRefused::contractStepBlocked()` names the blockers and the version they must reach but not the
command that gets them there — it should name `--to=<version − 1>` as the next action, so the operator meets the
remedy where they meet the refusal.

**The tag, specified so nobody re-invents it badly.** An optional `@depends-on-version N` in the migration's header
comment, read by `MigrationCatalogue` beside `@phase` and `@requires-offline`, **defaulting to V − 1 when absent**;
one `depends_on_version` column on `schema_versions` (an expand); the guard reads it instead of V − 1. Control: a
fixture whose declared dependency is *not* adjacent, which is red if the tag is parsed and ignored. **Trigger:** the
first contract step whose dependency is more than one version back. This is deliberately a backlog row and not this
task's work, against §0's own rule that a ruling specifying a replacement is work — §0's rule is for replacements
that can be *proven* when they are written, and this one cannot be until an input for it exists.

**To `ba`, one line, no rewrite:** AC-58 should say how the dependency is known — "the version immediately before the
contract step, until a declared dependency exists in the catalogue" — so the criterion stops implying a field there
is no field for. The dataset and the behaviour are unchanged. Filed with §9's finding list.

Both of `dev`'s sub-decisions are confirmed with it: the blocking set is the **fleet**, not the run's selection (a
tenant left out of a named run still blocks, because a contract step is a statement about every database that will
ever run it), and `provisioning` does not block (the criterion says so, and a tenant that has never served cannot be
mid-upgrade).

### 14.2 The FX observation — ruled: no exemption for FX files; the connection instrument is AC-39's proof, and `observe()` stands where the reading *is* the criterion

Nothing shipped depends on this; it is ruled now so step 11 does not have to ask.

1. **AC-39's proof is the connection instrument** — the queried connection list is exactly `[master]` and no tenant
   connection is built — and not a fixture's `up()` counting job rows. The instrument is stronger, and for a reason
   that belongs to §7's family: when the handler refuses at **plan time** the fixture's `up()` never runs, so the log
   is empty, and *"the fixture saw zero job rows"* is then indistinguishable from *"the fixture never ran"*. An
   observation whose absence is also its pass is not evidence. `dev`'s choice is upheld, and the general form of it
   goes to the next person: **where `FixtureMigrationLog::observe()` is used at all, the same test file carries the
   positive control — a run that proceeds and records a non-zero reading.**
2. **AC-52 keeps the criterion's literal wording, because there the reading is the criterion.** "The mapping status
   is `migrating` *during* the migration" has no observation point outside `up()`; a reading taken afterwards cannot
   tell a correct implementation from one that never set the status. That is step 11's engine-side work. FX-3 already
   declares *when* the reading is taken and the test installs the probe that takes it, because the status lives in
   the master and a migration naming that connection would violate AC-37 — and could not then be a control for
   AC-37, which is the second reason the split is right.
3. **No exemption for FX files from the statement rules.** `dev` argues against one and I agree; an exemption list is
   how a containment check rots (§7), and the first entry is always for a file that "obviously" does not mean it.
   What FX-3's import of `FixtureMigrationLog` needs is not an exemption but a **scope**: check (d)'s subject is the
   files under the two *shipped* trees, read from configuration, and fixture migrations are outside its subject —
   with the scan asserting its own scanned set is non-empty and equal to those trees, so the scope cannot quietly
   become nothing. That is written into step 13a. Every rule whose subject FX files *are* still applies to them:
   AC-33/34/35/37 (FX-6 is AC-33's own control), the no-FX-under-a-shipped-tree scan, and the drop-containment check,
   which has no scope rule at all and scans the whole branch.
4. **The consequence, stated rather than hidden:** `FixtureMigrationLog` is a helper that check (c) does not scan. It
   is bounded — it runs only inside reserved-prefix fixture databases, it issues no database-level drop (and the
   unscoped containment check would see one), and its production-shaped power is reading. The day it grows a
   statement, either it moves under scanned territory or (c)'s scope moves to it; it does not get an exemption.

### 14.3 The runner's status authority — confirmed: `tenant_databases.status`

**Confirmed, and the docblock is the right place for it.** The runner selects on the same column `Tenancy::enter()`
gates serving on, so the runner cannot plan a run against a database the request path considers unservable, nor skip
one it would serve. One status, one authority. The division is also the right one on meaning: `tenants.status` is a
fact about the commercial tenant — subscription, lifecycle, the thing an invoice is about — while
`tenant_databases.status` is a fact about the database a run would touch, and a runner acts on databases.

D-11's list spanning both tables' vocabularies is a defect in the decision's wording rather than an open choice:
**`ba` is asked to record in D-11 that the authority is `tenant_databases.status`**, and to say what the values that
belong to `tenants.status` were meant to mean there. Reversible cheaply — the selection is two private methods and
one pair of constants.

One thing deliberately **not** added: the two statuses can disagree (`tenants.archived` over an `active` database),
and the natural home for noticing that is a report, not a selector. `DetectSchemaDrift` is about versions, so this is
out of T-008's scope and is recorded here as a backlog candidate rather than smuggled into step 12.

### 14.4 `PerTenantLock` and the two-process criteria — confirmed

**Refusing on a driver with no advisory lock is correct** and is the same fail-closed shape as the floor, the
destructive refusal and the engine hold: a guard that cannot do its job says so, and never reports a lock it does not
hold. The named exception is what makes the stand-in's inability visible instead of comfortable.

**`dev`'s statement that AC-49 and AC-65 cannot be tested in one process is correct and is the load-bearing part.**
`GET_LOCK` is re-entrant within a session, so a single-process test of "the second holder is refused" would be green
on an implementation that never locks at all, and a test written to pass in one process would be asserting the
*opposite* of the criterion. §7's grade table already requires a second connection for AC-49. They stay **NOT
VERIFIED** behind G-1, `qa` records them with G-1 named and never as PASS, and no one is to make them green by
weakening what they assert.

### 14.5 What I judge wrong in the account

Two things, and one of them is mine.

- **"Tick all five" against "step 13 stays legible as partial" cannot both be done to one bullet.** Step 13 is now
  ticked for the three checks it was scoped to and the remaining (c), (d) and (e) are **step 13a**, an unticked step
  of their own. Nothing is ticked that is not on disk, and nothing outstanding is hidden inside a ticked bullet.
- **Step 10's bullet claimed AC-42 and AC-46 and the account did not mention either.** Neither is shipped: the
  `RetryFailedJobs` slice does not exist, and AC-42 (two jobs for two tenants in one worker process) has no site yet.
  What shipped covers AC-41, AC-47, AC-48, AC-50 and AC-46's attempt cap. Ticking step 10 as worded would have
  retired two criteria silently — the plan's wording was the fault, not `dev`'s work — so both move to step 11, where
  the execution path gives each an input that binds. This is the only correction I have to the account.
- **One qualification `qa` must carry, not a defect:** AC-48's grade of *independently proven* now rests on the
  **statement pin**, not on the seven behavioural cases, and the QA report should quote the pin rather than the case
  count. A later reader who sees seven green behavioural cases and deletes the "odd" SQL-shaped assertion as
  duplication would remove the only evidence in the file.

Everything else in the account matches the tree: `is_numeric($configured)` separating absence from explicit zero and
the second config read gone; the contract guard reading `tenant_databases.status` with its authority stated in the
docblock; the `V − 1` inference in `assertContractStepsUnblocked()`; FX-3 declaring the observation point with no
connection named; and the renamed `PerTenantLockEngineTest.php`.

---

## 15. Rulings after steps 11 to 13a, and the consequences on record

Steps 11, 12 and 13a landed (`733dcca`, `d00ff55`, `8c9a5b1`, plus `91d0b2c` for `dev`'s memory). **Gate verified by
the lead: exit 0, 720 passed, 11 skipped, 3682 assertions**, no sentinel, tree clean; +71 tests and +403 assertions
against §14's figure, and the six new skips are six new declared `[gated]` engine cases. Nine slices exist, which is
the layout §4 drew. Twenty-one mutations were run one at a time, each applied to the shipped file and restored in a
`finally`, and each produced a red signature `dev` reports. Two findings came out of building it and both are ruled
on here, because each is a place where the obvious implementation is the wrong one.

### 15.1 The status gate forbids the order this plan implied — ruled: enter first, then mark, and the order is normative

**`Tenancy::enter()` refuses any status but `active`, for both purposes** — step 4 of `enter()`, which the migration
purpose deliberately does *not* waive (§13's exemption is the version gate and nothing else). So a runner that wrote
`migrating` before entering the tenant would make that tenant **unenterable by its own run**: the mapping it just
wrote is the mapping `enter()` then reads and refuses. The shipped order is therefore

> **enter (on `active`) → write `migrating` → apply → restore `active` (or `failed`)**

and the status write is inside the scope, after the identity check. **Nothing in this plan said either way, and that
is the defect being corrected here**, because the order that reads as obviously safer — mark the tenant unservable
*before* you touch it — is the one that cannot work. It is now normative for anything that enters a tenant and then
changes its status: **the status write happens inside the scope, never before the entry that depends on it.**

Two consequences worth stating so they are not rediscovered:

- It is also **the only order at which AC-52's from-inside observation can see `migrating` at all.** The reading is
  taken inside `up()`, which only runs inside the scope, so a design that marked first and entered second would have
  had no observation point even if `enter()` had allowed it. The criterion and the gate agree; the plan's silence was
  the only thing that did not.
- The narrow window this leaves is real and accepted: between `enter()` and the `migrating` write, a request can
  still be served. It is bounded by two statements on the master and closes as soon as the mapping is written and the
  cache evicted (D-19). The alternative — a third status meaning "about to migrate" — buys a smaller window at the
  cost of a second unservable state in every tenant's vocabulary, and FP-206's provisioning path is where such a
  state would have to be designed, if ever. Recorded, not scheduled.

### 15.2 AC-53's literal wording is unreachable in one process — ruled: reworded into two halves, and the in-process form is not allowed to stand in for it

`dev` is right, and the distinction belongs here rather than in a hand-back. AC-53 says entering B's scope **for
serving** is refused *during* the run. In one process that cannot be asserted: `enter()` is **re-entrant for the same
tenant** (step 2 of `enter()`, which increments the depth and returns) and so returns **before** the status check the
criterion is about. A test written to pass the criterion's words from inside the migration would be asserting the
opposite of the criterion — the AC-49 shape exactly (§14.4), and the same trap.

**What shipped instead is D-19's actual content:** the read the request path makes, through the caching decorator,
answers `migrating` from inside the run and `active` at the new version afterwards — **with the mask control beside
it**, a warm read after a status change written *around* the mapping writer still answering `active`. That control is
the load-bearing half: without it the test passes on a cache that simply never updates, which is §7's family in
miniature.

**Ruled: the engine form does not close the gap, so AC-53 is reworded.** The obstruction is in-process re-entrancy of
`Tenancy`, not the driver; MariaDB changes nothing about it. Closing it literally needs a **second process** making a
serving entry while the first is mid-migration, which is the AC-49/AC-65 class — G-1 *plus* a second request-path
process this task has no harness for. Paying for that harness to prove a property that decomposes into two cheaper
and jointly stronger halves is the wrong trade.

**The replacement, and it is strictly stronger than the in-process literal.** AC-53 becomes two criteria:

- **(a) the cache tells the truth** — the mapping read the request path makes answers `migrating` from inside the run
  and `active` at the new version after it, with the around-the-writer control. This is shipped and proven (step 11).
- **(b) a `migrating` mapping is refused for serving** — a serving entry against a mapping whose status is
  `migrating` throws `TenantDatabaseNotActive` naming the status, with the state-unchanged assertions **T-007's** AC-10 defined (this
  spec's AC-10 is the audit-log JSON criterion, and the plan said the wrong one twice; corrected 2026-10-15). This needs no run, no second process and no engine: it is a statement about `enter()`, provable where
  `enter()`'s other status refusals already are.

(a) and (b) compose to the production behaviour the criterion wanted — the request path reads `migrating` and
`enter()` refuses it — and each half has an input that binds, which the literal form does not.

**The tooth, and it is one dataset row.** `packages/kernel/tests/Feature/TenancyResolutionTest.php` asserts the
non-active refusal over `suspended` and `provisioning` only; **`migrating` is absent from that dataset**, so half (b)
is true of the code and asserted nowhere. [dev] adds the `'migrating' => ['tenant-migrating', 'migrating']` row with
its fixture mapping, in whatever commit this task makes next (step 16's fixes if nothing else). Until then **AC-53
stays NOT VERIFIED and `qa` records it with `ENGINE_HOLD` and G-1 named** as §11.6 requires — but the reason is now
two reasons and the report should say so: the `[E]` marker, and the reworded half (b) that is not yet asserted.

**To `ba`, through the lead:** reword AC-53 into (a) and (b) above; the dataset and the behaviour are unchanged, only
the observation point moves from "during the run, in the same process" to the two places it is observable. Filed with
§9's finding list, beside §14.1's AC-58 line and §13.6's `[E]` marker correction.

### 15.3 The four declared deviations — all accepted, and why each is the better shape

1. **`SchemaRepairRefused::mirrorAheadOfTenant()` — accepted, and the naming rule is clarified rather than bent.**
   My constraint was that the refusal be named for its site; the codebase convention is `<Site>Refused`, and the site
   here is the repair. `mirrorAheadOfTenant()` names the *condition* on a class that names the site, which satisfies
   the constraint as intended — one throw site, in `RepairSchemaVersionHandler`. **The part worth keeping is the
   order:** it writes the unservable status **before** it throws, so AC-55's "refused for serving until corrected" is
   structural rather than dependent on a caller catching the exception. That is the same shape as §0's D-10 ruling
   (refuse before the first write) and it is the default for every refusal that must also leave a mark.
2. **`Support/TenantSchemaVersion` with fewer callers than that directory's rule asks for — accepted**, on the
   argument the docblock makes: the alternative is not one caller, it is **two copies of D-14's "the tenant's own
   table is the authority"**, and D-14 is a decision, not a detail. The caller *count* in that docblock is wrong and
   §15.4 corrects it; the ruling does not depend on the number.
3. **`error_class` stores the cause's class, not the wrapper's — accepted, and it is the stronger choice.** The
   wrapper is on every failed row by construction, so a column holding it distinguishes nothing, and a column that
   cannot discriminate is worse than absent: an operator filtering it believes they have filtered. The wrapper's text
   survives in the message column, where it names the migration. The general rule, for the next bookkeeping column:
   **a column exists to tell two rows apart; if it holds the same value for every row it describes, it is holding the
   wrong value.**
4. **The mapping mirror is written even on a lost claim — accepted, and the docblock is the right place for it.** The
   database really did change, and hiding a real version behind a bookkeeping race is the worse failure: the mirror
   is a statement about the database, the claim is a statement about who was allowed to speak. The ordering that
   makes this safe is already shipped and already stated — mirror first, then the job — and it is what lets the
   completion statement re-check the holder without risking a version nobody wrote down. The residue is a job row
   that may be closed by another worker, which is a bookkeeping discrepancy a report can see, against a version
   drift only `DetectSchemaDrift` could ever have found.

### 15.4 What I judge wrong in the account

One correction, and one qualification for `qa`.

- **`Support/TenantSchemaVersion` has one direct caller in production code, not two.** `DetectSchemaDriftHandler` is
  the only site that names it; `RepairSchemaVersionHandler` reaches it **through** `DetectSchemaDriftHandler`, and
  `MigrateTenantHandler` does not use it at all — it computes the version itself in `versionFrom()`, from the list of
  applied migrations the applier reports rather than from a read of the tenant's `migrations` table. So "two callers"
  is a count of *features*, and the docblock should read "two features, one caller, and the second reaches it through
  the first". **The second site is the thing worth noticing, not the number:** `versionFrom()` applies the same
  "highest set version present" rule to a different input. That is **accepted** — the applier has just told the
  handler what it applied, and re-reading the table to learn what the previous statement returned would be a second
  answer to a question already answered — but it means D-14's rule has two implementations whose *inputs* differ, and
  the docblock's claim of a single site is true only of reads of the table. [dev] corrects the docblock in the same
  commit as §15.2's tooth; no code moves.
- **The plan's defect this round is §15.1, and it is mine, not a deviation.** A missing line about ordering is a
  silence of the plan that `dev` resolved correctly by building it. Recorded that way so `reviewer` reads it as a
  plan correction rather than as an implementation choice that might be revisited.
- **For `qa`, the same qualification §14.5 made about AC-48:** AC-53's evidence is the **mask control** beside the
  eviction case, not the eviction case itself. A later reader who sees the warm read answering `migrating` and
  deletes the "duplicate" around-the-writer read as redundant would remove the only assertion in the file that a
  cache which never updates cannot pass. Quote the control in the report.

Everything else in the account matches the tree: nine slices under `Schema/Features/`; the identity check first
inside the migrating scope, before the status write and before the apply; `migrating` written only for a
`requires_offline` plan; the ahead-mapping refusal with one throw site; `MigrationRunRefused::contractStepBlocked()`
naming `tenant:migrate --to=<version − 1>`; and the two statement pins with the signatures `dev` reports.

---

## 16. Rulings after QA run 1 — the two design conflicts, and the three gaps the report exposed

QA run 1 is **FAIL and not verified**: 13 PASS, 49 PARTIAL, 4 NOT VERIFIED, 2 FAIL out of 68, no `[E]` criterion run
on any engine (`docs/qa/T-008-master-schema-and-tenant-baseline.md`, `959b0ab`). Two of the ten defects are mine
rather than `dev`'s: BUG-1 is a contradiction between two things this plan ruled separately, and BUG-7 is an
implementation narrower than §14.1's words. Both are ruled here. `ENGINE_HOLD` stays on and nothing in this section
needs an engine.

### 16.1 BUG-1 — ruled: the status gate is purpose-scoped, over one list, owned by the purpose

**The defect is real and it is a deadlock, not an inconvenience.** `Tenancy::enter()` step 4 refuses every status but
`active` for **both** purposes, while D-11 has the planner accept `read_only` in a bulk run and `provisioning` and
`failed` in a named one. So three of the four statuses the planner accepts produce a job row that then fails with
"cannot be served" — and because D-12 sets a tenant to `failed` after a failed offline migration, **a failed tenant
can never be entered and therefore never retried**. The retry slice step 11 shipped has nothing it can act on, which
makes AC-46's recovery path unreachable by construction.

**Ruled: waive the status gate for the migrating purpose over a declared set, and make that set the planner's set by
construction.** The second option — narrowing the planner to what `enter()` accepts — is rejected, and not on taste:
it makes `failed` terminal, which contradicts D-12 (`failed` exists precisely so an operator can fix the cause and
re-run) and deletes AC-46's retry, and it also kills FP-206's `migrate_remainder` on a `provisioning` tenant, which
is the next task in the queue. A design that cannot migrate the states it invents for "needs migrating" is the wrong
design; the gate is what is wrong here, exactly as it was for the version gate.

**The mirror to §13/D-23 is exact, and the limit is stated the same way.** D-23 waived the *version* gate for the
migrating purpose and said in terms that no other refusal is waived by purpose. That sentence now has one more
exception and it must be written as an exception, never loosened into a principle: **the migrating purpose waives
the version gate entirely and the status gate only over D-11's four statuses.** Everything else — the identifier
check, re-entrancy, the mapping's existence, the driver whitelist, the credential dereference, `schema_meta`'s
identity check — is untouched by purpose, and `Serving` still accepts exactly `active`. The migrating list is an
**allow list, deny by default**: `suspended`, `moving`, `restoring`, `archived`, `migrating` and every unrecognised
value are refused for both purposes.

**`migrating` is deliberately not in the list**, and the consequence is stated rather than discovered: a worker
killed mid-offline-run leaves a tenant at `migrating`, and no later run can enter it. That is correct for now — the
schema may be half-applied and nothing in T-008 can tell a live run from a dead one — and the operator's path is
`RepairSchemaVersion` plus a status correction in the master. The row that makes it automatic needs a lease on the
mapping, which is FP-206's shape and not this task's. Recorded; not scheduled.

**The single authority, which is the part that must not be lost.** Two lists that must agree will not stay agreed,
so there is one list and the other site reads it:

- `TenantConnectionPurpose::acceptedStatuses()` is **the** authority. It lives on the purpose because the purpose is
  already the thing that scopes a waiver — it selects the mapping source and waives the version gate — and because
  it lives in `Tenancy/`, which `Schema/` may depend on while the reverse would invert the kernel's own layering.
  `enter()` asks `$purpose->permits($status)`; nothing in `Tenancy` holds a second copy.
- `PlanMigrationRunHandler`'s accepted set is **derived** from `TenantConnectionPurpose::Migrating->acceptedStatuses()`
  — `NAMED_ACCEPTED` is that list, `BULK_STATUSES` is it minus `NAMED_ONLY_STATUSES`. A derived list cannot drift; a
  copied one is one edit away from BUG-1 again. `ReportSchemaStatusHandler`, which already reads `BULK_STATUSES`
  rather than repeating it, is the convention this follows.
- The tooth is the shape step 5 used, and it is in step 13b: exact equality between the planner's accepted set and
  the purpose's, **plus** `qa`'s behavioural reachability test — every status the planner accepts is actually
  migrated. Equality alone passes on two lists that are equal and both wrong; the behavioural test alone passes on
  two lists that happen to agree today. Both, or neither proves the invariant.

**The restore is part of this ruling, because the waiver creates a bug if it is not.** `MigrateTenantHandler` writes
the literal `active` after a successful offline migration. With the waiver, a `read_only` tenant entered for
migration would come back **`active`** — a silent promotion of a shop the business put into read-only, caused by a
schema change. Ruled: **the handler restores the status it entered on**, captured inside the scope with the rest of
the identity work, with exactly one documented exception: **a tenant entered at `failed` that migrates successfully
becomes `active`**, because `failed` means "do not serve, the schema may be half-applied" and a successful run is
precisely the removal of that condition — restoring `failed` would make the retry path a no-op and re-open the
deadlock from the other end. `provisioning` restores to `provisioning`; FP-206 owns when that becomes `active`. The
failure side is unchanged: D-12's `failed` on an offline failure, and an ordinary expand writes no status at all.

**To `ba`, three lines, through the lead.** (1) **D-23** must record the second waiver and its limit, because it
currently says the opposite in terms. (2) **D-11** should say that its four statuses are *also* the set the
migrating entry accepts, and name `TenantConnectionPurpose::acceptedStatuses()` as the one place they live.
(3) **AC-51** (and T-007's AC-10, not this spec's) should say that the non-`active` refusal is the *serving* rule, and **AC-52** should carry the
restore-the-entry-status rule with the `failed` → `active` exception, or the next implementer will put the literal
back. Dataset and behaviour otherwise unchanged; nothing here changes what the request path does.

### 16.2 BUG-7 — ruled: the implementation is wrong, the ruling meant the fleet, and it needs a test

**§14.1 and D-46 mean exactly what they say: the whole fleet the master holds, minus `provisioning`.**
`PlanMigrationRunHandler::tenantsBelow()`'s `whereIn('status', BULK_STATUSES)` is the defect — it is the *selection*
predicate reused for the *blocking* question, and the two questions are opposites. Selection asks "whom may I
touch", and is rightly an allow list. Blocking asks "whose existence makes this contract step unsafe", and the
answer is every database that will ever run it: a suspended shop comes back, an archived one is restored, a `moving`
one lands somewhere. A contract step that dropped a column while six statuses looked away is the failure D-46 exists
to prevent, and it is unrecoverable in the way this project's worst failures are.

So: the blocking read excludes exactly one value, `provisioning`, and **an unrecognised status blocks**. The two
sets stay separate rules with opposite defaults, and step 13c carries the assertion that they differ, because "these
two lists look the same, let us unify them" is precisely how this returns.

**Yes, it needs a test, and the absence of one is why it survived.** `qa` calls it a probe with no red test and
grades it minor; I grade the *gap* major and the *exposure* currently nil — there is no shipped contract migration,
so nothing can be wrongly permitted today, and that is exactly the condition under which a latent rule rots. Step
13c specifies it: seven statuses below the target blocking, `provisioning` as the control that does not, the blocker
named in the message. It costs no engine and no fixture database. Until that test exists, AC-58's bulk half is
**corroborating at best**, whatever the engine later says.

### 16.3 The three things from the report that change this plan

1. **The engine matrix is 42 criteria of unwritten work and step 15 implied otherwise.** `qa` is right and the
   wording was mine: only 10 of the 52 `[E]` criteria have an engine test at all, and all ten are skipped. Clearing
   G-1 unblocks **ten**. Step 15 is corrected and **step 14a** now carries the 42 explicitly, before step 15 can
   mean anything. The report's framing — that nobody should treat the dump as the finish line — is adopted into the
   step. This is the largest piece of remaining work in T-008 and it was previously invisible in the plan.
2. **Reachability is the sibling of the layout check, and §7 is where it belongs.** Agreed without reservation. A
   lock with no caller and a retry that does not exist both pass every structure check in the branch, which means
   the checks measure shape and nothing measures connection. §7 gains the grade row and **step 13d** gains the
   check, with BUG-3 and BUG-4 as its two day-one reds. The general form, for every later task: **a criterion whose
   mechanism has no production caller is not proven by any test of that mechanism.**
3. **A rule proven against one spelling is proven against one spelling.** BUG-5 is the sharpest finding in the
   report, because the evaded spelling is the one D-5's four deferred keys will be written in — the rule is blind in
   the exact case the whole D-5 defence rests on. `dev` fixes the rule; §7 now carries the standing rule that every
   source rule ships an **evasion set** enumerated from the framework's API rather than from the author's memory,
   and that `qa`'s `QaSourceRuleEvasionShapesTest` is kept, not consumed, when it goes green.

### 16.4 What I judge wrong in the account

Little, and nothing load-bearing.

- **The account calls BUG-7 "a reading question rather than a measured failure"; the first half is right and the
  second understates it.** It is a measured failure of the *code against this plan's own ruling*; what is missing is
  a test, not a finding. Graded in §16.2.
- **BUG-1's framing as "the same seam as §15.1 from the other side" is exactly right**, and the record should say
  which of the two is normative: §15.1's order — enter on `active`, then write `migrating` inside the scope — is
  unaffected and stays, with the one addition that the status written back at the end is now the entry status rather
  than a literal. If anything the waiver makes §15.1's order *more* necessary, since more entry statuses now exist
  to be preserved.
- **One thing in the QA report I would not let stand as written:** AC-16 and AC-49 are FAIL "on a finding that needs
  no engine", which is true, but a reader could conclude that wiring them makes them PASS. It does not. AC-49 and
  AC-65 stay NOT VERIFIED behind G-1 (§14.4, `GET_LOCK` is re-entrant within a session) and AC-16's behavioural half
  needs an authentication failure the stand-in cannot produce. Step 13d says so; the re-test report should too.
- **The master recount, the statement-pin mutation and the assertion-shape guard catching `qa`'s own draft are
  confirmed and correct.** 25 live tables = 17 + 8, our files create 24, the design's 39 means 17 built by
  deferral: **not a shortfall**, and that now has two independent counts behind it.

---

## 17. Rulings after fix round 1 — the reachability scope, and two residuals

### 17.1 Step 13d cannot be built as worded — ruled: classify by the consumer kind the class declares, and count the kinds

**The measurement is the ruling's subject, not its context.** `dev` measured the check as §13d worded it: **red on 27
public methods, of which 3 are defects** — eight false positives per true one. A check at that ratio has exactly one
future: it is narrowed until it is green, the narrowing is done per method by whoever is holding it at the time, and
what remains proves nothing and costs a run every gate. `dev` was right to route the scope back rather than build it
and narrow it on day one, which §7 forbids in terms.

**The error in my wording was assuming one answer for all of `Support/`, which holds three different kinds of thing.**
Widening "production" to include the migration tree fixes six of the 24 and leaves eighteen — still far above the
tuning threshold — because the remainder is `SourceRules`, and a static rule's consumer genuinely *is* the suite. So
the axis is not the directory tree. It is **what kind of thing the class is**, declared by the class:

| Kind | What it is | Caller set the check searches | The obligation |
|---|---|---|---|
| `Mechanism` | a thing the system **calls** at run time | `packages/kernel/src/**` and `modules/host/app/**`, outside `Support/` | at least one call. **A test caller is not evidence** — this is the withdrawn wording, kept exactly, for the kind it was right about. |
| `SchemaVocabulary` | a thing **migrations are written in** | the `Mechanism` set ∪ `modules/host/database/migrations/**` ∪ `…/migrations-tenant/**` | at least one call **from a shipped migration**. A fixture migration under `tests/` does **not** count, so this is *stricter* than the withdrawn wording, not a widening: a helper used only by FX fixtures is still reported. |
| `RuleEvaluator` | a thing the system **is**: a static rule whose consumer is the suite | the test tree | at least one **red control** — the method must appear in the rule→fixture map **keyed by method name**, i.e. there exists a fixture on which it returns a finding. "Some test calls it" is still refused; what counts is that the rule has been seen to *fire*. |

Six rules stop this being a loophole:

1. **The kind is declared on the class**, once, beside the class name (a constant or a marker interface — `dev`'s
   choice), never per method. **Undeclared means `Mechanism`**, the strictest: deny-by-default, the same direction as
   13b's allow-list.
2. **No per-method exemption exists anywhere.** No ignore array, no annotation, no `@reachability-ignore`. The only
   move a future author has is changing a whole class's kind, which is one line next to the class name and reads in
   review as a *claim about the design* ("this class's consumer is the suite") rather than as a list entry nobody
   reads.
3. **The census is pinned, and this is the tooth against the 27-to-3 failure mode.** The check's own test asserts the
   **exact** number of classes in each kind and the **exact** number of public methods checked in each kind. Moving a
   mechanism into `RuleEvaluator` to silence it turns the census red in the same commit, so the escape hatch is
   itself counted. Every other property of this check can be argued; this one is why it is worth having.
4. **Each kind's scanned set must be non-empty** (13a's scope rule, applied per kind, not to the scan as a whole — a
   scan that is non-empty overall while one kind matched zero classes is the same vacuous pass in a smaller box).
5. **Detection runs over `SourceRules::codeOnly()`** and carries an evasion set enumerated from PHP's callable
   syntaxes rather than from memory — `C::m(`, `->m(`, `[C::class, 'm']`, `'C::m'`, `C::m(...)` — because BUG-5's
   standing rule (§7) binds this rule as much as the migration rules. A method reachable **only** through a dynamic
   string is **reported**, deliberately: that is a design we do not want, not a spelling we missed.
6. **Today's classification is the day-one census**: `Mechanism` — `MigrationSet`, `MigrationCatalogue`,
   `PerTenantLock`, `RunnerClock`, `TenantSchemaVersion`. `SchemaVocabulary` — `IdentifierColumn`, `JsonValidity`,
   `MigrationPhase`, `MigrationTree`. `RuleEvaluator` — `SourceRules`. `dev` re-derives the counts from the tree; if
   they differ from this list, **the tree wins and the plan is corrected**, because this list was read, not run.

**Alternative rejected: a declared list of classes that must be reachable.** Honest, as the lead says, and weak in
the one case the check exists for — the day somebody adds a mechanism and forgets the list, the check says nothing.
A list is a record of what we already know; the kind model is a rule about everything in the directory.

**The general form, for every later task:** a reachability check must name its caller universe **per kind of thing**,
and its exemptions must be **counted**. A check whose exemptions are not counted will be tuned to green, and the
tuning will look like maintenance.

### 17.2 Residual 1 — a queued run stays `running` until the finishing command is invoked: CONFIRMED

`dev`'s argument is the right one and it is the same argument that keeps the finish step in the loop: a worker
executing one job cannot know it executed the last one, and a worker that assumed it could would close a run while
another job was still in flight. Printing the instruction with the run id, and saying so in the docblock, is the
correct shape for T-008. Deferring the watcher that would close it automatically to a later backlog row is confirmed
— the lead files the row; it is not a line inside this plan.

Two conditions, because "a residual" must not become "a run whose state lies":

- **The finishing command refuses to finalise a run that still has a non-terminal job, and names it**, and is
  idempotent on a run already finished. Otherwise the manual step is a way to write a false terminal state by hand.
- **`tenant:schema-status` must distinguish "running, every job terminal" from "running, work outstanding".** A
  human reading the report is the only watcher this task ships, so the report has to carry the distinction the
  watcher would have acted on. If that is not already true it is one line in `ReportSchemaStatus` and it rides in
  13d's or 14a's commit.

### 17.3 Residual 2 — `TenantConnectionFailed` is no longer `final`: ACCEPTED, with four conditions

Subclass rather than sibling is the right call for the stated reason — every existing `catch` keeps working, so the
change cannot silently widen what escapes a handler — and un-finalising deserves the line `dev` asked for. Accepted
with four conditions, all cheap:

1. The **base class's docblock records why it is not final** and names the single permitted subclass. An un-final
   class with no note is an invitation.
2. The subclass carries **no credential-shaped state**, and T-007's property-name test (the one that forbids a
   password-shaped property name) is extended to cover it. A credential-rejection exception is precisely the class
   where a helpful author adds the rejected username.
3. One test asserts `catch (TenantConnectionFailed)` **catches the subclass** — the property the whole decision rests
   on, currently true by construction and nowhere asserted.
4. A **third** subclass needs a plan ruling. Two is a design; a family is a hierarchy nobody chose.

The `final public` constructor for late static binding is correct and needs no further argument; if PHPStan or
Deptrac say otherwise, believe the tools over this paragraph.

### 17.4 Two things from the round that become standing rules

- **Exactly-N-attempts is a property of shape, not of a counter.** `dev`'s rotation retry is two calls to one
  scope-opening method rather than a loop with a bound, so "exactly two attempts, no more" cannot drift by someone
  changing a constant. That is the preferred form in this repository for any bounded-retry requirement, and where a
  loop is genuinely needed the bound gets its own mutation test.
- **A wiring check that stays green when the behaviour is deleted is as weak as it declares itself to be — and
  saying so about your own test is the grade §7 asks for.** `dev` mutated its own work twice and reported that
  `qa`'s wiring check survived the deletion that reddened the behavioural cases. That division is now written into
  13d as the way the reachability check proves itself (reachability, not arity), and the general rule is: when a
  mutation leaves one of your tests green, say which one and why, in the test file.

### 17.5 What I judge wrong in the account

- **The stale criterion reference is mine and it was relayed twice.** §15.2's half (b) said "the state-unchanged
  assertions AC-10 already carries" and §16.1's note to `ba` said "AC-10/AC-51"; both are **T-007's** AC-10. This
  spec's AC-10 is the audit-log JSON criterion, as `ba` already recorded in the spec. Both lines are corrected in
  this file today. The lead relayed my own error back to me, which is what a relay does.
- **Nothing else in the account is wrong.** The BUG-2 reading — a constant closes the class of path a forced
  declaration only narrows — is the stronger option and I endorse it as shipped; that clearing the hold is now a
  commit by the owner is the correct cost, and G-1's conditions in §11.4 are unchanged by it.
- **One framing I would sharpen:** the account calls 13d's problem a scope question. It is a **kind** question that
  looked like a scope question because `Support/` is one directory, and that is why widening the scope would not
  have fixed it. The directory stays as it is; the classes now say what they are.
