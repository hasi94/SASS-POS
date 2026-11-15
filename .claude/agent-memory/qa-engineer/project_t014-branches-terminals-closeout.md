---
name: t014-branches-terminals-closeout
description: T-014 step 17 close-out — diff-base trap, FP-316's real shape, a non-discriminating mutation, and overlapping gate runs again
metadata:
  type: project
---

T-014 (branches, warehouses, terminals) was QA'd as a resumed session, steps
0-16 already green. Step 17 (qa's nine mandatory mutations) all behaved as the
plan predicted and reverted byte-identical, confirmed with `diff` after every
one — see [[project_negative-control-every-guard]] for why that confirmation
step matters.

**Diffing a long-running branch against `main` lies when `main` is stale.**
This repo's `main` was still at the T-013 tip while T-014 branched from the
T-011 tip; `main` does not even contain `migrations-tenant/`. A `git diff
--stat main...HEAD` over tenant migrations therefore reports every pre-T-014
migration file as newly added, which would wrongly fail an AC-8-style "no
shipped file edited" check. **Always find the actual fork point** (`git
merge-base <branch-tip-candidate> HEAD`) before trusting a diff's file list,
especially on a project where tasks branch from each other's tips rather than
from `main`.

**FP-316's 12 failures are not confined to one file.** Three clean runs in one
session showed three different splits of the same 12 failures across
`QaModuleRegistryContractTest`, `QaModuleNameValidationTest`,
`QaHealthProbeResilienceTest` and `ModulesEnvParsingTest` — all module-
registry/bootstrap-adjacent. The count of 12 held every time; the named tests
did not. Treat "12 failures, rotating names, all in this cluster of files" as
the real signature of FP-316, not "always this one file" — a future qa who
sees a different file name in that cluster should not treat it as a new
defect.

**Not every plan-specified mutation is actually discriminating.** T-014 step
17 mutation 8 offered two options: key the activation throttle by the tenant-
part string instead of the tenant id, or omit the tenant lock entirely. The
first option turned out to be a no-op mutation in this suite: `tenants.code`
is unique and both the stored code and the parsed tenant part are upper-cased
the same way (D-40), so swapping one key for the other is not actually a
different key space, and no test went red. Only "omit the tenant" (the second
option) and the pre-existing unit-level mis-keyed-double control
(`ActivationThrottleTest.php`) actually prove the isolation requirement. When
a plan gives two alternative mutations for one criterion, try both — one may
be vacuous for reasons specific to this codebase's data shapes, and that is
worth reporting, not silently dropping the finding by only trying the one that
happens to work.

**I overlapped two `composer check` gate runs again**, same mistake flagged in
[[project_t010-mutation-survivors-and-harness]]. This time it was subtler: I
started one with a bare `&` inside a Bash call (intending it as a quick
backgrounding trick) without realizing the tool's own `run_in_background`
mechanism was the only one the harness tracks — then, not seeing output
appear, I launched a second run via `run_in_background` while the first was
still alive. Both finished with wildly inflated failure counts (260 and 275,
versus the true 12) from shared SQLite/storage contention, and had to be
discarded. **Lesson restated more specifically:** never use a bare `&` to
background a long gate command — only the tool's `run_in_background: true`
parameter is tracked and awaitable; and before starting any new gate run,
check for an output file or task you already started rather than assuming a
quiet terminal means nothing is running.

**An undeclared-but-benign edit to another task's file is still worth
flagging.** Found six files from T-009/T-010 edited by T-014's `dev` without
being named in the plan's section 3 or section 9 tables: `AuthRefusals.php`
(new 503 constant), `DatabasePermissionResolver.php` (AC-61's fold added to
`decide()`), `SystemRole.php` (`isHeldBy()` added for the PIN rule),
`PasswordHashingAndClockBindingTest.php` (config-key enumeration extended),
and two T-010 fixture-support files (`OwnerRoleSyncTest.php`,
`PermHashRowsTest.php`) that both needed the same branch-seeding fix
`TenantAccessKeysTest` was explicitly credited with in section 9. None
weakened an assertion or did anything destructive, but the plan's own
"red-list rule" exists so an unmeasured reader like these gets reported to
`architect`, not quietly absorbed — filed as BUG-1 (minor, process) rather than
waved through, consistent with [[project_t009-round2-ingress-and-pin-blind-spots]]'s
pattern of edits-to-check-against-the-stated-list.
