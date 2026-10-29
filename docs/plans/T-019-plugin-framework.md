# Plan: T-019 Plugin framework

Spec: `docs/specs/T-019-plugin-framework.md` (AC-1 to AC-48, groups A to L). Decisions: `docs/decisions/T-019-plugin-framework.md` (D-1 to D-13). Backlog row FP-213. Branch `ai/T-019-plugin-framework`.

**Gate baseline: not measured by `architect`.** Step 0 measures it. The T-018 close-out recorded 2306 passed, 12 failed (FP-316, already known), and 20 skipped (9 Redis D-1 skips plus 11 engine-group skips). FP-328 already shows the test step's headroom below 2x, and this task adds roughly 150 cases (risk 2).

**Two engine conditions, kept apart (as in T-018):**

- `ENGINE_HOLD` is on. AC-48 is the only MySQL/MariaDB criterion. Step 14 writes its engine test now, and that test skips under the hold.
- No Redis exists here (T-018 D-1). AC-28 stays NOT VERIFIED. The same write, relay and consume contract is proven on the in-memory stand-in bus (steps 12 and 13).

**Locked by `ba` and not re-decided here:**

| Decision | What is locked |
|---|---|
| D-1 | exactly five modes: `add`, `before`, `after`, `filter`, `override` |
| D-2 | `after` goes through T-018's outbox and consumers; the other four modes are synchronous and in-process |
| D-3 | licensing uses the documented `licences` / `tenant_plugins` / `plugin_tenant_access` model; T-016's `FeatureFlags` / `PlanLimits` are not reused |
| D-4 | `plugin_execution_logs.mode` holds five values |
| D-5 | a failure is an exception or a breach of the per-call timeout |
| D-6 | the breaker is per (tenant, plugin) on the `tenant_plugins` columns; default threshold 5, counting consecutive failures; only an operator resets it |
| D-7 | the runtime is an in-process manifest loader plus a hook registry; fixtures live under `packages/plugin-sdk/tests/Fixtures` |
| D-8 | the minimum manifest fields; everything is validated before anything is registered |
| D-9 | the plugin migration runner is built and proven on fixture migrations only |
| D-10 | console only, no HTTP route |
| D-11 | no frontend plugin loading |
| D-12 | no special case for the workflow engine |
| D-13 | one proof-of-pipeline fixture, `fixture-echo` |

---

## 0. Rulings

Rulings marked **-> ba** read or narrow the spec. Rulings marked **-> lead** need a decision from outside `architect`. The rest are technical choices the spec left open.

### Ruling 1: which package owns what (AC-47, D-7)

**`packages/contracts/src/Plugins/`** is new and published. A module, and a plugin's own handler code, may depend on these symbols only. All of them are pure PHP, because `Package_Contracts: ~` stays as it is.

| Symbol | What it is |
|---|---|
| `HookMode` | backed string enum. Cases in this order: `add`, `before`, `after`, `filter`, `override` (D-1) |
| `HookPoint` | readonly. `name` must match `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$` and be at most 60 characters, because `plugin_execution_logs.point` is VARCHAR(60). `modes` is `list<HookMode>`, the modes this point accepts. `isOverridable()` returns `in_array(Override, modes)` |
| `DeclaresHookPoints` | provider interface with one method, `hookPoints(): list<HookPoint>`. Collected through `$app->getProviders()`, the way `ConsumerRegistry` collects `DeclaresEventConsumers` |
| `HookGateway` | `dispatch(string $point, array $payload = [], ?Closure $action = null): HookOutcome`. This is the one call per extension point (AC-21) |
| `HookCall` | readonly: `point`, `plugin` (name), `payload` |
| `AddHook`, `BeforeHook`, `FilterHook`, `OverrideHook`, `AfterHook` | one handler interface per mode (Ruling 5) |
| `Veto` | readonly `reason`. A `BeforeHook` returns one of these, or null to allow |
| `VetoSignal` | extends `RuntimeException` and carries `reason`. Throwing it is the other way to veto (AC-23: "returning/throwing") |
| `AfterHookCall` | readonly: `eventId`, `tenantId`, `plugin`, `point`, `payload`, `result` |
| `HookOutcome`, `HookVeto`, `HookContribution` | what `dispatch` returns (Ruling 5) |

`packages/contracts/src/Platform/PluginLicences.php` is also new. It has one method, `currentLicenceId(string $tenantId, string $pluginName): ?string`. The platform package implements it (Ruling 9).

**`packages/plugin-sdk` is internal.** It holds the manifest validator, the registry, the enablement slices, the migration runner, the runtime, the gateway, the breaker, the `after` consumer and the commands. The existing `composer.json` already describes this package as "extension gateway, plugin base classes and manifest validation", so the scaffold is used as it is, with no new package.

- Rejected: a separate `packages/plugins` package for the gateway. Nothing measured needs the split, and it would add a layer, a path and a ruleset to `deptrac.yaml` for no gain.
- Rejected: abstract base classes for plugins inside the SDK. A plugin then has to import SDK internals, and that is exactly what AC-47 forbids. The contracts in `packages/contracts/src/Plugins/` are the "base classes".

### Ruling 2: the manifest (AC-1 to AC-6, AC-10, D-8)

The file is `plugin.json` at the root of a plugin package directory. `ManifestValidator::validate(string $json): Manifest` throws `ManifestRefused` with **one named constructor per reason**. It runs its checks in this order and stops at the first failure:

1. **`malformedJson()` (AC-5).** `json_decode(..., JSON_THROW_ON_ERROR)` fails, or the top level is not an object. Nothing else is checked after this.
2. **`missingField(string $path)` (AC-1).** The required fields are `name`, `version`, `core_constraint`, `hooks`, `permissions` and `requires_licence`. Inside a hook entry, the path is named as `hooks[2].handler`.
3. **`invalidField(string $path, string $why)`.** The shape checks:
   - `name` must match `^[a-z][a-z0-9]*(-[a-z0-9]+)*$` and be at most 40 characters (Ruling 6 explains the length);
   - `version` must match `^\d+\.\d+\.\d+$` and be at most 20 characters;
   - `core_constraint` must parse under Ruling 3 and be at most 40 characters;
   - `hooks` must be a list of objects;
   - `permissions` must be a list of strings, each in the dotted lower-case key shape;
   - `requires_licence` must be a real bool. `"true"` is refused, never coerced.

   **Optional fields:**
   - `display_name`;
   - `type`, one of `module, integration, theme, report, vertical`; the default is `integration`;
   - `visibility`, one of `official, private, third_party`; **the default is `private`**, so access is denied unless granted;
   - `author`;
   - `description`;
   - `requires`, an object mapping a plugin name to a constraint;
   - `migrations`, a directory relative to the package; the default is `migrations`.

   **An unknown top-level key is refused** (`unknownField`), so a misspelt key is caught.
4. **`coreVersionMismatch(constraint, core)` (AC-10).** The constraint is checked against `config('plugins.core_version')`.
5. **For each hook, in order:**
   - **`unknownMode(string $mode)` (AC-2).** `HookMode::tryFrom()` is exact and case-sensitive, and nothing is coerced.
   - **`unknownPoint(string $point)`.** The point is not in the `HookPointCatalogue`.
   - **`overrideNotPermitted(point)` (AC-3).** The mode is `override` and the point is not overridable. This is a distinct name from the next one.
   - **`modeNotAccepted(point, mode)`.** The point does not accept this mode.
   - **`duplicateRegistration(point, mode)` (AC-25, within one manifest).** At most one `override` and at most one `after` per point per manifest. `add`, `before` and `filter` may repeat, which is how two filters chain (AC-24, AC-44).
   - **`handlerNotFound(class)` / `handlerWrongContract(class, mode)` (AC-4).** The handler must satisfy `class_exists`, must not be abstract, and must implement the interface that matches its mode. This check lives in a private method whose parameter is typed `string`, which is the PHPStan friction noted in the developer memory.
6. **An empty `hooks` list is valid** (AC-6). It produces a manifest with zero registrations.

**Re-validation (AC-35).** The same validator runs when a version is registered (Ruling 7), when a plugin is enabled (Ruling 8), and when the runtime loads it (Ruling 10). Nothing trusts a stored manifest without validating it.

### Ruling 3: version constraints, with no new dependency (AC-9, AC-10)

`composer/semver` is not installed. It appears in `composer.lock` only as a require-dev entry of two dependencies. This task does not add it.

`VersionConstraint` supports a narrow grammar:

- a `||` between alternatives means OR;
- a space between terms means AND;
- each term is `*`, or an optional operator from `>=, <=, >, <, =, ^, ~` followed by `MAJOR.MINOR.PATCH`, where `MINOR` and `PATCH` may be omitted and count as `0`;
- `^1.2.3` means at least `1.2.3` and below `2.0.0`. `^0.2.3` means at least `0.2.3` and below `0.3.0`;
- `~1.2` means at least `1.2.0` and below `1.3.0`.

Anything else is refused with a named error. The comparison uses `version_compare`.

`config('plugins.core_version')` defaults to `'0.1.0'`, which is the version every `packages/*/composer.json` declares. It is one configuration value, not an environment key.

### Ruling 4: hook points and how the five modes run in one dispatch (AC-21 to AC-26) -> ba, informational

**A point declares the modes it accepts.** This follows `docs/architecture.md` §13, "each declaring its mode". Listing `override` among them is what "explicitly marked overridable" means (AC-3). A plugin can never claim overridability for a point.

`HookPointCatalogue` collects `DeclaresHookPoints` providers and keeps no memo, so a provider a test registers is seen at once. It refuses, with a `LogicException`:

- a duplicate point name;
- a name that does not match the pattern.

Dispatching a point the catalogue does not hold throws `LogicException`. That is a bug in the caller, so it fails loudly.

**One `dispatch()` call, in a fixed order:**

1. **Resolve the registrations at this point for the current tenant** (Ruling 10), in registration order (AC-21). With no tenant resolved, the kernel's `NoTenantResolved` is thrown.
2. **`before`, in order.** A handler that returns a `Veto` or throws a `VetoSignal` stops the dispatch. The outcome carries `HookVeto{plugin, reason}`. The point's action, the `filter`s, the `add`s and the `after` rows **do not run** (AC-23). A handler that allows passes control to the next `before`.
3. **`filter`, in order.** Each handler receives the current payload and returns the next one, so the filters chain (AC-24). A handler that returns something other than an array counts as a failure, and the payload passes on unchanged.
4. **Action, `add` and `after` inside one tenant transaction.** If the caller already holds a transaction, this one nests as a savepoint.
   - The action is the `override` handler when one is registered and the point is overridable (AC-25). Otherwise it is the caller's `$action($payload)`. A null action gives a null result.
   - **If the override fails,** the point's own action runs instead.
   - **`add` handlers, in order,** receive `(HookCall, $result)`. Each return value becomes a `HookContribution{plugin, value}` (AC-22). An `add` handler can neither veto nor replace the result.
   - **One outbox row is written per `after` registration** (Ruling 11, AC-27). Because this happens inside the same transaction, an action that throws rolls those rows back as well.
5. **Return `HookOutcome`.** It carries:
   - `point`;
   - `veto` (null or a `HookVeto`), with `vetoed()` as a helper;
   - `payload`, the payload after filtering;
   - `result`;
   - `overriddenBy`, a plugin name or null;
   - `contributions`;
   - `skipped`, the plugins the breaker refused;
   - `failed`, the plugins whose handler failed during this call.

**With zero registrations, the dispatch is exactly `$action($payload)` inside the transaction** (AC-26).

An exception thrown by the caller's own action is not a plugin failure. It propagates to the caller.

**A failed `before` allows the dispatch to continue (fail-open).** The user story asks for a broken plugin to be "skipped safely", so this matches the spec, and D-12 rules out a workflow-specific case. Risk 4 still records it for FP-214, because a crashing approval plugin would let an action through.

### Ruling 5: the invoker, timing and savepoints (AC-30, AC-36, D-5)

Every synchronous handler call goes through `HookInvoker::invoke(Registration, Closure $call): InvocationResult`. The invoker does these things in order:

1. **Refuses an open breaker without calling the handler** (Ruling 12).
2. **Resolves the handler** with `app()->make($class)`, in-process (AC-36).
3. **Runs the call inside `$tenant->transaction(...)`.** This is a savepoint, so a failing plugin's partial writes are rolled back. Inside that transaction:
   - it reads `HookClock::nowMs()` before and after the call;
   - if the elapsed time exceeds `config('plugins.timeout_ms')` (default 500), it **throws `HookTimedOut` inside the closure**, which rolls back that handler's writes.
4. **Catches every `Throwable` from the handler and swallows it.** A `VetoSignal` is a veto, not a failure.
5. **Writes one `plugin_execution_logs` row** outside the handler's savepoint:
   - `mode`;
   - `duration_ms`;
   - `status` is one of `ok`, `veto`, `error`, `timeout`;
   - `error` is the exception's class and message, cut to 1000 characters;
   - `request_id` comes from the kernel's `RequestId::current()`;
   - `created_at` comes from `RunnerClock`.
6. **Reports the outcome to `CircuitBreaker`:** a success resets the count and a failure records one (Ruling 12).

**The timeout is measured after the call, not enforced during it** (-> ba, informational). In PHP a call running in the same process cannot be interrupted portably: `pcntl_alarm` does not exist on Windows. So a slow handler finishes, its result is discarded, its writes are rolled back, and it counts as one failure (D-5). PHP's `max_execution_time` remains the real backstop against a handler that never returns (risk 3).

**`HookClock` is an internal port.**
- `SystemHookClock` uses `hrtime(true)`.
- Tests bind `FakeHookClock` (tests tree). A fixture "slow" handler advances that clock, so nothing in the tests really waits.

**Handler signatures:**
- `AddHook::add(HookCall $call, mixed $result): mixed`;
- `BeforeHook::before(HookCall $call): ?Veto`;
- `FilterHook::filter(HookCall $call): array`;
- `OverrideHook::override(HookCall $call): mixed`;
- `AfterHook::after(AfterHookCall $call): void`. The docblock says it **must be idempotent by `$call->eventId`** (AC-29, inherited from T-018 AC-23).

### Ruling 6: names, the `plg_` prefix and plugin migrations (AC-19, AC-38, AC-39)

**The table prefix.** For plugin name `n`, the prefix is `plg_` + `str_replace('-', '_', n)` + `_`. For example, `fixture-echo` becomes `plg_fixture_echo_`.

- Plugin names may not contain `_`, so this mapping never sends two names to one prefix.
- At most 40 characters leaves room under MySQL's 64-character limit on table names.
- `PluginName::tablePrefix()` is the one place that computes the prefix.

**One prefix can begin another** (`plg_foo_` and `plg_foo_bar_`). So **"the tables a plugin owns" are never found by prefix match**. They are the tables its own migration files create, read with `SourceRules::tablesCreatedIn()`. Uninstall drops exactly that list (Ruling 8).

**`PluginMigrationRules::inspect(string $source, string $prefix)`** checks every plugin migration file before any of them runs. Any violation refuses the whole set (`PluginMigrationRefused`) and nothing runs. The checks:

1. `SourceRules::inspect($source, MigrationTree::Tenant)` is empty. So the tenant tree's conventions apply: no native enum, no bare decimal, no timestamp column, no `tenant_id`, no charset, and the rest.
2. `SourceRules::importsOutsideAllowedNamespaces()` is empty.
3. Every `Schema::create|table|drop|dropIfExists|rename(` has a string-literal first argument that starts with the prefix. A non-literal argument is refused. **This is what keeps a plugin from altering a core table or adding a key to one** (AC-19).
4. No raw SQL: no `DB::`, no `->statement(`, no `->unprepared(`.
5. Every `JsonValidity::enforce(` names a prefixed literal table.

**`PluginMigrationRunner::run(string $pluginName, string $directory): int`** runs inside the tenant scope the caller has entered:

- It takes the files in `<package_path>/<migrations>/*.php`, sorted by file name.
- It skips any file already recorded in `plugin_migrations` for this plugin.
- For each remaining file:
  - it loads the file with `require` (the result is an anonymous `Migration`);
  - it runs `DB::usingConnection(<tenant connection>, fn () => $migration->up())`, which is how Laravel's own migrator makes the `Schema` facade target the right connection;
  - it inserts `plugin_migrations{plugin_name, migration (file name without .php), batch, ran_at}`. `batch` is the plugin's highest batch plus 1, computed once per run.
- It returns the plugin's applied count, and that count **is** `schema_version`.
- When there is nothing to do, nothing changes and nothing errors (AC-39).

The master mirror (`tenant_plugins.schema_version = count`) is written by the caller, the `MigratePlugin` handler (Ruling 8).

**Tenant scope for DDL.** Enabling, migrating and uninstalling enter the tenant with `TenantConnectionPurpose::Migrating`, which is the migrate credential that §9.3 gives DDL rights. This needs a **new allowed deptrac edge**, `Package_PluginSdk -> Package_Kernel_TenancyStatus` (section 5). -> lead: this edge is withheld from access, platform and eventbus because "whether a database is served" is not their question. Here the package runs DDL, as the kernel's runner does. Step 15's sweep keeps the use to two files.

The alternative is to run plugin DDL on the serving credential. That would fail in production wherever the app user lacks DDL rights, so it was rejected.

**The command name (-> ba, informational; doc edit in step 16).** `docs/database.md` §9.6 describes `tenant:migrate --plugin=<name>`. That command belongs to the kernel, and the kernel may not depend on `plugin-sdk`. This task ships `plugin:migrate {plugin} {--tenant=*}` instead, with the same behaviour.

### Ruling 7: the registry (AC-7, AC-8, AC-10, AC-11)

Every slice below is in `packages/plugin-sdk/src/Features/<X>/` and writes to the **master** connection only.

**`RegisterPluginVersion`, the command `plugin:register {path}`:**

1. Read `<path>/plugin.json` and validate it (Ruling 2). AC-10's core-version refusal happens here, before any write.
2. Compute `checksum = hash('sha256', <the bytes of the manifest file>)`.
3. Set `migration_count` to the number of `*.php` files in the migrations directory.
4. **Refuse `versionAlreadyRegistered(name, version)` before any insert.** The `unique(plugin_id, version)` key is the backstop. Nothing is ever overwritten (AC-8).
5. In one master transaction:
   - Find `plugins` by `name`, or insert it with `status='published'`. There is no publishing pipeline (out of scope), so a validated registration *is* publication.
   - Refresh `display_name`, `type`, `visibility`, `author`, `description` and `requires_licence` from this manifest.
   - Insert `plugin_versions`:
     - `manifest` holds the validated JSON;
     - `package_path` holds the path as given;
     - `checksum`, `migration_count`;
     - `status='available'`;
     - `released_at` comes from the clock;
     - `signature` is null.
   - Insert one `plugin_dependencies` row per `requires` entry.
   - Move `plugins.latest_version_id` to this version when it is higher by `version_compare`.

**`GrantPluginAccess`, the command `plugin:grant {plugin} {--tenant=} {--as=}`.** It inserts `plugin_tenant_access`. A grant that already exists is a no-op. It refuses a plugin that is not `private`, because the grant would mean nothing (AC-11).

-> ba, informational: AC-11 needs some way to write a grant. Without this slice an operator could only grant access with SQL.

### Ruling 8: enablement state machine (AC-9, AC-11, AC-13 to AC-16, AC-20, AC-25)

```
(no row) --enable--> installing --(migrations ok)--> enabled
installing --enable (retry)--> enabled            (a failed install leaves installing plus last_error)
enabled --disable--> disabled --enable--> enabled (no recorded migration re-runs, AC-15)
enabled --breaker--> error --plugin:reset--> enabled
disabled | installing | error --uninstall--> (row deleted)
```

`updating` belongs to the documented enum, but nothing writes it. No upgrade flow is in scope (-> ba, informational).

**`EnablePlugin`, the command `plugin:enable {plugin} {--tenant=} {--plugin-version=} {--as=}`.** The option is `--plugin-version` because Symfony reserves `--version`.

**Every refusal below happens before any write,** with a named `PluginRefused` constructor:

1. `unknownPlugin`. The plugin does not exist or is soft-deleted.
2. `unknownVersion` or `versionNotAvailable`.
3. `unknownTenant`. There is no master `tenants` row.
4. **The current state of the row:**
   - `enabled` is a **no-op**: nothing is written, `updated_at` is unchanged, and no migration runs (AC-14);
   - `error` refuses with `tripped`, which tells the operator to run `plugin:reset` (AC-33);
   - `updating` refuses;
   - a requested version that differs from the row's version refuses with `versionChangeNotSupported`.
5. `accessNotGranted`. The plugin is `private` and the tenant has no `plugin_tenant_access` row (AC-11).
6. The stored manifest fails re-validation (Ruling 2), so `ManifestRefused` propagates.
7. `dependencyNotSatisfied(dep, constraint)`. For each `plugin_dependencies` row, the tenant must hold an `enabled` row for that plugin whose version satisfies the constraint (AC-9).
8. `overrideAlreadyClaimed(point, holder)`. Another plugin enabled for this tenant already overrides a point this manifest overrides (AC-25, across plugins).
9. **`licenceRequired(plugin)`.** `requires_licence` is true and `PluginLicences::currentLicenceId()` returns null. **One named error covers none, expired, revoked and suspended** (AC-13, AC-16). When a licence exists, its id is stored in `licence_id`.

**The writes:**

- Insert the row, or update it, to `status='installing'`. The columns are:
  - `plugin_version_id`;
  - `licence_id`;
  - `installed_at`, `installed_by` (the `--as` user, which may be null);
  - `failure_count=0`.
- Run `MigratePlugin` for this one tenant (Ruling 6, §9.6: "enabling is a one-tenant run").
- Set `status='enabled'`, `enabled_at=now` and `last_error=null`.
- **If a migration fails,** set `last_error` to the message, leave the status at `installing`, and rethrow. Running `plugin:enable` again resumes.

**`DisablePlugin`, the command `plugin:disable {plugin} {--tenant=}`:**
- `enabled` becomes `disabled` and `disabled_at` is stamped (AC-15). No table is dropped.
- `disabled` is a no-op.
- `installing` refuses with a named error, and so does `error` (Ruling 12: a tripped plugin is reset or uninstalled, never quietly re-labelled).

**`UninstallPlugin`, the command `plugin:uninstall {plugin} {--tenant=}` (AC-20):**

- Refuses an `enabled` plugin with `disableFirst`.
- Otherwise it enters the tenant (Migrating). For each table in the owned list (Ruling 6) that exists:
  1. hand it to `PluginDataExporter::export($tenantId, $plugin, $table, iterable $rows)`. The default implementation is `JsonFilePluginDataExporter`, which writes `<plugins.export_path>/<tenant>/<plugin>/<YmdHis>/<table>.json`. The data's shape is not this task's to define (Group K);
  2. once **every** export has succeeded, drop the tables. Before each drop the code asserts `str_starts_with($table, prefix)`;
  3. delete that plugin's `plugin_migrations` rows, so a reinstall runs its migrations again.
- Then it deletes the master row.
- An exporter that throws stops the uninstall before any drop.
- `plugin_settings` rows are **kept** (-> ba, informational: the spec names only `plg_` data, and deleting more than asked is the irreversible direction).

### Ruling 9: licence validity has one authority (AC-13, AC-16, D-3) -> ba, informational

**Why D-3's separation matters (my reading of it).** The question "is this licence row current?" is a platform fact. T-016 already answers it for `type=platform` rows in `PlatformLicence::verdict()`. A copy of that rule inside `plugin-sdk` would be a second authority (the FP-184 family). T-016's `FeatureFlags` and `PlanLimits` answer a different question: a plan-level toggle or number. They know nothing about versions, dependencies or grants per tenant, so folding plugin enablement into them would lose the model §4.3 designs.

**The platform package edits:**

- Extract `PlatformLicence::isCurrent(object $row, DateTimeInterface $now): bool` from the body of `verdict()`'s loop. The rule is: `status='active'`, `valid_from` is null or not later than now, and `valid_until` is null or later than now. `verdict()` calls it, and its behaviour is unchanged.
- The new `Flexipos\Platform\Support\MasterPluginLicences implements PluginLicences`:
  - it reads `licences` where `tenant_id`, `type='plugin'` and `subject=<name>`;
  - it orders by `valid_until desc` (nulls first) and then `id`;
  - it returns the id of the first row that `isCurrent()` accepts, or null.
- `PlatformServiceProvider` binds it.
- `PlatformLicence` itself still reads only platform rows, so T-016's AC-27 is intact.

`valid_from` is honoured even though the wording of AC-13 does not mention it, because a licence that is not valid yet is not active.

-> ba: the licence is checked **when the plugin is enabled**, not on every dispatch. A licence that expires while its plugin is enabled leaves the plugin enabled. AC-13 and AC-16 are worded for enable time. Proposed follow-up: re-check at load, together with a sweep that disables such plugins.

### Ruling 10: the runtime (AC-17, AC-35 to AC-37)

**`PluginRuntime::tableFor(string $tenantId): TenantHookTable`** is called **on every dispatch**. It makes one master query:

```
tenant_plugins tp join plugins p join plugin_versions v
where tp.tenant_id = ? and tp.status in ('enabled','error')
order by tp.enabled_at, p.name
```

1. **Rows with status `error`** contribute registrations that are marked "refused". The invoker then logs a refusal and calls nothing (AC-31).
2. **Any status other than `enabled` or `error`** never loads at all (AC-17). The filter is in the runtime, so a caller has no check of its own to remember.
3. **For each `enabled` row,** take the validated `Manifest` from `ManifestCache`, which is keyed by `plugin_version_id` (an immutable row). On a cache miss, validate the stored manifest from scratch (AC-35). **A failure is logged at `error` once per process for each version, and none of that plugin's hooks are registered.** The other plugins load normally (AC-37). The failure is cached too, so a broken version does not log on every request.
4. **Registration order:** plugins by `(enabled_at, name)`, then the order of hooks within each manifest (AC-21).
5. **A second plugin that overrides a point already claimed by an earlier one is not loaded at all,** and this is logged. It can happen only through a race at enable time, because Ruling 8 refuses it there (AC-25).

**Why a query on every dispatch and no cache.** A state change made by another process (a trip, a disable) is then seen on the very next call, and neither AC-17 nor AC-31 can go stale. The cost is one indexed master read per dispatch (`unique(tenant_id, plugin_id)` serves it), recorded as risk 5. A cache scoped to the request is a later optimisation if it is ever measured.

**Plugin code runs in-process (AC-36).** The handler is resolved from the container and called directly. Step 15's sweep finds no `proc_open`, `Process`, `exec`, `shell_exec`, `pcntl_fork` or `popen` under `packages/plugin-sdk/src`.

### Ruling 11: `after` mode on T-018's outbox (AC-27 to AC-29, AC-34, D-2) -> ba, AC-27 wording

**The writer.** For each `after` registration, inside the transaction of Ruling 4, the gateway calls `Outbox::record(new NewEvent(...))` with:

- `type` = `'plugin.'.$point`, for example `plugin.fixture.echo`;
- `aggregateType` = `'plugin'`;
- `aggregateId` = `plugins.id` (a ULID);
- `actor` = `EventActor::plugin(plugins.id, name)`, so `actor_type` is `plugin` (AC-27);
- `payload` = `{plugin, plugin_version, point, payload: <filtered payload>, result: <result when it is null, scalar or array, else null>}`.

Only T-018's one writer is used.

**The row names the point in `type`, and the plugin in `aggregate_id`, `actor` and `payload`, not in `type`.** -> ba: AC-27 says "`type` naming the hook point and plugin". Two measured facts prevent that:

1. T-018's consumer runner matches `eventTypes()` exactly (`ConsumeEventsHandler.php:126`, `in_array`). A type per plugin would need a subscription list read from the master on every delivery.
2. `outbox_events.type` is limited to 80 characters (T-018 Ruling 5). `plugin.` plus a 40-character name plus a 60-character point is up to 108.

What the criterion is after, that the row identifies both, still holds. Please amend AC-27's text. This plan does not change T-018's runner.

**The consumer.** `PluginAfterHookConsumer implements EventConsumer` is declared by `PluginSdkServiceProvider implements DeclaresEventConsumers`.

- `group()` is `plugins.after`.
- `eventTypes()` is `'plugin.'.$name` for every catalogue point that accepts `after`. This is static, and it is read from the catalogue each time.
- `handle($envelope)` runs inside the tenant scope T-018's runner already opens. It does these things in order:
  1. Read the master `tenant_plugins` row for `(envelope.tenantId, envelope.aggregateId)`.
  2. **No row, or `disabled` / `installing`:** acknowledge with an `info` log and do nothing (AC-17).
  3. **`error`, meaning tripped:** throw `PluginDispatchRefused`. T-018's runner leaves the message unacknowledged and, after `max_deliveries`, dead-letters it, so an operator can `eventbus:replay` it after `plugin:reset`. -> ba, informational: a tripped plugin's `after` events are kept, not dropped.
  4. **`enabled`:** load its registrations (Ruling 10) and call each `after` handler for `payload.point` through the same `HookInvoker`, with `mode=after`.
     - On `error` or `timeout`, the invoker has already rolled the handler back, logged it and counted it against the breaker (AC-34). The consumer then **rethrows**, so T-018's redelivery applies. Because the write was rolled back, nothing is half-applied.
     - On `ok`, the breaker resets.
- **Idempotency belongs to the handler, by `eventId`** (AC-29; T-018 AC-23 is unchanged). The fixture handler shows the pattern: a marker row in its own `plg_fixture_echo_after_effects`, inside the handler's savepoint.

### Ruling 12: the circuit breaker (AC-30 to AC-34, D-6)

`CircuitBreaker` writes to the master connection, using `RunnerClock` and `config('plugins.breaker.threshold')` (default 5). It writes no new column and creates no table.

| Method | What it does |
|---|---|
| `recordFailure(tenantId, pluginId, error): bool` | ① `update tenant_plugins set failure_count = failure_count + 1, last_error = ? where tenant_id = ? and plugin_id = ?`. ② `update … set status='error', tripped_at=? where tenant_id = ? and plugin_id = ? and status='enabled' and failure_count >= ?`. When ② affects one row, the breaker **tripped now**. That is logged once at `critical` with the tenant, the plugin and `last_error` (AC-31), and the method returns true |
| `recordSuccess(tenantId, pluginId)` | `update … set failure_count = 0 where tenant_id = ? and plugin_id = ? and failure_count > 0` (AC-30, consecutive semantics) |
| `reset(tenantId, pluginId): bool` | `update … set status='enabled', failure_count=0, tripped_at=null, last_error=null where … and status='error'` (AC-33). Zero rows means the row was not tripped |

- **Two statements, not one `CASE` expression.** MySQL evaluates a single-table `UPDATE … SET` from left to right using values already updated, and SQLite does not. Two plain statements behave the same on both engines.
- **The master writes are on their own connection,** so a failure still counts when the caller's tenant transaction rolls back.
- **The trip is noticed at once by the process that tripped it,** because the invoker marks that plugin refused for the rest of the dispatch. Every other process sees it on its next dispatch (Ruling 10). There is no timer and no automatic reset anywhere (D-6).
- **The grain is the pair `(tenant_id, plugin_id)`,** so isolation follows from the key of the row (AC-32).

**Commands:**
- `plugin:reset {plugin} {--tenant=}` (`ResetPluginBreaker`, AC-41). A row that is not tripped gives a named refusal and exit code 1.
- `plugin:list {--tenant=}` (`ListTenantPlugins`, AC-40) prints `plugin`, `version`, `status`, `schema_version`, `failure_count`, `tripped_at`, `last_error` and `enabled_at`, ordered by name.

### Ruling 13: plugin settings (AC-18)

`PluginSettingsStore` is internal and works on the tenant connection.

- `put(plugin, key, ?string value, bool isSecret, ?employeeId)`. When `isSecret` is true, the value is stored as `Encrypter::encryptString()` with the application key, Laravel's standard encryption.
- `get(plugin, key): ?string` decrypts.

No published contract and no command is added. AC-18 asks only for encryption at rest, and FP-296 can publish an accessor when a real plugin needs one.

### Ruling 14: AC-48 asks for `down()`, and T-008 D-8 forbids it -> lead and ba, a real conflict

AC-48 says every migration "is reversible (`down()` undoes exactly what `up()` did)". T-008 D-8 says the opposite: tenant migrations are forward-only, the runner has no rollback, and every shipped migration in either tree has no `down()`. `BranchForeignKeysMigrationTest` asserts that for one file. The master files T-016 and T-017 shipped have no `down()` either.

**This plan follows D-8 and ships no `down()`.** That is the established, reviewed convention and the safer rule (D-8's reason).

What AC-48 actually needs is covered this way:
- **"runs cleanly"** is proven on the stand-in now (step 3 and step 4, applying each tree twice) and on the engine by step 14 once the hold lifts;
- **"reverse what it did"** for plugin-owned tables is what uninstall does (AC-20).

**`ba` should amend AC-48 to drop the `down()` half, or overrule D-8 explicitly.** `architect` has not overridden either decision silently. -> lead.

### Ruling 15: configuration

There is a new file, `modules/host/config/plugins.php`. It follows the header style of `config/eventbus.php`: numbers and names only, with every number validated where it is used.

| Key | Default |
|---|---|
| `core_version` | `'0.1.0'` |
| `breaker.threshold` | 5 |
| `timeout_ms` | 500 |
| `export_path` | `storage_path('app/plugin-exports')` |

There is **no new environment key**. `.env.example` changes only `APP_REQUIRED_SCHEMA_VERSION`.

---

## 1. Approach summary

1. **Contracts first.** Modules and plugin code see only `Flexipos\Contracts\Plugins\*` (the point declarations, the gateway, five handler interfaces and the outcome types) and `Contracts\Platform\PluginLicences`. Everything else is internal to `packages/plugin-sdk` (Ruling 1). `Module_Demo` loses its `Package_PluginSdk` edge. That is AC-47's instrument.
2. **The schema §4.3 and §6.20 already designed is migrated as written.** There are five master tables and three tenant tables. `mode` is a VARCHAR whose application enum has five values (D-4). There is no `down()` (Ruling 14).
3. **A strict manifest validator** gives every refusal its own name. It runs at registration, at enable time and at runtime load (Ruling 2). Versions are compared by a small parser written here, with no new dependency (Ruling 3).
4. **The registry and enablement** are master-side slices with a strict state machine, written to the master and run as console commands. Licensing asks platform's one validity authority (Rulings 7 to 9). Enabling runs the plugin's own migrations, restricted to the `plg_` prefix, through a migration-scoped tenant entry (Ruling 6).
5. **The gateway** loads the tenant's enabled plugins on every dispatch and runs `before`, then `filter`, then (`override` or action), then `add`, then `after`. The last three run in one tenant transaction. Each handler runs in its own savepoint, timed, logged, and reported to a per (tenant, plugin) breaker on the master `tenant_plugins` row (Rulings 4, 5, 10, 12). `after` writes outbox rows and is drained by a T-018 consumer group (Ruling 11).
6. **One fixture plugin, `fixture-echo`,** proves all five modes end to end on the stand-in bus (step 13).

## 2. Alternatives rejected

- **A new `packages/plugins` package for the gateway.** It adds deptrac surface with no measured need (Ruling 1).
- **SDK base classes that plugins extend.** A plugin would have to import SDK internals, and AC-47 forbids that (Ruling 1).
- **`composer/semver`.** It would be a new production dependency and churn the lock, for a grammar 40 lines can cover (Ruling 3).
- **Matching a plugin's tables by prefix.** One prefix can begin another, so uninstalling `foo` would drop `foo-bar`'s tables (Ruling 6).
- **One `UPDATE … CASE` for the breaker.** MySQL and SQLite evaluate it differently (Ruling 12).
- **A breaker state cached per process, or a cool-down timer.** The first goes stale across processes and the second is forbidden by D-6 (Rulings 10 and 12).
- **One outbox type per plugin.** It breaks T-018's exact-match subscription and its 80-character type limit (Ruling 11).
- **Extending the kernel's `tenant:migrate` with `--plugin`.** The kernel may not depend on `plugin-sdk` (Ruling 6).
- **Plugin DDL on the serving credential.** Production's app user may lack DDL rights (Ruling 6).
- **Promoting `TenantConnection` / `MasterConnection` to the kernel now.** It would refactor reviewed packages. `plugin-sdk` gets its own small `Connections` helper. -> lead: FP-331's family; propose again.

---

## 3. Files to change or create

`dev` writes every file. Files named `Qa*` are `qa`-accountable.

### `packages/contracts` (new files)

| Path | What |
|---|---|
| `src/Plugins/{HookMode,HookPoint,DeclaresHookPoints,HookGateway,HookCall,AddHook,BeforeHook,FilterHook,OverrideHook,AfterHook,AfterHookCall,Veto,VetoSignal,HookOutcome,HookVeto,HookContribution}.php` | Ruling 1. Pure PHP |
| `src/Platform/PluginLicences.php` | Ruling 9 |

### `packages/plugin-sdk` (all new; `src/.gitkeep` may stay)

| Path | What |
|---|---|
| `src/PluginSdkServiceProvider.php` | `implements DeclaresEventConsumers`. It binds `HookGateway` to `Gateway`, `HookClock` to `SystemHookClock`, `PluginDataExporter` to `JsonFilePluginDataExporter`, and makes `HookPointCatalogue` and `ManifestCache` singletons. It registers the 8 commands under `runningInConsole()`. **No schedule** |
| `src/Manifest/{Manifest,ManifestHook,ManifestValidator,VersionConstraint,PluginName}.php` | Rulings 2, 3 and 6 |
| `src/Gateway/{Gateway,HookPointCatalogue,HookInvoker,InvocationResult,Registration,TenantHookTable,HookClock,SystemHookClock,HookTimedOut,ExecutionLog}.php` | Rulings 4 and 5 |
| `src/Breaker/CircuitBreaker.php` | Ruling 12 |
| `src/Runtime/{PluginRuntime,ManifestCache,PluginAfterHookConsumer}.php` | Rulings 10 and 11 |
| `src/Migrations/{PluginMigrationRunner,PluginMigrationRules}.php` | Ruling 6 |
| `src/Support/{Connections,PluginSettingsStore,PluginDataExporter,JsonFilePluginDataExporter}.php` | Rulings 8 and 13 |
| `src/Exceptions/{ManifestRefused,PluginRefused,PluginMigrationRefused,PluginDispatchRefused}.php` | named constructors, one per reason |
| `src/Features/{RegisterPluginVersion,GrantPluginAccess,EnablePlugin,DisablePlugin,UninstallPlugin,MigratePlugin,ResetPluginBreaker,ListTenantPlugins}/{X,XHandler,XCommand}.php` (plus `XResult` where a result is printed) | Rulings 6 to 8 and 12. Each slice satisfies `VerticalSliceLayoutTest`, so each has a mirrored `tests/Features/<X>/*Test.php` |
| `tests/Fixtures/FixtureEcho/plugin.json` | the **one** proof-of-pipeline manifest (step 5). It declares at `fixture.echo`: `add`, `before`, `filter` twice, `override` and `after`. It requires a licence: `false`. Its visibility is `official` |
| `tests/Fixtures/FixtureEcho/{EchoAdd,EchoBefore,EchoUpperFilter,EchoSuffixFilter,EchoOverride,EchoAfter}.php` | They import only `Flexipos\Contracts\Plugins\*`. `EchoBefore` vetoes when `payload['veto'] === true`. `EchoAfter` writes a marker row keyed by event id |
| `tests/Fixtures/FixtureEcho/migrations/{0001_create_plg_fixture_echo_after_effects_table,0002_create_plg_fixture_echo_notes_table}.php` | Each starts with `@phase expand`. The `notes` table holds a nullable `employee_id` keyed to `employees.id`, a foreign key **into** a core table (AC-19) |
| `tests/Support/{FixtureHookPointProvider,FakeHookClock,ThrowingHook,SlowHook,NotAHook,RecordingExporter,PluginSeed}.php` | test doubles and seeders. `FixtureHookPointProvider` declares `fixture.echo` (all five modes) and `fixture.plain` (no `override`). `PluginSeed` migrates the master and inserts `tenants` and `users` rows plus licences |
| `tests/Support/plugin-fixture.php` | file-scope helpers: `pluginEnterTenant()` (enter only, never `boot()`, per the developer memory), `pluginClockAt()`, `pluginWriteManifest(dir, array)` (writes a temporary package for the negative and isolation tests) |
| `tests/...` test files | listed per step in section 6 |

### `packages/platform`

| Path | What |
|---|---|
| `src/Support/MasterPluginLicences.php` *(new)* | Ruling 9 |
| `tests/Support/MasterPluginLicencesTest.php` *(new)* | step 6 |

### Host, `modules/host`

| Path | What |
|---|---|
| `config/plugins.php` *(new)* | Ruling 15 |
| `database/migrations/2026_10_29_000801_create_plugins_table.php` … `000805_create_tenant_plugins_table.php` *(new; order: plugins, plugin_versions, plugin_dependencies, plugin_tenant_access, tenant_plugins)* | step 3 |
| `database/migrations-tenant/2026_10_29_000801_create_plugin_settings_table.php`, `000802_create_plugin_migrations_table.php`, `000803_create_plugin_execution_logs_table.php` *(new)* | step 4. The names sort after `2026_10_29_000701` and contain no `fx` or `tenant` token |
| `tests/Engine/PluginSchemaEngineTest.php` *(new)* | step 14 |
| `tests/Feature/QaPluginSourceRulesTest.php`, `tests/Feature/QaPluginNoHttpSurfaceTest.php` *(new)* | step 15 |

Edits to files that already exist are listed in section 9.

---

## 4. Data and API changes

### Master schema: five tables (step 3)

Every migration starts with `@phase expand`, uses `IdentifierColumn`, `dateTime(..., 3)` and `VARCHAR(30)` for enums, and has no `down()` (Ruling 14). JSON columns use `JsonValidity::enforce`, as sibling master tables already do.

| Table | Columns, matching §4.3 column for column |
|---|---|
| `plugins` | `id`, `created_at`, `updated_at`, `deleted_at`, `name` VARCHAR(100) unique, `display_name` VARCHAR(150), `type`, `author` VARCHAR(150) NULL, `description` TEXT NULL, `visibility`, `status`, `latest_version_id` CHAR(26) NULL **unguarded** (a key to a table created later; the design names no foreign key), `signature_key_id` VARCHAR(40) NULL, `requires_licence` BOOL default false |
| `plugin_versions` | `id`, timestamps, `plugin_id` FK plugins, `version` VARCHAR(20), `core_constraint` VARCHAR(40), `manifest` JSON, `package_path` VARCHAR(255), `checksum` CHAR(64), `signature` TEXT NULL, `migration_count` INT default 0, `status`, `released_at` NULL; unique (`plugin_id`, `version`) |
| `plugin_dependencies` | `plugin_version_id` FK, `depends_on_plugin` VARCHAR(100), `version_constraint` VARCHAR(40); PK (`plugin_version_id`, `depends_on_plugin`) |
| `plugin_tenant_access` | `plugin_id` FK, `tenant_id` FK tenants, `granted_by` FK users **NULL**, timestamps; PK (`plugin_id`, `tenant_id`) |
| `tenant_plugins` | `id`, timestamps, `tenant_id` FK, `plugin_id` FK, `plugin_version_id` FK, `status`, `schema_version` BIGINT default 0, `licence_id` FK licences NULL, `installed_at`, `enabled_at`, `disabled_at` (all NULL), `installed_by` FK users **NULL**, `failure_count` INT default 0, `tripped_at` NULL, `last_error` TEXT NULL; unique (`tenant_id`, `plugin_id`) |

**-> ba, informational:**
- `installed_by` and `granted_by` are nullable, because a console action may have no platform user.
- `latest_version_id` is left unguarded.

### Tenant schema: three tables (step 4)

| Table | Columns, matching §6.20 column for column |
|---|---|
| `plugin_settings` | `plugin_name` VARCHAR(100), `key` VARCHAR(120), `value` TEXT NULL, `is_secret` BOOL default false, `updated_by` CHAR(26) NULL FK employees, `created_at`, `updated_at`; PK (`plugin_name`, `key`) |
| `plugin_migrations` | `plugin_name` VARCHAR(100), `migration` VARCHAR(191), `batch` INT, `ran_at` DATETIME(3); PK (`plugin_name`, `migration`) |
| `plugin_execution_logs` | `id` BIGINT auto PK, `plugin_name` VARCHAR(100), `point` VARCHAR(60), `mode` VARCHAR(30) (the application enum `HookMode` has **five** values, D-4), `duration_ms` INT, `status` VARCHAR(30) (`ok`, `veto`, `error`, `timeout`), `error` TEXT NULL, `request_id` CHAR(26) NULL, `created_at` |

- The tenant tree grows from 22 files to 25, and from 20 tables to 23.
- The catalogue is republished with `php artisan schema:publish --write-fixture`.
- `APP_REQUIRED_SCHEMA_VERSION` in `.env.example` goes from 22 to 25.
- **No core table is altered,** and no core table gets a key into a plugin table (AC-19).

### Writes

| Table | Written by |
|---|---|
| `plugins`, `plugin_versions`, `plugin_dependencies` | `RegisterPluginVersionHandler` |
| `plugin_tenant_access` | `GrantPluginAccessHandler` |
| `tenant_plugins`: lifecycle columns | the enable, disable and uninstall handlers, and `MigratePluginHandler` (`schema_version`) |
| `tenant_plugins`: `failure_count`, `tripped_at`, `last_error`, and status to and from `error` | `CircuitBreaker` only |
| `plugin_migrations` | `PluginMigrationRunner` (insert) and `UninstallPluginHandler` (delete) |
| `plugin_execution_logs` | `ExecutionLog` only |
| `plugin_settings` | `PluginSettingsStore` only |
| `outbox_events` | T-018's `OutboxWriter`, called by the gateway through `Outbox` |
| `plg_<plugin>_*` | the plugin's own migrations and handlers; dropped by `UninstallPluginHandler` |

### Console, HTTP and dependencies

- **Console:** `plugin:register`, `plugin:grant`, `plugin:enable`, `plugin:disable`, `plugin:uninstall`, `plugin:migrate`, `plugin:reset`, `plugin:list`. No route is added (AC-43).
- **New consumer group** on the shared stream: `plugins.after`.
- **Dependencies:** none added.

## 5. Boundary impact (deptrac and PHPStan)

All of this is in `modules/host/deptrac.yaml`. Every change is an edge change, and **no exemption is added**.

- **Add the path** `../../packages/plugin-sdk/tests`.
- **Split the layer** the way eventbus's is split:
  - `Package_PluginSdk` = `^Flexipos\\PluginSdk\\(?!Tests\\).*`;
  - `Package_PluginSdk_Tests` = `^Flexipos\\PluginSdk\\Tests\\.*`.
- **`Package_PluginSdk: ~` becomes `[Package_Kernel, Package_Kernel_TenancyStatus, Package_Contracts]`.**
  - `Package_Kernel` covers `RunnerClock`, `Tenancy`, `TenantContext`, `RequestId`, `SourceRules`, `MigrationTree` and `IdentifierColumn`.
  - `Package_Kernel_TenancyStatus` is **new for a package**, and is only for `TenantConnectionPurpose::Migrating` (Ruling 6, -> lead). Step 15 narrows the use to two files by a source scan.
- **`Package_PluginSdk_Tests` reaches:**
  - `Package_PluginSdk` and `Package_Contracts`;
  - `Package_Kernel` and `Package_Kernel_TenancyStatus`;
  - `Package_Access`, for `AccessTenantFixture`, as in the eventbus-tests precedent;
  - `Package_Eventbus`, to inspect `InMemoryEventBus` in the proof of pipeline.
- **`Module_Demo` loses `Package_PluginSdk`.** This narrowing is **AC-47's instrument**.
  - Measured: nothing in `modules/demo` references `Flexipos\PluginSdk`.
  - **Negative control (step 1):** a scratch class in `modules/demo/src` that imports `Flexipos\PluginSdk\Gateway\Gateway` must fail `composer deptrac`, and the same class importing `Flexipos\Contracts\Plugins\HookGateway` must pass. Delete both afterwards (memory: probes).
- **`Package_Platform` gains no edge.** `MasterPluginLicences` implements a contract that platform can already reach.
- **`HostApp` and `HostTests`** already reach `Package_PluginSdk`.

**PHPStan:** add `../../packages/plugin-sdk/tests` to `phpstan.neon.dist`.

**Composer:**
- In `modules/host/composer.json`, `autoload-dev` gains `"Flexipos\\PluginSdk\\Tests\\": "../../packages/plugin-sdk/tests/"`. Then run `composer dump-autoload`, and confirm with `composer validate --no-check-publish` that `composer.lock` has not moved.
- `packages/plugin-sdk/composer.json` stays unchanged. Its description already fits.

**The stated limit (memory).** Deptrac proves type references only. "Only the breaker writes the breaker columns", "only two files name `TenantConnectionPurpose`" and "fixture handlers import only contracts" are carried by the text scans in step 15.

---

## 6. Implementation steps

Each step ends with `composer check` green from `modules/host`, compared against the gate as it stood at the start of the step: the delta must be zero or explained. **Before each gate run, `dev` lists any `php.exe` processes that are not its own.**

- **Red-list rule.** After steps 1, 3, 4 and 6, record the red list **before** fixing anything, and compare it with section 9. A red file that section 9 does not predict means **stop and report to `architect`**.
- **Red-first rule.** Show red by leaving something out, or by a fixture. A refusal is never stubbed to allow.
- **No child processes.** Use `Artisan::call` in-process, so no new file joins the `toolchain` group.
- **Vocabulary.** The working tree is swept by `QaVocabularyRenameSweepTest`, and its one case-insensitive token is the three-letter abbreviation of the retired module word. Describe it; never write it.
- **Basenames.** Every new test file's basename must be unique across the whole repository (`ToolchainGroupBindingTest`). For that reason the pipeline test is `PluginPipelineTest`, not `ProofOfPipelineTest`.

- [x] **Step 0: baseline.**
  - Run `composer check`. Record the passed, failed and skipped counts, the seconds the test step took, the FP-328 headroom line and the process list in the commit message.
  - *(No AC.)*

- [x] **Step 1: package plumbing.**
  - Make the edits in section 5 to `deptrac.yaml`, `phpstan.neon.dist` and `composer.json`, then run `dump-autoload`.
  - Create `config/plugins.php` and an empty `PluginSdkServiceProvider`. Register it in `bootstrap/providers.php` after `EventbusServiceProvider`.
  - **Test `tests/Support/PluginSdkProviderTest.php`:**
    - the provider is loaded;
    - the threshold is 5, the timeout is 500 and the core version is `0.1.0`.
  - Run section 5's demo negative control.
  - **Predicted red:** none. `QaToolchainRegistryCoverageTest` already lists `plugin-sdk`.
  - *(AC-47 instrument.)*

- [x] **Step 2: contracts.**
  - Create the Ruling 1 files.
  - **Tests:**
    - `HookModeTest`: exactly five values, in the D-1 order;
    - `HookPointTest`: the name pattern, the 60-character limit, `isOverridable()` both ways;
    - `HookOutcomeTest`: `vetoed()`.
  - *(Groundwork for AC-1 to AC-3 and AC-21.)*

- [x] **Step 3: master migrations.**
  - Create the five files.
  - **Edits:** `Helpers.php` `masterApplicationTables()` gains five names; `MigrationTreeDisciplineTest` 21 becomes 26; `QaSchemaSignatureInstrumentTest` 29 becomes 34.
  - **Test `packages/plugin-sdk/tests/Schema/PluginMasterSchemaTest.php` (AC-7, AC-12):**
    - for each table, the sorted column list equals §4.3's list **exactly**;
    - `type_name` and `nullable` for the nullable columns. Never assert lengths: SQLite reports bare types (memory);
    - the primary and unique keys;
    - the foreign keys, including `tenant_plugins.licence_id -> licences.id`;
    - **AC-12:** among the master tables, only `licences` matches `/licen/i`;
    - applying the tree twice is a no-op.
    - **Control:** a scratch table missing one column is reported by the column comparison.
  - **Predicted red:** the two count files above. `MasterSchemaBuildsTest` derives its counts, so it should not go red. Anything else is a stop.
  - *(AC-7, AC-12; AC-48's stand-in half.)*

- [x] **Step 4: tenant migrations.**
  - Create the three files.
  - **Edits:**
    - republish the catalogue with `--write-fixture`;
    - `.env.example` 22 becomes 25;
    - `Helpers.php` `tenantTreeTables()` gains three names;
    - `MigrationTreeDisciplineTest` 20 becomes 23;
    - `TenantBaselineBuildsTest`: the foreign-key inventory gains `plugin_settings.updated_by -> employees.id`;
    - `QaAccessCloseOutSweepsTest` and `QaAccessSchemaShapeTest`: add `$t019 = ['plugin_execution_logs','plugin_migrations','plugin_settings']` beside `$t017`.
  - **Test `PluginTenantSchemaTest` (AC-18 columns):**
    - the exact column lists and keys;
    - `SourceRules::inspect()` reports nothing for the three files;
    - **D-4:** the application enum holds five values, and a row with `mode='add'` is accepted and read back.
  - **Predicted red:** the files above, plus `SchemaCataloguePublishTest`, which the republish fixes without any edit. Anything else is a stop. Memory: the first full run after a tenant migration lands is where the full inventory of affected files is found.
  - *(AC-18 schema, D-4.)*

- [x] **Step 5: manifest, constraints, point catalogue, and the fixture package.**
  - Create the `Manifest/*` classes, `HookPointCatalogue`, `ManifestRefused`, `FixtureHookPointProvider`, and the `FixtureEcho` package (manifest, six handlers, two migrations).
  - **`VersionConstraintTest`:**
    - exact, `^`, `~`, ranges, `||`, `*`;
    - `^0.x` semantics;
    - every malformed form is refused.
  - **`HookPointCatalogueTest`:** a fixture provider is seen with no memo; a duplicate is refused; a bad name is refused.
  - **`ManifestValidatorTest`:** **every case asserts the named constructor, never just "throws".**
    - **AC-1:** one case per missing required field, through a dataset; plus `hooks[0].handler`.
    - **AC-2:** `Before`, `BEFORE`, `replace` and `""` each give `unknownMode`. **Control:** `before` is accepted.
    - **AC-3:** `override` on `fixture.plain` gives `overrideNotPermitted`, and on `fixture.echo` it is accepted.
    - **AC-4:** a missing class gives `handlerNotFound`. `NotAHook`, and a `FilterHook` class declared for mode `before`, each give `handlerWrongContract`.
    - **AC-5:** `{"name":` gives `malformedJson`, and so does `[]`. A malformed manifest that also lacks fields still names `malformedJson` (ordering).
    - **AC-6:** `hooks: []` is accepted, with zero registrations.
    - **AC-10:** `>=9.0` gives `coreVersionMismatch`. **Control:** moving `plugins.core_version` to `9.1.0` accepts it.
    - **Also:** `requires_licence: "true"` gives `invalidField`; an unknown key gives `unknownField`; two `override` entries at one point give `duplicateRegistration`; the default visibility is `private`.
    - The real `FixtureEcho/plugin.json` validates.
  - *(AC-1 to AC-6, AC-10 part, AC-25 within one manifest.)*

- [x] **Step 6: plugin licences in platform.**
  - Extract `PlatformLicence::isCurrent()` and create `MasterPluginLicences` with its binding.
  - **`MasterPluginLicencesTest`, using a matrix:**
    - an active licence with null `valid_until` gives its id;
    - a future `valid_until` gives its id;
    - a past one gives null;
    - `revoked` and `suspended` give null;
    - a future `valid_from` gives null;
    - another plugin's `subject` gives null;
    - another tenant gives null;
    - a `platform` row with the same subject gives null;
    - an expired row plus a current row gives the current one.
  - **Predicted red:** none. Every case in `PlatformLicenceTest` must stay green untouched, and a red one is a stop.
  - *(AC-13 and AC-16 at the source.)*

- [x] **Step 7: the registry.**
  - Create the `RegisterPluginVersion` and `GrantPluginAccess` slices and their commands.
  - **Tests:**
    - **AC-8:** registering `FixtureEcho` gives one `plugins` row and one `plugin_versions` row. The manifest round-trips, `checksum` equals the sha256 of the file, `status` is `available` and `migration_count` is 2. **Registering the same version again** gives `versionAlreadyRegistered`, the row counts are unchanged, and the stored manifest is unchanged.
    - **Version 1.1.0** moves `latest_version_id`, and registering 1.0.1 afterwards does not move it.
    - **`requires`** produces `plugin_dependencies` rows.
    - **AC-10:** a temporary manifest with `core_constraint: ">=9.0"` is refused, and the master has zero rows.
    - **The grant:** inserted once, and a repeat is a no-op. Granting a non-private plugin is refused.
    - **Command tests:** exit codes and output.
  - *(AC-8, AC-10, AC-11 data.)*

- [x] **Step 8: the migration runner and the settings store.**
  - Create `PluginMigrationRules`, `PluginMigrationRunner`, the `MigratePlugin` slice and command, `Connections` and `PluginSettingsStore`.
  - **`PluginMigrationRulesTest` (AC-19).** Each planted source is reported, each with its own control:
    - `Schema::table('employees', …)`;
    - `Schema::create('loyalty_x', …)`;
    - `Schema::create($name, …)`;
    - `DB::statement('alter table employees …')`;
    - a native `enum(`;
    - a `tenant_id` column.

    The two `FixtureEcho` migrations are clean.
  - **`PluginMigrationRunnerTest`** on `tenant-a`, entered with the Migrating purpose:
    - **AC-38:** both migrations run in file order. `plugin_migrations` holds 2 rows with batch 1. The two `plg_fixture_echo_*` tables exist. The return value is 2.
    - **AC-39:** a second run returns 2, inserts nothing, and touches no DDL (the query log holds no `create`).
    - **A refused set:** a planted bad third file means zero files run and nothing is recorded.
  - **`MigratePluginTest`:**
    - the master `tenant_plugins.schema_version` mirrors 2 (AC-38);
    - the command walks only tenants whose row is `enabled`, or the named tenants;
    - one tenant's failure is logged, the others still run, and the exit code is 1.
  - **`PluginSettingsStoreTest` (AC-18):**
    - for a secret, the stored `value` is not the plaintext, `Crypt::decryptString` gives it back, and `get()` gives it back;
    - a value that is not secret is stored as plain text.
  - *(AC-18, AC-19, AC-38, AC-39.)*

- [x] **Step 9: enablement.**
  - Create the `EnablePlugin`, `DisablePlugin` and `UninstallPlugin` slices and commands, plus `PluginDataExporter` and `JsonFilePluginDataExporter`.
  - **`EnablePluginTest`:**
    - **AC-14:** the row passes through `installing` and ends `enabled`. `installed_at`, `enabled_at` and `installed_by` are stamped, and `schema_version` is 2.
      - **Observe the `installing` state during the write, not afterwards** (memory). A fixture migration's `up()` reads the master row's status. The test checks the value it saw is `installing`.
      - A second enable: the whole master row is unchanged, including `updated_at`, and `plugin_migrations` is unchanged.
    - **AC-13:** `requires_licence` with no licence gives `licenceRequired`, and there is no row. With a licence, `licence_id` is stored. A plugin with `requires_licence=false` has no licence query at all (a spy on `PluginLicences`).
    - **AC-16:** an expired licence, a revoked one and a suspended one each give the **same** constructor as "none", and there is no row.
    - **AC-11:** a private plugin without a grant gives `accessNotGranted`; with a grant it enables. `official` and `third_party` enable with no grant.
    - **AC-9:** a temporary plugin `fixture-needs-a` declares `requires: {"fixture-echo": "^1.0"}`, and `fixture-needs-b` declares `"^2.0"`.
      - With echo not enabled, `fixture-needs-a` gives `dependencyNotSatisfied`, and no row is written.
      - With echo 1.0.0 enabled, `fixture-needs-b` still refuses (the constraint is not satisfied), and **the control** `fixture-needs-a` enables.
    - **AC-25 across plugins:** a second temporary plugin that overrides `fixture.echo` gives `overrideAlreadyClaimed`.
    - **A tripped row** gives `tripped`.
    - **A migration failure** (a planted throwing file) leaves the status at `installing` with `last_error` set. After the file is fixed, running enable again completes it.
  - **`DisablePluginTest`:**
    - **AC-15:** the status is `disabled`, `disabled_at` is stamped, and the `plg_` tables still exist.
    - Re-enabling gives `enabled`, and `plugin_migrations` still holds 2 rows (no re-run).
    - Disabling a disabled row is a no-op; disabling an `error` row is refused.
  - **`UninstallPluginTest`:**
    - **AC-20:** an enabled plugin is refused, and nothing is dropped.
    - Disabled, then uninstalled: `RecordingExporter` received both tables with their rows **before** any drop (the call order is recorded). The tables are gone, the plugin's `plugin_migrations` rows are gone, and the master row is gone. The other plugin's tables, and every core table, are unchanged.
    - **A throwing exporter** means nothing is dropped.
    - **Prefix collision:** with `fixture-echo` and a temporary `fixture-echo-two` both installed, uninstalling the first leaves `plg_fixture_echo_two_*` alone.
    - **`JsonFilePluginDataExporterTest`:** writes the files into a temporary directory.
  - *(AC-9, AC-11, AC-13 to AC-16, AC-20, AC-25, AC-42.)*

- [x] **Step 10: the runtime and the gateway's synchronous modes.**
  - Create `PluginRuntime`, `ManifestCache`, `TenantHookTable`, `Registration`, `Gateway`, `HookInvoker` (with the breaker wired as a no-op until step 11), `ExecutionLog`, `HookClock`, `SystemHookClock` and `HookTimedOut`.
  - **`GatewaySyncModesTest`.** `fixture-echo` is enabled for `tenant-a`, the clock is fixed and `FakeHookClock` is bound.
    - **AC-21:** a second temporary plugin enabled later runs after `fixture-echo`, and the order is asserted from a recorded call list. Swapping `enabled_at` swaps the order.
    - **AC-22:** `contributions` holds `EchoAdd`'s value, attributed to the plugin, and the result is the action's or the override's, unchanged.
    - **AC-23:**
      - the payload `{veto: true}` gives `vetoed()`, the plugin name `fixture-echo` and the reason, and **the action closure is never called** (a counter at 0). No `add` runs and no outbox row is written;
      - the `VetoSignal` variant behaves the same;
      - `{veto: false}` lets the action run;
      - two `before` handlers where the first allows: both run.
    - **AC-24:** `EchoUpperFilter` is declared first and `EchoSuffixFilter` (appends a lower-case `x`) second. `"ab"` becomes `"AB"` and then `"ABx"`. The reverse order would give `"ABX"`, so the assertion tells the two orders apart and proves the chain. Controls:
      - one filter alone gives the single transform;
      - a filter that throws leaves the payload passing on unchanged.
    - **AC-25:**
      - on `fixture.echo`, the result is `EchoOverride`'s and `overriddenBy` is `fixture-echo`;
      - the action closure is not called;
      - an override that throws falls back to the action.
    - **AC-26:**
      - on `fixture.plain` with nothing registered, the result is exactly `$action($payload)` and the outcome is empty;
      - for a tenant with no plugins (`tenant-b`) on `fixture.echo`, the same holds.
    - **AC-17:** with a row in `disabled`, then `installing`, then `updating`, then `error`, the dispatch calls nothing. Each case asserts a recorded call list that is empty.
    - **AC-36:** the fixture handler records `getmypid()`, and it equals the test's pid.
    - **Execution logs:** one row per invocation, holding `mode`, `status` and `duration_ms` from the fake clock.
    - **A savepoint:** a handler that inserts into its `plg_` table and then throws leaves no row, while the action's own write commits.
    - **A timeout:** `SlowHook` advances the clock 600 ms. Its result is discarded, its write is rolled back, the log row says `timeout`, and the outcome lists it in `failed`.
    - **`NoTenantResolved`** with no tenant entered; an unknown point gives `LogicException`.
  - **`PluginRuntimeTest`:**
    - **AC-35 and AC-37:** two plugins are enabled. One stored manifest is corrupted directly in the master (a field removed) **before the first dispatch**. Dispatch runs the healthy plugin, registers nothing from the corrupted one, and logs one `error` naming it. A second dispatch does not log again.
    - **A second failure kind:** the stored manifest is well formed, but `plugins.core_version` is moved outside its `core_constraint`. That plugin is isolated too, which shows that re-validation covers the whole of Ruling 2 and not just whether the JSON parses.
    - **The runtime race for override:** two plugins forced into `enabled` by a direct insert, both overriding `fixture.echo`. Only the earlier one is loaded, and an `error` is logged.
  - *(AC-17, AC-21 to AC-26, AC-35 to AC-37; AC-30's timeout input.)*

- [x] **Step 11: the circuit breaker and the operator commands.**
  - Create `CircuitBreaker`, wire it into `HookInvoker`, and create the `ResetPluginBreaker` and `ListTenantPlugins` slices and commands.
  - **`CircuitBreakerTest`.** The threshold is 5 and `ThrowingHook` is enabled for `tenant-a`.
    - **AC-30:**
      - three failures give `failure_count` 3;
      - a success gives 0;
      - four failures then do not trip;
      - the 5th trips;
      - a timeout counts exactly like an exception.
    - **AC-31:**
      - `status` becomes `error`, `tripped_at` comes from the clock, and `last_error` is the message;
      - **one** `critical` log;
      - the next dispatch: the handler's call counter does not move, there is one `warning` refusal log, the plugin is listed in `skipped`, and the action still runs (the same shape as AC-26).
    - **AC-32:**
      - `fixture-echo` at the same point keeps working;
      - the same plugin for `tenant-b` keeps working, and its `failure_count` is 0.
    - **AC-33:**
      - with the clock moved forward 30 days, the plugin is still `error`;
      - only `plugin:reset` brings it back. Afterwards `failure_count` is 0 and `tripped_at` and `last_error` are null;
      - a reset on an `enabled` row exits 1 and changes nothing.
    - **The rolled-back caller:** a dispatch inside an outer tenant transaction that is rolled back still counts the failure.
  - **`ListTenantPluginsTest` (AC-40):** the output holds every listed column for `enabled`, `disabled` and `error` rows, and lists the other tenant's rows nowhere.
  - **Command tests for `plugin:enable` and `plugin:disable`** (AC-42).
  - *(AC-30 to AC-33, AC-40 to AC-42.)*

- [x] **Step 12: `after` mode.**
  - Make the gateway write the `after` rows, and create `PluginAfterHookConsumer`, `PluginDispatchRefused` and the provider's `eventConsumers()`.
  - **`GatewayAfterModeTest`:**
    - **AC-27:**
      - one dispatch writes **one** `outbox_events` row for `fixture-echo`, with `type='plugin.fixture.echo'`, `aggregate_type='plugin'`, `aggregate_id=plugins.id`, the actor `{type: plugin, id, name: fixture-echo}`, and the payload holding the point, the plugin and the filtered payload;
      - `EchoAfter` is **not** called in-process (a counter at 0);
      - an action that throws leaves zero outbox rows, and the control (the action succeeds) leaves 1;
      - a veto leaves zero;
      - a disabled plugin leaves zero.
  - **`PluginAfterHookConsumerTest`.** The stand-in bus, with `eventbus:relay --once`, then `eventbus:consume --group=plugins.after --once`:
    - the handler runs once and the marker row exists;
    - `eventTypes()` equals `['plugin.fixture.echo']`, because the fixture catalogue makes only `fixture.echo` accept `after`;
    - **AC-29:**
      - redelivering the same envelope (handled directly a second time) leaves one marker row;
      - **control:** a fixture variant that does not check the marker gives 2;
    - **AC-34:**
      - a throwing `after` handler counts against the same `tenant_plugins` row. After 5 deliveries (clock advanced past T-018's reclaim back-off) the plugin is `error`, with **no synchronous failure at all**;
      - a timeout in `after` counts too, and the handler's write is rolled back;
    - **Refusals:**
      - a disabled plugin: the event is acknowledged, the handler is not called, and there is an `info` log;
      - a tripped plugin: the handler is not called, the message stays unacknowledged, and it ends in the dead-letter stream (stand-in);
    - **`ConsumerRegistry::groups()`** contains `plugins.after`.
  - **Predicted red:** possibly an eventbus test that pins the full set of groups or the output of a run without `--group`. The grep in section 9 found none. A red one is a stop.
  - *(AC-27, AC-29, AC-34; AC-28's stand-in half.)*

- [x] **Step 13: proof of pipeline.**
  - **`tests/Pipeline/PluginPipelineTest.php` (AC-44).** One test, through the commands only, on the stand-in bus:
    1. `plugin:register` the `FixtureEcho` package;
    2. `plugin:enable fixture-echo --tenant=tenant-a`;
    3. `fixture.echo` with `{veto: true}`: vetoed, with the plugin and reason reported;
    4. with `{text: "ab"}` and an action: the result is `EchoOverride`'s, the payload went through both filters, there is an `add` contribution, and there is one outbox row;
    5. `eventbus:relay --once`, then `eventbus:consume --group=plugins.after --once`: the `after` marker is in `plg_fixture_echo_after_effects`;
    6. a simulated redelivery leaves one marker;
    7. `plugin:list` shows it as `enabled` with `failure_count` 0.
  - A header comment says this is a fixture and not a reference plugin (D-13, AC-45).
  - *(AC-44, AC-28 on the stand-in.)*

- [x] **Step 14: the engine test, ready to lift (AC-48).**
  - **`modules/host/tests/Engine/PluginSchemaEngineTest.php`,** in the shape of `ThreeTenantFixtureTest`:
    - **Under the hold:** creating a reserved database through `EngineDatabase` throws `EngineOnHold`. This case is skipped once the hold is cleared.
    - **Once lifted** (skipped under the hold):
      1. on one reserved database, apply the master tree and assert the five tables;
      2. on another reserved database, apply the tenant tree, then run `PluginMigrationRunner` with `FixtureEcho`, assert its tables and the foreign key into `employees`, then run uninstall's drop and assert the tables are gone;
      3. drop both databases by name through `EngineDatabase` only.
  - Measure that the skip count rises by exactly one.
  - *(AC-48 stays NOT VERIFIED; the code to verify it is ready.)*

- [x] **Step 15: close-out sweeps (`qa`-accountable files, written by `dev`).**
  - **`QaPluginSourceRulesTest`.** It has its own helpers and calls nothing in another `Qa*` file (FP-164). Its scan set is `packages/*/src`, `modules/*/src` and `modules/host/app`, and it asserts that each scanned directory exists. Every rule has a planted control:
    - **AC-19, core half:** no file in either shipped migration tree names a `plg_` table.
    - **AC-36:** no process-spawning call under `packages/plugin-sdk/src`.
    - **Breaker columns:** the only file writing `failure_count` or `tripped_at` is `CircuitBreaker.php`.
    - **Logs:** the only file inserting into `plugin_execution_logs` is `ExecutionLog.php`.
    - **Purpose:** `TenantConnectionPurpose` appears in `plugin-sdk/src` only in `PluginMigrationRunner`'s caller (`MigratePluginHandler`) and in `UninstallPluginHandler`.
    - **Ruling 1:** the files under `tests/Fixtures/FixtureEcho/*.php` import nothing under `Flexipos\PluginSdk\`.
    - **AC-45:**
      - `FixtureEcho/plugin.json` is the only `plugin.json` in the repository (vendor excluded);
      - every manifest `name` literal in `packages/plugin-sdk/tests` starts with `fixture-`;
      - `packages/plugin-sdk/src` names no plugin at all.
  - **`QaPluginNoHttpSurfaceTest` (AC-43):**
    - no registered route's URI or action matches `/plugin|hook/i`;
    - **control:** a mounted scratch `GET api/v1/plugins` is reported.
  - **AC-47:** re-run step 1's deptrac control. Record the pair in the `qa` report; do not commit it as a test.
  - *(AC-19, AC-36, AC-43, AC-45, AC-47.)*

- [x] **Step 16: docs.** Describe; do not quote retired wording (memory).
  - **`docs/database.md`:**
    - **§6.20:** the `plugin_execution_logs.mode` row now names the five values `add, before, after, filter, override` (D-4). It notes that the `status` vocabulary is unchanged.
    - **§4.3:** an "As built (T-019)" note. `installed_by` and `granted_by` are nullable, `latest_version_id` has no key, and the breaker columns follow Ruling 12.
    - **§9.6:** the command is `plugin:migrate`, from the plugin package, because the kernel cannot depend on it.
  - **`docs/architecture.md` §13:** one "As built (T-019)" paragraph. It covers the dispatch order, fail-open, the measured timeout, and `after` through the outbox.
  - *(D-4's documentation correction; no AC.)*

- [ ] **Step 17: close-out, `qa`.**
  - **Mandatory mutations,** each shown red and then reverted:
    1. The validator coerces the mode case. AC-2's `Before` case goes red.
    2. The runtime skips re-validation and trusts the stored manifest. AC-37 goes red.
    3. The runtime loads `status in ('enabled','disabled')`. AC-17 goes red.
    4. A `before` veto no longer stops the action. The AC-23 counter goes red.
    5. Every filter receives the original payload. AC-24's order assertion goes red.
    6. The breaker's `>=` becomes `>`. AC-31's 5th-failure case goes red.
    7. `recordSuccess` is removed. AC-30's reset case goes red.
    8. The breaker is keyed by plugin only. AC-32's `tenant-b` case goes red.
    9. The after-row is written outside the transaction. AC-27's rollback case goes red.
    10. Enable on an `enabled` row re-runs the runner. AC-14's unchanged-row case goes red.
    11. Licence validity ignores `status`. AC-16's revoked case goes red.
    12. Uninstall matches tables by prefix. The prefix-collision case goes red.
    13. `EchoAfter` drops its marker check. The AC-29 end-state goes red.
  - **Then:** the diff check against section 9's edit list, and the final `composer check` with its process list and skip delta.
  - *(AC-46.)*

---

## 7. Test strategy

- **Unit, pure:** `HookMode`, `HookPoint`, `VersionConstraint`, `ManifestValidator`, `PluginMigrationRules`, `PluginName`.
- **Slice and gateway tests:**
  - the master is SQLite in memory, migrated by `PluginSeed`;
  - the tenants come from `AccessTenantFixture`: `boot()` once per test in `beforeEach`, and only `enter()` afterwards (memory);
  - `RunnerClock` is fixed and `FakeHookClock` is bound;
  - the bus is the in-memory stand-in, with `Sleep::fake()`;
  - no network, no real wait, no child process.
- **Host:** `PluginSchemaEngineTest` (engine group, skipped) and the two `Qa*` sweeps.
- **Cost:** about 150 cases. The master is migrated in memory, which is cheap, and the tenants come from file copies. FP-328's headroom is already below 2x, so steps 0 and 17 record the seconds the test step took, and `dev` reports any step that adds more than 15 s.

| Criteria | Proved in |
|---|---|
| AC-1 to AC-6 | step 5 (`ManifestValidatorTest`) |
| AC-7 | step 3 (`PluginMasterSchemaTest`) |
| AC-8 | step 7 |
| AC-9 | step 9 (`EnablePluginTest`) |
| AC-10 | step 5 (validator); step 7 (registry refuses, no rows) |
| AC-11 | step 7 (grant); step 9 (enable gate) |
| AC-12 | step 3 (only `licences`; `licence_id` FK) |
| AC-13, AC-16 | step 6 (licence matrix); step 9 (enable refusals, same named error) |
| AC-14, AC-15 | step 9 |
| AC-17 | step 10 (synchronous); step 12 (`after` consumer) |
| AC-18 | step 4 (columns); step 8 (encryption) |
| AC-19 | step 8 (`PluginMigrationRulesTest`, fixture FK into `employees`); step 15 (the core tree never names `plg_`) |
| AC-20 | step 9 (`UninstallPluginTest`) |
| AC-21 to AC-26 | step 10 (`GatewaySyncModesTest`); AC-25 also steps 5 and 9 |
| AC-27, AC-29, AC-34 | step 12 |
| AC-28 | **NOT VERIFIED** (Redis, T-018 D-1); the stand-in half is steps 12 and 13 |
| AC-30 to AC-33 | step 11 (`CircuitBreakerTest`); AC-30's timeout input in step 10 |
| AC-35, AC-37 | step 10 (`PluginRuntimeTest`) |
| AC-36 | step 10 (pid); step 15 (no spawn) |
| AC-38, AC-39 | step 8 |
| AC-40 to AC-42 | step 11; AC-42 also step 9 |
| AC-43 | step 15 (`QaPluginNoHttpSurfaceTest`) |
| AC-44 | step 13 (`PluginPipelineTest`) |
| AC-45 | step 15 |
| AC-46 | every step; step 17 |
| AC-47 | step 1's deptrac control and the `Module_Demo` narrowing; re-run in step 15 |
| AC-48 | **NOT VERIFIED** (engine hold). The stand-in halves are steps 3, 4 and 8. The engine test is written in step 14. The `down()` half conflicts with T-008 D-8 (Ruling 14) |

## 8. Risks and rollback

| # | Risk | Size | Mitigation |
|---|---|---|---|
| 1 | **Each tenant migration shifts many pinned sites** (memory: about 25 in T-010). The prediction in section 9 is based on T-017's actual list | medium | Red-list rule after steps 3 and 4; stop on anything not predicted |
| 2 | **Gate headroom** (FP-328). About 150 new cases | medium | Cheap fixtures; timings at steps 0 and 17; a 15 s flag per step. -> lead: FP-328 may need resolving first |
| 3 | **The timeout is measured after the fact.** A handler that never returns blocks the request until PHP's `max_execution_time` stops it | medium, inherent to running in-process (D-7) | Ruling 5's docblock; process isolation is the additive fix D-7 already names |
| 4 | **A failing `before` allows the action (fail-open).** FP-214's approval hook would let an action through if its plugin crashes | high for FP-214, none today | Spec-conformant. -> lead/ba: FP-214 should consider a per-point option to fail closed, which is additive |
| 5 | **One master read per dispatch** on the serving path | small now (no point is dispatched in production) | Indexed by the unique key. A cache scoped to the request is a later optimisation once measured |
| 6 | **A licence that lapses while its plugin is enabled is not re-checked** | medium, commercial | Ruling 9. -> lead: a follow-up row |
| 7 | **MySQL DDL commits implicitly,** so a plugin migration is not atomic with its `plugin_migrations` row | small | The same exposure the core runner has. Recovery is "run enable again": the file is re-tried, and DDL that is not idempotent fails loudly |
| 8 | **Nothing runs `eventbus:consume` in production** (FP-330), so `after` hooks would not drain | high, an existing class of problem | Inherited; FP-330 |
| 9 | **The gate sweeps the working tree** (memory) | small | Describe, do not quote |

**Rollback.** Everything is unpushed on the branch. Reverting it removes:
- the package code and the contracts;
- the platform implementation and its `isCurrent` extraction (behaviour-neutral);
- the eight migrations;
- the republished catalogue and the `.env.example` value;
- the config file and the provider line;
- the deptrac edges, which restores `Module_Demo`'s edge.

No tenant database exists in production yet, so no live data is involved.

## 9. Pre-existing files legitimately edited

Each edit is listed here so that `qa` reports it as intended, not as a regression. **No assertion is weakened.**

| Step | File (origin) | Edit and cause |
|---|---|---|
| 1 | `modules/host/deptrac.yaml` | the tests path; the layer split; the `Package_PluginSdk` edges, including `Package_Kernel_TenancyStatus`; the `Package_PluginSdk_Tests` ruleset; `Module_Demo` loses `Package_PluginSdk` (section 5) |
| 1 | `modules/host/phpstan.neon.dist` | the path `packages/plugin-sdk/tests` |
| 1 | `modules/host/composer.json` | `autoload-dev` gains `Flexipos\PluginSdk\Tests\` |
| 1 | `modules/host/bootstrap/providers.php` | `PluginSdkServiceProvider` after `EventbusServiceProvider` |
| 3, 4 | `modules/host/tests/Helpers.php` | `masterApplicationTables()` +5; `tenantTreeTables()` +3; docblock counts |
| 3, 4 | `modules/host/tests/Feature/MigrationTreeDisciplineTest.php` | `toHaveCount(21)` becomes 26 and `toHaveCount(20)` becomes 23; comments name T-019 |
| 3 | `modules/host/tests/Feature/QaSchemaSignatureInstrumentTest.php` | `toHaveCount(29)` becomes 34; the comment |
| 4 | `modules/host/database/catalogue/tenant_schema_versions.json` | republished with `--write-fixture` |
| 4 | `modules/host/.env.example` | `APP_REQUIRED_SCHEMA_VERSION=25` |
| 4 | `modules/host/tests/Feature/TenantBaselineBuildsTest.php` | the exact foreign-key inventory gains `plugin_settings.updated_by -> employees.id`; the comment |
| 4 | `modules/host/tests/Feature/QaAccessCloseOutSweepsTest.php` | `closeOutNewTables()` excludes `$t019` |
| 4 | `modules/host/tests/Feature/QaAccessSchemaShapeTest.php` | the T-010 table derivation excludes `$t019` (the same T-017 precedent) |
| 6 | `packages/platform/src/Support/PlatformLicence.php` (T-016) | extract `isCurrent()`; `verdict()` calls it; no change in behaviour |
| 6 | `packages/platform/src/PlatformServiceProvider.php` (T-016) | bind `PluginLicences` to `MasterPluginLicences` |
| 16 | `docs/database.md` §4.3, §6.20, §9.6 | step 16 |
| 16 | `docs/architecture.md` §13 | step 16 |

**Count:** 12 source, config and doc files, plus 6 test files (`Helpers.php` included). `modules/host/composer.lock` is predicted unchanged; confirm it in step 1.

**Expected unchanged,** listed so that a red one is investigated, not patched:

| Files | Why each is unaffected |
|---|---|
| `MasterSchemaBuildsTest`, `QaMasterStandInShapeTest`, `QaPlatformDatabaseSeparationTest`, `QaOrganizationTenantIsolationTest`, `AccessTenantFixtureTest`, `QaTenantBaselineStandInShapeTest`, `QaPinSinkCaptureTest`, `QaSecretSettingSinkTest`, `QaBranchIdFoldTest` | each derives its domain from the helpers, or the new tables are empty; `MasterSchemaBuildsTest`'s credential scan finds no `*_secret` or `password` column |
| `SchemaCataloguePublishTest`, `FixtureMigrationSetsTest`, every kernel `PlanMigrationRun*` / `MigrateTenant*` test | derived through `tenantMigrationFileCount()` / `topVersion()`; measured, no literal `22` remains. The new files are `expand`, so the contract-step trap (memory) does not apply |
| `QaDatetimePrecisionSourceTest`, `MigrationSourceRulesTest`, `QaSourceRuleEvasionShapesTest` | the new migrations follow the rules; `MigrationSourceRulesTest` gains one assertion per file, which is expected |
| `BranchForeignKeysMigrationTest` | unrelated; the new files have no `down()` |
| `PlatformLicenceTest` and every T-016 test | `verdict()` behaves the same |
| `ConsumerRegistryTest`, `RelayOutboxCommandTest`, `ConsumeEventsCommandTest`, `ProofOfPipelineTest` | they assert with `toContain` or with their own group; measured, none pins the full set of groups |
| `ModuleMakeDemoRegenerationTest` | `modules/demo` is not touched |
| `QaToolchainRegistryCoverageTest` | `plugin-sdk` is already in its list, and the tests tree is namespaced |
| `VerticalSliceLayoutTest` | it covers the 8 new slices, which must pass |
| `ConfigCacheGuardTest`, `QaVocabularyRenameSweepTest`, `QaHealthProbeResilienceTest` | `.env.example` changes one value |
| `DestructiveStatementContainmentTest` | uninstall drops tables, never a database |

## 10. Messages

**`architect` → lead.**

1. **A real conflict between two `ba` decisions (Ruling 14).** AC-48's `down()` half contradicts T-008 D-8, which makes migrations forward-only. The plan follows D-8. `ba` should amend AC-48 or overrule D-8 explicitly.
2. **A wording deviation that needs `ba` to amend the text (Ruling 11).** AC-27 says the outbox `type` names the point and the plugin. The type names the point, and the plugin is in `aggregate_id`, `actor` and `payload`. Two measured reasons: T-018's subscriptions match exactly, and `type` is limited to 80 characters.
3. **A new allowed edge (Ruling 6, section 5).** `Package_PluginSdk -> Package_Kernel_TenancyStatus`, only for `TenantConnectionPurpose::Migrating`, so that plugin DDL uses the migrate credential. A step 15 scan narrows its use.
4. **Rulings for `ba`, all informational, none changing what a criterion asks for:**
   - Ruling 2: unknown keys are refused, and visibility defaults to `private`;
   - Ruling 4: a point declares the modes it accepts, and `before` is fail-open;
   - Ruling 5: the timeout is measured after the call;
   - Ruling 6: the command is `plugin:migrate`;
   - Ruling 7: there is a `plugin:grant` slice;
   - Ruling 8: `updating` is unused, version changes are refused, and settings are kept on uninstall;
   - Ruling 9: `valid_from` is honoured;
   - Ruling 11: a tripped plugin's `after` events are dead-lettered rather than dropped;
   - the AC-25 timing: within one manifest at registration, across plugins at enable;
   - section 4: `installed_by` and `granted_by` are nullable.
5. **Follow-up rows proposed:**
   - re-check the licence at runtime, and disable plugins whose licence has lapsed (risk 6);
   - FP-214: consider a per-point option to fail closed (risk 4);
   - write platform audit rows for plugin lifecycle actions (`plugin.enabled` is already in §4.9's example list);
   - seed tenant `permissions` rows from a plugin's declared `permissions` when it is enabled (database.md §6.2);
   - an upgrade flow that uses `updating`;
   - promote the connection helpers to the kernel (now a fifth copy).
6. **The skip count rises by 1** (step 14). That is expected, not a regression.

**`architect` → `dev`.**

- Validate fully before any write, in every slice. Each step's "nothing written" controls depend on it.
- `CircuitBreaker` is the only writer of the breaker columns. Keep its two statements separate.
- The gateway never invokes an `after` handler in-process.
- Never find a plugin's tables by prefix. Read them from its migration files.
- Stop on any red file that section 9 does not name.

## Questions for the human

None that block the work. Nothing is irreversible or external, and no dependency is added. The two items that need a ruling, the AC-48 `down()` conflict with T-008 D-8 and AC-27's wording, are routed to the lead and `ba` in section 10. The plan takes the conservative side on both: no `down()`, and T-018's runner left untouched.
