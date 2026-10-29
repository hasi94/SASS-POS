# T-019: Plugin framework

Backlog: FP-213, "Manifest, registry in the master, licensing and per-tenant enablement, hook gateway
with the five modes, runtime, circuit breaker" (dependency FP-212, done in T-018). Decisions D-1 to
D-15 are in `docs/decisions/T-019-plugin-framework.md` (D-14 and D-15 resolve two conflicts `architect`
flagged between this spec's first draft and established codebase facts: AC-48's wording against T-008
D-8's forward-only convention, and AC-27's wording against T-018's outbox `type`-matching and column
limit). **No BLOCKING questions** — this is
autonomous/team-mode work; every open design question below was decided and logged rather than left
for the human.

Convention, following T-016/T-017/T-018: every criterion is provable on a deterministic fixture while
a real engine is unreachable, unless marked **[ENGINE]**. Two separate conditions sit behind that tag,
exactly as T-018 established:

- **MySQL/MariaDB** criteria stay gated by `ENGINE_HOLD`/gate G-1, owner-controlled.
- **Redis** criteria (reached only through `after`-mode hooks riding T-018's event bus) are gated by
  the narrower, non-owner-gated fact T-018 D-1 measured: no Redis service, client dependency or Docker
  Compose exist in this repository today. This lifts automatically the moment that infrastructure is
  added.

## Problem / goal

FP-213 is the first task to give a plugin a documented, enforced way to extend FlexiPOS without
touching core code: `docs/architecture.md` §13 and `Refference/ARCH.md` §12 already state the intended
shape (master decides licensing/enablement, tenant holds settings and data, code runs in-process
behind a hook gateway with five named modes, failures are caught/timed/circuit-broken per tenant), and
`docs/database.md` §4.3/§6.20 already designs the exact tables for it — `plugins`, `plugin_versions`,
`plugin_dependencies`, `plugin_tenant_access`, `tenant_plugins` in the master; `plugin_settings`,
`plugin_migrations`, `plugin_execution_logs`, `plg_<plugin>_*` in the tenant. None of it has a
migration, a registry, a loader or a gateway today — this task builds exactly that framework and
proves it with one fixture plugin, not a real one (FP-296, Phase 6, ships the first real plugins; FP-214,
the workflow engine, is a later `before`-mode hook consumer this task must not design for but must not
block either).

## Users affected

- Developers of the next modules that extend core behaviour without modifying it (FP-214's workflow
  engine, FP-296's reference plugins, any future integration) — today they have no manifest format, no
  registry, no way to be licensed per tenant, and no hook point to attach to.
- Operators, who today have no way to see which plugins are installed, enabled, licensed, or tripped
  for a given tenant, and no way to reset a tripped plugin — this task gives them console-only (no
  HTTP) visibility and control.
- Tenant administrators, who per §17 must grant a plugin's declared permissions explicitly — this task
  does not build that granting UI (no HTTP surface exists yet, D-10) but the manifest's declared
  permissions are the data a later UI would read.
- Not served by this task: cashiers and tills see no behaviour change — no real plugin exists to extend
  anything they touch yet.
- Downstream, explicitly not served by this task: FP-214's workflow engine and FP-296's reference
  plugins are different, later consumers of this framework, not designed here (D-12).

## Current behaviour

Re-derived from the files, not assumed:

- `docs/architecture.md` §13 and `Refference/ARCH.md` §12 state the intended design in prose; no code
  anywhere in the repository implements any part of it (measured: no file under `modules/` or
  `packages/` references a plugin manifest, registry, hook gateway or circuit breaker).
- `docs/database.md` §4.3 already designs the master-side tables (`plugins`, `plugin_versions`,
  `plugin_dependencies`, `plugin_tenant_access`, `tenant_plugins`) and §6.20 the tenant-side tables
  (`plugin_settings`, `plugin_migrations`, `plugin_execution_logs`, `plg_<plugin>_*`), plus `licences`
  (§4.2, `type=plugin`) and `feature_flags.is_plugin_flag`. **None of these tables has a migration
  anywhere in the repository** (measured: no `create_plugins_table`, `create_tenant_plugins_table`,
  `create_plugin_versions_table`, `create_plugin_settings_table` or similarly named migration exists
  under `modules/host/database` or `modules/host/database/migrations-tenant`).
- `packages/plugin-sdk` exists only as a Composer path-package scaffold: `composer.json` (description:
  "FlexiPOS plugin SDK: extension gateway, plugin base classes and manifest validation") and an empty
  `src/.gitkeep`. No code.
- `packages/eventbus` (T-018) ships a working `EventBus`/`Outbox` pair and `packages/contracts/src/Events`
  publishes `EventConsumer`/`DeclaresEventConsumers`/`NewEvent`/`EventEnvelope` — the mechanism this
  task's `after`-mode hooks ride (D-2), already proven end-to-end on a stand-in bus.
- `packages/platform` (T-016) ships `FeatureFlags`/`PlanLimits` contracts and `PlanMirror`/
  `CommercialStatus` — a different, simpler toggle mechanism this task does not reuse for plugin
  enablement itself (D-3), because `docs/database.md` already designs a richer, dedicated model for
  that.
- `packages/contracts/src/Audit/ActorType.php` already closes a five-value actor vocabulary
  (`employee, terminal, system, plugin, platform_staff`) that includes `plugin` as an actor type —
  relevant because a hook-triggered write (e.g. an `after` hook writing its own outbox row) is expected
  to carry `actor_type=plugin`, reusing that existing enum rather than inventing a second one.
- `docs/database.md` line 1439 documents `plugin_execution_logs.mode` as `enum(before, after, filter,
  override)` — missing `add`, one of the five modes `docs/architecture.md` §13 names. No migration for
  this table exists yet, so this task ships the corrected five-value enum (D-4) rather than carrying the
  gap forward.
- No platform-admin authorization model or HTTP surface exists anywhere in the codebase for any master-
  or tenant-side operational command (T-016 D-21, T-017 D-7, T-018 D-9) — the same precedent this task's
  own operator-visibility commands follow (D-10).

## User stories

- As a developer of a future module, I want to declare a plugin manifest naming the hooks I extend and
  the mode each one runs in, so that the gateway can validate and register my extension points without
  me writing dispatch code of my own.
- As a platform operator, I want to decide, in the master, whether a tenant may install, enable, or
  update to a given version of a plugin, so that licensing and compatibility are enforced centrally
  regardless of which tenant database the plugin's code eventually touches.
- As a tenant administrator, I want a plugin's settings and business data to live entirely inside my
  own tenant database, keyed to my own core records, so that enabling a plugin never gives it the power
  to alter core tables.
- As a developer calling an extension point, I want to call one hook gateway and trust that a plugin
  which throws, times out, or is disabled for this tenant is skipped safely, so that one broken plugin
  can never take down the request I am serving.
- As an operator, I want to see which plugins are enabled, disabled, or tripped for a tenant, and to
  reset a tripped plugin deliberately, without reading the database by hand.
- As a developer of the next infrastructure-consuming module (the workflow engine, FP-214, among them),
  I want a stable hook-gateway contract already proven end-to-end on a fixture plugin, so that I can
  register a `before`-mode handler without caring how the gateway resolves, times or circuit-breaks it.

## Acceptance criteria

### A. The plugin manifest

- AC-1: A manifest format exists requiring at minimum `name`, `version`, `core_constraint`, `hooks`
  (a list of `{point, mode, handler}`), `permissions` (a list), and `requires_licence` (bool). A
  manifest missing any required field is refused by a validator **before** registration, with a named
  error identifying the missing field.
- AC-2: A manifest declaring a `mode` outside the five legal values (`add`, `before`, `after`, `filter`,
  `override`) is refused with a named error — never silently coerced to the nearest known mode.
- AC-3: A manifest declaring `mode: override` against an extension point that was not itself
  registered as overridable (overridability is a property the point's own registration declares, never
  something a plugin can assert about itself) is refused with a named error (`docs/architecture.md`
  §13: "override... permitted only on points explicitly marked overridable").
- AC-4: A manifest naming a `handler` class that cannot be resolved (does not exist, or does not
  implement the expected hook-handler contract for its declared mode) is refused with a named error —
  never registered and later failing at call time instead.
- AC-5: A syntactically invalid manifest (malformed JSON) is refused before any further validation
  runs, with a named error distinct from a missing-field error.
- AC-6 (negative/edge case): An empty `hooks` list is legal — a plugin that declares no extension point
  yet (e.g. settings-only) registers successfully with zero gateway registrations.

### B. The master registry

- AC-7: Migrations exist for `plugins`, `plugin_versions`, `plugin_dependencies`, `plugin_tenant_access`
  and `tenant_plugins` in the master database, matching `docs/database.md` §4.3 column-for-column
  (including `tenant_plugins.failure_count`, `tripped_at`, `last_error`, `schema_version`, `licence_id`).
- AC-8: Registering a new plugin version writes a `plugin_versions` row carrying its manifest (AC-1's
  validated JSON), `checksum`, and `status='available'`; registering the same plugin name and version
  twice is refused (unique `(plugin_id, version)`), never silently overwritten.
- AC-9: A plugin version naming a dependency (`plugin_dependencies`) on another plugin/version
  constraint that is not installed, or not satisfied, for a tenant is refused at install/enable time for
  that tenant — the registry never installs a plugin whose declared dependency cannot be satisfied.
- AC-10 (negative case): Registering a plugin version whose `core_constraint` does not match the running
  core version is refused with a named error, never installed anyway with a warning.
- AC-11: A `private`-visibility plugin can only be installed for a tenant with a matching
  `plugin_tenant_access` grant; a tenant with no grant attempting to install it is refused. `official`
  and `third_party` visibility plugins (per the existing `visibility` enum) are not access-gated by this
  table.

### C. Licensing and per-tenant enablement

- AC-12: Migrations exist for the tenant-enablement model, reusing the already-designed `licences`
  table (`type=plugin`, `subject=<plugin name>`) — no second licensing table is created.
- AC-13: Enabling a plugin for a tenant that `requires_licence=true` with no active, unexpired,
  unrevoked `licences` row (`type=plugin`, matching `subject`, `status='active'`, `valid_until` in the
  future or null) is refused. A plugin with `requires_licence=false` enables with no licence check.
- AC-14: Enabling a plugin for a tenant writes/updates exactly one `tenant_plugins` row
  (`unique(tenant_id, plugin_id)`) with `status` progressing `installing` → `enabled`, stamping
  `installed_at`/`enabled_at`/`installed_by`. Enabling an already-`enabled` plugin for the same tenant is
  a no-op that changes nothing (never a duplicate row, never a double-run of its migrations).
- AC-15: Disabling a plugin for a tenant sets `tenant_plugins.status='disabled'` and stamps
  `disabled_at`; it does **not** drop that plugin's tenant-side tables (`docs/database.md` §9.6).
  Re-enabling a previously disabled plugin returns it to `status='enabled'` without re-running
  migrations already recorded in `plugin_migrations`.
- AC-16 (negative case): Enabling a plugin for a tenant whose licence has expired (`valid_until` in the
  past) or is `status='revoked'`/`'suspended'` is refused, with the same named error AC-13 uses for "no
  licence" — a reader does not need to distinguish "never licensed" from "licence lapsed."
- AC-17: The hook gateway (Group E/F) never dispatches to a plugin whose `tenant_plugins.status` for
  that tenant is anything other than `enabled` — `disabled`, `installing`, `updating`, and `error`
  (tripped, Group G) are all treated as "do not call," with no separate check a caller must remember to
  run.

### D. Tenant-side plugin data

- AC-18: Migrations exist for `plugin_settings`, `plugin_migrations`, and `plugin_execution_logs` in the
  tenant database, matching `docs/database.md` §6.20 column-for-column, **except** `plugin_execution_logs.mode`
  ships as the corrected five-value enum `(add, before, after, filter, override)` (D-4), not the
  four-value enum the document's prose currently shows.
  `plugin_settings.value` is encrypted at rest when `is_secret=true`.
- AC-19: A plugin's own tables follow the documented `plg_<plugin>_*` naming convention and may hold a
  foreign key **into** a core table (e.g. `plg_loyalty_accounts.customer_id → customers.id`); no
  migration this task ships gives a core table a foreign key into a plugin table, and no migration this
  task ships alters an existing core table's columns.
- AC-20 (negative case): Uninstalling a plugin for a tenant exports its `plg_<plugin>_*` data (format
  unspecified by this task — the export step itself is a hook the runtime calls; the business shape of
  an export is out of scope, Group K) then drops those tables; disabling alone never drops them (AC-15).

### E. The hook gateway — synchronous modes (add, before, filter, override)

- AC-21: A hook gateway exists with one call per extension point; a caller names the point and a
  payload, and the gateway resolves every tenant-enabled plugin (AC-17) that registered a handler at
  that point, in a deterministic order (registration order), and invokes each per its declared mode.
- AC-22 (`add`): Every enabled plugin registered at an `add` point is invoked; its return value is
  collected alongside every other `add` handler's and every other mode's effect — an `add` handler
  cannot veto or replace the call, only contribute alongside it.
- AC-23 (`before`, veto): A `before` handler returning/throwing a veto signal stops the gateway from
  proceeding to the point's own action; the gateway reports which plugin vetoed and why. A `before`
  handler that does not veto allows the gateway to proceed to the next `before` handler, then the point's
  action.
- AC-24 (`filter`): A `filter` handler receives the current value and returns a transformed value, which
  becomes the input to the next `filter` handler (or the point's action if none remain) — chained, not
  replaced wholesale by the last one to run.
- AC-25 (`override`): Exactly one `override` handler may be registered per point marked overridable; a
  second plugin attempting to register `override` at the same point is refused at registration time
  (AC-2 to AC-5's registration-time validation), not discovered later at call time by last-writer-wins.
- AC-26 (negative/edge case): Calling a point with zero registered handlers (no plugin enabled, or none
  declared this point) runs the point's own action unchanged — the gateway's absence of plugins is
  always a safe no-op, never an error.

### F. The hook gateway — asynchronous mode (after)

- AC-27: An `after` handler is never called in-process; the gateway instead writes one outbox row per
  enabled plugin registered at that point (via T-018's single outbox writer, AC-5 of T-018), inside the
  same transaction as the point's own action — a rolled-back action leaves no `after` row (mirroring
  T-018 AC-6). `type` is a fixed string naming only the hook point (`plugin.<point>`), matching T-018's
  exact-list `eventTypes()` matching (`ConsumeEventsHandler.php:126`) and staying inside the 80-character
  `type` column; the plugin's identity travels in `aggregate_id`, `actor` (`actor_type='plugin'`,
  reusing `ActorType::Plugin`), and `payload` instead. The row must still unambiguously identify, for
  any reader (operator, consumer, or test), both which hook point fired and which plugin it fired for —
  that traceability is what this criterion actually guarantees, not the literal shape of the `type`
  string (D-15).
- AC-28 [ENGINE — Redis]: An `after` hook's outbox row, relayed and consumed end-to-end against a real
  Redis connection, is NOT VERIFIED in this environment (T-018 D-1, inherited unchanged); the stand-in
  bus proves the same write/relay/consume contract deterministically in the meantime.
- AC-29: The consumer that drains a plugin's `after` hooks is idempotent by the outbox row's own
  identifier (T-018 AC-23's rule, inherited, not re-decided) — redelivery of the same `after` hook event
  never double-applies its effect.

### G. Circuit breaker

- AC-30: A synchronous-mode (`add`/`before`/`filter`/`override`) hook invocation that throws any
  exception, or exceeds a configured per-call timeout, counts as one failure for that
  `(tenant, plugin)` pair; a successful invocation resets that pair's consecutive-failure count to zero.
- AC-31: On reaching a configured consecutive-failure threshold (default 5) for a `(tenant, plugin)`
  pair, the gateway sets `tenant_plugins.status='error'`, stamps `tripped_at` and `last_error`, logs the
  trip at a severity an operator would notice, and from that point on refuses to invoke that plugin for
  that tenant at any hook point without attempting the call — it logs each refusal and returns the
  gateway's safe no-op (same shape as AC-26) for that plugin only, continuing normally for every other
  plugin and every other tenant.
- AC-32 (negative/isolation case): One plugin tripping its breaker for one tenant never affects a
  different plugin at the same hook point, nor the same plugin for a different tenant — each
  `(tenant, plugin)` pair's failure count and trip state is independent.
- AC-33: A tripped plugin is only returned to `status='enabled'` by a deliberate operator console
  action (Group I) that resets `failure_count=0` and clears `tripped_at`/`last_error` — there is no
  automatic, time-based reset.
- AC-34 (edge case): A plugin's `after`-mode hook failures (Group F, consumed asynchronously, possibly
  by a different process entirely) feed the **same** `(tenant, plugin)` failure count and threshold as
  its synchronous-mode failures — a plugin that only ever fails its `after` hooks still trips.

### H. Runtime — manifest loading and registration

- AC-35: Given a `tenant_plugins` row with `status='enabled'`, the runtime loads that plugin version's
  stored manifest (AC-8), validates it again (AC-1 to AC-6 — validation is not trusted to have been
  correct at registration time forever; a manifest cannot change without a new version, but the runtime
  still re-validates on load, never skipping the check because "it passed before"), and registers each
  declared hook with the gateway (Group E/F).
- AC-36: Plugin handler code runs in-process, in the same PHP runtime as the host application — no
  separate process, container, or sandbox is spawned by this task's runtime.
- AC-37: Loading a plugin whose manifest fails re-validation (AC-35) at runtime load time (not just at
  registration time) logs the failure, does not register any of that plugin's hooks, and does not
  prevent any other enabled plugin from loading — one bad manifest isolates to its own plugin, matching
  the circuit breaker's isolation principle (AC-32).
- AC-38: A per-tenant migration runner capability exists (`docs/database.md` §9.6): enabling a plugin
  for a tenant runs that plugin's migrations against the tenant connection, records them in that
  tenant's `plugin_migrations`, and mirrors the resulting version to `tenant_plugins.schema_version` in
  the master — proven with a fixture plugin's own migrations only (no real plugin exists, D-9).
- AC-39 (negative case): Re-running the migration runner for a plugin already at its latest recorded
  `schema_version` for a tenant changes nothing and does not error (same "no-op on nothing to do"
  convention as T-018 AC-19).

### I. Operator visibility and control

- AC-40: A console command lists plugins installed/enabled/disabled/tripped for a given tenant, showing
  enough of each row (`plugin`, `version`, `status`, `failure_count`, `tripped_at`, `last_error`) for an
  operator to decide what to do next.
- AC-41: A console command resets a tripped plugin for a tenant back to `enabled` (AC-33) — a
  deliberate operator action, never automatic.
- AC-42: A console command enables or disables a plugin for a tenant (the same transitions Group C
  describes), for use when no HTTP surface exists.

### J. No HTTP surface

- AC-43: This task ships no HTTP route and no admin UI for any registry, licensing, enablement, hook,
  or breaker-reset operation — console commands and internal handlers only, the same precedent T-016
  D-21/T-017 D-7/T-018 D-9 already established.

### K. Proof-of-pipeline fixture

- AC-44: Exactly one fixture plugin (e.g. `fixture-echo`) proves the pipeline end-to-end: a manifest
  declaring one handler per mode, installed and enabled for a fixture tenant, exercising `add`,
  `before` (once vetoing, once allowing), `filter` (transforming a value through two chained handlers),
  `override` (on a point marked overridable), and `after` (via the outbox, consumed idempotently) — all
  provable in feature tests against the stand-in event bus, with no real Redis connection required.
- AC-45: No other, business-meaningful plugin (a loyalty plugin, a commission plugin, or anything else
  naming a real extension FP-296 would own) is designed, named, or shipped by this task. Each becomes
  real the moment FP-296 ships it — the same "no hooks/handlers for modules that don't exist" convention
  T-017 D-15/T-018 AC-35 already used.

### L. Framework integrity and the gate

- AC-46: `composer check` (lint, static analysis, boundary rules, tests) exits 0 on the branch.
- AC-47: No module outside `packages/plugin-sdk` (and whichever package hosts the gateway's hook
  dispatch, if split out) gains a dependency on the plugin framework's internals — only on its
  published contract in `packages/contracts` — enforced by the existing boundary check with no new
  exemption added.
- AC-48 [ENGINE — MySQL/MariaDB]: Every migration this task ships (master and tenant) is forward-only,
  with no `down()` method, matching the project-wide convention T-008 D-8 established (no shipped
  migration anywhere in the repository has one, and `BranchForeignKeysMigrationTest` asserts this
  project-wide). Every migration runs cleanly, in order, against a real MySQL/MariaDB connection, and
  running the full set twice is a safe no-op (nothing errors, nothing is double-applied) — the same
  forward-only "mistakes are corrected by a new migration, never a rollback" discipline every other
  task's migrations already follow. NOT VERIFIED while `ENGINE_HOLD`/gate G-1 is in force; provable
  against the deterministic test engine stand-in in the meantime, following the unchanged convention
  every prior task has used (D-14).

## Out of scope

- **Any real, business-meaningful plugin** (loyalty, commission, discount-control, or anything else
  FP-296 would own) — D-13, AC-45. FP-296, Phase 6, ships the first real ones.
- **The workflow engine itself** (FP-214) — this task's `before` mode gives it what it needs (veto
  semantics) but designs no workflow-specific hook point, approval table, or condition language (D-12).
- **The frontend plugin-remote-loading mechanism** (`docs/architecture.md` §15: "plugins ship as
  separately built remotes the shell loads at runtime") — D-11, a distinct frontend-scoped concern,
  FP-213's backlog row is `BE` only.
- **Process isolation or sandboxing for plugin code** — D-7; plugin code runs in-process, matching §13
  explicitly; a stronger isolation model is additive hardening if a real plugin ever needs it.
- **An automatic, time-based circuit-breaker reset** — D-6; reset is a deliberate operator action only
  in this task; a cool-down timer is a reversible addition later.
- **A plugin marketplace, upload/signing pipeline, or package distribution mechanism** — `plugins`/
  `plugin_versions` carry `signature`/`checksum`/`signature_key_id` columns per the already-designed
  schema, but this task does not build a publishing pipeline that writes them from an upload; it only
  builds the registry that stores and validates whatever a version row already carries.
- **An HTTP route or admin UI for any part of this mechanism** (AC-43).
- **A metrics/alerting system or dashboard** for plugin timings or circuit trips (`docs/architecture.md`
  §18 names "plugin circuit trips" as something to alert on) — this task produces the raw signal
  (`tenant_plugins.status='error'`, `tripped_at`), not the alerting mechanism itself.
- **Retention/archival for `plugin_execution_logs`** beyond the documented 30-day policy staying a
  stated policy, not a built job — same caution T-017 D-12/T-018 D-13 already applied elsewhere.
- **Standing up real Redis infrastructure** — a devops/deployment concern; this task's `after` mode
  ships code behind T-018's existing interface regardless of whether that infrastructure exists yet.

## Non-functional requirements

- **One plugin's failure never blocks another plugin or another tenant** (AC-32, AC-37) — isolation is
  a correctness requirement of this framework, not an optimisation.
- **A plugin can never alter a core table** (AC-19) — enforced by this task shipping no migration that
  gives a core table a column or constraint referencing a plugin table, and by the manifest/handler
  contract giving plugin code no access path to write outside its own `plg_<plugin>_*` tables and
  `plugin_settings`.
- **At-least-once delivery for `after` hooks, never exactly-once** (AC-29) — inherited unchanged from
  T-018; every `after` handler this task's runtime registers must be idempotent by the outbox event's
  own identifier.
- **A real external dependency (Redis, MySQL) that cannot be reached fails loudly, never silently** —
  inherited unchanged from T-018 AC-4/T-016's engine-harness convention.
- **No new exposure of secret or sensitive data** — `plugin_settings.value` is encrypted at rest when
  `is_secret=true` (already a documented column); this task adds no new way for a secret to leave that
  column's existing protection.

## Assumptions

- The fixture plugin's exact name (`fixture-echo` or equivalent) and the exact file layout under
  `packages/plugin-sdk` vs. a new `packages/plugins`/`packages/pluginbus` split are `dev`'s/`architect`'s
  technical choice, following the T-003 D-33 precedent that `ba` states behaviour, not file layout.
- Default failure-threshold (5) and per-call timeout values (D-5/D-6) are this task's own reversible
  defaults, configurable later, in the absence of any stated business rule — the same convention T-016
  D-11/T-017 D-11/T-018 D-8 already used.
- `docs/database.md`'s `plugin_execution_logs.mode` enum is corrected to five values (D-4) as part of
  this task's own migration, on the basis that no migration for that table exists yet anywhere in the
  repository (measured) — not treated as an expand-and-contract change against live data.
- The `plg_<plugin>_*` naming convention, and which specific tables a plugin may create, are enforced
  by the runtime's own contract (a plugin's migrations may only create tables matching that prefix) —
  `dev`'s technical implementation of a rule `docs/database.md` line 134 already states in prose.

## Open questions

None. Every design question this task's own scoping raised was decided and logged in
`docs/decisions/T-019-plugin-framework.md` (D-1 to D-15), per the autonomous team protocol. D-14 and
D-15 were added after `architect`'s plan flagged AC-48 and AC-27 as worded in a way that conflicted
with established codebase facts; both are resolved in favour of the established convention, and the
AC text above already reflects the resolution.
