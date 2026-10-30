# T-020: Workflow engine

Backlog: FP-214, "Configurable trigger, condition and steps; approval records; drives discounts,
expenses, purchase orders, payroll and leave" (dependency FP-213, done in T-019). Decisions D-1 to
D-12 are in `docs/decisions/T-020-workflow-engine.md`. **No BLOCKING questions** — this is
autonomous/team-mode work; every open design question below was decided and logged rather than left
for the human.

Convention, following T-018/T-019: every criterion is provable on a deterministic fixture while a
real engine is unreachable, unless marked **[ENGINE]**. As in T-019, this task needs only the
**MySQL/MariaDB** engine (gated by `ENGINE_HOLD`/gate G-1, owner-controlled) — it does not ride the
plugin gateway's `after`/outbox mechanism (D-2), so no Redis-gated criterion exists in this spec.

## Problem / goal

FP-214 gives FlexiPOS one configurable approval engine — a rule naming a trigger entity, a condition
and an ordered sequence of approval steps — instead of letting each future business module (discounts,
expenses, purchase orders, payroll, leave) grow its own approval logic independently.
`docs/architecture.md` §14 states the intent in prose ("Approvals are configuration, not code... One
engine serves discounts, expenses, purchase orders, payroll and leave, rather than each module growing
its own approval logic") and `docs/database.md` §6.15 already designs the exact schema
(`workflow_definitions`, `workflow_steps`, `workflow_instances`, `workflow_tasks`, `workflow_events`).
None of it has a migration, an evaluation mechanism, or a decision-recording path today. This task
builds exactly that generic engine and proves it with one fixture scenario, not a real business
workflow — none of discounts (FP-231), expenses (FP-266), purchase orders (FP-254), payroll (FP-280s)
or leave (FP-272) exist yet, and this task does not invent their approval rules.

## Users affected

- Developers of the future modules that need an approval gate (FP-231, FP-266, FP-254, FP-280s,
  FP-272, and any other entity type the documented `entity_type` enum already names) — today they have
  no contract to call and no schema to read or write against.
- Operators, who today have no way to see or manage workflow definitions or pending approval tasks —
  this task gives them console-only (no HTTP, D-10) visibility and the ability to decide a task for
  test/fixture purposes.
- Approvers (employees acting as a role, manager-of-requester, department head, branch manager, or
  named individually) — this task records their decisions but does not build any notification or
  inbox UI for them (that is a future, module-specific concern).
- Not served by this task: no cashier, discount, expense, purchase order, payroll or leave workflow
  changes behaviour, because none of those modules exist yet and none is wired to this engine (D-8).

## Current behaviour

Re-derived from the files, not assumed:

- `docs/architecture.md` §14 states the intended design in three sentences; no code anywhere in the
  repository implements any part of it (measured: no file under `modules/` or `packages/` references
  a workflow definition, step, instance, task or condition).
- `docs/database.md` §6.15 already designs the full schema: `workflow_definitions` (key, name,
  `entity_type` enum naming thirteen real/future entities plus `custom`, `conditions` JSON, `branch_ids`,
  `priority`, `on_reject` enum(`stop`, `return_to_requester`), `escalation_hours`, `is_active`,
  `plugin_name`, `version`), `workflow_steps` (sequence, `approver_type` enum, `approver_ref` JSON,
  `quorum` enum, `can_edit`, `can_skip_if`, `sla_hours`, `escalate_to`), `workflow_instances`
  (`definition_id`, `definition_version`, `entity_type`, `entity_id`, `branch_id`, `requested_by`,
  `amount`, `current_step`, `status` enum(`pending, approved, rejected, cancelled, expired`),
  `started_at`, `completed_at`, `sla_due_at`, `context` JSON), `workflow_tasks` (per-step assignment and
  decision), `workflow_events` (an append-only audit log). **None of these tables has a migration
  anywhere in the repository** (measured: no `create_workflow_definitions_table` or similarly named
  migration exists under `modules/host/database` or `modules/host/database/migrations-tenant`).
- Several already-documented business tables already carry a `workflow_instance_id`/
  `workflow_definition_id` forward reference waiting for this engine to exist: `discounts.
  workflow_definition_id`, `expenses.workflow_instance_id` (and `expense_categories.
  workflow_definition_id`), `purchase_requests.workflow_instance_id`, `leave_types.
  workflow_definition_id`/`leave_requests.workflow_instance_id`, `overtime_records.
  workflow_instance_id`, `commission_periods.workflow_instance_id`, `stock_adjustments.
  workflow_instance_id`. None of those columns' owning migrations exist yet either (their owning
  modules are all unbuilt), so this task cannot and does not populate them — it only ensures the engine
  they will point at exists and behaves as documented.
- `packages/contracts/src/Plugins` (T-019) already ships `HookGateway`, `BeforeHook`/`Veto`/
  `VetoSignal`, `HookOutcome`/`HookVeto`. Its `before` mode is, as built, **fail-open**:
  `Gateway::runBefore()` (`packages/plugin-sdk/src/Gateway/Gateway.php`) records a throwing or
  timed-out `before` handler in `$failed` and continues the dispatch rather than treating it as a veto.
  T-019's own plan (Ruling 4, Risk 4) and backlog row FP-338 both flag this as a problem specifically
  "for FP-214" — this task does not inherit that mechanism (D-2/D-3) and resolves FP-338's concern by
  not needing it (D-4).
- No HTTP route, platform-admin authorization model, or admin UI exists anywhere in the codebase for
  any master- or tenant-side operational command (T-016 D-21, T-017 D-7, T-018 D-9, T-019 D-10) — the
  same precedent this task's own operator-visibility/decision commands follow (D-10).
- No `modules/` directory exists yet in this repository (measured) — no business module is booted from
  `MODULES` today, confirming none of discounts/expenses/purchase-orders/payroll/leave can be wired to
  this engine by this task even if it wanted to.

## User stories

- As a developer of a future module that needs an approval gate (expenses, purchase orders, leave,
  payroll, discounts, or anything else the documented `entity_type` enum names), I want to call one
  contract that tells me whether a given entity+context needs approval right now, so that I never write
  my own approval-chain logic.
- As a developer of that same future module, I want an engine failure (a bad definition, an
  unevaluable condition) to block my gated action rather than silently letting it through, so that a
  broken approval rule can never become an accidental approval.
- As a tenant administrator, I want to define a named approval rule — which entity type it applies to,
  what condition triggers it, and which ordered steps and approvers it requires — without anyone
  writing code for it.
- As an approver, I want my approve/reject decision recorded with who decided, when, and any comment,
  so that every approval chain has a permanent, auditable record.
- As an operator, I want to list pending workflow tasks and definitions for a tenant from the console,
  without reading the database by hand.

## Acceptance criteria

### A. Schema

- AC-1: Migrations exist for `workflow_definitions`, `workflow_steps`, `workflow_instances`,
  `workflow_tasks` and `workflow_events` in the tenant database, matching `docs/database.md` §6.15
  column-for-column, including the full documented `entity_type` enum (`expense, purchase_request,
  purchase_order, supplier_invoice, stock_adjustment, stock_transfer, leave_request, overtime,
  payroll_run, commission_period, discount, sale_void, refund, custom`).
- AC-2: `workflow_definitions.key` is unique; registering a second definition with the same `key`
  (active or not) is refused, never silently replacing the first.
- AC-3 (negative case): A `workflow_steps` row referencing a `definition_id` that does not exist, or
  duplicating `(definition_id, sequence)`, is refused at write time (FK constraint / unique index),
  never silently creating a gap or a duplicate step in the chain.
- AC-4 [ENGINE — MySQL/MariaDB]: Every migration this task ships is forward-only, with no `down()`
  method, matching the project-wide convention (T-008 D-8, unchanged by T-016 through T-019). Every
  migration runs cleanly, in order, against a real MySQL/MariaDB connection, and running the full set
  twice is a safe no-op. NOT VERIFIED while `ENGINE_HOLD`/gate G-1 is in force; provable against the
  deterministic test engine stand-in in the meantime, following the unchanged convention every prior
  task has used.

### B. Condition model

- AC-5: A definition's `conditions` is an ordered list of simple comparisons — `field` (a key read from
  the evaluation context), `operator` (one of `=, !=, >, >=, <, <=, in, not_in`), `value` — ANDed
  together; all must be true for the definition to match. There is no `OR`, no nested grouping, no
  expression language and no function call in this task's condition model (D-5).
- AC-6: Evaluating a condition against a context missing the field it reads is **not** treated as a
  silent non-match — it is a named evaluation error, and (per D-3/AC-14) propagates as a failure rather
  than resolving to "no approval needed."
- AC-7 (negative/edge case): A definition with an empty `conditions` list always matches (unconditional
  approval requirement) — this is legal, not an error, for a rule that always requires approval for an
  entity type regardless of amount or any other field.
- AC-8 (negative case): An `in`/`not_in` operator whose `value` is not a list is refused at
  definition-write time, with a named error — never evaluated as "matches nothing" at evaluation time.

### C. Steps, approvers, quorum

- AC-9: A definition's steps run in `sequence` order; a step's `approver_type` is one of `role,
  employee, manager_of_requester, department_head, branch_manager, any_of, all_of`, resolved against
  `approver_ref` (role id(s) or employee id(s), per type) at the moment the step becomes active — not
  pre-resolved at definition time, so a role's membership change is reflected on the next instance.
- AC-10: A step's `quorum` (`any, all, majority`) decides when that step is satisfied: `any` on the
  first approval, `all` once every resolved approver has approved, `majority` once more than half have
  approved. A single rejection vote on a step whose `quorum` is `any` or `majority` does not, by itself,
  reject the step while other approvers have not yet voted and quorum is still reachable; a rejection
  that makes quorum unreachable (e.g. `all` with one rejection, or `majority` with too many rejections
  for it to still be reached) rejects the step immediately.
- AC-11: Once a step is satisfied, the instance's `current_step` advances to the next step; once the
  final step is satisfied, the instance's `status` becomes `approved` and `completed_at` is stamped.
- AC-12 (negative/edge case): A step whose resolved approver set is empty (e.g. a `role` with no
  employee currently holding it) cannot be satisfied by any vote; this is a named condition the engine
  reports (not a silent stall), distinct from a step still waiting on a real approver.

### D. The `WorkflowGate` contract (standalone, fail-closed — D-2/D-3)

- AC-13: A published contract (`packages/contracts/src/Workflow`) exposes
  `evaluate(entityType, context): WorkflowDecision`, callable directly by any module, independent of
  the plugin gateway (`HookGateway`) — this task registers no plugin hook point and this evaluation
  path never runs through `Gateway::dispatch()`.
- AC-14: If no active, matching definition exists for the given `entityType`/`context`, `evaluate()`
  returns a decision meaning "no approval required" and creates no `workflow_instances` row. If a
  matching definition exists, `evaluate()` creates a `workflow_instances` row (`status='pending'`),
  its first step's `workflow_tasks` row(s), freezes the matched fields into `context`/`amount`, and
  returns a decision meaning "approval required, instance <id> created."
- AC-15 (negative case, D-3): If evaluation cannot complete — the definition's `conditions` cannot be
  evaluated against the given context (AC-6), the tenant connection errors, or more than one active
  definition matches the same entity with conflicting `priority` ties the engine cannot resolve
  deterministically — `evaluate()` throws rather than returning "no approval required." The calling
  module's gated action must not proceed past that exception; this is the explicit opposite of
  T-019's `before`-mode fail-open (D-2/D-3), and is the reason this task does not reuse that mechanism.
- AC-16: When two or more active definitions for the same `entity_type` match the same context,
  `priority` (ascending, lower runs first) breaks the tie deterministically; only the first matching
  definition by priority creates an instance — this task does not run multiple simultaneous instances
  for one gated action.

### E. Recording decisions

- AC-17: Approving or rejecting a `workflow_tasks` row records `decided_by` (the employee), `decided_at`,
  and an optional `comment`; a task already `approved` or `rejected` cannot be decided again (refused,
  named error) — no overwriting a recorded decision.
- AC-18: Every state transition (`started, assigned, approved, rejected, delegated, escalated,
  commented, cancelled, expired, completed`) writes one `workflow_events` row carrying the instance,
  the task (where applicable), the acting employee, and any comment — an append-only audit trail,
  matching `docs/database.md`'s documented enum exactly; no transition this task drives is left
  unrecorded.
- AC-19 (D-7): When a step is rejected, the instance's `status` becomes `rejected` and
  `completed_at` is stamped; the definition's `on_reject` value (`stop` or `return_to_requester`) is
  reported on the instance/decision read but drives no further action by this engine — undoing or
  compensating whatever the gated business action was is explicitly the calling module's
  responsibility, never this engine's (this task performs no rollback of any business record).
- AC-20 (negative case): Deciding a task for an instance that is already `approved`, `rejected`,
  `cancelled` or `expired` is refused with a named error — a completed instance's outcome cannot be
  changed by a late vote.
- AC-21: Cancelling a pending instance (e.g. the requester withdrew the underlying request) sets
  `status='cancelled'`, stamps `completed_at`, and writes a `cancelled` `workflow_events` row; no
  further task on that instance can be decided afterward (AC-20 applies to `cancelled` too).

### F. Escalation/SLA columns (schema only — D-9)

- AC-22: `workflow_steps.sla_hours`/`escalate_to`/`can_skip_if` and `workflow_instances.sla_due_at`
  and `workflow_tasks.notified_at`/`reminded_at` are present and writable per the documented schema,
  but this task ships no scheduled command, job, or notification that reads or acts on them — no
  automatic escalation, reminder, or SLA-breach behaviour exists yet. This is a deliberate scope
  boundary (D-9), not an oversight.

### G. Operator visibility (console only, no HTTP — D-10)

- AC-23: A console command lists active workflow definitions for a tenant (`key`, `name`, `entity_type`,
  `is_active`, `priority`).
- AC-24: A console command lists pending `workflow_tasks` for a tenant (optionally filtered by
  assignee), showing enough of each row (`instance_id`, `step_sequence`, `assignee`, `status`) for an
  operator or test harness to decide one.
- AC-25: A console command decides a pending task (approve/reject, with an optional comment) by its id
  — the same mechanism AC-17 describes, exposed for operator/test use where no business module exists
  yet to call `WorkflowGate` itself.
- AC-26: This task ships no HTTP route and no admin UI for any definition, instance, task, or decision
  operation — console commands only, the same precedent T-016 D-21/T-017 D-7/T-018 D-9/T-019 D-10
  already established.

### H. Proof-of-pipeline fixture (D-11)

- AC-27: Exactly one fixture scenario proves the pipeline end-to-end, explicitly labelled a
  proof-of-pipeline example and not a real business workflow: a definition on `entity_type='custom'`
  with one condition (`amount > <threshold>`) and two ordered steps (step 1: `approver_type='role'`,
  `quorum='any'`; step 2: `approver_type='role'`, `quorum='all'`). Tests exercise: a context that does
  not match the condition (`evaluate()` returns "no approval required", no instance created); a context
  that matches (`evaluate()` creates an instance at step 1); step 1 satisfied by any one approval,
  advancing to step 2; step 2 requiring every resolved approver's approval before the instance reaches
  `approved`; and a reject path at either step reaching `status='rejected'` with `on_reject` reported
  per AC-19.
- AC-28: No other, business-meaningful workflow (a real discount-approval rule, expense-approval chain,
  purchase-order chain, payroll or leave approval rule) is designed, named, or shipped by this task.
  Each becomes real the moment the module that owns that entity type (FP-231, FP-266, FP-254, FP-280s,
  FP-272, respectively) ships and calls `WorkflowGate` itself.

### I. Framework integrity and the gate

- AC-29: `composer check` (lint, static analysis, boundary rules, tests) exits 0 on the branch.
- AC-30: No module outside whichever package hosts the engine's implementation gains a dependency on
  its internals — only on `WorkflowGate`/`WorkflowDecision` in `packages/contracts` — enforced by the
  existing boundary check with no new exemption added.
- AC-31: This task adds no plugin hook point, no new `HookMode`, and no change to
  `packages/plugin-sdk`'s `Gateway`/`BeforeHook`/`Veto` behaviour — the workflow engine is additive,
  standalone infrastructure (D-2), not a modification of T-019's as-built fail-open semantics.

## Out of scope

- **Any real, business-meaningful approval rule** for discounts, expenses, purchase orders, payroll, or
  leave (D-1, D-8, AC-28) — each is wired in by the task that ships that entity's own module
  (FP-231, FP-266, FP-254, FP-280s, FP-272).
- **Reusing the plugin gateway's `before`/veto mechanism as the dispatch path** (D-2) — a standalone
  contract is used instead; this task makes no change to `packages/plugin-sdk`.
- **A full expression/scripting condition language** — D-5; a flat AND of simple comparisons is this
  task's condition model. OR/nested groups are an additive grammar extension later.
- **Escalation, SLA-breach handling, reminders, or notifications** — D-9; the documented columns ship,
  no job reads them yet.
- **Delegation of a task to another approver** (`workflow_tasks.delegated_to`/`delegated_by` and the
  `delegated` event exist in the documented schema) beyond the column/enum existing — no delegation
  workflow (who may delegate to whom, under what rule) is built by this task; a future task can use the
  same column once a business rule for it exists.
- **An HTTP route or admin UI for any part of this mechanism** (AC-26).
- **Wiring any of the already-documented forward-reference columns** (`discounts.
  workflow_definition_id`, `expenses.workflow_instance_id`, `purchase_requests.workflow_instance_id`,
  `leave_requests.workflow_instance_id`, `overtime_records.workflow_instance_id`, `commission_periods.
  workflow_instance_id`, `stock_adjustments.workflow_instance_id`) into any actual business flow — those
  columns' owning tables/modules do not exist yet.
- **Resolving FP-338** directly (closing/rewording the backlog row) — `ba` has no write access to
  `docs/backlog.md`; D-4 records that this task resolves the underlying concern and flags it for `lead`.

## Non-functional requirements

- **Fail closed, not fail open, on engine error** (D-3, AC-15) — the defining difference from T-019's
  `before`-mode plugin veto; this is a correctness requirement of the engine, not an optimisation.
- **Every decision is permanently auditable** (AC-18) — `workflow_events` is append-only; this task
  ships no mechanism to edit or delete a written event row.
- **A real external dependency (MySQL/MariaDB) that cannot be reached fails loudly, never silently** —
  inherited unchanged from T-016 through T-019's engine-harness convention.
- **No new exposure of secret or sensitive data** — this task introduces no new secret-bearing column;
  `context`/`approver_ref` JSON columns carry only business field values and identifiers already
  visible elsewhere in the tenant database.

## Assumptions

- The exact package/file layout (a new `packages/workflow` vs. hosting the engine inside
  `packages/contracts` plus a new SDK package) is `dev`'s/`architect`'s technical choice, following the
  T-003 D-33/T-019 precedent that `ba` states behaviour, not file layout.
- The fixture scenario's exact naming (`fixture-approval` or equivalent) is likewise a technical choice,
  as long as it is clearly labelled a proof-of-pipeline example per AC-27/AC-28.
- `majority` quorum (AC-10) is read as "more than half of the resolved approver set," the ordinary
  reading of the word absent a stated tie-break rule; an even-numbered approver set exactly at half is
  therefore not satisfied by half approving — this is a reversible default, not a stated business rule.
- Default behaviour for `priority` ties where two definitions still tie after ordering (identical
  priority) is covered by AC-16's requirement for deterministic resolution; the exact deterministic
  tie-break (e.g. `id` ascending) is `dev`'s implementation choice, since no document states one.

## Open questions

None. Every design question this task's own scoping raised was decided and logged in
`docs/decisions/T-020-workflow-engine.md` (D-1 to D-12), per the autonomous team protocol.
