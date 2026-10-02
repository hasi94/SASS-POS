---
name: feedback-cleanup-must-survive-a-killed-process
description: Cleanup for a planted probe belongs at the front of the composer check script, not in a test hook - check runs stan and deptrac before any test hook, so a survivor aborts the gate before a beforeEach could ever fire
metadata:
  type: feedback
---

A test that plants a file carrying a deliberate defect must have that file removed
by **a step at the front of the `composer check` script**. A test hook cannot do
it. Neither can `finally` or `afterEach`.

**Why, in the order I learned it (two rounds, one QA defect):**

1. `finally` + `afterEach` are both correct and both skipped when the process is
   **killed** rather than finishing — a Composer process timeout (300 s here, and
   the gate has run over it), Ctrl-C, a CI step limit. Measured: `composer check`
   was killed mid-test and `HostDeptracProbe.php` survived into every later run.
2. So I moved the cleanup into `beforeEach`, reasoning that the *next* process
   performs it. **That was still wrong, and worse, I wrote in a code comment and
   in this memory note that it closed the hole.** `composer check` runs
   `lint`, `stan`, `deptrac`, `test` in that order. A surviving probe aborts the
   run at step 2 or 3, so no test hook ever executes. The `beforeEach` is dead
   code in precisely the case it was written for. QA found this by planting the
   probe by hand and reproducing the original two violations.
3. The fix that works: a `probes:clean` script as the **first entry** of `check`,
   ahead of the first analysing step, implemented as a dependency-free PHP script
   that does not boot the framework — it has to work when the application cannot.

**The general rule, which is the part worth keeping:** a cleanup only protects the
steps that run *after* it. Before claiming any guard closes a hole, name the exact
execution order it depends on and check the guard is upstream of every step that
could fail. Position is as load-bearing as existence, and it is worth an assertion
of its own (`expect($check[0])->toBe('@probes:clean')`), because a cleanup that
drifts to second place is silently useless again.

**And the meta-lesson, which cost this project a QA round:** prose that claims a
guarantee the code does not provide is worse than no prose, because the next task
inherits the claim. If I write "this closes X", I must have executed the failing
case and watched it pass. I had not.

**Supporting details worth not rediscovering:**
- Make the remover idempotent (early-return when absent) so the same helper is
  safe before and after, and so a cleanup step is harmless on a clean tree.
- It must **report, not assume**: exit non-zero / throw a `RuntimeException`
  naming the file. Never `expect()` in a hook — a teardown assertion silently
  inflates every using test's assertion count, fatal on any task whose contract is
  "counts do not change". See [[feedback-guard-must-not-disturb-what-it-measures]].
- Keep one registry of probe paths and **assert it covers every probe the suite
  plants**, or the next probe added is unprotected exactly as the first was. A
  sibling test here had the same shape and no cleanup at all, and its probe path
  was not even git-ignored, so a staging command could have committed a file
  carrying a deliberate defect.
- Git-ignore every probe path. Check with `git check-ignore -v`, do not assume.
- **The root `.gitignore` here has `**/bin/`** (for .NET build output), so a
  gate-critical script placed in any `bin/` directory is silently untracked. I hit
  this and moved the script to `tools/`. Run `git check-ignore -v <path>` on any
  new non-test file before believing it is committed.
- **Diagnostic order, every time:** before believing any broad failure or any
  apparent slowdown, run `git status --porcelain`, `ls` the fixture and module
  directories, and look for leftover probes. A killed run's leftovers are far more
  likely than a real regression, and they also explained an apparent 3x slowdown
  that did not exist (it was a cold PHPStan result cache plus collected leftover
  fixture modules). Related: [[feedback-clean-up-probe-files-immediately]],
  [[project-repo-wide-rename-recipe]].
