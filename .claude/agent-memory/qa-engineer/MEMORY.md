# QA engineer memory

- [Module host fragile areas](project_module-host-fragile-areas.md) — T-001/T-002 defect patterns that produced green-but-meaningless gates; what is guarded now, what still trusts the manifest.
- [Negative-control every guard](project_negative-control-every-guard.md) — making an assertion-of-absence mean something: the `toContain()` trap, the tautology shapes, mutation-testing a bound, proving a repo-wide rename.
- [Tenancy boundary leak surfaces](project_tenancy-boundary-leak-surfaces.md) — which connection-construction paths skip the tenant guard, which dumpers print a "redacted" credential anyway, what survives a purge.
- [Assert the contract, not the symptom](feedback_assert-contract-not-symptom.md) — also covers symptom-as-precondition, and correcting a disproved belief everywhere it is stated.
- [Gate split and shared state](project_gate-split-and-shared-state.md) — fast/full counts, proving coverage by name from git, the clone-vs-worktree line-ending proof, and the four files that fake a red.
- [T-007 measurement and test traps](project_t007-measurement-and-test-traps.md) — mutation via prepended autoloader, old-tip runs, stan covers tests, toBe message trap, build() wedge.
- [T-008 built but not wired](project_t008-built-but-not-wired.md) — classes unit-tested with no caller, status-vocabulary mismatch, PHPUnit env/$_SERVER hold trap, how to report a held [E] matrix.
- [T-009 cross-cutting proofs](project_t009-auth-cross-cutting-proofs.md) — mutating prod via a phpunit bootstrap autoloader, sink-harness through real writers, Pest/router/logger traps, AC-22 reading.
- [T-009 round 2 blind spots](project_t009-round2-ingress-and-pin-blind-spots.md) — ingress is not a sink, select-star evades column-keyed pins, QA files must pass Stan/Pint.
- [T-010 mutation survivors and harness](project_t010-mutation-survivors-and-harness.md) — nine kinds of mutant that survived a green suite, the python mutation driver, never overlap gate runs.
- [T-014 close-out](project_t014-branches-terminals-closeout.md) — diff-base trap on a stale `main`, FP-316's real (rotating) shape, a non-discriminating mutation, overlapping gate runs again.
- [T-016 platform close-out](project_t016-platform-closeout.md) — concurrent gate-vs-ad-hoc-pest contention looks like real bugs, a console-command-existence gap, full 10/10 mutation verification.
- [T-017 audit framework close-out](project_t017-audit-framework-closeout.md) — mutation-test a text-scan QA test via a real scratch file in the real scanned dir, mutate a handler temporarily for a query-log test, file-count reconciliation technique.
- [Isolated-clone technique for contaminated worktrees](project_isolated-clone-technique-for-contaminated-worktrees.md) — T-017 round 2: an unexplained concurrent process shared my assigned worktree; `git clone --local` + copy vendor + fix dereferenced Composer symlinks as Windows junctions.
- [T-018 eventbus mutation and SQLite lock trap](project_t018-eventbus-mutation-and-sqlite-lock-trap.md) — all 11 mutations held clean, the SQLite single-writer-lock trap on an "own transaction" mutation, independent AC-37 deptrac re-verification technique.
