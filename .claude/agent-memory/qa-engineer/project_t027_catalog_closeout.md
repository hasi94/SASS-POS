---
name: project-t027-catalog-closeout
description: T-027 catalog task close-out — a clean PASS on a very large task, the contamination triage technique used, and where to find the two baseline-failure signatures.
metadata:
  type: project
---

T-027 ("Catalog": categories, brands, units/conversions/`UnitResolver`, products/variants, exact-match
barcodes, product-location/branch settings, product-components, modifiers — 33 feature slices across
`packages/catalog`) closed QA with a clean PASS on the first round. 44/44 `packages/catalog/tests` suites
passed, every host-side static instrument passed, deptrac reported 0 violations, and the full
`composer check` reproduced `dev`'s own claimed baseline exactly: 13 failed / 3255 passed / 31 skipped.

**The two baseline-failure signatures to recognize instantly, so a real regression is never confused
with noise:**
1. [[gate-split-and-shared-state]]'s FP-316 cluster, now at **12** failures (grown from the older counts
   recorded in earlier memory): `ModulesEnvParsingTest`×1, `QaHealthProbeResilienceTest`×2,
   `QaModuleNameValidationTest`×3, `QaModuleRegistryContractTest`×6. Rotates across runs but the total
   count and the member-file set are stable.
2. A 13th failure, `QaVocabularyRenameSweepTest`, citing exactly two non-production survivors:
   `.claude/agent-memory/developer/project_t026_create_owner_verify_activate.md:43` (a `dev` memory file)
   and `frontend/package-lock.json:2371` (an npm lockfile entry). First confirmed pre-existing during
   T-026's own QA (all three of its rounds); still present, still the same two lines, unrelated to every
   task since. Verify via `git log` on the two named files — if the last commit touching them predates
   the task under review, it's baseline, not a regression.

**Contamination triage technique used here:** before and during a long gate run, list `php.exe` processes
via `Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Select ProcessId,CommandLine` — bash's
`tasklist`/`wmic` piping mangles the full command line on this machine, PowerShell does not. A process
with no path reference to the assigned worktree is noise from one of the machine's other local Laravel
projects (seen repeatedly: `flexi-crm`, `Scriber`, a `tinker` script) — safe to ignore if the gate's final
tally still matches the independently-expected baseline count exactly (strong evidence against the
extra process having corrupted shared state).

**Verifying "judgment call" review items without re-running mutations on production code:** for a task
this large (6 dev sessions, 13 named mutation checks M1–M13), re-running every mutation live would mean
editing shipped production code, which is outside a QA engineer's file ownership. Instead: read the
implementation directly to confirm the guard exists as described, then confirm the *shipped test file*
already contains the exact discriminating row the plan's mutation claim implies (e.g. a
"round-order-reversed value would give X, not Y" dataset row that only a correct implementation passes).
This is a legitimate substitute for live mutation when the test's own assertion is provably
non-tautological (an exact expected string/value, not a loose `toContain`/boolean).

See [[negative-control-every-guard]] for the general discipline this task's own tests followed
throughout (real `QueryException`-throwing inserts for schema uniqueness, planted-file controls for
every source-rules scan, diamond-vs-cycle controls for the component graph).
