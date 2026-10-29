# Code Review: T-016 (subscriptions-plans-licensing)

**Round 1 · Verdict: CHANGES REQUESTED** (1 Critical, 8 Warnings, 6 Suggestions)
**Round 2 · Verdict: CHANGES REQUESTED** (1 new Critical, R-2, introduced by the W-8 fix itself)
**Round 3 · Verdict: APPROVE** (R-2 fixed, 0 new Criticals, 1 Suggestion)

---

## Round 1

Diff range: `99da29e..e01208a`, 1 commit, 110 files changed, +5809/-14. Criteria:
AC-1 to AC-33 plus AC-2a and AC-10a. Decisions: D-1 to D-21.

### Gate, measured by the reviewer

`composer check` from `modules/host`, solo, no other pest process: exit 2, only
because of FP-316. Pint passed. PHPStan: no errors. Deptrac: 0 violations, 593
uncovered, 581 allowed. Tests: **2048 passed, 12 failed, 11 skipped, 15857
assertions**, 415.69s. The 12 failures are exactly FP-316's filed shape
(`ModulesEnvParsingTest` ×1, `QaHealthProbeResilienceTest` ×2,
`QaModuleNameValidationTest` ×3, `QaModuleRegistryContractTest` ×6). No T-016
test failed. Drift against qa's 428.64s: -12.95s. Ratio to the 900s limit:
2.17 (T-014 was 1.34 — this branch is not worse). `ENGINE_HOLD` respected
throughout; AC-28 correctly NOT VERIFIED.

### Critical

- **R-1** `QaPlatformSourceRulesTest.php:213-215`: the AC-23 scan built its own
  directory list with `dirname(__DIR__, 3)`, the same off-by-one BUG-1 had
  elsewhere, resolving to a non-existent `modules/packages/*/src` and never
  covered by the existing-directory guard. Effect: the scan only ever read
  `modules/host/app`/`routes`, and would stay green even if a package started
  using `FeatureFlags`. (The underlying AC-23 claim was still true today by
  manual grep — only the evidence was broken.)

### Warnings (all fixed in round 2)

- **W-1** Every planted-string control asserted directly on the planted
  file's contents rather than running the scanner over a directory
  containing it — proving `str_contains` works, nothing else. Also: the
  sole-writer regex couldn't match `TenantStatusSync`'s actual
  build-array-then-`update($variable)` shape.
- **W-2** The AC-16 grace-period test's fixture never entered the sweep's
  candidate set, so mutation #4 (measuring grace from the wrong date field)
  went red via unrelated fixtures, not this test.
- **W-3** The eviction test's rollback half was missing, and the commit half
  never warmed the cache first, so it couldn't distinguish an eviction from
  a cold-cache miss.
- **W-4** `withoutOverlapping()` used Laravel's 1440-minute default mutex; one
  killed sweep run could silently block enforcement for 24 hours.
- **W-5** The sweep's candidate queries grew unbounded: protected tenants
  with a past_due subscription, and tenants whose expired licence had since
  been superseded by a valid one.
- **W-6** Three reactivation handlers resolved the plan limit inside the
  tenant transaction rather than before it, an undeclared deviation from
  plan step 2 (a cold cache becomes a master round-trip under a held lock).
- **W-7** AC-6's "existing rows untouched" check wrapped only
  `config()->set(...)` (writes nothing) and ended in `expect(true)->toBeTrue()`.
- **W-8** `TenantStatusSync::reconcile()` returned early without updating
  `suspension_reason` when the target status equalled the current one but a
  different sentinel cause now applied — the field went stale, weakening
  AC-25 and corrupting the sweep's own candidate selection.

### Suggestions

- **S-1** Plan section 3 didn't list the AC-33 commands/tests — fixed in `9d8fd40`.
- **S-2** Lazy-register the scheduler via `callAfterResolving` — filed as FP-322.
- **S-3** No console command to give a tenant its first subscription — filed as FP-321.
- **S-4** Cancellation/revocation fields never cleared on reactivation — folded into the fix round.
- **S-5** `PlatformAudit` writes a fabricated `request_id` — filed as FP-323.
- **S-6** An unknown flag key re-reads the master on every call — filed as FP-324.

### Done well

`CommercialStatus::target()` follows Ruling 4's order exactly. `TenantStatusSync`
and `PlanMirror` are the only writers of `tenants.status`/`tenants.plan_id`
(confirmed by grep across all packages). Reconciliation always runs inside the
triggering transaction (AC-29). The entitlement cache mirrors the kernel's
existing pattern; eviction happens only after commit. Limits are resolved
before the transaction and checked first inside it in the create handlers.
D-14 kept T-009's login/selection/refresh handlers untouched.

---

## Round 2 — fixes only

Diff range: `e01208a..7b31609`, 21 files changed, +785/-190.

### Critical (new)

- **R-2** `TenantStatusSync.php:88-91`: the W-8 fix's branch could fire with
  a null trigger — `CommercialStatus::target()` row 8 ("no subscription
  row") returns `['status' => current, 'trigger' => null]` — and overwrite a
  real sentinel `suspension_reason` with NULL. Reachable via the shipped
  `platform:change-licence-status` command on any tenant with no
  subscription row (every tenant today, per AC-26's population): suspend for
  `licence_lapsed`, reactivate the licence, reconcile hits row 8, reason
  goes to NULL and is now unrecoverable (a null reason reads as
  manually-imposed per D-16, so no automatic rule can ever lift it).

### New Warning (non-blocking)

- **W-9** No rule exists at all to lift a licence suspension on a tenant with
  no subscription row — row 8 just preserves the status quo forever. Needs a
  product ruling from `architect`/`ba` on what status such a tenant returns
  to. Filed as FP-325.

### Round 1 items re-checked: R-1 and W-1 to W-8 all confirmed fixed by re-reading the diff.

### Gate (round 2): inconclusive

Killed by the gate's own 900s timer at 882.98s under 7 contending `php.exe`
processes from other sessions — not a code failure. No test had failed
before the cut-off.

---

## Round 3 — the R-2 fix only

Diff range: `7b31609..c043820`, 5 files (`TenantStatusSync.php` +8,
`TenantStatusSyncTest.php` +20, the QA doc, qa's agent-memory note, and the
FP-325 backlog row from `7ba16dc`).

### Verdict: APPROVE

**Fix verified by independent enumeration of all 9 return rows of
`CommercialStatus::target()`:**
- Row 0a (current status protected): never targets SUSPENDED — the W-8
  branch can't be entered.
- Row 0b (suspended, non-sentinel reason): already excluded by the
  pre-existing sentinel check.
- Rows 1-3: SUSPENDED with non-null triggers (`subscription_lapsed`,
  `past_due_grace_expired`, `licence_lapsed`) — the legitimate W-8
  sentinel-to-sentinel cases; the new clause doesn't affect them.
- Rows 4-7: non-suspended targets — branch never entered.
- Row 8: the only row combining a null trigger, a SUSPENDED target and a
  sentinel reason — exactly the R-2 bug, now guarded.

The new test reaches row 8 (active, dateless platform licence → VALID
verdict, no subscription) and asserts the reason and audit-row count stay
unchanged; removing the guard clause makes two of its assertions fail.

### Suggestion

- **S-7** The new test only catches the bug because the seeded licence
  verdict happens to be VALID; pinning that precondition with an explicit
  assertion would make the test's intent self-evident. Optional — qa's
  mutation run already proved the test goes red without the fix.

### Gate, re-measured by the reviewer

Solo run, no contending processes: **2059 passed, 12 failed, 11 skipped,
15927 assertions, 436.43s** (ratio 2.06 against the 900s limit — healthy, and
consistent with qa's view that their 634s run was pure machine-load noise).
12 failures are exactly FP-316's known cluster. The new R-2 test passed.

### Final sanity pass, `99da29e..c043820` (113 files, +6463/-18)

No `.env`, no `vendor/`, no hard-coded credentials. `composer.lock` change is
only the expected `packages/platform` path-package entry. Module boundary
changes (access/organization/auth) use only the published
`Flexipos\Contracts\Platform\*` contracts; deptrac stays at 0 violations.

### Done well

The R-2 fix is the smallest correct change: one clause, with a comment
explaining why a null trigger means "nothing to say" rather than "clear the
reason," citing D-16. The open product question (W-9) was split out as a
backlog follow-up instead of being decided without a ruling.

---

## Final verdict

**APPROVE.** Task ready to close. R-1, W-1 to W-8 and R-2 are all verified
fixed, independently, across three rounds. The only gate failures throughout
were FP-316's known, pre-existing, unrelated cluster. Open follow-ups:
FP-321 to FP-325.
