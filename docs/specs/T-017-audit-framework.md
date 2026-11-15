# T-017: Audit framework

Backlog: FP-211, "Who changed what, before and after, in the tenant database; platform audit in the
master" (dependency FP-204, done in T-008). Decisions D-1 to D-16 are in
`docs/decisions/T-017-audit-framework.md`. **No BLOCKING questions** — this is autonomous/team-mode
work; every open business question below was decided and logged rather than left for the human.

Convention, following T-008/T-009/T-010/T-014/T-016: every criterion is provable on the SQLite
stand-in while `ENGINE_HOLD` is on, unless marked **[ENGINE]**, in which case it is deferred under the
hold and recorded NOT VERIFIED, never skipped and never PASS. **Nothing in this spec is marked
[ENGINE]**: everything it asks for is handler-level logic, a query, a scheduled job and one new
tenant migration of the ordinary shape T-008 already proves statically — none of it needs a real
server to be true.

## Problem / goal

FP-211 is not "build the audit tables" and not "write the first audit row" — both are already done,
by name, by earlier tasks:

- T-008 (FP-204) shipped the schema for both halves: `audit_logs` in the tenant baseline (its AC-23,
  AC-29) and `platform_audit_logs` in the master (its AC-1, AC-10), with the exact column lists
  `docs/database.md` §6.21 and §4.9 describe, including `request_id` and `user_agent` columns on both.
- T-010 (FP-208, D-20) built the first writer, `Flexipos\Access\Support\AuditWriter` (implementing
  `Flexipos\Contracts\Audit\AuditTrail`), and used it from every administrative handler it and T-014
  added — employees, roles, role assignments, per-person permissions, branch attachments, PINs,
  branches, warehouses, locations, terminals, settings. D-20's own text: *"FP-211 later replaces the
  writer... Not asked [of this task]: search, retention, export."*
- T-016 (FP-210) built the second writer, `Flexipos\Platform\Support\PlatformAudit`, covering
  subscription, licence, plan and feature-flag changes in the master.

So what FP-211 actually owes is what every one of those tasks explicitly declined to build, by name:

1. **The request identifier.** `docs/architecture.md` §17 states the design in one sentence: *"Audit of
   who changed what, before and after, **with the request identifier**, kept in the tenant database,
   plus a separate platform audit in the master."* Today neither writer carries one:
   `AuditWriter::insert()` hard-codes `'request_id' => null` (its own comment: *"they stay null until a
   task owns them"*), and `PlatformAudit::write()` mints a fresh random ULID with no correlation to
   anything (filed by the T-016 reviewer as FP-323: *"writes a made-up `request_id` instead of the real
   one or null"*).
2. **Search** — nothing anywhere reads `audit_logs` or `platform_audit_logs` back. Every write path
   exists; no read path does.
3. **Retention** — `docs/database.md` §9 states the tenant convention (*"`audit_logs` archived to
   `audit_logs_archive_<year>` yearly and exported"*) and §4.9 states the master one (*"Never pruned
   below 7 years"*). Neither is built; nothing archives, exports or even guards against pruning either
   table.
4. **One master-level write path that already exists and still has no audit row.** The per-tenant
   migration runner (T-008, FP-205) already records who started a run (`tenant_migration_runs.
   initiated_by`) and `docs/database.md` §4.9 names `migration.run` as a worked example of a
   `platform_audit_logs.action` value — but nothing in `packages/kernel` writes one.

This task closes those four gaps by **extending** the two writers T-010 and T-016 already built, never
duplicating or replacing their tables or their existing call sites.

## Users affected

- Platform operators / support staff, investigating a dispute or an incident ("who changed this
  setting, and when") who today have no way to read what both writers already recorded.
- `dev`/`architect` of later modules (catalog, sales, payroll, …), who will each need to call the one
  tenant writer when their own mandatory-coverage actions (price changes, discounts, refunds, voids,
  stock adjustments, payroll changes, journal entries, plugin changes — `docs/database.md` §6.21,
  `Refference/ERP.md` §24) first exist.
- The business owner / compliance: the platform's own 7-year audit floor is a stated commitment with
  nothing today enforcing or even guarding it.
- Not affected: cashiers and tills — no audit UI is built or asked for; nothing here touches the POS.

## Current behaviour

Re-derived from the files, not assumed:

- Tenant `audit_logs` (T-008 AC-23, AC-29): `id`, `actor_type`, `actor_id`, `actor_name`, `branch_id`,
  `module`, `action`, `subject_type`, `subject_id`, `before`, `after`, `changed_fields`, `ip`,
  `user_agent`, `request_id`, `created_at`. Written only by `AuditWriter` (package `access`), reached by
  other packages (`organization`) only through the `AuditTrail` contract (`packages/contracts`) — one
  writer, already proven transactional with its caller (T-014 AC-71: audit row, state change and
  `perm_version` bump share one transaction).
- Master `platform_audit_logs` (T-008 AC-1, AC-10): `id`, `actor_type`, `actor_id`, `actor_email`,
  `tenant_id`, `action`, `subject_type`, `subject_id`, `before`, `after`, `ip`, `user_agent`,
  `request_id`, `created_at`, never pruned below 7 years (§4.9's own words). Written only by
  `PlatformAudit` (package `platform`), from the subscription/licence/plan/flag handlers T-016 built.
  `ip` and `user_agent` are always null there today — deliberately, per its own docblock, because no
  write path there is HTTP-driven.
- `AuditWriter::insert()` sets `'request_id' => null` and `'user_agent' => null` unconditionally.
  `ClientIp` (package `access`) already solves the identical shape of problem for `ip`: a lazy,
  per-current-request resolver that returns null outside a request (console, queued job, test) rather
  than inventing a value — and is the pattern this task reuses rather than inventing a second one.
- `PlatformAudit::write()` sets `'request_id' => Str::ulid()->toBase32()` unconditionally (a random
  value with no correlation to anything) and `'actor_email' => null` unconditionally, even when
  `actorUserId` is given.
- No code in `packages/` or `modules/host/app` reads `audit_logs` or `platform_audit_logs` back; no
  handler, console command or route lists, filters or exports either table.
- No code archives, exports or deletes rows from either table; no scheduled job touches either table.
- `packages/kernel/src/Schema/Features/RunTenantMigrations/` and `.../MigrateTenant/` (T-008) create and
  run `tenant_migration_runs`/`tenant_migration_jobs` rows, with `initiated_by` already captured, but
  write nothing to `platform_audit_logs`.
- `docs/database.md` §6.21 also designs a **tenant** `security_events` table (`pin_bruteforce`,
  `permission_denied`, `override_used`, `after_hours_access`, `bulk_export`, `mass_delete`,
  `negative_stock_forced`). It is **not** one of the six tables T-008's AC-23 lists as shipped in the
  tenant baseline, and nothing in the repository produces any of those seven event types today. It is a
  different concern from "who changed what" (security incidents, not change history) and is addressed
  under Out of scope.
- The master already has a **different**, already-built `security_events` table (§4.1,
  `modules/host/database/migrations/2026_10_15_000204_create_security_events_table.php`) for
  authentication incidents (`lockout`, `token_reuse`, `impersonation`, …) — T-009's territory, unrelated
  to FP-211's "who changed what" scope, and not touched by this task.
- No permission key for reading audit records exists in `Flexipos\Access\Registry\AccessPermissions`
  (six keys today, none about audit). No platform-admin authorization model or HTTP surface exists for
  any master-side operation (T-016 D-21): subscription/licence/plan/flag changes ship as console
  commands and internal handlers only, and this is the precedent this task follows for reading audit
  records too.
- FP-319 (open): nothing runs Laravel's scheduler in deployment today, so T-016's own time-based job
  (grace-period/licence expiry) is dead code in production until that lands. Any scheduled job this task
  adds inherits exactly the same gap — named, not re-solved, here.

## User stories

- As an operator investigating a support ticket, I want to find every audit row for one subject (an
  employee, a role, a setting, …) or one actor, in order, so that I can answer "who changed this and
  when" without reading the database by hand.
- As an operator, I want every audit row written while one request was being handled to carry that
  request's own identifier, so that one incident's trail can be reassembled instead of guessed at from
  timestamps alone.
- As the business owner, I want audit history moved out of the hot table rather than deleted when it
  ages out, so that a dispute from last year can still be answered.
- As the business owner, I want the platform's own audit trail never pruned under seven years, so the
  record of who touched a tenant's billing, licence or database mapping survives as long as a dispute
  or the law could need it.
- As an operator, I want a tenant migration run to leave a platform-audit trace the same way a plan
  change does, so "who deployed what, and when" is answerable the same way for schema changes as for
  commercial ones.
- As a developer of a future module, I want one documented writer to call when my feature performs a
  mandatory-coverage action, so that I do not invent a second audit mechanism when my module ships.
- As a shop owner, I want reading the audit trail to be its own permission, separate from managing
  employees or roles, so that I can let a supervisor administer staff without handing them the shop's
  full change history.

## Acceptance criteria

### A. Tenant audit — closing the two gaps in the existing writer

- AC-1: `AuditWriter` gains a `request_id` value exactly as `ClientIp` already supplies `ip`: a lazy,
  per-current-request resolver (null outside a request — console, queued job, package test — never a
  fabricated value). Every `audit_logs` row written while one HTTP request is being handled carries the
  same, non-null identifier; two rows written during two different requests carry two different ones;
  a console-invoked write carries null. Control: the existing `ip`-resolution tests' "null outside a
  request" case is the template this reuses, named so a reviewer checks the two behave alike.
- AC-2: `AuditWriter` gains a `user_agent` value with the identical lazy/null-outside-a-request
  treatment as `ip` (currently also hard-coded null). Control: a request with no `User-Agent` header
  writes null, not an empty string.
- AC-3 (non-regression): every administrative action T-010 and T-014 already wired — employee create,
  update, delete and status change; role create, rename, delete; role assignment and removal; employee
  permission set and clear; branch attachment and detachment; PIN set; branch, warehouse, location and
  terminal create, update, status change, activation, revocation and activation-code reissue; setting
  set and clear — still writes exactly one `audit_logs` row through the one existing writer, with
  `before`/`after`/`changed_fields`/`actor`/`branch`/`module`/`action` unchanged in shape. This task
  deletes or weakens none of T-010's or T-014's audit assertions (`AuditWriterTest`,
  `AuditTransactionTest`, and every feature test that asserts an audit row). Evidence: diff review (no
  existing audit-assertion test is removed) plus a full test run.

### B. Tenant audit — reading audit records

- AC-4: An internal query capability lists `audit_logs` rows filtered by any combination of
  (`subject_type`, `subject_id`), `actor_id`, `module`, `action` and a `created_at` range, ordered
  newest-first, paginated with a configured maximum page size (same clamping convention as T-014 D-28's
  employee/branch lists: a page size above the maximum is clamped, not refused). It never performs a
  cross-database join and never reads the master connection (topology rule 2, `docs/database.md` §2) —
  a tenant-local query only.
  - **Empty case:** a filter set matching no rows returns an empty list, not an error.
  - **Invalid-filter case:** a `created_at` range whose end precedes its start, or a `module`/`action`
    value that names nothing any registered writer has ever used, is refused with a named validation
    error (same "a typo is caught loudly" convention as T-016 AC-21), not silently returned as an empty
    list.
- AC-5: Reading audit records requires a dedicated permission, distinct from every existing
  administrative permission (this spec names the key `audit.read` — D-8), checked through the same
  resolver T-010 built. An actor holding every one of the six existing `access` permission keys but not
  this one is refused; granting it, and nothing else, allows the read. Proved against the resolver and
  fixture roles the same way T-010's own permission tests are, not through a production seeding flow —
  none exists yet, because tenant provisioning (FP-206) is not built (same bounded-proof convention as
  T-016 D-19/AC-8).
- AC-6: This task ships no HTTP route and no admin UI for reading audit records — an internal
  handler/console command only, following T-016 D-21's precedent that no platform-admin authorization
  model or HTTP surface exists anywhere in the codebase yet. A follow-up row for an HTTP surface rides
  whatever later task first gives the platform an admin UI (same deferral T-016 already recorded for
  its own operations).

### C. Tenant audit — retention and archival

- AC-7: A new tenant migration (expand phase, ordinary shape — conforms to every existing static rule
  T-008 already enforces: explicit `decimal` precision and scale where relevant, no native `enum`, no
  bare `timestamp`, no charset/collation/engine literal outside the shared identifier helper) creates
  `audit_logs_archive`, column-for-column identical to `audit_logs`. **Not** a year-suffixed table
  created at runtime (`audit_logs_archive_<year>`, which is `docs/database.md` §9's literal wording) —
  D-10 records why and flags the document line as the thing to correct, not the thing to match.
- AC-8: A scheduled job moves `audit_logs` rows older than the configured retention threshold (default
  365 days, D-11) into `audit_logs_archive`, in bounded chunks — never one single `INSERT`/`DELETE` pair
  — mirroring the chunked-deletion convention `docs/database.md` §9 already states for `sync_inbox`,
  `outbox_events` and `tenant_backups`. After a run: the live table's matching rows are gone, the
  archive holds them with every column byte-identical (including `before`/`after`/`changed_fields`
  JSON), and no row exists in both tables at once.
  - **Boundary case (injectable clock, no real wait — same convention as T-009 AC-19/T-016 AC-11):** a
    row exactly 365 days old is archived; one written a day later (364 days old) is not.
  - **No-op case:** running the job against a tenant with nothing past the threshold changes neither
    table and does not error.
- AC-9: The query capability of AC-4 can also search `audit_logs_archive`, as an explicit, separate
  call rather than a transparent union of both tables on every query — D-9 records why (an invisible
  per-query cost over a cold table is exactly what `docs/patterns.md` §5 asks a projection to earn with
  a measurement, and this task has none).
- AC-10: This task builds no pruning (hard-delete) job for `audit_logs`, `audit_logs_archive` or
  `platform_audit_logs`. The business has stated a floor for the platform table (never below 7 years)
  and an archive for the tenant table, never a ceiling; D-12 records this as a deliberate omission, not
  a gap QA should file.
- AC-11: A guard asserts that nothing in the diff ever issues a `DELETE` against `platform_audit_logs`
  for a row less than 7 years old (restating `docs/database.md` §4.9's own stated rule as a checked
  fact rather than only a comment). Control, so the guard is shown to discriminate rather than always
  passing vacuously: a fixture table that is not `platform_audit_logs` is deletable in the same test
  run.

### D. Platform audit — extending `PlatformAudit` (not duplicating T-016)

- AC-12 (closes FP-323): `PlatformAudit::write()`'s `request_id` is the real identifier of whatever
  caused the write — resolved lazily, exactly as AC-1 does for the tenant writer — never a freshly
  minted, uncorrelated ULID. A console-triggered write (for example T-016's own scheduled
  deadline-enforcement job) carries null, not a random value; two rows written while handling one HTTP
  request (if any master-side route is ever added) carry the same identifier.
- AC-13: `PlatformAudit::write()`'s `actor_email` is populated as a snapshot of the acting user's email
  at write time when an `actorUserId` is given — taken once, never joined later (same reasoning as
  `AuditWriter::actorName()`: a user's email may change or the row may later be deleted, and an audit
  line whose meaning changes when somebody edits their profile is not an audit line). It stays null for
  a system-initiated write (no actor id), matching the existing `actor_type` rule.
- AC-14: The per-tenant migration runner (T-008/FP-205) writes exactly one `platform_audit_logs` row
  per migration run, through the extended `PlatformAudit`: `action` = `migration.run`, `subject_type` =
  `tenant_migration_run`, `subject_id` = the run's id, `tenant_id` = null for a bulk/selected/canary run
  or the one tenant's id when the run names exactly one tenant, `actor_user_id` from
  `tenant_migration_runs.initiated_by`, `before` = null, `after` = a snapshot of the run's target
  version, mode and selector. Written once, at the point the run is created — not duplicated when
  individual jobs finish, and not written again on retry of the same run.
- AC-15: No other master-level action gains a producer in this task: `tenant.created`,
  `mapping.updated`, `credential.rotated`, `impersonation.started` and `plugin.enabled` (the remaining
  examples `docs/database.md` §4.9 names) all stay unaudited, because none has a write path yet — tenant
  provisioning (FP-206), credential rotation, impersonation and the plugin framework (FP-213) are none
  of them built. Stated so `qa` does not look for a row nothing in the branch can produce (D-5).

### E. Platform audit — reading records

- AC-16: An internal query capability lists `platform_audit_logs` rows filtered by `tenant_id`,
  `actor_id`, `action`, (`subject_type`, `subject_id`) and a `created_at` range, with the same
  pagination, empty-case and invalid-filter behaviour as AC-4.
- AC-17: Reading `platform_audit_logs` requires the caller to be platform staff
  (`users.is_platform_staff`) — the one distinction the master schema already draws between a platform
  operator and a tenant-side user (T-008 AC-2). Ships as an internal handler/console command only, same
  precedent as AC-6 and T-016 D-21: no HTTP route, no platform-admin authorization model.

### F. Export

- AC-18: The query capabilities of AC-4 and AC-16 can each write their result set to a local file (JSON
  or CSV, caller's choice) from a console command, bounded by the same maximum page size as the live
  query. No integration with object storage, email or any delivery mechanism is built — none exists in
  this codebase for any table yet (D-13): `tenant_backups.location` is itself a designed column with no
  shipped integration behind it.

### G. Framework integrity and the gate

- AC-19: The one writer (`AuditWriter`) remains the only inserter into `audit_logs` anywhere in the
  diff, reachable by any module through the existing `AuditTrail` contract. Evidence: a source scan for
  a second `->insert()`/`->table('audit_logs')` call site outside `AuditWriter` finds none; a reviewer
  rejects a new module's own ad hoc insert on sight.
- AC-20: `PlatformAudit` remains the only inserter into `platform_audit_logs` anywhere in the diff,
  including from the migration runner's new call site (AC-14) — the runner calls the existing class, it
  does not gain a second one.
- AC-21: `composer check` (lint, static analysis, boundary rules, tests) exits 0 on the branch, with
  every audit-related test that existed at the T-016 tip still present and none weakened (diff review,
  same convention as T-008 AC-64).

## Out of scope

- **The audit tables themselves and the base writers** (T-008, T-010, T-016) — already built; this task
  extends them.
- **Mandatory-coverage action types with no module yet**: price changes, discounts, refunds, voids,
  stock adjustments, payroll changes, journal entries and plugin changes (`docs/database.md` §6.21,
  `Refference/ERP.md` §24). Each becomes this task's writer's business the moment its owning module
  ships (D-15) — FP-211 does not pre-build hooks for modules that do not exist.
- **Tenant `security_events`** (`docs/database.md` §6.21) — a different table from `audit_logs`
  (security incidents, not change history), not in T-008's shipped baseline, and every one of its seven
  event types has no producer today (D-6). A later row, not this one.
- **Master `security_events`/`login_history`** (T-009) — a different, already-built mechanism for
  authentication incidents, untouched by this task.
- **`audit_logs.branch_id`'s missing foreign key** (FP-313) — already assigned to the branches/
  organization module with the data-clean pre-check pattern; unchanged by this task.
- **An HTTP route, an admin UI, or a platform-operator authorization model** for reading either audit
  table (AC-6, AC-17; D-7) — none exists for any master-side operation yet (T-16 D-21's precedent); a
  follow-up once one is built for any reason.
- **Deleting (pruning) audit history** — no ceiling has been asked for on either table (AC-10, D-12).
- **A real request-id propagation framework for structured application logs in general**
  (`docs/architecture.md` §18) — this task wires a request identifier into audit rows only; a
  codebase-wide correlation-id/observability effort is a separate, larger concern.
- **Running the scheduler in production** (FP-319) — the archival job (AC-8) depends on the same
  scheduler T-016's grace-period job already depends on; the deployment gap is named, not re-solved,
  here.
- **MySQL 8.4 / cross-engine comparison** (FP-194) — nothing in this task needs it: no criterion is
  marked **[ENGINE]**.

## Non-functional requirements

- **No secret ever reaches an audit row.** Already true by construction of the existing writers
  (`SettingsAudit`'s `is_set`-only rule for secret settings; the general PIN/credential scan T-010's
  AC-18-family instrument already runs over every column of every table) — this task adds no new
  payload shape that could regress it, and the query/export capabilities (AC-4, AC-16, AC-18) return
  only what the writer already permitted into `before`/`after`.
- **No query added by this task crosses the master/tenant boundary** (AC-4, AC-7, AC-16) — consistent
  with `docs/database.md` §2's topology rule and T-016 AC-32's convention.
- **Chunked, never a single large statement**, for the archival move (AC-8) and the export (AC-18) —
  consistent with the existing retention conventions for `sync_inbox`, `outbox_events` and
  `tenant_backups`.

## Assumptions

- The permission key named in AC-5 (`audit.read`) is a name this spec proposes for concreteness; which
  package's registry declares it is `architect`'s/`dev`'s call, not a business rule.
- "Platform staff" (AC-17) is read from the existing `users.is_platform_staff` column; no new identity
  concept is introduced.
- The retention threshold (365 days, D-11) and the archive-table design (D-10) are this task's own
  reversible defaults where `docs/database.md` either stated something this codebase's own rules cannot
  build as literally worded (the year-suffixed table) or stated a cadence ("yearly") without a number.

## Open questions

None. Every question this task's own scoping raised was decided and logged in
`docs/decisions/T-017-audit-framework.md` (D-1 to D-16), per the autonomous team protocol.
