# Plan: T-017 Audit framework

Spec: `docs/specs/T-017-audit-framework.md` (AC-1 to AC-21). Decisions: `docs/decisions/T-017-audit-framework.md` (D-1 to D-16). Backlog row FP-211; closes FP-323. Branch `ai/T-017-audit-framework`, cut from the T-016 tip.

**Gate baseline: not measured by `architect`.** Step 0 takes it.

`ENGINE_HOLD` is on. Nothing below needs a live connection to 127.0.0.1:3306, and no criterion is `[ENGINE]`. The stand-ins are the ones T-008 to T-016 already use: the in-memory SQLite `master` connection, the kernel run fixture (`packages/kernel/tests/Support/migration-run-fixture.php`), and the per-tenant SQLite files of `AccessTenantFixture`, entered with `AccessTenantFixture::enter()`.

**Locked by `ba`, not re-decided here:** D-1 (extend, never rebuild), D-2 (lazy request id, null outside a request), D-3 (email snapshot), D-4 (one `migration.run` row per run), D-5/D-6/D-15 (no new producers, no tenant `security_events`, no hooks for unbuilt modules), D-7 (no HTTP surface), D-8 (`audit.read`), D-9 (`is_platform_staff`), D-10 (one permanent `audit_logs_archive`), D-11 (365 days, app-wide config), D-12 (no pruning), D-13 (local file export), D-14 (FP-319 inherited), D-16 (FP-313 untouched).

---

## 0. Rulings

Each ruling takes the safest reversible option. Those marked **-> ba** read or narrow the spec; the lead routes them. The rest are technical choices the spec left open.

### Ruling 1: where the request identifier comes from (AC-1, AC-12, D-2)

Measured: **nothing in the repository mints a request identifier today.** No middleware, header or attribute exists (`grep` over `packages/*/src`, `modules/host/app`, `bootstrap`, `config` finds only the two writers' own `request_id` lines). D-2 says "reuse the `ClientIp` shape". That covers *reading* the value. Something still has to *create* it.

**Decision:**

- One global host middleware, `App\Http\Middleware\AssignRequestId`, **prepended** in `bootstrap/app.php`. For every HTTP request it stores a fresh ULID (`Str::ulid()->toBase32()`, 26 upper-case characters, which fits `request_id CHAR(26)`) as a request **attribute** under `RequestId::ATTRIBUTE`, and echoes it back as the `X-Request-Id` response header. That header is how an operator connects a support ticket to audit rows.
- **An inbound `X-Request-Id` is ignored, never adopted.** A client-chosen value would let a caller plant one request's identifier on another request's audit rows. Adopting a trusted upstream id belongs to the observability work the spec puts out of scope.
- One resolver, `Flexipos\Kernel\Http\RequestId`, built exactly like `ClientIp`: `fromRequests(Container)`, `none()`, `fixed(?string)`, `current(): ?string`. It is **lazy and repeated**. It reads the attribute from whichever request the container holds *at call time* and returns null when there is no request, or when the request has no attribute.
  - **Why it keys on the attribute and not only on `bound('request')`.** Laravel's console bootstrap (`SetRequestForConsole`) binds a synthetic request. Keying on the attribute makes "null outside a request" structural: only a request that passed through the middleware has an id. Console, queue and package tests have none.
- **It lives in the kernel because both writers need it.** Access and platform each depend on the kernel already, and neither may depend on the other. The kernel's `TenancyServiceProvider` binds it as a singleton holding the closure, for `ClientIp`'s reason.

### Ruling 2: the user agent (AC-2)

`Flexipos\Access\Support\ClientUserAgent` is the sibling of `ClientIp` in the same package, with the same three constructors. It returns:

- **null** when the request carries no `RequestId` attribute (the same "inside a request" test as Ruling 1);
- **null** when the `User-Agent` header is missing, empty, or whitespace only (AC-2's control: null, never `''`);
- otherwise the header, **truncated to 255 characters** with `mb_substr`. The column is `VARCHAR(255)`. SQLite would store a longer value silently, but MySQL in strict mode would refuse the whole audit insert and roll back the business operation with it.

`PlatformAudit` keeps `ip` and `user_agent` null. No criterion asks for them, and no master write path is HTTP-driven (its own docblock).

### Ruling 3: "a module/action value that names nothing any registered writer has ever used" is a declared catalogue (-> ba: AC-4, AC-16)

There are two ways to read "has ever used":

- **Empirical: distinct values in the table.** This is rejected. A fresh shop's empty table would refuse every valid filter, which contradicts AC-4's own empty case. It is also a full `DISTINCT` scan of a table that only grows.
- **Declared: a catalogue.** This is the one chosen.

**Decision:**

- **The contract.** A published interface, `Flexipos\Contracts\Audit\DeclaresAuditActions`, has `auditActions(): array<string, list<string>>`, keyed by module. It is implemented on the provider, the same way `DeclaresPermissions` is.
- **Who declares what:**
  - Access declares `access` (the 15 actions T-010 writes);
  - Organization declares `organization` (the 16 action constants T-014 handlers already carry);
  - Platform keeps its own closed list, `PlatformAuditActions::ALL` (the 7 T-016 actions plus `migration.run`). No other package writes that table.
- **The writers enforce it.** This is what makes the catalogue impossible to drift:
  - `AuditWriter` refuses a (module, action) pair the catalogue does not hold, with a `LogicException` naming both;
  - `PlatformAudit` does the same for its list.

  Every existing handler test therefore proves the catalogue complete for its own action, and a future module that forgets to declare goes red in its own first test. The refusal fires inside the caller's transaction, so nothing half-happens.
- **What is measured.** No test writes an arbitrary action through either writer. Measured: the only `->write(` and `->record(` calls are in `packages/*/src`, and the four T-016 doubles delegate real actions.

**This is new behaviour on an existing writer.** The rows it writes are unchanged in shape (AC-3). What changes is that an undeclared action now refuses. -> ba: informational. It is the "a typo is caught loudly" convention (T-016 AC-21) applied at write time as well as at read time.

### Ruling 4: filter rules the spec left open (-> ba: AC-4, AC-16, informational)

| Filter | Rule |
|---|---|
| `subject_id` | requires `subject_type`. The index is (`subject_type`, `subject_id`), and an id means nothing without its type. `subject_id` alone gives `validation` |
| `module` (tenant) | must be a catalogue key |
| `action` | must be in the catalogue. That means in the named module's list when `module` is given, and in the union of all lists otherwise |
| `from` / `to` | each parsed as an instant (ISO 8601 or `Y-m-d`). Unparseable gives `validation`; `to` before `from` gives `validation`; both ends are inclusive |
| `actor_id`, `tenant_id` | free identifiers, with no catalogue |
| page size | null or less than 1 means the default; above the maximum is clamped (T-014 D-28) |
| cursor | `before_id`, a positive integer, or `validation`. This is keyset paging on the BIGINT key, which is monotonic, so newest-first is `ORDER BY id DESC`. There is no `OFFSET`: an offset over a growing log is both slow and unstable |

The result is an `AuditPage`: the rows, plus `nextBeforeId`, which is null on the last page. The query fetches `size + 1` rows to know whether another page exists.

### Ruling 5: the archive move (AC-7, AC-8)

- **The table.** `audit_logs_archive` is built column-for-column with the same `Blueprint` calls as `audit_logs`. That includes `$table->id()`, the same four indexes, and `JsonValidity::enforce` on the three JSON columns. `branch_id` stays unguarded, for the same reason it is unguarded on `audit_logs` (T-014 D-2, FP-313).
- **One shared column list.** `Flexipos\Access\Support\AuditColumns::ALL` holds the column list. The move and the reader both use it. A shape test pins it against **both** live tables, so a later expand step that adds a column to one table and not the other goes red.
- **The move, per tenant** (`ArchiveAuditLogsHandler`):
  1. `threshold = clock.now() - retention_days`, formatted exactly as `RunnerClock::timestamp()` formats `created_at`.
  2. **Loop:**
     - select up to `chunk_size` ids `WHERE created_at <= threshold ORDER BY id`, and stop when there are none;
     - in **one tenant transaction**: `insertUsing(AuditColumns::ALL, select those columns from audit_logs where id in ids)` into the archive, then delete those ids from `audit_logs`.
  3. Return the counts: moved, and chunks.
- **What the move guarantees:**
  - **Byte-identical.** The copy is SQL-level, `INSERT … SELECT`, so the binary `ip` and the JSON text never pass through PHP. Ids are preserved, so archive order equals live order.
  - **No row in both tables.** Each chunk is atomic. A crash between chunks leaves a consistent state, and the next run continues from it.
- **The bound.** `chunk_size` defaults to 500 and is clamped to at most 900, which stays under SQLite's default 999 bound parameters.
- **The boundary.** `<=` means a row exactly 365 days old is archived and a 364-day-old row is not (AC-8).

### Ruling 6: which tenants the archival job visits (-> ba: AC-8, informational)

The job visits every tenant whose mapping status the **serving** purpose accepts. `TenantConnectionPurpose::SERVING_STATUSES` is `active` today. The job enters each one with `Tenancy::run($id, …, Serving)`.

- **Why `read_only`, `suspended` and the other statuses are skipped.** They write no new audit rows, so their backlog does not grow. Entering them would also need a new connection purpose, which is a kernel decision this task should not make. They are archived on their first run after they become active again.
- **Fleet enumeration is a new kernel reader,** `Flexipos\Kernel\Tenancy\Contracts\TenantFleet::servingTenantIds(): list<string>`, sorted. It has two implementations, chosen by the same `MappingSource` switch as the two existing repositories:
  - `MasterTenantFleet` reads `tenant_databases.status` on the master connection;
  - `InMemoryTenantFleet` reads `tenancy.databases`.

  The access package never names the master connection, so T-016's AC-32 source scan stays true.
- **One tenant's failure does not stop the others** (T-016's sweep shape). It is logged with the tenant id and the exception class, the loop continues, and the command exits 1 if any tenant failed.

### Ruling 7: the migration-run audit crosses a boundary the kernel does not have (AC-14, AC-20)

Measured in `deptrac.yaml`: `Package_Kernel` may depend on `Package_Kernel_TenancyStatus` only. **The runner cannot import `PlatformAudit`** without a new edge, and that edge would form a cycle, because platform already depends on the kernel.

**Decision: a port owned by the kernel.**

- **The kernel interface:** `Flexipos\Kernel\Schema\Contracts\RecordsMigrationRuns::recordPlanned(Connection $master, string $runId, int $targetVersion, string $mode, ?array $tenantIds, string $setName, ?string $initiatedBy): void`.
- **The platform implementation:** `Flexipos\Platform\Support\MigrationRunAudit`. It calls the existing `PlatformAudit::write()` once. **That keeps `PlatformAudit` the only inserter (AC-20).**
- **The binding** is made by `PlatformServiceProvider`.
- **No default binding in the kernel, on purpose.** A host that boots the kernel without the platform cannot plan a run. It fails with a resolution error, and does not run a migration that nobody audits. Today every process boots both (`bootstrap/providers.php`).
- **Where the call goes.** `PlanMigrationRunHandler::write()` gains the port and wraps the run row, the job rows and the audit call in **one master transaction**. Until now the inserts were un-wrapped. Every refusal still fires before the first write, so the handler's documented order is unchanged.
- **Once per run, structurally.** A resumption, `--retry-failed`, and the queued `dispatch()` resumption never reach the planner (`runFor()` plans only when `! resumes()`). The row is therefore written once per run with no extra check.
- **The row** (AC-14):

  | Field | Value |
  |---|---|
  | `action` | `migration.run` |
  | `subject_type` | `tenant_migration_run` |
  | `subject_id` | the run id |
  | `tenant_id` | the id when the mode is `tenant` and exactly one tenant is named; otherwise null |
  | `actor_id` | `initiated_by`; `actor_type` follows the existing rule |
  | `before` | null |
  | `after` | `{target_version, mode, selector}`, where `selector` is the same structure the run row stores |

  The planner's real modes are `bulk` and `tenant` (`PlanMigrationRun::mode()`). The spec's "bulk/selected/canary" are `docs/database.md` §4.6's designed enum values, and only `bulk` exists in code.

### Ruling 8: `PlatformAudit`'s new collaborators stay optional for the four T-016 doubles

Four T-016 fault-injection tests build `new class(app(RunnerClock::class)) extends PlatformAudit`. They are `ChangeLicenceStatusTest`, `ChangeSubscriptionPlanTest`, `ChangeSubscriptionStatusTest` and `StartSubscriptionTest`.

- **The constructor** becomes `(RunnerClock $clock, ?RequestId $requestId = null)`, where null means `RequestId::none()`. Those doubles compile unchanged and need no edit.
- **Production never takes the default.** `PlatformServiceProvider` binds `PlatformAudit` **explicitly** with the live resolver, and the AC-12 test resolves the class from the container and asserts a non-null id inside a request. Without the binding that test goes red.
- `AuditWriter` has no such constraint, because measured: no test constructs it. Its new collaborators are required parameters.

### Ruling 9: the permission key lives in its own declaration class (AC-5, D-8)

- **The key.** `Flexipos\Access\Registry\AuditPermissions` declares `audit.read`: module `access`, group `audit`, not branch-scoped, default closed.
- **Wiring.** `AccessServiceProvider::permissionDeclarations()` returns `AccessPermissions` merged with `AuditPermissions`.
- **Why a separate class.** `AccessPermissions` keeps its "six keys" contract, and the tests that pin those six do not move. Only `PermissionRegistryTest`'s provider-count line moves (section 9).
- **The check.** It runs in the handler, through the injected `PermissionResolver::decide($actor, 'audit.read', null)`. A refusal throws `AccessRefused::notPermitted(AccessDecision)`. This is a new named constructor that carries the decision's own `RefusalReason`; the enum is unchanged.

### Ruling 10: platform read gate and refusal (AC-17, D-9)

- **Who may read.** The caller passes `actorUserId`. The handler reads `users` on the master connection and allows the read only when the row exists, `deleted_at` is null, `status = 'active'` and `is_platform_staff` is true.
- **Who is refused.** Anything else is refused with `PlatformRefused::forbidden()`, a new fourth closed reason. A missing user is also `forbidden`, not `not_found`, so the gate does not reveal which user ids exist.

### Ruling 11: export (AC-18, D-13)

`Flexipos\Kernel\Audit\AuditExport::write(AuditPage $page, string $path, ExportFormat $format)`. It lives in the kernel because both sides use it and neither may import the other.

- **JSON** is an array of row objects.
- **CSV** has a header row from `AuditColumns`, then one line per row. The JSON columns are re-encoded as JSON strings. `ip` is printable on the tenant side; the platform side gives hex or null (see below).
- **It refuses to overwrite an existing file.** That is safest; nothing is lost.
- **It writes one page.** The bound is "the same maximum page size" by construction. The command prints `nextBeforeId`, so an operator can continue from where the page ended.

**Platform `ip` is rendered as hex.** A third `inet_ntop` home is the hazard FP-306 records. Platform search therefore returns `bin2hex` or null, never an address. No producer writes it today.

### Ruling 12: configuration

New `modules/host/config/audit.php`. Numbers only, in the header style of `config/access.php`:

| Key | Default |
|---|---|
| `page_size` | 50 |
| `max_page_size` | 500 |
| `retention_days` | 365 |
| `archive_chunk_size` | 500 |

A non-positive `retention_days` is refused by the archival handler with a `LogicException`. "Archive everything now" is not a value a typo should produce.

### Ruling 13: no new package

Search, archival and export are slices in the two packages that already own the two writers. Access owns `audit_logs` (T-010 D-20); platform owns `platform_audit_logs`. A `packages/audit` package would need access's `TenantConnection` and `AuditWriter` internals, or a second copy of them. It would also add two deptrac layers and a registry-coverage entry for no behaviour.

---

## 1. Approach summary

1. **Request context.** The host middleware mints a ULID per HTTP request (Ruling 1). The kernel `RequestId` resolver reads it lazily, and access's `ClientUserAgent` reads the header lazily (Ruling 2). Both writers fill `request_id`; the tenant writer also fills `user_agent`.
2. **`PlatformAudit` is extended, not replaced.** It gains the real `request_id` (null in console runs, which closes FP-323) and the `actor_email` snapshot. The migration runner reaches it through a kernel-owned port, inside the planner's new transaction (Ruling 7).
3. **A declared action catalogue** (Ruling 3) is the single authority for "a valid action". Both writers enforce it, and both readers validate against it.
4. **One ordinary tenant expand migration** adds `audit_logs_archive`. A daily access slice moves aged rows in atomic chunks across the active fleet (Rulings 5 and 6).
5. **Read side.** One query slice per side (`SearchAuditLog` in access, `SearchPlatformAuditLog` in platform), each with a console command that can also export one page to JSON or CSV. **No route** (D-7).
6. **Integrity.** Source scans prove the sole-writer rules and the absence of any delete of audit history (AC-10, AC-11, AC-19, AC-20).
7. **No new dependency and no new package.** The clock is `RunnerClock`, the scheduler is Laravel's, and ULIDs come from `Str`.

## 2. Alternatives rejected

- **Adopting an inbound `X-Request-Id`.** A spoofable correlation key (Ruling 1).
- **Reading "has ever used" from the table.** It contradicts AC-4's empty case and needs a full scan (Ruling 3).
- **A catalogue enforced only by a source scan of call-site literals.** Fourteen access handlers pass literal strings across multiple lines, so a regex parser is fragile. Write-time enforcement is exact and free.
- **Replacing the 15 access action literals with constants.** It touches 14 reviewed T-010 handlers for no behavioural gain once the writer enforces the catalogue.
- **Granting `Package_Kernel -> Package_Platform`.** That edge is a cycle (Ruling 7).
- **A Laravel event for the migration run.** Implicit, and a missing listener audits nothing silently. A bound port fails loudly.
- **The year-suffixed archive table.** D-10.
- **A transparent union of live and archive tables.** D-9.
- **Adding `list()` to `TenantDatabaseRepository`.** It would touch three implementations, including the caching one, whose single-tenant cache key has no meaning for a list.

---

## 3. Files to change or create

`dev` writes every file. Files named `Qa*` are `qa`-accountable.

### `packages/kernel` (new files; edits in section 9)

| Path | What |
|---|---|
| `src/Http/RequestId.php` *(new)* | Ruling 1: `ATTRIBUTE`, `fromRequests()`, `none()`, `fixed()`, `current()` |
| `src/Audit/AuditPage.php` *(new)* | `list<array<string,mixed>> $rows`, `?int $nextBeforeId` |
| `src/Audit/PageSize.php` *(new)* | `clamp(?int $asked, int $default, int $max): int`. **The one clamp** for both readers |
| `src/Audit/ExportFormat.php` *(new)* | backed enum: `json`, `csv` |
| `src/Audit/AuditExport.php` *(new)* | Ruling 11 |
| `src/Schema/Contracts/RecordsMigrationRuns.php` *(new)* | Ruling 7's port |
| `src/Tenancy/Contracts/TenantFleet.php`, `src/Tenancy/MasterTenantFleet.php`, `src/Tenancy/InMemoryTenantFleet.php` *(new)* | Ruling 6 |
| `tests/Unit/RequestIdTest.php`, `tests/Unit/Audit/PageSizeTest.php`, `tests/Unit/Audit/AuditExportTest.php`, `tests/Feature/TenantFleetTest.php` *(new)* | steps 1, 7 and 10 |

### `packages/contracts` (new)

| Path | What |
|---|---|
| `src/Audit/DeclaresAuditActions.php` | Ruling 3's provider interface |

### `packages/access`

| Path | What |
|---|---|
| `src/Support/ClientUserAgent.php` *(new)* | Ruling 2 |
| `src/Support/AccessAuditActions.php` *(new)* | `MODULE = 'access'`, `ALL`: the 15 literals the T-010 handlers write. The docblock says the writer enforces this list |
| `src/Support/AuditCatalogue.php` *(new)* | collects `DeclaresAuditActions` providers through `$app->getProviders()`, as `PermissionRegistry::providers()` does, with no memo. Methods: `modules()`, `actions(?module)`, `knows(module, action)` |
| `src/Support/AuditColumns.php` *(new)* | `ALL`: the 16 column names, in table order |
| `src/Registry/AuditPermissions.php` *(new)* | Ruling 9 |
| `src/Features/ArchiveAuditLogs/{ArchiveAuditLogs,ArchiveAuditLogsHandler,ArchiveAuditLogsResult,ArchiveAuditLogsCommand}.php` *(new)* | Rulings 5 and 6. The command is `audit:archive {--tenant=*}`: no option means the fleet, and the option names tenants |
| `src/Features/SearchAuditLog/{SearchAuditLog,SearchAuditLogHandler,SearchAuditLogCommand}.php` *(new)* | the query carries the filters, `bool $archive`, `?int $pageSize`, `?int $beforeId` and `string $actorEmployeeId`. The command is `audit:search --tenant= --as= [filters] [--archive] [--output= --format=json\|csv]`, entering the tenant through `Tenancy::run(…, Serving)` |
| `tests/Support/ClientUserAgentTest.php`, `tests/Support/AuditRequestContextTest.php`, `tests/Support/AuditCatalogueTest.php` *(new)* | steps 2 and 3 |
| `tests/Schema/AuditLogsArchiveShapeTest.php` *(new)* | step 6 |
| `tests/Features/ArchiveAuditLogs/ArchiveAuditLogsTest.php`, `ArchiveAuditLogsCommandTest.php`, `ArchiveAuditLogsScheduleTest.php` *(new)* | step 8 |
| `tests/Features/SearchAuditLog/SearchAuditLogTest.php`, `SearchAuditLogPermissionTest.php`, `SearchAuditLogCommandTest.php` *(new)* | step 11 |

### `packages/organization`

| Path | What |
|---|---|
| `src/Registry/OrganizationAuditActions.php` *(new)* | `ALL` built from the 16 existing handler constants (`CreateBranchHandler::ACTION` and so on), so no literal is copied |

### `packages/platform`

| Path | What |
|---|---|
| `src/Support/PlatformAuditActions.php` *(new)* | the 8 literals (Ruling 3) |
| `src/Support/MigrationRunAudit.php` *(new)* | implements `RecordsMigrationRuns` (Ruling 7) |
| `src/Features/SearchPlatformAuditLog/{SearchPlatformAuditLog,SearchPlatformAuditLogHandler,SearchPlatformAuditLogCommand}.php` *(new)* | the command is `platform:audit-search --as=<user id> [filters] [--output= --format=]` |
| `tests/Support/PlatformAuditRequestIdTest.php`, `tests/Support/PlatformAuditActorEmailTest.php`, `tests/Support/MigrationRunAuditTest.php` *(new)* | steps 4 and 5 |
| `tests/Features/SearchPlatformAuditLog/SearchPlatformAuditLogTest.php`, `SearchPlatformAuditLogCommandTest.php` *(new)* | step 12 |

### Host, `modules/host`

| Path | What |
|---|---|
| `app/Http/Middleware/AssignRequestId.php` *(new)* | Ruling 1 |
| `config/audit.php` *(new)* | Ruling 12 |
| `database/migrations-tenant/2026_10_29_000701_create_audit_logs_archive_table.php` *(new)* | `@phase expand`. The name must sort after `2026_10_22_000506_…` and carry no `fx` or `tenant` token. No `down()`. The docblock states D-10, the unguarded `branch_id`, and that the column list is pinned |
| `tests/Feature/AuditRequestIdHttpTest.php` *(new)* | step 2 (tenant) and step 4 (platform), HTTP halves, through scratch routes |
| `tests/Feature/QaAuditSourceRulesTest.php`, `tests/Feature/QaAuditDatabaseSeparationTest.php`, `tests/Feature/QaAuditNoHttpSurfaceTest.php` *(new)* | step 13 |

### Docs

| Path | What |
|---|---|
| `docs/database.md` §11, the "standard" retention line *(change, step 6)* | Restate tenant audit retention as one permanent archive table, filled by the scheduled chunked move, citing D-10. **Describe the old wording, do not quote it** (memory: the gate sweeps the working tree) |
| `docs/database.md` §6.21 *(change, step 6)* | Add the `audit_logs_archive` row: "same columns and indexes as `audit_logs`" |

---

## 4. Data and API changes

### Tenant schema

- **One expand migration:** `audit_logs_archive` (Ruling 5).
- The tenant tree goes from 19 tables to 20, with the file count derived.
- The catalogue is republished with `php artisan schema:publish --write-fixture`, **only with the flag** (T-011 §3).
- `.env.example` `APP_REQUIRED_SCHEMA_VERSION` moves to the new top.

### Master schema

**No change.**

### Writes

| Table | Writer | Change |
|---|---|---|
| `audit_logs` | `AuditWriter` only | `request_id` and `user_agent` now filled; an undeclared action now refuses |
| `audit_logs` delete + `audit_logs_archive` insert | `ArchiveAuditLogsHandler` only | new |
| `platform_audit_logs` | `PlatformAudit` only | `request_id` real or null; `actor_email` snapshot; undeclared action refuses; new action `migration.run` |

### Console (no HTTP route; D-7)

| Command | Side | Scheduled |
|---|---|---|
| `audit:search` | tenant | no |
| `audit:archive` | tenant fleet | `dailyAt('03:00')->withoutOverlapping()`, registered by `AccessServiceProvider`. It inherits FP-319 |
| `platform:audit-search` | master | no |

### HTTP

- The **only** change is a new `X-Request-Id` response header on every response.
- No route is added.

---

## 5. Boundary (deptrac and PHPStan) impact

**No new layer, path or allowed edge.** Every new symbol lives in an existing package and depends only on what that package may already reach:

| Package | Reaches |
|---|---|
| Access | kernel (`RequestId`, `TenantFleet`, `Tenancy`, `Audit\*`) and contracts |
| Organization | contracts (`DeclaresAuditActions`) |
| Platform | kernel (`RequestId`, `Audit\*`, `RecordsMigrationRuns`) |
| Host app | kernel (`RequestId::ATTRIBUTE`) |

The kernel gains no edge. **Step 5's negative control:** a kernel scratch class importing `Flexipos\Platform\Support\PlatformAudit` must fail `composer deptrac`, and importing `RecordsMigrationRuns` must pass. Delete both afterwards.

**Stated limit (memory).** Deptrac proves type references only. "No other file inserts into an audit table" is carried by step 13's source scans.

---

## 6. Implementation steps

Each step ends with `composer check` green from `modules/host`, with a zero-delta-or-explained comparison against the gate as it stood at the step's start. **Before each gate run, `dev` lists the foreign `php.exe` processes.**

**Red-list rule.** After steps 2, 3, 5 and 6, `dev` records the red list **before** fixing anything and compares it with section 9. A red file section 9 does not predict is a **stop and report to `architect`**.

**Red-first rule.** Red is shown by omission or by a fixture. No resolver, gate or writer check is ever stubbed to allow.

- [ ] **Step 0: baseline.**
  - Run `composer check`, and record the counts, the test-step seconds and the process list in the commit message.
  - *(No AC.)*

- [ ] **Step 1: the request identifier.**
  - Create `RequestId`, bind it in `TenancyServiceProvider`, create `AssignRequestId`, and prepend it in `bootstrap/app.php`.
  - **`packages/kernel/tests/Unit/RequestIdTest.php`:**
    - `none()` gives null and `fixed('X')` gives `X`;
    - `fromRequests` with no bound request gives null;
    - a bound request with no attribute gives null (the console case);
    - a request carrying the attribute gives its value;
    - **laziness:** swapping the container's request between two calls changes the answer.
  - **Host, start of `AuditRequestIdHttpTest`:**
    - a request gets an `X-Request-Id` of 26 upper-case Crockford characters;
    - two requests get two different values;
    - an inbound `X-Request-Id: AAAA…` is **not** echoed back.
  - *Done when:* green. *(Infrastructure for AC-1 and AC-12.)*

- [ ] **Step 2: the tenant writer's two gaps.**
  - Create `ClientUserAgent` and bind it in `AccessServiceProvider` beside `ClientIp`.
  - `AuditWriter` takes `RequestId` and `ClientUserAgent` and fills both columns. Its docblock and inline comment are restated: the "they stay null until a task owns them" passage and the "no retention, no search, no export" passage. Describe them, do not quote.
  - **`ClientUserAgentTest`:**
    - no request gives null;
    - a request without the id attribute gives null;
    - no header gives null; an empty header gives null; a whitespace-only header gives null (**not `''`**, AC-2's control);
    - 300 characters are stored as 255;
    - laziness, as in step 1.

    The cases are **named after `ClientIp`'s "null outside a request" case** so a reviewer can check the two behave alike (AC-1's stated control).
  - **`AuditRequestContextTest`:** a handler-level write in a package test stores `request_id` null and `user_agent` null. This is the console case.
  - **Host `AuditRequestIdHttpTest`, tenant half:**
    - mount a scratch `api` route inside an `AccessTenantFixture` tenant that calls `AuditTrail::record()` **twice**. Both rows carry the same non-null id, equal to the response's `X-Request-Id`, and `user_agent` equals the sent header;
    - a second call gives a different id;
    - `Artisan::call` of a command that writes a row stores null.
  - **Predicted red:** none. `AuditWriterTest`'s whole-row cases run outside a request and still expect null for both columns. A red one is a stop.
  - *Done when:* green, and `AuditWriterTest` and `AuditTransactionTest` are untouched. *(AC-1, AC-2, AC-3 part.)*

- [ ] **Step 3: the action catalogue.**
  - Create `DeclaresAuditActions`, `AccessAuditActions`, `OrganizationAuditActions` and `AuditCatalogue`.
  - Both providers implement the interface.
  - `AuditWriter::insert()` refuses an undeclared (module, action) pair before inserting.
  - **`AuditCatalogueTest`:**
    - the modules are exactly `access` and `organization`;
    - access holds 15 actions and organization 16, printed;
    - `knows()` both ways;
    - a fixture provider declaring a third module is seen without any memo.
  - **Writer refusal:**
    - inside a transaction, `record('organization', …, 'no_such_action', …)` throws `LogicException`, and `audit_logs` has zero rows after the rollback;
    - **control:** a declared action writes one row.
  - **The whole existing suite is the drift proof.** Every T-010 and T-014 handler test writes through the enforcing writer.
  - *Done when:* green. *(AC-4 prerequisite, AC-19 part, AC-3 part.)*

- [ ] **Step 4: `PlatformAudit` (closes FP-323).**
  - Ruling 8's constructor, plus the explicit binding in `PlatformServiceProvider`.
  - `request_id = $this->requestId->current()`.
  - `actor_email`: read from `users.email` on the given master connection when `actorUserId` is non-null; null when the id names nobody. It is never joined later.
  - `PlatformAuditActions` is enforced.
  - The docblock is restated.
  - **`PlatformAuditRequestIdTest` (AC-12):**
    - `Artisan::call('platform:enforce-deadlines')` over a seeded transition gives a row with `request_id` null;
    - a direct handler call in a package test also gives null;
    - **host HTTP half,** in `AuditRequestIdHttpTest`: a scratch route resolving `PlatformAudit` **from the container** writes two rows. Both carry the response's id. This is the binding proof of Ruling 8.
  - **`PlatformAuditActorEmailTest` (AC-13):**
    - with a seeded user, the email is snapshotted;
    - after updating `users.email`, the stored row is unchanged;
    - a system write (null actor) gives null;
    - an unknown actor id gives null.
  - **Refusal:** an undeclared action throws, and there is no row.
  - **Predicted red:** none. The four T-016 doubles compile under Ruling 8. A red one is a stop.
  - *Done when:* green. *(AC-12, AC-13, AC-20 part.)*

- [ ] **Step 5: the migration-run audit.**
  - Create the port; inject it into `PlanMigrationRunHandler`; wrap `write()` in a master transaction; create `MigrationRunAudit` and its binding.
  - Run the section 5 negative control.
  - **`packages/platform/tests/Support/MigrationRunAuditTest.php`,** on the kernel run fixture (`require_once`), whole-row comparisons:
    - **A bulk run:** exactly one row. `action` is `migration.run`, `subject_type` is `tenant_migration_run`, `subject_id` is the run id, `tenant_id` is null, `actor_type` is `system`, `before` is null, and `after` is `{target_version, mode: bulk, selector}`.
    - **A named one-tenant run:** `tenant_id` is that tenant. **A named two-tenant run:** null.
    - **`initiatedBy`** set to a seeded user gives `actor_type` `user`, that `actor_id`, and the email snapshot.
    - **Once only.** `tenant:migrate --run=<id> --retry-failed` and a `dispatch()` resumption each leave **one** row (AC-14's "not written again on retry").
    - **A planner refusal** (destructive without backups) writes zero audit rows and zero run rows.
    - **Atomicity:** bind a `RecordsMigrationRuns` that throws. There is no run row and no job row afterwards.
  - **Predicted red:** none. Kernel planner tests assert only that the `master` connection is used, and the audit is on master. A kernel test that counts insert statements, or pins the planner's statements, would be red, and is a stop.
  - *Done when:* green. *(AC-14, AC-15 by construction, AC-20 part.)*

- [ ] **Step 6: the archive table.**
  - The migration; the republished catalogue (`--write-fixture`); `.env.example`; `tests/Helpers.php` `tenantTreeTables()` gains `audit_logs_archive`; `MigrationTreeDisciplineTest` 19 becomes 20; and the two `docs/database.md` edits.
  - **`AuditLogsArchiveShapeTest`:**
    - for each column of `audit_logs`, the archive has the same name, type and nullability, and `AuditColumns::ALL` equals both lists;
    - the same four indexes;
    - invalid JSON is refused in each JSON column, as AC-29 is for `audit_logs`;
    - `SourceRules::inspect()` returns nothing for the file.
    - **Control:** a scratch archive file missing one column is reported by the column comparison.
  - **Predicted red:** `MigrationTreeDisciplineTest` (edited), `SchemaCataloguePublishTest` (fixed by the republish, not edited), and possibly `QaAccessCloseOutSweepsTest` (section 9). Anything else is a stop.
  - *Done when:* green. *(AC-7.)*

- [ ] **Step 7: the tenant fleet.**
  - Create `TenantFleet`, its two implementations and its binding.
  - **`TenantFleetTest`:**
    - on the master source, `active` tenants are returned sorted, and `read_only`, `suspended`, `provisioning` and `archived` are absent;
    - on the config source, the same from `tenancy.databases`;
    - the switch's refusal outside local environments goes through `MappingSource`'s own guard.
  - *Done when:* green. *(Prerequisite for AC-8.)*

- [ ] **Step 8: archival.**
  - The slice, the command, the schedule and `config/audit.php`.
  - **`ArchiveAuditLogsTest`,** with `RunnerClock::fixedAt()` and rows inserted through the writer, then aged by direct `created_at` updates in the fixture:
    - **Boundary:** a row at exactly now − 365 days is moved, and one at now − 364 days stays.
    - **Byte-identical:** each moved row is compared as a whole raw row (binary `ip`, JSON text, `request_id`, `user_agent`) before and after. Ids are preserved, and the id sets of the two tables are disjoint.
    - **Chunked:** with `archive_chunk_size = 2` and 5 aged rows, there are 3 chunks. The query log shows 3 `insert … select` and 3 `delete` statements, never one of each.
    - **No-op:** nothing aged means both tables are unchanged and nothing errors.
    - **Atomic chunk:** a `beforeExecuting` hook throws on the first `delete from "audit_logs"`. The archive holds none of that chunk's ids, and the live table still holds them.
    - **Configuration:** `retention_days = 0` refuses (Ruling 12), and `chunk_size = 5000` is clamped to 900.
  - **`ArchiveAuditLogsCommandTest`:**
    - two fixture tenants, both archived;
    - one tenant made unreachable: the other is still archived, the exit code is 1, and the failure is logged with the tenant id;
    - `--tenant=` limits the run to that tenant.
  - **`ArchiveAuditLogsScheduleTest`:** the schedule lists `audit:archive` daily, without overlapping.
  - *Done when:* green. *(AC-8, AC-10's "no prune" for the live table — the move is not a prune.)*

- [ ] **Step 9: the permission key.**
  - Create `AuditPermissions`, merge it in the provider, and add `AccessRefused::notPermitted()`.
  - The `PermissionRegistryTest` provider-count line moves (section 9). `PermissionDeclarationTest` and the "six keys" case stay unchanged.
  - *Done when:* green, and the sync puts `audit.read` in a fixture tenant's `permissions` table. *(AC-5 part.)*

- [ ] **Step 10: kernel paging and export.**
  - `AuditPage`, `PageSize`, `ExportFormat` and `AuditExport`.
  - **Unit tests:**
    - **`PageSize`:** null gives the default, 0 gives the default, `max + 1` gives `max`, and both values move with the arguments, which means no literals.
    - **`AuditExport` JSON:** round-trips.
    - **`AuditExport` CSV:** the header equals the given columns, and a JSON cell with a comma and a quote survives `str_getcsv`.
    - **Existing path:** refused, and the file is unchanged.
    - **Empty page:** gives `[]` for JSON, and a header only for CSV.
  - *Done when:* green. *(AC-18 mechanism.)*

- [ ] **Step 11: tenant search.**
  - The `SearchAuditLog` slice and command.
  - **`SearchAuditLogTest`,** with the actor granted `audit.read` through fixture roles:
    - **Filters:** each filter alone, then all combined, against a seeded mix of modules, actions, subjects, actors and times. Results are newest-first; keyset paging covers every row exactly once across pages; the page size is clamped (AC-4).
    - **Empty case:** a valid filter matching nothing gives an empty page, not an error.
    - **Invalid cases**, each a named `validation`:
      - `to < from`;
      - an unparseable date;
      - an unknown module;
      - an unknown action;
      - an action known under another module only;
      - `subject_id` without `subject_type`;
      - `before_id` of 0.
    - **AC-9:** after archiving, the default call does not see the moved rows, and `archive: true` sees exactly them with the same filters.
    - **Isolation:** the query log shows only tenant-connection statements, naming only `audit_logs` or `audit_logs_archive` (and the resolver's own access tables).
  - **`SearchAuditLogPermissionTest` (AC-5):**
    - an actor whose role holds **all six `AccessPermissions` keys** (and every organization key) but not `audit.read` is refused `not_permitted`;
    - a second actor holding **only** `audit.read` is allowed;
    - an unknown employee is refused.

    These run through the real resolver and fixture roles.
  - **`SearchAuditLogCommandTest`:**
    - `--output` plus `--format=csv` writes the same rows the handler returns;
    - the export of 7 rows with `max_page_size = 5` writes 5 and prints the next cursor (AC-18);
    - a refusal exits non-zero and prints the reason.
  - *Done when:* green. *(AC-4, AC-5, AC-9, AC-18 tenant half.)*

- [ ] **Step 12: platform search.**
  - The `SearchPlatformAuditLog` slice and command, plus `PlatformRefused::forbidden()` (whose docblock lists four reasons).
  - **`SearchPlatformAuditLogTest`:**
    - the same filter, paging, empty and invalid matrix as step 11, over `tenant_id`, `actor_id`, `action`, the subject pair and the range (AC-16);
    - the query log shows the master connection only;
    - **AC-17:**
      - a staff user is allowed;
      - a non-staff, a soft-deleted staff user, a non-`active` staff user and an unknown id are each `forbidden`;
      - **control:** flipping `is_platform_staff` on the same user changes the answer.
  - **`SearchPlatformAuditLogCommandTest`:** the export, the clamp and the refusal exit code (AC-18 platform half).
  - *Done when:* green. *(AC-16, AC-17, AC-18.)*

- [ ] **Step 13: the close-out sweeps (`qa`-accountable files, written by `dev`).**
  - **`QaAuditSourceRulesTest`.** It scans `packages/*/src` and `modules/host/app`, asserts every scanned directory exists, and gives each rule a planted-file control in the style of `QaPlatformSourceRulesTest`:
    - **AC-19:** the only file inserting into `audit_logs` is `AuditWriter`, and the only file inserting into `audit_logs_archive` is `ArchiveAuditLogsHandler`. The matching is exact-name, so `audit_logs_archive` never matches the `audit_logs` rule.
    - **AC-20:** the only file inserting into `platform_audit_logs` is `PlatformAudit`. `MigrationRunAudit` and the planner never name the table.
    - **AC-11 and AC-10:** no file issues a delete against `platform_audit_logs` or `audit_logs_archive`, and the only delete against `audit_logs` is in `ArchiveAuditLogsHandler`.
      - **Control (AC-11's "discriminates"):** a planted `->table('platform_audit_logs')->where(…)->delete()` is reported, and a planted `->table('fixture_table')->delete()` in the same run is **not**.
      - **A runtime half on the stand-in:** in the same test, deleting from a scratch fixture table succeeds, which proves the instrument does not refuse all deletes.
  - **`QaAuditDatabaseSeparationTest`:** the two searches and the archive move, under a two-connection query log. Tenant work issues no master statement, and platform search issues no tenant statement (NFR, and AC-4's "never reads the master").
  - **`QaAuditNoHttpSurfaceTest` (AC-6, AC-17):**
    - no registered route's URI or action names `audit`;
    - **control:** a mounted scratch `GET api/v1/audit` is reported.
  - *Done when:* green. *(AC-6, AC-10, AC-11, AC-17 surface half, AC-19, AC-20.)*

- [ ] **Step 14: close-out, `qa`.**
  - **Mandatory mutations,** each shown red and then reverted:
    1. `AuditWriter` writes `request_id` null: AC-1's HTTP case goes red.
    2. `RequestId` keys on `bound('request')` only: the console-null case goes red.
    3. `ClientUserAgent` returns `''` for a missing header: AC-2's control goes red.
    4. `PlatformAudit` mints `Str::ulid()` again: AC-12's console case goes red.
    5. `<=` becomes `<` in the threshold: the 365-day boundary goes red.
    6. Drop the chunk transaction: the atomic-chunk case goes red.
    7. Call the port outside the planner transaction: the atomicity case goes red.
    8. Write the migration audit row in `markRunning()`: the retry-once case goes red.
    9. Skip the `audit.read` check: AC-5 goes red.
    10. Drop the staff check: AC-17 goes red.
    11. Accept an unknown action: AC-4's invalid case goes red.
  - **Then:** section 9's edit-list diff check, confirming **no pre-existing audit assertion was removed or weakened** (AC-3, AC-21), and the final `composer check` with its process list.
  - *(AC-3, AC-21.)*

---

## 7. Test strategy

- **Unit, pure:** `RequestId`, `ClientUserAgent`, `PageSize`, `AuditExport`, `AuditCatalogue` (steps 1, 2, 3 and 10).
- **Slice (package, stand-ins, fixed clock):** `ArchiveAuditLogs`, `SearchAuditLog` and `SearchPlatformAuditLog`, each with a mirror test folder (steps 8, 11 and 12). The `VerticalSliceLayoutTest` covers them automatically.
- **Writer extensions:** steps 2, 4 and 5.
- **Host HTTP:** `AuditRequestIdHttpTest` (steps 1, 2 and 4).
- **Sweeps (host, `qa`):** step 13.

| Criteria | Proved in |
|---|---|
| AC-1 | steps 1 and 2 (`RequestIdTest`, `AuditRequestIdHttpTest`, `AuditRequestContextTest`) |
| AC-2 | step 2 (`ClientUserAgentTest`, HTTP half) |
| AC-3 | `AuditWriterTest`, `AuditTransactionTest` and every T-010/T-014 audit assertion unchanged and green; step 3's enforcement runs through all of them; step 14 diff review |
| AC-4 | steps 3 and 11 |
| AC-5 | steps 9 and 11 (`SearchAuditLogPermissionTest`) |
| AC-6 | step 13 (`QaAuditNoHttpSurfaceTest`) |
| AC-7 | step 6 (`AuditLogsArchiveShapeTest`) |
| AC-8 | step 8 |
| AC-9 | step 11 |
| AC-10 | step 13 (no delete of either history table outside the move); a statement, D-12 |
| AC-11 | step 13, with its discriminating control |
| AC-12 | steps 1 and 4 |
| AC-13 | step 4 |
| AC-14 | step 5 |
| AC-15 | by construction (no write path exists); the AC-20 scan shows the only `platform_audit_logs` producers |
| AC-16, AC-17 | step 12; AC-17's no-route half in step 13 |
| AC-18 | steps 10, 11 and 12 |
| AC-19, AC-20 | steps 3, 4 and 5 plus step 13's scans |
| AC-21 | step 14 |

Every criterion is independently proven on the stand-in. None is `[ENGINE]`.

## 8. Risks and rollback

| # | Risk | Size | Mitigation |
|---|---|---|---|
| 1 | **No scheduler runs in production** (FP-319), so archival never fires | high, silent, pre-existing | D-14; the schedule registration is asserted in step 8; FP-319 already owns it |
| 2 | **The write-time catalogue check** turns a forgotten declaration into a refused business operation | medium, but caught by the module's own first test | Ruling 3; the error names the module and action |
| 3 | **`X-Request-Id` on every response** reds a test that pins exact headers | small | the red-list rule; none found by search |
| 4 | **The planner's new transaction** changes a kernel test's statement sequence | small | step 5 red-list; Ruling 7 keeps the refusal order |
| 5 | **`ActivateTerminalHandler::issuerOf()` reads `audit_logs`** (T-014 Ruling 4). Measured: the spec's "nothing reads `audit_logs`" is not quite true. Archival could remove the row it needs | none in practice | It reads the issuing row of the code **in force**, and activation codes live 24 hours (`config/organization.php`), against a 365-day retention. The step 8 docblock records the coupling. A retention below the activation lifetime would break activation, and Ruling 12 refuses only 0 or less. -> lead: informational |
| 6 | **Archive growth** with one permanent table | small | D-10 accepted; the indexes match the live table; partitioning comes later, only if measured |
| 7 | **The gate sweeps the working tree** (memory): the `docs/database.md` or docblock edits quote old wording | small | describe, do not quote; run `composer check` with the docs edits in place |

**Rollback.** Everything is unpushed on the branch, and no production database exists.

- Reverting steps 8 to 13 removes the read and archive features and leaves the writers improved.
- Reverting step 6 removes the table; nothing outside step 8 and step 11's archive flag reads it.
- Reverting step 5 restores the un-audited planner.

## 9. Pre-existing files legitimately edited

Each edit is stated here so `qa` reports it as intended, not as a regression. **No assertion is weakened, and no audit assertion is removed.**

| Step | File (origin) | Edit and cause |
|---|---|---|
| 1 | `packages/kernel/src/Tenancy/TenancyServiceProvider.php` (T-003) | bind `RequestId` (step 1) and `TenantFleet` (step 7) |
| 1 | `modules/host/bootstrap/app.php` (T-003/T-016) | prepend `AssignRequestId` in `withMiddleware` |
| 2, 3 | `packages/access/src/Support/AuditWriter.php` (T-010) | `RequestId` and `ClientUserAgent` collaborators; catalogue enforcement; docblock restated |
| 2, 3, 8, 9, 11 | `packages/access/src/AccessServiceProvider.php` (T-010) | bind `ClientUserAgent`; implement `DeclaresAuditActions`; merge `AuditPermissions`; register `audit:archive` and `audit:search` and the daily schedule |
| 3 | `packages/organization/src/OrganizationServiceProvider.php` (T-014) | implement `DeclaresAuditActions` |
| 4 | `packages/platform/src/Support/PlatformAudit.php` (T-016) | Ruling 8 constructor; real `request_id`; `actor_email` snapshot; catalogue enforcement; docblock |
| 4, 5, 12 | `packages/platform/src/PlatformServiceProvider.php` (T-016) | explicit `PlatformAudit` binding; `RecordsMigrationRuns` binding; `platform:audit-search` command |
| 5 | `packages/kernel/src/Schema/Features/PlanMigrationRun/PlanMigrationRunHandler.php` (T-008) | inject the port; one master transaction around `write()`; docblock point 5 gains the audit row |
| 6 | `modules/host/database/catalogue/tenant_schema_versions.json` (T-008/T-014) | republished with `--write-fixture` |
| 6 | `modules/host/.env.example` | `APP_REQUIRED_SCHEMA_VERSION` follows the catalogue |
| 6 | `modules/host/tests/Helpers.php` | `tenantTreeTables()` gains `audit_logs_archive` |
| 6 | `modules/host/tests/Feature/MigrationTreeDisciplineTest.php` | `toHaveCount(19)` becomes 20; the comment adds T-017's one table in one file |
| 6 *(predicted, confirm by measuring)* | `modules/host/tests/Feature/QaAccessCloseOutSweepsTest.php` (T-010) | `closeOutNewTables()` derives "T-010's tables" as tree minus baseline minus T-014's five, so the archive would join T-010's set. Exclude it by name beside `$t014`, with a T-017 comment. Edit only if it goes red |
| 6 | `docs/database.md` §6.21 and §11 | section 3 |
| 9 | `packages/access/tests/Registry/PermissionRegistryTest.php` (T-010) | the provider-count line counts `AccessPermissions` plus `AuditPermissions`. The "six keys" case is untouched |
| 9 | `packages/access/src/Exceptions/AccessRefused.php` (T-010) | `notPermitted(AccessDecision)` |
| 12 | `packages/platform/src/Exceptions/PlatformRefused.php` (T-016) | `forbidden()`; the docblock's closed list becomes four |

**Count, from the table above:** 13 pre-existing source, config and doc files, plus 4 pre-existing test files (`Helpers.php` included; `QaAccessCloseOutSweepsTest` is predicted, not certain).

**Expected unchanged,** listed so that a red one is investigated, not patched:

- `AuditWriterTest`, `AuditTransactionTest`, and every T-010/T-014 feature test asserting an audit row;
- the four T-016 `PlatformAudit` doubles (Ruling 8);
- `PermissionDeclarationTest`;
- `TenantBaselineBuildsTest`: its unguarded map is a fixed list, and the archive adds no foreign key;
- `QaTenantBaselineStandInShapeTest`, `QaSchemaSignatureInstrumentTest`, `QaPinSinkCaptureTest`, `QaSecretSettingSinkTest`, `QaBranchIdFoldTest`: each derives its domain, and the archive is empty;
- `QaPlatformSourceRulesTest` and `QaPlatformDatabaseSeparationTest`;
- `VerticalSliceLayoutTest`;
- every kernel `PlanMigrationRun*` test;
- every shipped migration file.

## 10. Messages

**`architect` → lead.**

1. **Rulings for `ba`, all informational and none changing a criterion's text:**
   - Ruling 3: a catalogue, enforced at write time;
   - Ruling 4: the filter details;
   - Ruling 6: active tenants only;
   - Ruling 7: the real modes are `bulk` and `tenant`.
2. **Spec citation corrections, which go into no code:**
   - the tenant retention sentence is in `docs/database.md` **§11** (Index and partition strategy), not §9;
   - "no code reads `audit_logs`" overlooks `ActivateTerminalHandler::issuerOf()` (risk 5, harmless).
3. **D-10's documentation correction** is made in step 6 under the T-016 precedent, where the plan edited `docs/database.md` itself. No separate row is needed unless the lead prefers one.
4. **FP-323 is closed by step 4.** FP-319 is inherited, not re-solved.
5. **The one externally visible change** is the `X-Request-Id` response header (Ruling 1). An inbound header is deliberately ignored.

**`architect` → `dev`.**

- Step 3's write-time enforcement is what makes the catalogue trustworthy. Do not weaken it to a log line.
- Step 5's transaction is what makes "one row per run" atomic.
- Stop on any red file that section 9 does not name.

## Questions for the human

None. No escalation trigger is met: nothing is irreversible or external, and the one security-relevant choice (never adopting a client-supplied request id) takes the conservative side.
