---
name: t024-ci-pipeline-closeout
description: T-024 CI pipeline - a committed backlog-row narration re-triggered the vocabulary sweep trap, how the gate's +1/-1 shifted count proved it was real not flake, the D-13/IdentifierColumn/SQL cross-checks that held clean, and (round 3) how a 46-failed gate run turned out to be a second php.exe process, not a regression
metadata:
  type: project
---

T-024 shipped `.github/workflows/ci.yml`, `.github/ci/register-ci-tenant.sql`,
`.github/ci/assert-tenant-state.sh`, `modules/host/tests/Feature/CiWorkflowShapeTest.php` — all
four held up under independent re-reading (no engine ever contacted, per `ENGINE_HOLD`/D-1). 12/12
`CiWorkflowShapeTest` cases reproduced exactly (75 assertions). D-13's `provisioning` exemption
verified directly against source: `PlanMigrationRunHandler::BLOCKING_EXEMPT_STATUS` and
`TenantStatusPolicy::NAMED_ONLY_STATUSES`, both grepped, not trusted from the decision doc.
`register-ci-tenant.sql`'s three inserted rows checked column-by-column against the real
migrations (`2026_10_14_000105/000112/000113`) — the easy one to miss is `tenant_databases.profile`
(no default, easy to forget); it was present. ULID ids were hand-counted at exactly 26 ASCII chars
against `IdentifierColumn`'s `CHAR(26)`.

**The real defect (BUG-1, major): a *committed* vocabulary-sweep violation, not an untracked one.**
[[project_gate-split-and-shared-state]] already documents the untracked-scratch-note version of this
trap ("describe, don't quote"). This time dev's own `IN_QA` status-update commit added a backlog-row
sentence describing a vocabulary fix made elsewhere — and that description itself quoted the
forbidden GitHub-Actions job-dependencies key, tripping
`QaVocabularyRenameSweepTest`'s own quoted-key pattern. Confirmed as newly-introduced (not
pre-existing) by diffing the backlog row between the prior commit and the reported-done commit
(no match vs. one match). This is worse than the historical trap: it ships
on the branch itself, so anyone cloning `c267880` and running `composer check` gets a red gate, not
just the one machine with the stray untracked file.

**How the +1/-1 gate-count trick proved it wasn't flake.** Dev reported 12 failed / 2762 passed / 23
skipped (total 2797). My independent full-gate run: 13 failed / 2761 passed / 23 skipped (same total
2797). One case moved from passed to failed — exactly the vocabulary-sweep case, both by name match
and by the arithmetic lining up to a single shift, not a different-sized flaky cluster. The other 12
failures matched the already-cross-task-documented `QaModuleRegistryContractTest` state-pollution
cluster (15/15 green standalone, full-suite-only failure, confirmed present in T-021/T-022/T-023's
own QA reports too) by test-name signature in this run's own log.

**How to apply.** When a dev report claims an exact gate N/M and your own independent run differs by
exactly one case, don't average it away as "probably the known flaky cluster" — name which specific
case shifted and check whether it's inside the known cluster's signature or a new name. Re-running
the specific named test solo (not just trusting the aggregate number) is what separates a real
regression from noise here. Full evidence: `docs/qa/T-024-ci-pipeline.md`.

**Round 2 (fix verification): PASS.** Lead's `fa0a52c` reworded both `docs/backlog.md` and this
same agent-memory file's own prior note (it had the identical quoting trap — a real instance of
the "needle in my own memory file" risk) to describe rather than quote the fragment.
`git diff c267880..fa0a52c --stat` confirmed doc/memory-only. Solo re-run:
`QaVocabularyRenameSweepTest` 5/5 (was 1 failed/4 passed), `CiWorkflowShapeTest` unchanged 12/12.
Full gate: 2762 passed / 12 failed / 23 skipped — the exact pre-fix baseline, confirming the
regression (not the pre-existing `QaModuleRegistryContractTest` cluster) was the only thing that
moved. A composer-check full run on this machine took 1367s this time vs. 1072s in round 1 — real
machine-load variance, not a gate-step concern since it stayed well inside the manifest's margin
(ratio 5.70).

**Round 3 (reviewer Warning fix verification): PASS.** Dev's `7851890` added 3 assertions to
`CiWorkflowShapeTest.php` (W-1 fixed-port blind spot, W-2 tenant-status/ordering blind spot, W-3
T-023-lesson-reversion blind spot) — 12→13 cases, 75→101 assertions (+26). Independently mutated
all three (not just two) via my own edit-run-restore of the shipped `ci.yml`/`register-ci-tenant.sql`
(never trusting dev's own revert-test report): `3306`→`'3306:3306'` reddened line 364 exactly,
`'provisioning'`→`'active'` on the tenants row reddened the `substr_count`==2 check at line 525,
and `--password=`→space-split `-p` reddened line 371 — all three for the named reason, all three
restored clean (`git checkout -- <file>` + `git diff --stat` empty) and re-confirmed 13/13 green.

**A `composer check` run that returns 46 failed instead of ~12 is contamination, not a
regression — verify the process table before reporting it.** First full-gate attempt: 46 failed /
2729 passed / 17243 assertions, with a `ProcessTimedOutException` spawning `pest --list-groups`
and a tenancy-fixture SQLite `PDOException: table marker already exists` — both textbook
[[project_gate-split-and-shared-state]] "second suite sharing the tree" signatures. Instead of
reporting the scary number, `Get-CimInstance Win32_Process -Filter "name='php.exe'"` found a
second `E:\xampp\php\php.exe artisan test --testsuite=Feature` (PID, not mine) whose start time
sat squarely inside the contaminated run's window, with near-zero CPU minutes later (hung but
present). Discarded that run, re-ran with full output to a log file (never `| tail -N` on a gate
run — it hides the one line that would tell you *why* a surprising count happened) and got a
clean **12 failed / 2763 passed / 19334 assertions**, exactly matching the task's predicted
baseline (2762+1 case, 19308+26 assertions, both reconciling to the same dev-added test). Lesson
reinforced: a composer check's number that is 3-4x the known baseline is itself evidence of
contamination, not grounds to mark the task FAIL — check `tasklist`/WMI for a second PHP process
in the same window before concluding anything.

**The pre-existing full-suite-only cluster is wider than previously recorded.** It is not just
`QaModuleRegistryContractTest` (6 cases) — this round's clean 12 also included
`ModulesEnvParsingTest` (1), `QaHealthProbeResilienceTest` (2) and `QaModuleNameValidationTest`
(3). Two of those four files (`QaModuleNameValidationTest`, `QaModuleRegistryContractTest`) are
fully green run completely standalone; the other two (`ModulesEnvParsingTest`,
`QaHealthProbeResilienceTest`) each still show exactly 1 failure even alone on this machine (an
`.env` `MODULES` default leaking into a "MODULES unset" expectation) — a machine/environment-level
default, not test-order pollution, and not touched by any of T-024's 4 shipped artefacts. Prior
notes calling this cluster "15/15 green standalone" describe only the `QaModuleRegistryContractTest`
slice of it; the other two files have their own, narrower, always-reproducing-alone failure.
