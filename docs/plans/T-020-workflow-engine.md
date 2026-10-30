# Plan: T-020 Workflow engine

Spec: `docs/specs/T-020-workflow-engine.md` (AC-1 to AC-31, groups A to I). Decisions: `docs/decisions/T-020-workflow-engine.md` (D-1 to D-12). Backlog row FP-214. Branch `ai/T-020-workflow-engine`.

**`architect` did not measure the gate baseline.** Step 0 measures it. The T-019 close-out recorded 2499 to 2500 passed, 12 failed (FP-316, already known), 21 skipped, and a test step of about 469 s. The limit is 900 s, so FP-328's headroom is about 1.92x, which is already under the 2x floor. This task adds about 110 cases (risk 2).

**One engine condition.** `ENGINE_HOLD` is on. AC-4 is the only `[ENGINE]` criterion. Step 13 writes its engine test now, and that test skips under the hold. Nothing in this task touches Redis or the outbox (D-2), so no Redis-gated criterion exists.

**Locked by `ba` and not re-decided here:**

| Decision | What is locked |
|---|---|
| D-1 | the generic engine only, proven by one fixture |
| D-2 | `WorkflowGate` is standalone, in `packages/contracts/src/Workflow`. It is never a hook handler and never runs through `HookGateway::dispatch()` |
| D-3 | fail closed: an engine error throws, and the caller must not proceed |
| D-4 | FP-338 is resolved because this task does not need it (lead has already closed the row) |
| D-5 | conditions are a flat AND of `field` / `operator` / `value`, with the operators `= != > >= < <= in not_in` |
| D-6 | multi-step chains, `approver_type` and `quorum` as documented (**see Ruling 6c: three of the seven types cannot be resolved today**) |
| D-7 | a reject sets `rejected`; `on_reject` is reported only; no business rollback |
| D-8 | the full `entity_type` vocabulary ships; there is no call site in `modules/` |
| D-9 | escalation and SLA columns are schema only; nothing reads them |
| D-10 | console only, no HTTP |
| D-11 | one fixture, `entity_type='custom'`, two steps (role/`any`, then role/`all`) |
| D-12 | the actor is always the deciding employee |

---

## 0. Rulings

Rulings marked **-> ba** read or narrow the spec. **-> ba CONFLICT** marks a place where the spec cannot be met as written. **-> lead** needs a decision from outside `architect`. The rest are technical choices the spec left open.

### Ruling 1: a new path package, `packages/workflow` (AC-13, AC-30)

The engine is a new Composer path package, `flexipos/workflow`, with the namespace `Flexipos\Workflow\`. It follows the T-014 (`organization`) and T-016 (`platform`) precedent: shared infrastructure that every later business module calls through a contract is a `packages/*` package, not a `modules/*` module booted from `MODULES`.

- **Its source reaches only** `Package_Kernel` (`RunnerClock`, `Tenancy`, `IdentifierColumn`) and `Package_Contracts`.
- **It does not reach** `Package_PluginSdk` or `Package_Access`. Anything it needs from access goes through a contract (Ruling 6).
- **Its tests also reach** `Package_Access`, for `AccessTenantFixture` and `AccessSeed` (the same precedent as the eventbus and plugin-sdk tests).
- **`Module_Demo` is not granted `Package_Workflow`.** That omission is AC-30's instrument.

Rejected:
- **Hosting the engine inside `plugin-sdk`.** That is exactly the coupling D-2 forbids.
- **A `modules/workflow` module.** Every future module calls this engine, so it is a shared package by the same rule as `organization`.

### Ruling 2: the published contract (AC-13, AC-14, AC-19, AC-21, AC-30)

All of these are new files in `packages/contracts/src/Workflow/`, in pure PHP. **None of them imports anything under `Flexipos\Contracts\Plugins`.**

| Symbol | Shape |
|---|---|
| `WorkflowEntityType` | backed string enum. The **14** documented values, in the order §6.15 gives: `expense, purchase_request, purchase_order, supplier_invoice, stock_adjustment, stock_transfer, leave_request, overtime, payroll_run, commission_period, discount, sale_void, refund, custom`. Because the column is VARCHAR(30) (Ruling 11), a future value is one enum case and needs no migration |
| `WorkflowContext` | readonly, holding `entityId`, `requestedBy`, `?branchId`, `int\|string\|null amount`, and `array<string, string\|int\|bool\|null> fields`. Detail below |
| `WorkflowGate` | the requester-side API, with three methods. See below |
| `WorkflowApprovals` | the approver-side API: `decide(string $taskId, string $actorEmployeeId, WorkflowVerdict $verdict, ?string $comment = null): WorkflowInstanceState` |
| `WorkflowOutcome` | enum with two cases: `NotRequired`, `Pending` |
| `WorkflowDecision` | readonly: `outcome`, `?instanceId`, `?definitionKey`, `?WorkflowOnReject onReject`. Helper `requiresApproval(): bool` |
| `WorkflowInstanceState` | readonly: `id`, `definitionKey`, `entityType`, `entityId`, `status`, `currentStep`, `onReject`, `startedAt`, `?completedAt`, `?int unassignedStep` (AC-12, Ruling 7) |
| `WorkflowInstanceStatus` | enum: `pending, approved, rejected, cancelled, expired` |
| `WorkflowOnReject` | enum: `stop, return_to_requester` |
| `WorkflowVerdict` | enum: `approve, reject` |
| `WorkflowEvaluationFailed` | `extends RuntimeException`. **This is the fail-closed signal** (Ruling 3). It has one named constructor per cause, and exposes `cause` as a string |
| `WorkflowActionRefused` | `extends RuntimeException`. Refusals from decide, cancel and read, with one named constructor per reason and `reason` as a string (Ruling 8) |

**The three methods of `WorkflowGate`:**

```php
public function evaluate(WorkflowEntityType $entityType, WorkflowContext $context): WorkflowDecision;
public function instance(string $instanceId): WorkflowInstanceState;
public function cancel(string $instanceId, ?string $actorEmployeeId = null, ?string $comment = null): WorkflowInstanceState;
```

**Why there is more than `evaluate()`, -> ba informational.**
- AC-19 says `on_reject` is reported on "the instance/decision read". A rejection happens after `evaluate()` has already returned, so the caller needs a read: `instance()`.
- AC-21 is the requester withdrawing a request, and the module that owns that request needs a way to do it: `cancel()`.
- AC-25 says the console decides tasks through "the same mechanism" as AC-17. Publishing `decide()` as `WorkflowApprovals` means a future approver inbox never has to import engine internals.

All of these are additive to the `evaluate()` that AC-13 names, and all live in contracts, so AC-30 still holds.

**What `WorkflowContext` checks when it is built.** Each failure throws `InvalidArgumentException`. That is a caller bug, raised before `evaluate()` is ever reached.
- `entityId`, `requestedBy` and `branchId` must be ULIDs. They are **folded to upper case**, the T-014 AC-61 convention, because ids are compared under `ascii_bin`.
- `amount` is an int, or a decimal string matching `^-?\d{1,14}(\.\d{1,4})?$`, which is what DECIMAL(18,4) can hold. **A float is refused**, so money never passes through binary floating point.
- A key in `fields` must match `^[a-z][a-z0-9_]{0,59}$`, and must not be one of the reserved keys `amount`, `branch_id`, `entity_id` or `requested_by`.
- A value in `fields` must be a string, an int, a bool or null. A float is refused here too.

`values(): array` is the flat map that conditions read: `fields + ['amount' => amount, 'branch_id' => branchId]`.

### Ruling 3: what fail-closed means in practice (AC-15, D-3)

`WorkflowEngine::evaluate()` does three things:
1. It opens one tenant transaction. If the caller already holds one, this nests as a savepoint, which matches D-2's "inside that module's own transaction".
2. It runs `EvaluateWorkflowHandler` inside that transaction.
3. **It catches every `Throwable` and rethrows it as `WorkflowEvaluationFailed`,** keeping the original as `previous`. A `WorkflowEvaluationFailed` that is already of that type is rethrown as it is.

Because the throw leaves the transaction, any partly written instance, task or event rows are rolled back.

**No code path returns `NotRequired` from a `catch`.** The only `return` of `NotRequired` sits after a full scan in which no definition matched. Step 14's source scan checks this, and step 16's mutation 1 proves it.

The named causes:

| Constructor | When |
|---|---|
| `conditionNotEvaluable(key, field, why)` | AC-6: a missing or null field; an ordering operator on a value that is not numeric; a type mismatch (table in Ruling 4) |
| `ambiguousDefinitions(entityType, priority, keys)` | AC-15's tie case (Ruling 5) |
| `definitionCorrupt(key, why)` | a stored definition fails re-validation. Definitions are re-validated on read, as T-019 Ruling 2 does |
| `approverTypeUnavailable(type)` | Ruling 6c |
| `alreadyPending(instanceId)` | Ruling 9 |
| `engineError(Throwable)` | anything else, such as a lost tenant connection or a missing table |

The contract docblock states: **a caller must treat any exception from `evaluate()` as "do not proceed".** It must never catch the exception and carry on. Step 12 shows this pattern with a fixture caller.

### Ruling 4: the condition model (AC-5 to AC-8, D-5)

**The stored shape.** `conditions` is a JSON list of objects, each `{"field": "<name>", "operator": "<op>", "value": <v>}`. The list is evaluated in stored order and ANDed. There is no other key, no nesting and no OR.

**Checks when a definition is written** (`DefinitionValidator`, Ruling 10). Each failure has its own named constructor:
- `field` matches the `fields` key pattern of Ruling 2, but **may** be `amount` or `branch_id`.
- `operator` must be one of the eight, matched exactly (`unknownOperator`).
- For `in` and `not_in`, `value` must be a **non-empty JSON list** of scalars (`listValueRequired`, AC-8).
- For every other operator, `value` must be a scalar: a string, an int or a bool (`scalarValueRequired`).
- For `>`, `>=`, `<` and `<=`, `value` must be numeric: an int or a decimal string (`numericValueRequired`).
- A float or a null is refused (`invalidField`).
- **An empty list `[]` is valid** and always matches (AC-7).

**Evaluation** (`ConditionEvaluator::matches(conditions, values): bool`, a pure function):

| Case | Result |
|---|---|
| the field is absent from `values`, or is null | throws `conditionNotEvaluable(..., 'missing')` (AC-6). It is never treated as a non-match |
| both sides numeric (an int or a decimal string) | exact decimal comparison by `DecimalComparator` (Ruling 4b) |
| ordering operator and either side is not numeric | throws `conditionNotEvaluable(..., 'not numeric')` |
| `=` or `!=` with both sides strings | an exact, case-sensitive string comparison |
| `=` or `!=` with both sides bools | `===` |
| any other mix of types (a bool against a string, and so on) | throws `conditionNotEvaluable(..., 'type mismatch')` |
| `in` / `not_in` | applies the `=` rule to each element; `not_in` is the negation of `in` |

**Ruling 4b, `DecimalComparator`.** It is a pure class of about 30 lines. It normalises the sign, strips leading zeros, pads the fraction to four digits and then compares the strings. **It needs no bcmath,** which is not a declared requirement anywhere in this repository, and it never uses a float.

**`branch_ids` acts as an implicit condition (-> ba informational).** When a definition's `branch_ids` is not null, it means `branch_id in branch_ids` and is checked before `conditions`:
- if the context's branch is not in the list, the definition does not match;
- **if the context has no branch, that is AC-6's missing-field error.** Treating a missing branch as "does not apply" would let a caller skip a branch-scoped approval by leaving the branch out, so it fails closed.
- The ids in `branch_ids` are folded to upper case when the definition is written.

**String values in `conditions` are not folded.** The engine cannot tell an id from free text. A rule about branches belongs in `branch_ids`; this is said in the `docs/database.md` note in step 15.

### Ruling 5: choosing the definition (AC-14, AC-15, AC-16) -> ba informational

1. Load the definitions where `entity_type` matches, `is_active = 1` and `deleted_at` is null. Group them by `priority` ascending.
2. Take the **lowest** priority group first. Evaluate every definition in it, in `key` order. Any evaluation error throws (Ruling 3).
3. **One match** in the group: that definition wins.
4. **Two or more matches** in the same group: throw `ambiguousDefinitions`.
5. **No match** in the group: move to the next group.
6. No group matches: return `NotRequired`. Nothing is written.

**Why two matches throw instead of falling back to id order.** The spec's Assumptions offer "id ascending" as a possible tie-break. AC-15, however, names an unresolved priority tie as a reason to throw. Throwing satisfies both AC-15 and AC-16, and fails closed on what is almost certainly a configuration mistake.

Two definitions with the same priority whose conditions do not overlap, such as one per branch, stay legal: step 3 still finds exactly one match. **Only the first matching group creates an instance** (AC-16).

### Ruling 6: resolving approvers (AC-9, AC-12, D-6)

**6a. A new access contract.** `packages/contracts/src/Access/RoleHolders.php` has two methods:

| Method | What it returns |
|---|---|
| `holdersOf(list<string> $roleIds, ?string $branchId): array<string, string>` | employee id => the role id they qualify through (the smallest matching role id), ordered by employee id |
| `actingAmong(list<string> $employeeIds): list<string>` | the given ids filtered to live, acting employees |

It is implemented by `packages/access/src/Support/DatabaseRoleHolders.php` and bound in `AccessServiceProvider`. This follows the `EmployeeDirectory` precedent: **workflow never queries `employees`, `roles` or `employee_roles` itself.**

"Acting" means `EmployeeStatus::shipped()->isActing()` and `deleted_at` is null. That keeps the one authority inside access, and **a suspended employee is never assigned a task.**

**Branch narrowing for `holdersOf`, -> ba informational:**
- An assignment with `branch_id` null (all branches) always counts.
- A branch-specific assignment counts only when it equals the instance's `branch_id`.
- **An instance with no branch counts only all-branch holders.** Letting a manager scoped to one branch approve a company-wide request would widen their authority.

**6b. The shape of `approver_ref`, -> ba.**

`approver_ref` is `{"roles": [ulid...], "employees": [ulid...]}`. Both keys are optional, and any other key is refused. The ids are folded to upper case when the definition is written. Employee ids are checked with `EmployeeDirectory::isLive()` at that point.

| `approver_type` | `approver_ref` rule | Resolved set |
|---|---|---|
| `role` | `roles` non-empty, no `employees` | `holdersOf(roles, branch)` |
| `employee` | `employees` non-empty, no `roles` | `actingAmong(employees)` |
| `any_of` | at least one of the two lists non-empty | the union of both |
| `all_of` | at least one of the two lists non-empty | the union of both |

The documents do not distinguish `any_of` and `all_of` from `quorum`. This plan reads them as "a mixed set of roles and employees", and **requires the quorum to agree with the name**:
- `any_of` requires quorum `any`;
- `all_of` requires quorum `all`;
- any other pairing is refused with `quorumContradictsApproverType`.

That is the most literal reading and it is reversible.

**Role ids are checked only for shape, not for existence.** No contract answers "does this role exist?". An unknown role resolves to an empty set, which AC-12's stall then reports. A follow-up could add a role-existence check.

**The requester is removed from the resolved set (no self-approval), -> ba.** No document states a segregation-of-duties rule. Excluding the requester is the safe direction and can be reversed. If the requester was the only holder, the set becomes empty and AC-12's stall reports it.

**Resolution happens when a step becomes active, never when the definition is written** (AC-9). Step 1 resolves inside `evaluate()`. Step k+1 resolves inside the `decide()` call that satisfies step k. Nothing is cached.

**6c. -> ba CONFLICT: three of the seven `approver_type` values cannot be resolved in this codebase.**

| Type | Data it needs | What exists (measured) |
|---|---|---|
| `manager_of_requester` | a manager on the employee | `employees` carries no manager column (T-010 D-14 trimmed it to identity and access) |
| `department_head` | departments | no `departments` table; the HR module is unbuilt |
| `branch_manager` | a manager on the branch | `branches` has no manager column. Only `locations.manager_employee_id` exists, and choosing it would invent a rule |

AC-9 and D-6 say all seven are "implemented as documented". **This plan does not invent a data source.** Instead:
- all seven values stay in the application enum and are stored as given;
- **`workflow:define` refuses the three with `approverTypeUnavailable`;**
- a row inserted by hand with one of them makes step activation throw `WorkflowEvaluationFailed::approverTypeUnavailable`, which fails closed.

The future HR task adds the resolver: an `ApproverResolver` branch in `packages/workflow` calling an HR contract. **`ba` should amend AC-9 and D-6 to say "four of seven resolvable; three refused until HR exists", or name a data source.** `architect` has not overridden either silently.

### Ruling 7: the life of an instance and the quorum rule (AC-10, AC-11, AC-12, AC-14)

```
evaluate (a definition matches) -> pending, current_step = 1, step 1 activated
pending --step k satisfied, k < last--> pending, current_step = k+1, step k+1 activated
pending --last step satisfied--> approved, completed_at stamped
pending --a step rejected--> rejected, completed_at stamped
pending --cancel--> cancelled, completed_at stamped
approved | rejected | cancelled | expired: terminal. Nothing writes `expired` (D-9)
```

**Activating a step (`StepActivator`):**
1. Resolve the approver set S (Ruling 6).
2. Insert one `workflow_tasks` row per member of S, with:
   - `assignee_employee_id`;
   - `assignee_role_id`, the qualifying role, or null;
   - `status='pending'`.
3. Write one `assigned` event per task.

**If S is empty (AC-12),** no task is written. The instance stays `pending` at that step. `WorkflowInstanceState::unassignedStep` is set to that step's sequence; it is computed as "a pending instance whose current step has zero tasks". The event that activated the step records `"unassignable_step": k` in its `data`. `workflow:history` prints "step k: no approver resolved". That is a named, reported state, not a silent stall, and it is distinct from a step whose tasks are still pending. The remedy is to cancel the instance and evaluate again.

**`QuorumRule::state(Quorum $q, int $n, int $approved, int $rejected): StepState`** is a pure function. `StepState` is one of `Open`, `Satisfied` or `Rejected`. Here `need = intdiv(n, 2) + 1`:

| Quorum | Satisfied when | Rejected when |
|---|---|---|
| `any` | approved >= 1 | rejected == n (nobody is left who could approve) |
| `all` | approved == n | rejected >= 1 |
| `majority` | approved >= need | n - rejected < need |

Worked cases, all asserted in step 7:

| n | Quorum | Votes | State |
|---|---|---|---|
| 2 | any | 1 reject | Open |
| 2 | any | 2 rejects | Rejected |
| 2 | all | 1 approve | Open |
| 2 | all | 1 reject | Rejected |
| 3 | majority | 2 approve | Satisfied |
| 3 | majority | 2 reject | Rejected |
| 3 | majority | 1 approve, 1 reject | Open |
| 4 | majority | 2 approve | Open (exactly half is not enough, per the spec's Assumption) |
| 4 | majority | 2 reject | Rejected |

### Ruling 8: recording decisions (AC-17, AC-18, AC-19, AC-20)

**`DecideWorkflowTaskHandler`, inside one tenant transaction:**

1. **Load the task.** If there is none: `unknownTask`.
2. **Load its instance with `lockForUpdate()`.** This serialises concurrent votes on MySQL; SQLite serialises writers anyway (risk 3).
3. **The instance is not `pending`:** `instanceClosed(status)` (AC-20 and AC-21). This check comes **before** the task's own status. A task left on a closed instance is `skipped`, and the instance's state is the cause a caller needs to see.
4. **The task is not `pending`:** `taskNotPending(status)` (AC-17).
5. **The actor is not `assignee_employee_id`:** `notAssignee`. An operator decides on the assignee's behalf with `--as=<assignee>`. -> ba informational: there is no operator override.
6. **Update the task** to `status`, `decided_by`, `decided_at`, `comment` and `updated_at`, `where id = ? and status = 'pending'`. **If no row is affected, refuse with `taskNotPending`.** This guards against a race even without the lock.
7. **Write an `approved` or `rejected` event** with `task_id`, `actor_employee_id` and `comment`.
8. **Count this step's tasks** with `QuorumRule::state`.
   - **Satisfied:**
     - the step's remaining pending tasks become `skipped`;
     - if there is a next step, `current_step` moves to it and that step is activated;
     - otherwise the instance becomes `approved` and `completed_at` is stamped, and a `completed` event is written with `data.status = 'approved'` (AC-11).
   - **Rejected:**
     - the remaining tasks become `skipped`;
     - the instance becomes `rejected` and `completed_at` is stamped;
     - a `completed` event is written with `data = {status: 'rejected', on_reject}` (AC-19).
     - **Nothing else happens.** No business record is touched (D-7).
   - **Open:** nothing more.
9. **Return `WorkflowInstanceState`.**

**Event mapping (AC-18).** `workflow_events.event` keeps the documented ten-value vocabulary. This task writes six of them:

| Event | Written on | `task_id` | Actor |
|---|---|---|---|
| `started` | instance created | null | the requester |
| `assigned` | per task | the task | null |
| `approved` | a vote to approve | the task | the voter |
| `rejected` | a vote to reject | the task | the voter |
| `completed` | the instance reaches a terminal approve or reject | null | the voter whose vote closed it |
| `cancelled` | cancellation | null | `--as`, or null |

**Skipped tasks are listed in the event that caused them.** A move from pending to `skipped` has no event value of its own in the documented vocabulary. Every such move is recorded in the `data.skipped_task_ids` of the one event that caused it: the closing vote, `completed` or `cancelled`. Step advances are recorded the same way, as `data.advanced_to`. **No transition is left unrecorded.**

`delegated`, `escalated`, `commented` and `expired` are **valid but unwritten** (D-9 and the out-of-scope list; a comment rides on its vote). -> ba informational.

**Writers.** `WorkflowEventLog` is the **only** writer of `workflow_events`, and it only inserts. No file updates or deletes that table (step 14's scan).

### Ruling 9: cancelling, and a second evaluate (AC-21) -> ba informational

**`CancelWorkflowInstanceHandler`:**
1. Lock the instance.
2. A status other than `pending` gives `instanceClosed(status)`.
3. Otherwise set `cancelled` and stamp `completed_at`.
4. Pending tasks become `skipped`.
5. Write a `cancelled` event with the actor, the comment and `data.skipped_task_ids`.

**A matching definition while a `pending` instance already exists for the same `(entity_type, entity_id)` throws `alreadyPending(instanceId)`.** It fails closed instead of starting a second, parallel chain. Resubmitting after a rejection or a cancellation is allowed, because the earlier instance is then terminal (D-7: "creates a new instance").

### Ruling 10: definitions are written through `workflow:define {file}` (AC-2, AC-3, AC-5, AC-7, AC-8)

The file is JSON:

```json
{
  "key": "fixture-approval",
  "name": "Proof-of-pipeline fixture - not a business workflow",
  "entity_type": "custom",
  "priority": 10,
  "on_reject": "stop",
  "is_active": true,
  "branch_ids": null,
  "escalation_hours": null,
  "conditions": [{"field": "amount", "operator": ">", "value": "1000"}],
  "steps": [
    {"sequence": 1, "name": "Fixture step one", "approver_type": "role", "approver_ref": {"roles": ["<ULID>"]}, "quorum": "any",
     "can_edit": false, "can_skip_if": null, "sla_hours": null, "escalate_to": null},
    {"sequence": 2, "name": "Fixture step two", "approver_type": "role", "approver_ref": {"roles": ["<ULID>"]}, "quorum": "all"}
  ]
}
```

**Required fields:** `key`, `name`, `entity_type`, `priority`, `on_reject`, `conditions`, `steps`, and inside each step `sequence`, `name`, `approver_type`, `approver_ref`, `quorum`.

**Optional fields and their defaults:** `is_active` true, and null or false for the rest.

**`DefinitionValidator::validate(string $json): ValidatedDefinition` throws `WorkflowDefinitionRefused`.** It runs these checks in order and stops at the first failure:
1. `malformedJson`.
2. `missingField(path)`.
3. `unknownField(path)`.
4. `invalidField(path, why)`. This covers:
   - `key` matching `^[a-z][a-z0-9]*(-[a-z0-9]+)*$`, at most 60 characters;
   - `name` non-empty, at most 100 characters;
   - `priority` an int from 0 to 32767;
   - `escalation_hours` and `sla_hours` null or an int from 1 to 32767;
   - `branch_ids` null or a non-empty list of ULIDs;
   - `can_edit` a real bool;
   - `escalate_to` null, or a JSON object or list, stored as given.
5. `unknownEntityType`, plus the enums for `on_reject`, `approver_type` and `quorum` (`unknownValue(path)`).
6. The condition rules of Ruling 4. **`can_skip_if` is validated by the same rules,** so a later reader finds it well formed. Nothing evaluates it (D-9).
7. `noSteps`; `duplicateSequence`; `sequenceGap`. Sequences must run exactly 1..n, in order (AC-3, the application half).
8. The `approver_ref` rules, `quorumContradictsApproverType` and `approverTypeUnavailable` (Ruling 6), and `unknownEmployee(id)` through `EmployeeDirectory`.

**`RegisterWorkflowDefinitionHandler`:**
- **Refuses `keyAlreadyRegistered(key)` before any insert,** counting soft-deleted and inactive rows too (AC-2). The plain `unique(key)` index is the backstop.
- Then, in one tenant transaction, inserts the definition (`version = 1`, `created_by` and `updated_by` from `--as`, which is checked with `isLive`) and its steps.

**There is no edit, deactivate or delete command** (-> lead, follow-up). Changing a definition is therefore not possible yet, which also means an instance in flight can never see its definition change underneath it.

### Ruling 11: the schema as built (AC-1, AC-4, AC-22)

These are five tenant migrations, **numbered 2026_10_30_000901 to 000905** in creation order. Each one:
- starts with `@phase expand`;
- uses `IdentifierColumn`, `dateTime(..., 3)` and `decimal(18, 4)`;
- stores enums as VARCHAR(30) with the application enums beside them (`SourceRules` AC-35(b) forbids a native `enum`, which is how T-010 and T-019 shipped every enum);
- adds `JsonValidity::enforce` for every JSON column;
- has **no `down()`** (T-008 D-8, which AC-4 itself cites).

| Table | Columns (§6.15 column for column) | Keys and indexes |
|---|---|---|
| `workflow_definitions` | `id`, `created_at`, `updated_at`, `deleted_at`, `created_by`, `updated_by`, `key` VARCHAR(60), `name` VARCHAR(100), `entity_type` VARCHAR(30), `conditions` JSON, `branch_ids` JSON NULL, `priority` SMALLINT, `on_reject` VARCHAR(30), `escalation_hours` SMALLINT UNSIGNED NULL, `is_active` BOOL default true, `plugin_name` VARCHAR(100) NULL, `version` INT UNSIGNED default 1 | `unique(key)`; index (`entity_type`, `is_active`, `priority`); FKs `created_by` and `updated_by` -> employees |
| `workflow_steps` | `id`, `definition_id`, `sequence` SMALLINT UNSIGNED, `name` VARCHAR(100), `approver_type` VARCHAR(30), `approver_ref` JSON, `quorum` VARCHAR(30), `can_edit` BOOL default false, `can_skip_if` JSON NULL, `sla_hours` SMALLINT UNSIGNED NULL, `escalate_to` JSON NULL (no timestamps, as documented) | `unique(definition_id, sequence)`; FK `definition_id` -> workflow_definitions **cascade** |
| `workflow_instances` | `id`, `created_at`, `updated_at`, `definition_id`, `definition_version` INT UNSIGNED, `entity_type` VARCHAR(30), `entity_id` CHAR(26), `branch_id` NULL, `requested_by`, `amount` DECIMAL(18,4) NULL, `current_step` SMALLINT UNSIGNED, `status` VARCHAR(30), `started_at`, `completed_at` NULL, `sla_due_at` NULL, `context` JSON | indexes (`entity_type`, `entity_id`), (`status`, `sla_due_at`), (`requested_by`); FKs `definition_id`, `branch_id` -> branches, `requested_by` -> employees |
| `workflow_tasks` | `id`, `created_at`, `updated_at`, `instance_id`, `step_sequence` SMALLINT UNSIGNED, `assignee_employee_id` NULL, `assignee_role_id` NULL, `status` VARCHAR(30), `decided_by` NULL, `decided_at` NULL, `comment` TEXT NULL, `delegated_to` NULL, `notified_at` NULL, `reminded_at` NULL | indexes (`assignee_employee_id`, `status`), (`instance_id`, `step_sequence`); FKs `instance_id` **cascade**, `assignee_employee_id`, `decided_by` and `delegated_to` -> employees, `assignee_role_id` -> roles |
| `workflow_events` | `id` BIGINT auto, `instance_id`, `task_id` NULL, `event` VARCHAR(30), `actor_employee_id` NULL, `comment` TEXT NULL, `data` JSON NULL, `created_at` | FKs `instance_id` **cascade**, `task_id` -> workflow_tasks, `actor_employee_id` -> employees |

**-> ba informational, as-built notes:**
1. **"enum" columns are VARCHAR(30) with application enums.** The vocabularies match exactly.
2. **`actor_employee_id` gets a foreign key to `employees`.** D-12 says so; the §6.15 row only marks it NULL.
3. **There is one extra index on `workflow_definitions`,** used by the evaluation read. It is additive and changes no column.
4. **There is no `delegated_by` column.** The spec's out-of-scope paragraph names it; §6.15 does not, and the documented columns win.
5. **The new foreign keys total 14.** None of them goes from an existing table to a new one, so `SourceRules` AC-34 holds.

**The tenant tree grows from 25 files to 30, and from 23 tables to 28.** `APP_REQUIRED_SCHEMA_VERSION` goes from 25 to 30, and the catalogue is republished with `php artisan schema:publish --write-fixture`.

**AC-22.** Nothing writes `sla_due_at`, `notified_at` or `reminded_at`. Nothing reads `sla_hours`, `escalate_to`, `can_skip_if` or `escalation_hours`, except to store them at definition time. There is no schedule entry, job or command for them (step 14's scan).

### Ruling 12: the console commands (AC-23 to AC-26, D-10)

Every command takes `--tenant=` (required) and enters that tenant with `Tenancy::run()` under the Serving purpose, as `audit:search` does. A refusal prints the constructor's name and message and exits 1. **No route is added.**

| Command | Slice | Output |
|---|---|---|
| `workflow:define {file} {--tenant=} {--as=}` | `RegisterWorkflowDefinition` | the id, key and step count |
| `workflow:definitions {--tenant=}` | `ListWorkflowDefinitions` (AC-23) | `key`, `name`, `entity_type`, `is_active`, `priority`, `version`, `steps`. Every non-deleted row, ordered by (`entity_type`, `priority`, `key`) |
| `workflow:pending {--tenant=} {--assignee=}` | `ListPendingWorkflowTasks` (AC-24) | `task`, `instance_id`, `definition`, `step_sequence`, `assignee`, `status`, `created_at`. Pending tasks on pending instances, ordered by (`created_at`, `id`) |
| `workflow:decide {task} {--tenant=} {--as=} {--approve} {--reject} {--comment=}` | `DecideWorkflowTask` (AC-25) | the instance's state afterwards. Exactly one of `--approve` or `--reject` is required |
| `workflow:cancel {instance} {--tenant=} {--as=} {--comment=}` | `CancelWorkflowInstance` (AC-21) | the state |
| `workflow:history {instance} {--tenant=}` | `ShowWorkflowHistory` | a header (`status`, `current_step`, `definition`, `on_reject`, the unassigned step if any), then every event in `id` order |

**There is no `workflow:evaluate` command.** `evaluate()` is the calling module's API, and the fixture harness calls the contract directly (D-8).

### Ruling 13: what is deliberately not built (-> lead, follow-up rows)

1. **No outbox event when an instance completes.** A calling module learns the outcome by calling `WorkflowGate::instance()`. The first consuming module decides whether to add a `workflow.completed` event.
2. **No command to edit, deactivate or delete a definition, and no definition versioning beyond `version = 1`.**
3. **No `audit_logs` row when a definition is created** (T-017's `AuditTrail`), and **no permission keys** for define or decide. Both are console only for now.
4. **No role-existence check for `approver_ref`** (Ruling 6b).
5. **The resolvers for `manager_of_requester`, `department_head` and `branch_manager`,** together with HR (Ruling 6c).
6. **A sixth copy of the small tenant-connection helper,** `Flexipos\Workflow\Support\TenantConnection`, in the same FP-331 family T-019 named.

---

## 1. Approach summary

1. **A new `packages/workflow`.** Modules reach it only through `Flexipos\Contracts\Workflow\*` (`WorkflowGate`, `WorkflowApprovals` and their value types). It has no edge to `plugin-sdk`, and no class under `Contracts\Plugins` is touched (D-2, AC-31).
2. **The schema §6.15 already designed is migrated as written,** as five forward-only tenant migrations.
3. **`evaluate()`:**
   - picks the first matching active definition by priority group;
   - throws on an ambiguous tie, a missing field or any engine error, rolling back its own writes (**fail closed**);
   - on a match, writes the instance, step 1's tasks and the events in one savepoint.
4. **Approvers are resolved when each step becomes active,** through a new access contract (`RoleHolders`). The requester and non-acting employees are excluded. A pure `QuorumRule` decides when a step is satisfied or rejected.
5. **`decide()` and `cancel()`** lock the instance, refuse late or duplicate votes with named reasons, and append every transition to `workflow_events` through one writer.
6. **Six console commands** give operators visibility. **One labelled fixture,** a two-step `custom` chain, proves the pipeline end to end.

## 2. Alternatives rejected

- **Running the gate as a `before` hook through `HookGateway`.** Forbidden by D-2, and fail-open by construction.
- **A ULID tie-break for equal priorities.** It silently picks one of two overlapping rules. AC-15 asks for a throw (Ruling 5).
- **bcmath or float comparison for amounts.** The first adds an undeclared extension requirement and the second is inexact (Ruling 4b).
- **Querying `employee_roles` directly from workflow.** That breaks the T-010 AC-11 limit and the `EmployeeDirectory` precedent (Ruling 6a).
- **Resolving approvers when the definition is written.** AC-9 forbids it.
- **Inventing manager, department or branch-manager data sources** (Ruling 6c).
- **Throwing when an approver set is empty.** For step 2 or later, a throw would roll back the vote that advanced the instance. A reported stall is uniform across steps (Ruling 7).
- **Native `enum` columns.** `SourceRules` AC-35(b) forbids them.

---

## 3. Files to create

`dev` writes every file. Files named `Qa*` are `qa`-accountable.

### `packages/contracts` (new)

| Path | What |
|---|---|
| `src/Workflow/{WorkflowGate,WorkflowApprovals,WorkflowEntityType,WorkflowContext,WorkflowOutcome,WorkflowDecision,WorkflowInstanceState,WorkflowInstanceStatus,WorkflowOnReject,WorkflowVerdict,WorkflowEvaluationFailed,WorkflowActionRefused}.php` | Rulings 2 and 3 |
| `src/Access/RoleHolders.php` | Ruling 6a |

### `packages/access`

| Path | What |
|---|---|
| `src/Support/DatabaseRoleHolders.php` *(new)* | Ruling 6a |
| `tests/Support/DatabaseRoleHoldersTest.php` *(new)* | step 4 |

### `packages/workflow` (all new)

| Path | What |
|---|---|
| `composer.json` | `flexipos/workflow`, version `0.1.0`, psr-4 `Flexipos\Workflow\` => `src/`, requires `flexipos/contracts` |
| `src/WorkflowServiceProvider.php` | binds `WorkflowGate` and `WorkflowApprovals` to `Engine\WorkflowEngine`, and registers the six commands under `runningInConsole()`. **No schedule** |
| `src/Conditions/{Condition,ConditionOperator,ConditionEvaluator,DecimalComparator}.php` | Ruling 4 |
| `src/Definitions/{DefinitionValidator,ValidatedDefinition,ValidatedStep,ApproverType,Quorum}.php` | Rulings 6 and 10 |
| `src/Engine/{WorkflowEngine,DefinitionSelector,ApproverResolver,StepActivator,QuorumRule,StepState,InstanceReader}.php` | Rulings 3, 5, 6 and 7 |
| `src/Support/{TenantConnection,WorkflowEventLog,WorkflowEventType,TaskStatus}.php` | Ruling 8 |
| `src/Exceptions/WorkflowDefinitionRefused.php` | Ruling 10, one named constructor per reason |
| `src/Features/{RegisterWorkflowDefinition,ListWorkflowDefinitions,EvaluateWorkflow,DecideWorkflowTask,CancelWorkflowInstance,ListPendingWorkflowTasks,ShowWorkflowHistory}/…` | each slice holds `X.php` and `XHandler.php`, plus `XCommand.php` and `XResult.php` where Ruling 12 gives it a command. Each has a mirrored `tests/Features/<X>/*Test.php` (`VerticalSliceLayoutTest`) |
| `tests/Fixtures/FixtureApproval.php` | **the one proof-of-pipeline definition.** A builder `json(string $roleOneId, string $roleTwoId, string $threshold = '1000'): string`. The header says it is a fixture and not a business workflow (AC-27, AC-28) |
| `tests/Support/workflow-fixture.php` | file-scope helpers: `workflowEnterTenant()` (enter only, never `boot()`, per the memory note), `workflowClockAt()`, `workflowDefine(array $overrides)`, `workflowSeedChain()` (seeds requester R, role one held by E1 and E2, role two held by E3 and E4, returns the ids) |
| `tests/Support/FixtureGatedAction.php` | a caller that runs its action only when the outcome is `NotRequired`, and counts the actions. It shows the fail-closed pattern for callers (Ruling 3) |
| `tests/...Test.php` | listed per step in section 6 |

### Host, `modules/host`

| Path | What |
|---|---|
| `database/migrations-tenant/2026_10_30_000901_create_workflow_definitions_table.php` … `000905_create_workflow_events_table.php` | step 3, in the order definitions, steps, instances, tasks, events. No `fx` or `tenant` token in the names |
| `tests/Engine/WorkflowSchemaEngineTest.php` | step 13 |
| `tests/Feature/QaWorkflowSourceRulesTest.php`, `tests/Feature/QaWorkflowNoHttpSurfaceTest.php` | step 14 |

Edits to files that already exist are in section 9.

## 4. Data and API changes

- **Schema:** Ruling 11. Five tenant tables, no master change, and no existing table is altered.
- **Contracts:** Ruling 2 and Ruling 6a. There are 13 new contract files.
- **Console:** Ruling 12, six commands. **HTTP: none.**
- **Configuration:** no new configuration file and no new environment key. `.env.example` changes only `APP_REQUIRED_SCHEMA_VERSION` (25 becomes 30).
- **Dependencies:** none from outside the repository. The only Composer change is the new path package `flexipos/workflow`, which moves `composer.lock` (the T-014 and T-016 precedent).

**Writes:**

| Table | Written by |
|---|---|
| `workflow_definitions`, `workflow_steps` | `RegisterWorkflowDefinitionHandler` only |
| `workflow_instances` | `EvaluateWorkflowHandler` (insert); `DecideWorkflowTaskHandler` and `CancelWorkflowInstanceHandler` (status, `current_step`, `completed_at`) |
| `workflow_tasks` | `StepActivator` (insert); the decide and cancel handlers (status, decision columns, `skipped`) |
| `workflow_events` | `WorkflowEventLog` only, and only by insert |

## 5. Boundary impact (deptrac, PHPStan, Composer)

**In `modules/host/deptrac.yaml`, with no exemption added:**

- **Paths:** add `../../packages/workflow/src` and `../../packages/workflow/tests`.
- **Layers**, split the way eventbus's are:
  - `Package_Workflow` = `^Flexipos\\Workflow\\(?!Tests\\).*`;
  - `Package_Workflow_Tests` = `^Flexipos\\Workflow\\Tests\\.*`.
- **Rulesets:**
  - `Package_Workflow: [Package_Kernel, Package_Contracts]`;
  - `Package_Workflow_Tests: [Package_Workflow, Package_Contracts, Package_Kernel, Package_Access]`;
  - `HostApp` gains `Package_Workflow`;
  - `HostTests` gains `Package_Workflow` and `Package_Workflow_Tests`.
- **`Module_Demo` is unchanged and is not granted `Package_Workflow`.** That is AC-30's instrument.
  - **Negative control (step 1):** a scratch class in `modules/demo/src` that imports `Flexipos\Workflow\Engine\WorkflowEngine` must fail `composer deptrac`. The same class importing `Flexipos\Contracts\Workflow\WorkflowGate` must pass. Delete both afterwards.
- **`Package_Workflow` is not granted `Package_PluginSdk`, `Package_Access` or `Package_Kernel_TenancyStatus`.** Deptrac proves type references only (memory note). Step 14's text scan covers string references to `Contracts\Plugins` and `HookGateway`.

**PHPStan:** add both paths to `phpstan.neon.dist`.

**Composer, in `modules/host/composer.json`:**
- `require` gains `"flexipos/workflow": "*"`;
- `autoload-dev` gains `"Flexipos\\Workflow\\Tests\\": "../../packages/workflow/tests/"`;
- then run `composer update flexipos/workflow`, **never with `--lock`**, then `composer validate --no-check-publish`.

The diff of `composer.lock` must add only the path package.

## 6. Implementation steps

Each step ends with `composer check` green from `modules/host`, compared against the gate as it stood when the step began. The delta must be zero or explained. **Before each gate run, list any `php.exe` processes that are not your own.**

The rules carried over from T-019:
- **Red-list rule.** After steps 1, 3 and 4, record the red list **before** fixing anything, and compare it with section 9. A red file that section 9 does not name means **stop and report to `architect`**.
- **Red-first rule.** Show red by leaving something out, or with a fixture. Never stub a refusal to allow.
- **No child processes.** Use `Artisan::call` in-process.
- **Vocabulary.** Describe the retired module word; never write it (`QaVocabularyRenameSweepTest`).
- **Unique basenames** across the repository (`ToolchainGroupBindingTest`). That is why the files are named `WorkflowPipelineTest` and `WorkflowTenantSchemaTest`.

- [x] **Step 0: baseline.**
  - Run `composer check` and record the passed, failed and skipped counts, the seconds the test step took, the FP-328 headroom line and the process list.
  - *(No AC.)*

- [x] **Step 1: package plumbing.**
  - Create `packages/workflow/composer.json` and an empty `WorkflowServiceProvider`.
  - Make the section 5 edits to `deptrac.yaml`, `phpstan.neon.dist` and `composer.json`, then `composer update flexipos/workflow`.
  - Register the provider in `bootstrap/providers.php` after `PluginSdkServiceProvider`.
  - Add `'workflow'` to `QaToolchainRegistryCoverageTest`'s shared-package literal.
  - **Test `tests/Support/WorkflowProviderTest.php`:** the provider is loaded.
  - Run section 5's demo negative control.
  - **Predicted red:** `QaToolchainRegistryCoverageTest`, until its literal is edited. Nothing else.
  - *(AC-30 instrument.)*

- [x] **Step 2: contracts.**
  - Create the Ruling 2 files.
  - **Tests in `packages/workflow/tests/Contracts/`:**
    - `WorkflowEntityTypeTest`: exactly 14 values, in §6.15's order (AC-1 vocabulary);
    - `WorkflowContextTest`: ids are folded to upper case; a malformed ULID, a float amount, an amount with 5 decimal places, a reserved field key and a float field are each refused; `values()` merges `amount` and `branch_id`;
    - `WorkflowDecisionTest`: `requiresApproval()` both ways.
  - *(Groundwork for AC-1, AC-5 and AC-13.)*

- [x] **Step 3: the five tenant migrations.**
  - Create the Ruling 11 files.
  - **Edits (section 9):**
    - republish the catalogue;
    - `.env.example` 25 becomes 30;
    - `Helpers.php` `tenantTreeTables()` gains five names;
    - `MigrationTreeDisciplineTest` 23 becomes 28;
    - `TenantBaselineBuildsTest`: the foreign-key inventory gains the 14 keys, and the branch-referencing list gains `workflow_instances`;
    - `QaAccessCloseOutSweepsTest` and `QaAccessSchemaShapeTest` gain `$t020`.
  - **Test `packages/workflow/tests/Schema/WorkflowTenantSchemaTest.php`** on its own SQLite stand-in connection (the T-019 shape):
    - **AC-1:** each table's sorted column list equals Ruling 11's list **exactly**, plus the nullability of the nullable columns. Never assert lengths, because SQLite reports bare types;
    - the unique keys `workflow_definitions(key)` and `workflow_steps(definition_id, sequence)`;
    - the foreign keys from `pragma foreign_key_list`;
    - **AC-2 backstop:** a raw second insert with the same `key` throws, including when the first row is soft-deleted or inactive;
    - **AC-3:** a step with an unknown `definition_id` throws (foreign keys on), and a duplicate `(definition_id, sequence)` throws. **Control:** a distinct sequence is accepted;
    - **AC-4, stand-in half:** `migrate` a second time runs nothing (no new `migrations` row, and the query log holds no `create`); `SourceRules::inspect()` reports nothing for the five files; no file declares a `down` method;
    - **AC-22:** `sla_hours`, `escalate_to`, `can_skip_if`, `sla_due_at`, `notified_at` and `reminded_at` each accept a written value and read it back;
    - invalid JSON in `conditions` is refused (`JsonValidity`).
  - **Predicted red:** the six files above, plus `SchemaCataloguePublishTest`, which the republish fixes. Anything else is a stop.
  - *(AC-1, AC-2 schema half, AC-3, AC-4 stand-in half, AC-22 schema half.)*

- [x] **Step 4: the `RoleHolders` contract in access.**
  - Create `DatabaseRoleHolders` and bind it in `AccessServiceProvider`.
  - **`DatabaseRoleHoldersTest`, using `AccessSeed`:**
    - holders of one role and of two roles, with the qualifying role being the smallest id;
    - a branch-specific assignment counts for its own branch and not for another;
    - an all-branch assignment counts for every branch;
    - **an instance with no branch counts only all-branch holders;**
    - a `suspended` employee and a soft-deleted employee are excluded. **Control:** a `probation` employee is included, because they are acting;
    - `actingAmong` filters the same way;
    - **moved value:** binding `EmployeeStatus::withActingStatuses(['active'])` drops the `probation` holder, which proves the class reads access's authority rather than a copy.
  - **Predicted red:** none. Every existing access test stays green untouched.
  - *(AC-9 resolution source.)*

- [x] **Step 5: the condition model (pure).**
  - Create `Condition`, `ConditionOperator`, `ConditionEvaluator` and `DecimalComparator`.
  - **`DecimalComparatorTest`:**
    - `"1000"` < `"1000.0001"`;
    - `"-1"` < `"0"`;
    - `"007"` == `7`;
    - `"99999999999999.9999"` against itself;
    - `"0.5"` > `"0.49"`.
  - **`ConditionEvaluatorTest`:**
    - **AC-5:** each of the eight operators true and false; two conditions ANDed where one is false gives no match;
    - **AC-6:** a missing field throws `conditionNotEvaluable`, and so does a null field. **Control:** the field present matches;
    - **AC-7:** `[]` matches any context;
    - every row of Ruling 4's table, including the type-mismatch throws, and `in` / `not_in` with mixed numeric and string elements.
  - *(AC-5 to AC-7.)*

- [x] **Step 6: definitions.**
  - Create `DefinitionValidator`, `WorkflowDefinitionRefused`, the `RegisterWorkflowDefinition` and `ListWorkflowDefinitions` slices, their commands, and `FixtureApproval`.
  - **`DefinitionValidatorTest`. Every case asserts the named constructor, never just "throws":**
    - one case per required field, through a dataset;
    - an unknown key;
    - `unknownEntityType` for `Custom` and `widget`;
    - **AC-8:** `in` with `"value": "a"`, with `{}` and with `[]` each give `listValueRequired`. **Control:** `["a"]` is accepted;
    - `>` with `"abc"` gives `numericValueRequired`; a float value gives `invalidField`;
    - **AC-3:** duplicate sequences give `duplicateSequence` and the sequence `[1, 3]` gives `sequenceGap`;
    - `noSteps`;
    - `role` with `employees` gives `invalidField`;
    - `any_of` with quorum `all` gives `quorumContradictsApproverType`;
    - **Ruling 6c:** `manager_of_requester`, `department_head` and `branch_manager` each give `approverTypeUnavailable`;
    - an employee who is not live gives `unknownEmployee`;
    - a `can_skip_if` with a bad operator is refused;
    - the real `FixtureApproval` JSON validates.
  - **`RegisterWorkflowDefinitionTest`:**
    - the rows are written exactly: `version` 1, `created_by` from `--as`, the steps in order, the ids folded to upper case;
    - **AC-2:** a second define with the same key gives `keyAlreadyRegistered`, the row counts are unchanged and the first row is unchanged. The same holds when the first row has been made inactive, and when it has been soft-deleted;
    - **AC-22:** `sla_hours`, `escalate_to` and `can_skip_if` given in the file are stored;
    - **a refusal at any stage writes nothing.**
  - **`ListWorkflowDefinitionsTest` and the command tests (AC-23):** an active row, an inactive row and a soft-deleted row. The first two are listed with their `is_active` value and the soft-deleted one is absent. Exit codes are checked.
  - *(AC-2, AC-3, AC-8, AC-22 write half, AC-23.)*

- [x] **Step 7: approvers, quorum and step activation.**
  - Create `ApproverResolver`, `QuorumRule`, `StepState`, `StepActivator`, `WorkflowEventLog`, `WorkflowEventType` and `TaskStatus`.
  - **`QuorumRuleTest` (AC-10):** every row of Ruling 7's table, plus `n=1` for each quorum.
  - **`ApproverResolverTest`:**
    - `role`, `employee`, `any_of` and `all_of` resolve as Ruling 6b says;
    - **the requester is excluded;**
    - **a role nobody holds gives an empty set;**
    - a hand-inserted `branch_manager` step throws `approverTypeUnavailable`.
  - **`StepActivatorTest`:**
    - one task and one `assigned` event per approver, with `assignee_role_id` set;
    - **AC-12:** an empty set writes zero tasks and records `unassignable_step` in the activating event's `data`.
  - *(AC-9 resolution, AC-10, AC-12.)*

- [x] **Step 8: `evaluate()`.**
  - Create the `EvaluateWorkflow` slice, `DefinitionSelector`, `WorkflowEngine::evaluate` and the bindings.
  - **`EvaluateWorkflowTest`**, using `FixtureApproval` defined for `tenant-a` with the threshold 1000:
    - **AC-14, no match:**
      - an amount of 500 returns `NotRequired` and writes zero rows in all three instance-side tables;
      - another entity type with no definition returns `NotRequired`;
      - an inactive definition and a soft-deleted definition are each ignored.
    - **AC-14, a match:** an amount of 5000 returns `Pending` with the instance id, the key and `onReject=stop`. The instance has:
      - `status='pending'`, `current_step=1`, `amount` 5000.0000;
      - `definition_version` 1;
      - `context` equal to `{"amount": "5000"}`, only the fields the rules read;
      - two step-1 tasks (E1 and E2);
      - events in the order `started`, `assigned`, `assigned`.
    - **AC-6 and AC-15:** with no amount, it throws `WorkflowEvaluationFailed` with the cause `conditionNotEvaluable`, and writes zero rows.
    - **AC-15, engine error:** after a define, `Schema::drop('workflow_events')` makes it throw with the cause `engineError` and a `QueryException` as `previous`. **There are zero instance and task rows,** which proves the rollback.
    - **AC-15, the caller:** `FixtureGatedAction`'s action counter stays at 0 for both throws. **Control:** for `NotRequired` it is 1.
    - **AC-16:** two matching definitions with priorities 10 and 20; the one with priority 10 creates the instance. **Moved value:** swap the priorities and the other one creates it.
    - **AC-15, tie:** two matching definitions at priority 10 give `ambiguousDefinitions`. **Control:** at priority 10, with one of them not matching, an instance is created.
    - **An evaluation error in the winning group throws** even when a later group would match.
    - **`branch_ids`:** another branch gives `NotRequired`; no branch gives `conditionNotEvaluable`.
    - **Ruling 9:** a second matching evaluate for the same entity gives `alreadyPending`. **Control:** after a cancel, evaluating again creates a new instance.
    - **Nested in the caller:** inside an outer tenant transaction that is rolled back, the instance disappears.
    - **AC-9, membership timing:** instance 1 is created; E5 is then assigned to role one; instance 2's step-1 tasks include E5 and instance 1's are unchanged.
  - *(AC-6, AC-9, AC-14, AC-15, AC-16.)*

- [x] **Step 9: `decide()`.**
  - Create the `DecideWorkflowTask` slice and command, and `WorkflowEngine::decide`.
  - **`DecideWorkflowTaskTest`:**
    - **AC-17:** after E1 approves, the task holds `decided_by`, `decided_at` from the fixed clock and the comment. Deciding the same task again, approve or reject, gives `taskNotPending`, and the row is unchanged.
    - **AC-11:** E1's approval satisfies step 1 (quorum `any`). E2's task becomes `skipped`, `current_step` becomes 2, the step-2 tasks for E3 and E4 are written, and the vote event's `data` holds `skipped_task_ids` and `advanced_to: 2`.
    - **AC-9:** E6 is assigned to role two **after** the instance is created but before step 1 is approved, and step 2 includes E6.
    - **Step 2 (quorum `all`):**
      - E3 approves: still pending;
      - E4 approves: `approved`, `completed_at` stamped, one `completed` event with `data.status = 'approved'`.
    - **AC-10 and AC-19:**
      - at step 1, E1 rejects and the instance stays pending (quorum `any`, E2 still to vote);
      - E2 rejects and the instance becomes `rejected`, with `completed_at` stamped and a `completed` event whose data holds `on_reject: stop`;
      - **at step 2, a single rejection from E3 rejects at once** (quorum `all`), and E4's task becomes `skipped`;
      - `instance()` reports `onReject`;
      - with `on_reject=return_to_requester`, that value is reported and nothing else changes.
    - **AC-20:** for each status `approved`, `rejected`, `cancelled` and `expired` (the last set by a direct update), deciding gives `instanceClosed`.
    - **Refusals:** a non-assignee gives `notAssignee`; an unknown task gives `unknownTask`.
    - **AC-18:** after a full approve run, the event sequence equals `started, assigned×2, approved, assigned×n, approved, approved, completed`. Every `actor_employee_id` is as Ruling 8 says, and every value lies inside the documented vocabulary.
    - **AC-12 at step 2:** if role two's holders are removed before step 1 is approved, step 1's approval leaves the instance pending, `unassignedStep = 2` and zero step-2 tasks.
  - **`DecideWorkflowTaskCommandTest` (AC-25):**
    - `--approve` and `--reject` both work, with `--comment`;
    - both flags together, or neither, exit 1 with nothing written;
    - a refusal exits 1 and prints the reason.
  - *(AC-10, AC-11, AC-12, AC-17 to AC-20, AC-25.)*

- [x] **Step 10: cancelling and the instance read.**
  - Create the `CancelWorkflowInstance` slice and command, `InstanceReader`, and `WorkflowEngine::instance` and `cancel`.
  - **`CancelWorkflowInstanceTest` (AC-21):**
    - `cancelled`, `completed_at` stamped, the pending tasks `skipped`, one `cancelled` event with the actor, the comment and the skipped ids;
    - a later decide gives `instanceClosed`;
    - cancelling again gives `instanceClosed`.
  - The `instance()` read of an unknown id gives `WorkflowActionRefused` with the reason `unknownInstance`.
  - *(AC-19 read, AC-21.)*

- [x] **Step 11: the operator reads.**
  - Create the `ListPendingWorkflowTasks` and `ShowWorkflowHistory` slices and commands.
  - **`ListPendingWorkflowTasksTest` (AC-24):**
    - every listed column is present;
    - `--assignee=E3` filters to E3's tasks;
    - decided and skipped tasks, and tasks of closed instances, are absent;
    - **another tenant's tasks are absent** (`tenant-b`).
  - **`ShowWorkflowHistoryTest`:** the header and every event in order; a stalled instance prints its unassigned step.
  - *(AC-24; operator history.)*

- [x] **Step 12: proof of pipeline.**
  - **`packages/workflow/tests/Pipeline/WorkflowPipelineTest.php` (AC-27).** The header says this is a proof-of-pipeline fixture, not a business workflow. The test:
    1. seeds the chain;
    2. runs `workflow:define` on a temporary file holding `FixtureApproval::json(...)`;
    3. runs `workflow:definitions`, which shows `fixture-approval`;
    4. calls `evaluate(custom, amount 500)` and gets `NotRequired` with no instance;
    5. calls `evaluate(custom, amount 5000)` and gets `Pending`;
    6. runs `workflow:pending` and sees E1 and E2;
    7. runs `workflow:decide` for E1 with approve, and the instance moves to step 2;
    8. E3 approves and the instance is still pending; E4 approves and it is `approved`;
    9. runs `workflow:history` and sees the full trail;
    10. **reject path:** a second entity rejected at step 1 by E1 and then E2 becomes `rejected` with `on_reject` reported;
    11. **reject path:** a third entity, rejected at step 2 by E3, becomes `rejected`.
  - *(AC-27.)*

- [x] **Step 13: the engine test, ready to lift (AC-4).**
  - **`modules/host/tests/Engine/WorkflowSchemaEngineTest.php`,** in `PluginSchemaEngineTest`'s shape, on the reserved name `flexipos_t8test_t020_tenant`:
    - **Under the hold:** `EngineOnHold`. This case is skipped once the hold is cleared.
    - **Once lifted** (skipped under the hold):
      1. apply the tenant tree;
      2. assert the five tables and their foreign keys through `information_schema`;
      3. assert that `amount` is `decimal(18,4)`;
      4. a raw insert with invalid JSON is refused;
      5. applying again is a no-op;
      6. drop the database by name through `EngineDatabase` only.
  - The skip count rises by exactly 1.
  - *(AC-4 stays NOT VERIFIED.)*

- [x] **Step 14: close-out sweeps (`qa`-accountable files, written by `dev`).**
  - **`QaWorkflowSourceRulesTest`.** It has its own helpers and calls nothing in another `Qa*` file. It asserts that each directory it scans exists. **Every rule has a planted control:**
    - **AC-13, AC-31:** no file under `packages/workflow/src` names `Flexipos\Contracts\Plugins`, `HookGateway` or `Flexipos\PluginSdk`, whether in a `use` or a string.
    - **Ruling 3:** `EvaluateWorkflowHandler.php` and `WorkflowEngine.php` contain no `catch` block whose body returns. The planted control is a catch that returns `NotRequired`.
    - **Ruling 8:** the only file inserting into `workflow_events` is `WorkflowEventLog.php`, and no file anywhere updates or deletes from `workflow_events`.
    - **AC-22:**
      - `sla_due_at`, `notified_at` and `reminded_at` are named nowhere in `packages/*/src` or `modules/*/src`;
      - `sla_hours`, `escalate_to`, `can_skip_if` and `escalation_hours` are named only by `DefinitionValidator.php` and `RegisterWorkflowDefinitionHandler.php`;
      - no `schedule` or `Schedule` reference appears under `packages/workflow/src`.
    - **AC-28 and D-8:**
      - the only definition fixture is `FixtureApproval.php`;
      - every `entity_type` literal in `packages/workflow/tests` is `custom`, except datasets named for refusal tests;
      - `packages/workflow/src` holds no definition key literal;
      - nothing under `modules/*/src` references `Flexipos\Contracts\Workflow`.
  - **`QaWorkflowNoHttpSurfaceTest` (AC-26):**
    - no registered route's URI or action matches `/workflow|approval/i`;
    - **control:** a mounted scratch `GET api/v1/workflows` is reported.
  - **AC-30:** re-run step 1's deptrac pair and record it in the `qa` report.
  - **AC-31:** `qa` records that `git diff --stat main...HEAD -- packages/plugin-sdk packages/contracts/src/Plugins` is empty, and that `HookModeTest` is unchanged and green. This is recorded, not committed as a test, because it would need a child process.
  - *(AC-13, AC-22, AC-26, AC-28, AC-30, AC-31.)*

- [x] **Step 15: docs.** Describe; do not quote retired wording.
  - **`docs/database.md` §6.15:** an "As built (T-020)" note covering:
    - the VARCHAR enums;
    - the `actor_employee_id` foreign key;
    - the extra index;
    - no `delegated_by`;
    - the shapes of `conditions`, `branch_ids` and `approver_ref`;
    - the three approver types that cannot yet be resolved;
    - that the escalation and SLA columns are not read.
  - **`docs/architecture.md` §14:** an "As built (T-020)" paragraph. **The section currently says the engine "runs as a hook handler at that point".** Per D-2 it does not: it is a standalone, fail-closed contract that modules call directly. The paragraph says so and cites D-2 and D-3.
  - *(No AC; documentation correctness.)*

- [ ] **Step 16: close-out, `qa`.**
  - **Mandatory mutations,** each shown red and then reverted:
    1. `evaluate`'s catch returns `NotRequired`. AC-15 goes red.
    2. A missing field becomes a non-match. AC-6 goes red.
    3. The validator accepts a scalar for `in`. AC-8 goes red.
    4. An empty condition list never matches. AC-7 goes red.
    5. `majority` becomes `>= n/2`. The `n=4, 2 approve` case goes red.
    6. `any` rejects on the first rejection. The AC-10 case goes red.
    7. `all` ignores a single rejection. The step-2 reject case goes red.
    8. The `where status='pending'` guard and the status check are dropped. AC-17 goes red.
    9. The closed-instance check is removed. AC-20 goes red.
    10. Priority is sorted descending. AC-16 goes red.
    11. A tie is resolved by id. The AC-15 tie case goes red.
    12. Approvers are resolved at definition time. The AC-9 timing cases go red.
    13. Cancelling writes no event. AC-21 / AC-18 goes red.
    14. The requester is not excluded. The self-approval case goes red.
    15. `branch_ids` is ignored. The branch case goes red.
  - **Then:** the diff check against section 9's edit list, and the final `composer check` with its process list and skip delta.
  - *(AC-29.)*

## 7. Test strategy

- **Unit, pure:**
  - `DecimalComparator`, `ConditionEvaluator`, `DefinitionValidator` (except the `isLive` lookup, which uses a fake `EmployeeDirectory`);
  - `QuorumRule`;
  - the contract value types.
- **Slice and engine tests:**
  - tenants come from `AccessTenantFixture`: `boot()` once per test in `beforeEach`, and only `enter()` afterwards (memory note);
  - roles and employees are seeded through `AccessSeed`;
  - `RunnerClock` is fixed;
  - no network, no real waiting, no child process.
- **Host:** `WorkflowSchemaEngineTest` (engine group, skipped) and the two `Qa*` sweeps.
- **Cost:** about 110 cases. FP-328's headroom is already below 2x, so `dev` records the test step's seconds at steps 0 and 16, and reports any step that adds more than 15 s.

| Criteria | Proved in |
|---|---|
| AC-1 | step 2 (the vocabulary); step 3 (columns and keys) |
| AC-2 | step 3 (the index backstop); step 6 (the named refusal; inactive and soft-deleted rows) |
| AC-3 | step 3 (foreign key and unique index); step 6 (`duplicateSequence`, `sequenceGap`) |
| AC-4 | **NOT VERIFIED** (engine hold). The stand-in half is step 3; the engine test is written in step 13 |
| AC-5, AC-7 | step 5 |
| AC-6 | step 5 (the evaluator); step 8 (it propagates through `evaluate`) |
| AC-8 | step 6 |
| AC-9 | step 4 (the source); step 7 (the resolver); step 8 (instance-to-instance membership change); step 9 (a step-2 change). **Three types refused (Ruling 6c, -> ba)** |
| AC-10 | step 7 (`QuorumRuleTest`); step 9 |
| AC-11 | step 9 |
| AC-12 | step 7; step 9 (step 2); step 11 (history) |
| AC-13 | step 2 (the contract); step 8; step 14 (no plugin reference) |
| AC-14, AC-16 | step 8 |
| AC-15 | step 8 (missing field, engine error, tie, the caller's counter); step 14 (no returning catch) |
| AC-17, AC-19, AC-20 | step 9; AC-19's read also in step 10 |
| AC-18 | step 9 (the exact sequence); step 10 (cancel); step 14 (one writer, append-only) |
| AC-21 | step 10 |
| AC-22 | step 3 (writable); step 6 (stored); step 14 (no reader, no schedule) |
| AC-23 | step 6 |
| AC-24 | step 11 |
| AC-25 | step 9 (command test) |
| AC-26 | step 14 (`QaWorkflowNoHttpSurfaceTest`) |
| AC-27 | step 12 (`WorkflowPipelineTest`) |
| AC-28 | step 14 |
| AC-29 | every step; step 16 |
| AC-30 | step 1's deptrac pair; re-run in step 14 |
| AC-31 | step 14 (the scan and the recorded empty diff) |

## 8. Risks and rollback

| # | Risk | Size | Mitigation |
|---|---|---|---|
| 1 | **Each tenant migration shifts pinned test sites.** Section 9 is built from the files T-019 actually touched, plus the branch-referencing list in `TenantBaselineBuildsTest` that T-019 did not touch | medium | Red-list rule after steps 1, 3 and 4; stop on anything not predicted |
| 2 | **Gate headroom** (FP-328, already about 1.92x). About 110 new cases | medium | Timings at steps 0 and 16; a 15 s flag per step. -> lead: FP-328 may need resolving first |
| 3 | **`lockForUpdate` is not exercised on SQLite.** Two simultaneous votes on an `all` or `majority` step are serialised only on MySQL | medium | The `where status='pending'` guard still prevents a double decision. The engine half is NOT VERIFIED; a concurrency engine case is a follow-up |
| 4 | **Three approver types are refused** (Ruling 6c) | medium, a spec conflict | -> ba. It fails closed, so nothing is wrongly approved |
| 5 | **A calling module learns the outcome only by reading** (no completion event) | small now, because no consumer exists | Ruling 13, follow-up |
| 6 | **Definitions are immutable** until an edit or deactivate command exists | small | Ruling 13. A row can be fixed by hand in an emergency |
| 7 | **Floats are refused for amounts and condition values,** which may surprise a future caller | small | Named refusals, documented in step 15 |
| 8 | **The gate sweeps the working tree** (memory) | small | Describe, do not quote |

**Rollback.** Everything is unpushed on the branch. Reverting it removes:
- the package and the contracts;
- the access binding;
- the five migrations;
- the republished catalogue and the `.env.example` value;
- the provider line and the Composer and lock entries;
- the deptrac and PHPStan edits.

No tenant database exists in production, so no live data is involved.

## 9. Pre-existing files legitimately edited

Each edit is listed here so that `qa` reports it as intended, not as a regression. **No assertion is weakened.**

| Step | File | Edit and cause |
|---|---|---|
| 1 | `modules/host/composer.json` | `require` `flexipos/workflow`; `autoload-dev` `Flexipos\Workflow\Tests\` |
| 1 | `modules/host/composer.lock` | `composer update flexipos/workflow` adds the path package. **Nothing else may move** |
| 1 | `modules/host/bootstrap/providers.php` | `WorkflowServiceProvider` after `PluginSdkServiceProvider` |
| 1 | `modules/host/deptrac.yaml` | two paths, two layers, two rulesets; `HostApp` and `HostTests` widened (section 5). `Module_Demo` untouched |
| 1 | `modules/host/phpstan.neon.dist` | the two `packages/workflow` paths |
| 1 | `modules/host/tests/Feature/QaToolchainRegistryCoverageTest.php` | the shared-package literal gains `'workflow'` (its published domain), and the comment names T-020 |
| 3 | `modules/host/database/catalogue/tenant_schema_versions.json` | republished with `--write-fixture` |
| 3 | `modules/host/.env.example` | `APP_REQUIRED_SCHEMA_VERSION=30` |
| 3 | `modules/host/tests/Helpers.php` | `tenantTreeTables()` gains the five `workflow_*` names; the docblock counts |
| 3 | `modules/host/tests/Feature/MigrationTreeDisciplineTest.php` | `tenantTreeTables()` `toHaveCount(23)` becomes 28; the comment names T-020 |
| 3 | `modules/host/tests/Feature/TenantBaselineBuildsTest.php` | **two** edits: (a) the exact foreign-key inventory gains the 14 keys of Ruling 11, in sorted position; (b) the `$referencing` list of tables with a key into `branches` gains `workflow_instances` |
| 3 | `modules/host/tests/Feature/QaAccessCloseOutSweepsTest.php` | `closeOutNewTables()` excludes `$t020` (the five names), the same T-017 and T-019 precedent |
| 3 | `modules/host/tests/Feature/QaAccessSchemaShapeTest.php` | the T-010 table derivation excludes `$t020` |
| 4 | `packages/access/src/AccessServiceProvider.php` | bind `RoleHolders` to `DatabaseRoleHolders` |
| 15 | `docs/database.md` §6.15 | step 15 |
| 15 | `docs/architecture.md` §14 | step 15 |

**Count:** 7 source and configuration files (`composer.json`, `composer.lock`, `providers.php`, `deptrac.yaml`, `phpstan.neon.dist`, `.env.example`, the catalogue), plus 1 package source file (`AccessServiceProvider`), plus 2 documents, plus 6 test files (`Helpers.php` included).

**Expected unchanged,** listed so that a red one is investigated, not patched:

| Files | Why each is unaffected |
|---|---|
| `QaSchemaSignatureInstrumentTest` | the tenant count is derived (`count(tenantTreeTables()) + 1`); the master count of 34 is unchanged |
| `SchemaCataloguePublishTest`, `FixtureMigrationSetsTest`, `FixtureBaselineTest`, every kernel `PlanMigrationRun*` / `MigrateTenant*` test, `AccessTenantFixtureTest` | derived through `tenantMigrationFileCount()` / `topVersion()` / `tenantTreeTables()`. The new files are `expand`, so the contract-step trap (memory) does not apply. No fixture table name starts with `workflow_` |
| `QaOrganizationTenantIsolationTest`, `QaPlatformDatabaseSeparationTest` | they read `tenantTreeTables()` as the known set, which only grows |
| `QaBranchIdFoldTest`, `QaPinSinkCaptureTest`, `QaSecretSinkCaptureTest`, `QaPinAttemptSinkTest`, `QaPinResetRuleTest`, `QaActivationCodeSecrecyTest`, `QaAccessGuardIsolationHttpTest`, `TerminalActivationHttpTest` | they scan live tables for data, and the new tables are empty in their runs. No column name looks like a secret or a PIN |
| `QaDatetimePrecisionSourceTest`, `MigrationSourceRulesTest`, `QaSourceRuleEvasionShapesTest`, `MigrationHelperAndImportRulesTest` | the new migrations follow the rules (precision 3, `decimal(18,4)`, `IdentifierColumn`, `JsonValidity`) |
| `BranchForeignKeysMigrationTest` | unrelated; the new files have no `down()` |
| `QaAuditSourceRulesTest`, `QaEventbusSourceRulesTest`, `QaPlatformSourceRulesTest`, `QaPluginSourceRulesTest`, `QaPluginNoHttpSurfaceTest` | workflow writes none of their tables, adds no `plugin.json` and adds no route |
| every `packages/plugin-sdk` test, including `HookModeTest` | untouched (AC-31) |
| `VerticalSliceLayoutTest` | it covers the 7 new slices, which must pass |
| `ConfigCacheGuardTest`, `QaVocabularyRenameSweepTest`, `QaHealthProbeResilienceTest` | `.env.example` changes one value |
| `ModuleMakeValidationTest`, `ModuleMakeDemoRegenerationTest` | `modules/demo` and the generator are untouched |
| every access test | `AccessServiceProvider` gains one binding; no behaviour changes |

## 10. Messages

**`architect` → lead.**

1. **-> ba CONFLICT (Ruling 6c).** AC-9 and D-6 say all seven `approver_type` values are resolved. Three of them (`manager_of_requester`, `department_head`, `branch_manager`) have no data source anywhere in this codebase (measured: no manager column on `employees`, no `departments` table, no manager on `branches`). The plan refuses them at definition time and fails closed at runtime. `ba` should amend AC-9 and D-6, or name a source.
2. **Rulings for `ba`, informational, none changing what a criterion asks for:**
   - Ruling 2: `WorkflowGate` also has `instance()` and `cancel()`, and a second contract `WorkflowApprovals::decide()` exists;
   - Ruling 4: amounts and condition values are never floats; `branch_ids` acts as an implicit condition, and a missing branch fails closed;
   - Ruling 5: a priority tie between two matching definitions throws (AC-15) rather than using the Assumption's id tie-break;
   - Ruling 6: branch narrowing; **no self-approval**; `any_of` / `all_of` are mixed sets whose quorum must agree with the name;
   - Ruling 7: an empty approver set is a reported stall (`unassignedStep`), not an exception;
   - Ruling 8: skipped tasks are listed in the causing event's `data`; four event values are valid but unwritten; only the assignee may decide;
   - Ruling 9: a second matching evaluate while an instance is pending gives `alreadyPending`;
   - Ruling 11: VARCHAR enums, the `actor_employee_id` foreign key per D-12, one extra index, no `delegated_by`.
3. **A documentation contradiction.** `docs/architecture.md` §14 says the engine "runs as a hook handler", which D-2 overrides. Step 15 adds an "As built" note. The spec's line "no `modules/` directory exists" is inaccurate (`modules/host` and `modules/demo` exist) but changes nothing, because no business module exists.
4. **Follow-up rows proposed (Ruling 13):**
   - a completion event for calling modules;
   - commands to edit or deactivate a definition;
   - audit rows and permission keys for define and decide;
   - a role-existence check;
   - the HR-backed approver resolvers;
   - a concurrency engine test (risk 3);
   - promoting the connection helper (now a sixth copy).
5. **The skip count rises by 1** (step 13). That is expected, not a regression.

**`architect` → `dev`.**

- Validate fully before any write, in every slice.
- `evaluate()` never returns `NotRequired` from a `catch`.
- `WorkflowEventLog` is the only writer of `workflow_events`, and it only inserts.
- Resolve approvers when a step becomes active, never earlier.
- Stop on any red file that section 9 does not name.

## Questions for the human

None that block the work. One item needs `ba`'s ruling and is routed through lead: the three approver types that cannot be resolved (section 10, item 1). The plan takes the fail-closed side until then.
