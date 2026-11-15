---
name: t017-audit-framework-closeout
description: T-017 (audit framework) QA close-out - mutation-testing a text-scan QA test via a real scratch file in the real scanned directory (not dev's own temp-dir control), and a file-count reconciliation technique
metadata:
  type: project
---

**To independently verify a text-scan "sole writer" / "no delete" QA test, plant the violation as a
real new file inside the actual scanned production directory, not only inside dev's own
temp-directory control.** T-017's `QaAuditSourceRulesTest.php` already carried internal controls
(builds a temp dir, plants a file, calls the real scanner over `[$tempDir]`) - the T-016 reviewer's
fix pattern for the W-1 "asserts on its own planted string" trap (see
[[project_negative-control-every-guard]]). That is good, but it is still dev's own control. The
independent round-2 move: `cp` a tiny new `.php` file straight into `packages/access/src/` (or
`packages/platform/src/`) with a genuine second `->table('audit_logs')->insert(...)` (or a delete
against `platform_audit_logs`), run the real test file with `pest`, confirm it goes red and *names
the planted path*, delete the file, confirm green, confirm `git status` is clean. This proves the
production assertion (not just the scanner function in isolation) actually walks the real directory
tree. Did this for both the insert rule (two tables) and the delete rule in one QA pass; all three
went red then green cleanly.

**To independently verify a cross-connection "database separation" QA test (query-log-based), mutate
the real handler temporarily.** `QaAuditDatabaseSeparationTest.php` asserts a handler issues zero
statements on the connection it should never touch. Backed up the real handler file (`cp` to the
scratchpad, not git), inserted one `DB::connection('master')->select('select 1')` line at the top of
`handle()`, ran the specific test, watched it go red naming the exact leaked SQL, then restored from
the backup and confirmed `git diff`/`git status` showed zero residual change before trusting the
test again. This is a legitimate, established QA move in this project (same shape as T-004's "mutate
the fake fix, confirm red, revert, confirm `md5sum -c`") even though the QA role is nominally
test-file-only - the edit is never committed, is reverted inside the same tool-call sequence, and the
only artifact that survives is the confirmed-green report. Do this via `cp`/direct file write, not
the Edit tool, to keep the harness's file-state tracking honest about what changed.

**A file-count reconciliation is worth doing even when the dev report sounds confident.** Dev
reported "73 files touched (55 new, 18 edited)" to the lead. `git show --stat <sha>` measured
**76 files (55 new, 21 modified)**. All 21 modified files were legitimate and fully accounted for
(17 named in the plan's own section 9, 4 more named in dev's own "found only by running the suite"
deviations list) - so this was a spoken-summary arithmetic slip, not a hidden edit, and did not
become a defect. The check that matters: `git show --name-status <sha> | grep '^M' | sort`, then
map every line to a plan-section-9 row or a dev-declared deviation. An unmapped `M` line is the real
red flag, not a mismatched total.

**FP-316's signature held again, third confirmed sighting.** 12 failures, same four files
(`QaModuleRegistryContractTest` x6, `QaModuleNameValidationTest` x3, `QaHealthProbeResilienceTest`
x2, `ModulesEnvParsingTest` x1), nothing audit-related. See [[t014-branches-terminals-closeout]] for
the original "rotating names, same count" finding - this run did not even need the rotation check,
the exact same six/three/two/one split as T-016/T-014 appeared verbatim.

**Round 2 (review-fix verification) found the count itself is not perfectly stable, only the file set
is.** An isolated-clone re-run (see [[project_isolated-clone-technique-for-contaminated-worktrees]])
measured 6/3/**1**/1=11, not 6/3/2/1=12 - `QaHealthProbeResilienceTest` passed one of its usual two
failing cases this run. Same four files, nothing new, nothing audit-related, so still not this task's
defect, but the "verbatim every time" framing from round 1 needed a caveat: the file set is stable,
the exact per-file count within the probe-resilience/readiness file is not guaranteed to be.

**Toolchain group's single historical flake (`HostTestAnalysisEnforcementTest`) did not reproduce.**
Ran `--group=toolchain` independently (70/70 green) after dev reported the same test flaking once and
not reproducing on their own isolated re-run. Two independent clean runs of the same test on the same
branch is reasonable confidence it is a flake, not a regression - but it is still worth re-running
the toolchain group yourself rather than trusting "didn't reproduce for me" from the dev report alone.

Related: [[project_negative-control-every-guard]], [[t014-branches-terminals-closeout]],
[[t016-platform-closeout]].
