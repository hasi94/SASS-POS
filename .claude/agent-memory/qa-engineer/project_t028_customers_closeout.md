---
name: project_t028_customers_closeout
description: T-028 Customers rounds 1-2 — the enum-doesn't-match-the-spec's-named-value defect pattern, a long-running background gate's health-check technique, and the exit-code-capture trap.
metadata:
  type: project
---

T-028 (Customers: groups, credit ledger, loyalty, FP-232): round 1 FAIL on one real
defect (BUG-1); round 2 PASS after an independently-verified fix (`a7850b1`) — fixed
both the missing `CustomerStatus::Inactive` case and the `checkStatusConsistency()`
branch that had been hardcoded to only ever compare against `Active`. Otherwise a
clean 41-AC task both rounds.

**BUG-1 pattern worth checking on every future task with a documented enum of
statuses**: the spec named three `customers.status` values (`active`, `blocked`,
`inactive`); the shipped `CustomerStatus` PHP enum only declared two
(`Active`/`Blocked`). The DB column itself is a plain `VARCHAR(30)` with no
check, so nothing at the schema layer would ever catch this — the only proof is
reading the enum's case list against the spec's own enumerated values, then
confirming with a one-line `php -r` call against the autoloaded class
(`CustomerStatus::tryFrom('inactive')` → `NULL`). No dataset test anywhere
exercised the third value because `dev`'s own test for "a bad status" used an
arbitrary string (`'bogus'`), which is indistinguishable from a legitimately
missing case unless you cross-check the enum's full case list against the
spec's named list by hand. **Technique for next time**: whenever a spec names N
values for a column, grep the enum file for exactly N `case` lines before
trusting any "refuses a bad status" test to mean the good ones are complete.

**Long-running background `composer check` health-check technique, confirmed
useful again**: when the tail of a redirected log file stops growing for
multiple checks in a row, don't assume a hang — check the owning `php.exe`
process's `CPU` time via `Get-Process -Id <pid> | Select CPU` a few times a
minute apart. A climbing CPU number means it's still computing (pest buffers
output per describe-block, so a slow block like
`BranchForeignKeysContractRunTest` or `RunTenantMigrationsHandlerTest` can hold
the tail still for a couple of minutes while still being completely healthy).
This run's full `composer check` took 1299s wall time against a 7800s gate
limit, 3471 passed / 37 skipped / 13 failed (the known baseline, unchanged).

Bash-tool-calling-PowerShell gotcha hit again: `$_.CommandLine` inside a
double-quoted `-Command` string gets consumed by the **bash** layer before
PowerShell ever sees it (bash expands `$_` to its own last-argument variable).
Fix: use `Select-Object -ExpandProperty CommandLine` instead of
`ForEach-Object { $_.CommandLine }`, or pipe to a file and `grep` in bash
afterward — never rely on `$_` surviving into the PowerShell command text.

See [[project_module-host-fragile-areas]] for the general family of
"green gate, real gap" defects this joins.

**Exit-code capture trap, caught by `dev` in round 2, not by me**: `composer check`
on this repo exits nonzero (observed 2) whenever Pest has ANY failing test, even one
that is inside the documented, accepted baseline. My round-1 command was
`composer check > log.txt 2>&1; echo "EXITCODE:$?"` — when the Bash tool auto-backgrounds
a long command like this, the background-task-completed notification reports the exit
status of the **whole shell command** (here, the trailing `echo`, which always
succeeds), not of `composer check` itself. I misread that notification's "exit code 0"
as the gate's own result and wrote "Result: exit 0" in the QA report — wrong. The real
exit code was sitting in the backgrounded task's own `.output` file the whole time
(`cat <task-id>.output | tail` shows the literal `EXITCODE:2` line printed by the
`echo`). **Fix for next time:** never trust the background-task notification's own
exit code as a proxy for a command piped/chained inside it with `;` or `&&`; always
`cat` the task's own `.output` file and read the captured `EXITCODE:` line from there.
Trust the enumerated failing-class list against the accepted baseline, not the raw
shell exit code, for the PASS/FAIL verdict itself — the exit code is nonzero by design
on this repo whenever the baseline's own pre-existing failures are present.
