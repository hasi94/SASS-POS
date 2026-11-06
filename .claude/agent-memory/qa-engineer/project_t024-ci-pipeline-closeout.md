---
name: t024-ci-pipeline-closeout
description: T-024 CI pipeline - a committed backlog-row narration re-triggered the vocabulary sweep trap, how the gate's +1/-1 shifted count proved it was real not flake, and the D-13/IdentifierColumn/SQL cross-checks that held clean
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
