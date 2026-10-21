# Code Review: T-010

Round 1, 2026-10-21, `reviewer`, over `20f1875...HEAD` (T-010's own diff; the branch was cut from T-009's tip). Read-only; no files edited, no tests run.

## Verdict: CHANGES REQUESTED

One Critical item, an escalation bypass on AC-36 in `ClearEmployeePermission`. The fix is small. Everything else is a warning or a suggestion.

## Critical (must fix)

- R-1 `packages/access/src/Features/ClearEmployeePermission/ClearEmployeePermissionHandler.php:61` (reasoning in the docblock at :35-40). **Clearing a per-person deny restores a right, and nothing checks that the actor holds it.**
  - Scenario: the owner gives E a role containing K and denies K for E. Supervisor S holds `access.assign` but not K.
  - S cannot grant K to E directly: `SetEmployeePermission` with `allowed=true` is refused `no_escalation`.
  - S can `DELETE /employees/E/permissions/K`. The deny goes, E holds K through the role, and S has raised E above S's own rights. That is exactly what AC-36 forbids.
  - The docblock's reasoning ("the actor who may assign that role is the one AC-36 checked") is wrong: the actor clearing the deny is not the actor checked at role assignment.
  - A null actor clearing a deny also succeeds. That is the "guard permits when its input is missing" shape that D-36 / `Escalation` refuses as `actor_required`. `ClearEmployeePermissionTest.php:93` asserts this success.
  - The same flip made through `SetEmployeePermission` (deny replaced by allow) is checked, so the two routes to one outcome disagree.
  - Fix: read the row before deleting it, and when it is a deny, ask `Escalation::requireHeld($actorId, $key, $branchId)` the same question an allow asks. Then:
    - give the two existing test cases an actor (the owner);
    - add: an actor lacking K clears a deny of K → `no_escalation`, row intact, no bump, no audit row; the owner doing the same succeeds;
    - add: a null actor clears a deny → `actor_required`;
    - add: an actor lacking K clears an *allow* of K → succeeds, because removing a right raises nobody;
    - `ba` logs a D-n, because this tightens what AC-33 says about clearing.

## Warnings (should fix)

- W-1 `packages/access/src/Features/VerifyPin/VerifyPinHandler.php:121-124`: **the lockout does not bound concurrent guesses.**
  - The employee row is a plain consistent read, so N simultaneous wrong attempts all see `pin_locked_until` empty and all N are checked against the real hash before any counts.
  - The counter is atomic (`countFailure`), so AC-51's [ENGINE] wording is met. But a burst of 50 parallel guesses gets 50 tries, not 5.
  - The success path (:162) also resets the counter without checking whether a lock landed meanwhile.
  - Not reachable over the network today (AC-49, D-16). Must be fixed before FP-209 adds a till route.
  - Fix: `->lockForUpdate()` on the select at :121.
- W-2 `packages/access/src/Features/SetPin/SetPinHandler.php:66`: **any holder of `employees.set_pin` can set the owner's PIN**, and once a till verifies PINs, act at the till as the owner. Latent until FP-209. Either bound setting another person's credential (the actor holds every key the target holds, or the target holds no system role), or record the decision and file it against FP-209.
- W-3 `packages/access/src/Features/ChangeEmployeeStatus/ChangeEmployeeStatusHandler.php:79`: **reactivating a suspended owner needs only `employees.manage`.** The same shape as R-1: re-entering the acting set restores every grant the employee holds, and the actor may hold none of them. The spec does not forbid it (AC-27). Either check `Escalation` for each restored key on the entering crossing, or `ba` records that a status change is an HR action outside AC-36.
- W-4 **No rule protects an owner from a lesser administrator.**
  - A holder of `access.assign` can put per-person denies on the owner, including a deny of `access.assign` itself, and can remove a non-last owner's role.
  - The owner cannot clear their own deny (AC-34). If no one else holds `access.assign`, the shop is locked out, even though `LastOwner` (correctly, by AC-37's definition) still counts that owner.
  - Outside the spec as written. `ba` decides whether a system-role holder can be denied or demoted by someone who does not hold every key. Log it or file it.
- W-5 (ruling on QA's AC-12b / AC-74 flag): the edits to pre-existing tests are accepted.
  - `IssueTokenPairTest` and `TokenClaimsTest` were forced by `employeeId` becoming `EmployeeClaims`, and their assertions were widened, not weakened.
  - `SelectTenantTest`'s positive half moved to `QaTenantSelectionClaimsTest` and is stronger there.
  - `QaAuthHttpSurfaceTest`'s new row only adds.
  - No T-009 behaviour is weakened. AC-74's letter is broken, so the release notes should say so. Not a defect.

## Suggestions (optional)

- S-1 `packages/access/src/Support/LastOwner.php:31-41`: the InnoDB locking reasoning holds.
  - A `FOR UPDATE` over the join takes X locks on the scanned rows of `employees`, `employee_roles` and `roles`, in the same order in both transactions.
  - The waiter's current read sees the committed state, so the second transaction gets `last_owner`, not a deadlock.
  - The unstated condition: the locking read must be **the first locking statement in the transaction**. All three callers meet it today. A future caller that writes first could deadlock, which is safe (error 1213) but surfaces as a 500. Say so in the docblock.
- S-2 `packages/access/src/Support/BranchScope.php:71`: `Str::isUlid()` accepts lower case, and under `ascii_bin` `01abc…` and `01ABC…` are two branches, both for attachment and for `covers()`. Normalise with `strtoupper()`.
- S-3 `packages/access/src/Features/SyncPermissionRegistry/SyncPermissionRegistryHandler.php:163`: the owner role is found by key alone. Add `->where('is_system', true)`, and report a non-system `owner` row instead of granting it every key. This is the package's own "flag, never the key" rule.
- S-4 `modules/host/app/Http/Middleware/RequireTenantToken.php:150`: claims with `employee` but a non-integer `perm_version` count as not stale. Signed tokens can't forge this, but failing closed would match the docblock's stance everywhere else.
- S-5 `packages/access/src/Support/ClientIp.php:67` / `RequireTenantPermission.php:102`: TrustProxies is not configured, so behind `docs/deployment-vps.md`'s nginx, `access_log.ip` and `audit_logs.ip` record the proxy. Inherited from T-009 (FP-297).
- S-6 `modules/host/database/migrations-tenant/2026_10_20_000307_create_employee_branches_table.php`: `default_employee_id` is a plain `ulid()`, not `IdentifierColumn`, so on MySQL it gets `utf8mb4_unicode_ci`. Generated ids are upper case, so nothing breaks today. It is the one identifier-shaped column without `ascii_bin`.

## Done well

- **The resolver** (`DatabasePermissionResolver`):
  - one `covers()` predicate decides both the allow side and the deny side, so a deny cannot cover a scope an allow does not;
  - the deny check runs before any allow is read;
  - the refusal order is declared and argued;
  - `holdsEverywhere()` is kept off the published contract;
  - there is no cache, so AC-45 and AC-46 hold by construction.
- **Claims cannot be minted half-formed.** `EmployeeClaims` refuses to construct one, `TokenClaims::allowedNames()` derives from the same builder, and verification refuses `employee` without `perm_version`. All-or-none is enforced by types.
- **Tenant entry and refresh:**
  - every `enter()` has its `leave()` in a `finally`;
  - `TenantEmployeeClaims` gives selection and refresh one place for this;
  - a null `employee_id` opens no tenant database;
  - refresh re-reads the claims before `rotate()`, so a refused refresh leaves the token unused (D-24);
  - T-009's login, platform refresh and logout are unchanged.
- **Staleness** is checked inside `RequireTenantToken` after `enter()`. A missing or deleted employee is stale, and any version mismatch refuses.
- **PIN handling:**
  - the hasher runs once on every path, against a fixed dummy;
  - one refusal goes out, with five causes recorded only in `access_log`;
  - the digits never appear in a message, and the body is the only source;
  - `RefusePinInTheUrl` runs before the guard;
  - the single-statement lockout's assignment order is right for MySQL and MariaDB.
- **Data layer:**
  - sentinel generated columns give null-safe uniqueness;
  - a generated unique column enforces one default branch;
  - the sync never deletes and never flips scope;
  - every `perm_version` bump is one atomic statement;
  - `PermHash`, `all_branches` and "my permissions" read one `contributionsFor()`.
- **Routes:** exactly one declaration per route from the registry's constants, and exactly one `authenticated_only`.
- **Tests:** the sample assert behaviour, e.g. `LastOwnerTest`'s compiled `for update` check with its liveness case, and `ClearEmployeePermissionTest`'s deny-versus-allow fixtures.

---

## Lead's routing of round 1

- R-1 → `dev` (fix) and `ba` (decision).
- W-1 → `dev`, fix now (one clause, same pattern as `LastOwner`).
- W-2, W-3, W-4 → `ba` decides each and logs it, picking the safest reversible option.
- S-6 → `dev`, fix now: AC-4 requires identifier columns through the single helper, so this is a criterion miss, not style. No tenant database exists, so editing an unshipped migration costs nothing.
- S-1, S-3, S-4 → `dev`, fix now (a docblock sentence, one clause, one fail-closed branch).
- S-2, S-5 → filed. S-5 is already FP-297.
