---
name: isolated-clone-technique-for-contaminated-worktrees
description: How to get a trustworthy gate measurement when a worktree is contaminated by an unexplained concurrent process, and the Windows junction fix the clone itself then needs for Composer local-path repos
metadata:
  type: project
---

**T-017 review-round-1 verification (R-1/S-1/S-2) hit a worktree that was not actually mine alone, despite
being told "run everything from there."** Two full `composer check`/`pest` attempts taken directly in the
assigned worktree (`D:\...\worktrees\progress-check-1913d7`) returned wildly wrong shapes: the first crashed
in `deptrac` on a stale 24 MB `.deptrac.cache` (fixed by deleting it — this part is the ordinary "second suite
poisoned a shared file" hazard, see [[project_gate-split-and-shared-state]]); the second returned **48 failed**
scattered across totally unrelated feature files (`OrganizationTerminalHttpTest`, `PlanLimitEnforcementTest`,
`QaAccessGuardIsolationHttpTest`, ...), every failing block immediately preceded by `PHP Warning:
unlink(...tenant-a.sqlite): Resource temporarily unavailable`. `Get-CimInstance Win32_Process` on
`php.exe` traced this to a `composer.phar test` → `tools/gate-step.php test -- vendor/bin/pest` chain running
directly against the **same worktree path**, writing to `t017-suite.txt`/`t017-suite2.txt` **inside my own
session's scratchpad directory**, under a command I never issued. Its ~7-minute run window overlapped mine by
about 4 minutes. I never identified who/what started it (same scratchpad UUID rules out a different session's
scratchpad colliding by chance; most likely a duplicate/parallel agent instance or a supervisor-level retry
loop outside my own tool-call stream) — but the practical lesson holds regardless of cause.

**The fix: full filesystem isolation via a local clone, not a retry in place.** `git clone --local
--no-hardlinks <worktree> <scratch-dir-outside-the-repo>` gets an independent working tree sharing no SQLite
fixture, cache, or lock file with whatever else is running. Composer's local-path packages don't travel with
`git clone` (only `vendor/` matters here, but note it's `modules/host/vendor`, found via `find . -maxdepth 3
-type d -name vendor`), so: `cp -r` the vendor directory across, `cp .env.example .env` (plain file reads of
the real `.env` are permission-denied by design — don't fight that, use the example), `php artisan key:generate
--force` for a fresh `APP_KEY` (never copy a real key). Confirm `git log -1` in the clone matches the worktree's
HEAD before trusting anything from it.

**The clone then introduces its own, different false positive: `cp -r` dereferences Composer's local-path-repo
symlinks.** `vendor/flexipos/{access,auth,contracts,eventbus,http-client,kernel,module-demo,organization,
platform,plugin-sdk,testing}` are genuine symlinks in a working worktree (`ls -la` shows `lrwxrwxrwx`,
`-> /abs/path/to/packages/<name>`) — standard Composer `"symlink": true` local path repositories. `cp -r`
follows symlinks by default and copies the **target's content** into a new, separate real directory under
`vendor/`. Any test that does `realpath()` on a stack-trace frame and asserts it starts under `packages/<x>`
(T-017's own `QaSuspendedMappingGateTest`, asserting a kernel exception is thrown from `packages/kernel`, not
from "application" code) then fails for real — the executing file's `realpath()` now resolves to the `vendor/`
copy, not to `packages/kernel`, and that is a **true fact about the broken clone**, not about the codebase.
Confirmed by reverting the test's own fix hypothesis: the failure trace literally named
`vendor\flexipos\kernel\src\Tenancy\Tenancy.php`, which is the tell — a `packages/` path never appears in a
healthy run's trace for this assertion.

**Fix the clone, don't doubt the test.** On this Windows box, inside this sandboxed Bash tool, plain `ln -s
TARGET LINK` silently creates an **empty regular directory** instead of a symlink or an error — no
`SeCreateSymbolicLinkPrivilege`, and the failure is silent (exit 0, `ls -la` shows `drwxr-xr-x`, `readlink`
resolves to itself). What works without elevated privilege: a Windows **junction**, via PowerShell:
`New-Item -ItemType Junction -Path 'kernel' -Target '..\..\..\..\packages\kernel'` (relative path depth counted
from `vendor/flexipos/`, four `..` to the clone root then down into `packages/<name>`; `module-demo` targets
`modules/demo`, one level shallower, i.e. also four `..` since it's one more directory up in the source side
and the target doesn't need to re-enter `packages/`). `rm -rf` the dereferenced copy fully first — if the old
directory still exists, plain `ln -s TARGET existing-dir` silently links **inside** the existing directory
instead of replacing it (also silent, also exit 0), which looks identical to success from the command's own
exit code. `Get-ChildItem | Select-Object Name,LinkType,Target` is the only check that distinguishes "real
junction" from "empty directory that happens to be named the same." After fixing all eleven entries, re-run
just the one test file that named the artifact (6/6 passed), then re-run the whole gate once more end to end —
only then does the number mean anything.

**Net result reconciles cleanly against the developer's own report.** Dev reported 552.49s / 2173 passed / 12
failed (FP-316) / 11 skipped / 16382 assertions, taken directly in the worktree (presumably before whatever
else started colliding with it, or on a machine/window that didn't collide). The isolated-clone run, junctions
fixed, measured 453.89s / **2174 passed / 11 failed / 11 skipped / 16383 assertions** — a one-test delta
(`QaHealthProbeResilienceTest` showed 1 of its usual 2 failures instead of 2), confined to the same four files
as always (`QaModuleRegistryContractTest` ×6, `QaModuleNameValidationTest` ×3, `QaHealthProbeResilienceTest`
×1 this time, `ModulesEnvParsingTest` ×1), nothing audit-related, nothing outside FP-316. See
[[t017-audit-framework-closeout]] for the task-level record and [[t014-branches-terminals-closeout]] for
FP-316's original "rotating names, same count" framing — this run adds "and occasionally a different count
within the same four files," since 6/3/2/1=12 is not perfectly stable across every run, only the *file set* is.

**When to reach for this technique vs. just re-running in place.** Re-running in place (per
[[project_gate-split-and-shared-state]]'s "serialise every suite run" rule) is cheaper and is the first thing
to try; it is enough when the contamination source is identifiable and stoppable (a stray cache file, a
probe left by a killed process). Reach for the isolated clone only when a *process*, not a *file*, is actively
sharing the worktree and you cannot identify or stop it — the clone sidesteps the problem instead of diagnosing
it, which is weaker evidence about *why* but strictly stronger evidence about *what the code itself does*,
since by construction nothing else can be touching the clone's files.

Related: [[project_gate-split-and-shared-state]], [[t017-audit-framework-closeout]],
[[t014-branches-terminals-closeout]], [[project_module-host-fragile-areas]].
