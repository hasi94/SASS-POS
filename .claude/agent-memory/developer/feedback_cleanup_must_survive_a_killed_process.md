---
name: feedback-cleanup-must-survive-a-killed-process
description: A test that plants a file must delete it in beforeEach as well as afterEach - a killed process runs no PHP teardown, and the leftover then fails the gate naming a file that looks like real code
metadata:
  type: feedback
---

Any test that writes a file into a directory a tool analyses must remove it
**before it plants, not only after**. `finally` and `afterEach` are both correct
and both useless when the process is *killed* rather than finishing.

**Why:** on T-005 I added `HostTestAnalysisEnforcementTest`, which plants
`HostDeptracProbe.php` (a deliberate boundary violation) and `HostStanProbe.php`
(a deliberate level 6 defect) to prove `composer deptrac` and `composer stan` can
actually fail. It deleted each one in a `finally` *and* in `afterEach`. Composer's
**300 s per-process timeout** then killed `composer test` mid-run, so neither ran,
and the probe survived into every later run: the gate went red with
`Tests\Feature\HostDeptracProbe must not depend on …PingController`, which reads
exactly like a real defect in real code. The same kill also left generated fixture
modules behind, and because `phpunit.xml` globs `../*/tests`, every later child
process collected them — 7 failures in files I had not touched. I then spent a
long time chasing a phantom "3x slowdown" that was only a cold PHPStan result
cache plus that leftover state. Warm and clean the gate was 85–103 s against a
117 s baseline: there was no slowdown at all.

**How to apply:**
- `beforeEach` removes the probe too. That is the only step that survives a kill,
  because the *next* process performs it.
- Make the remover idempotent: early-return when the file is absent, so the same
  helper is safe before and after.
- It must **report, not assume**: throw a `RuntimeException` naming the file if it
  cannot remove it. Never `expect()` — a teardown assertion silently inflates every
  using test's assertion count, which is fatal on any task whose contract is
  "counts do not change". See [[feedback-guard-must-not-disturb-what-it-measures]].
- Keep the per-test `finally` on the plain non-throwing delete, so a cleanup
  problem cannot mask the assertion result it runs after.
- Git-ignore the probe filenames, so a copy left by a killed run cannot be
  committed by accident. This hides nothing as long as the test still asserts the
  probe is gone before it finishes.
- **Diagnostic order, every time:** before believing any broad or unrelated
  failure, run `git status --porcelain`, `ls` the fixture/module directories, and
  check for leftover probes. Leftover state from a killed run is far more likely
  than a real regression, and it is also the likeliest explanation for a sudden
  "everything got slower". Related:
  [[feedback-clean-up-probe-files-immediately]].
