---
name: feedback-guard-must-not-disturb-what-it-measures
description: When adding a guard that sweeps the repository or runs in a shared hook, make sure it cannot match itself and cannot change any existing test's assertion count
metadata:
  type: feedback
---

A guard that inspects the repository, or a helper that runs from a shared `afterEach`, has two
failure modes that look like success. Check both before writing it.

**1. A source-scanning guard matches its own test file.** A detector that greps test sources
for `Process::`, `proc_open(`, `exec(` … will flag the very test that proves the detector
works, and the obvious fix — exempting that file by name — is the same mechanism that hides a
real gap. Build the needles at runtime from the detector's own token list
(`'<?php '.$token.'run();'`) so no literal appears in the test source. That also makes the
test cover every token, including ones added later, rather than the two somebody wrote down.
Watch for the same thing in datasets: `->with(['exec("'])` puts the literal back.

Related trap from the same sweep: a naive `/\bexec\s*\(/` matches `$pdo->exec('create table
…')`, which swept the whole tenancy fixture in as a "toolchain" test. Bare-function tokens need
a `(?<![\w$>:])` lookbehind.

**2. A shared teardown helper must not assert.** Replacing `File::deleteDirectory(...)` with a
robust helper in ~20 `afterEach` hooks is safe; putting an `expect()` inside that helper is
not — it silently adds one assertion to every test that uses it, and on a task whose whole
point is "no existing assertion changes" that is the defect. Throw a `RuntimeException` with a
diagnostic message instead: loud, and invisible to the assertion count.

**Why:** both were caught while building the `toolchain` group guard for T-004, whose stated
defining risk was a suite that quietly measures less than it claims. A self-matching detector
and an assertion-inflating teardown would each have produced exactly that.

**3. An assertion that a *list* does not contain something it could never contain.** Asserting
a hand-written registry does not contain a path under `storage/app` reads like a safeguard and
is true by construction. Exercise the comparison that would actually catch the regression, over
input planted for the purpose, and assert it comes back empty again without the plant.

**4. Absence assertions need a positive control on the same input.** `str_contains($x, 'npm ')
=== false` passes when `$x` is an empty string because the file failed to load. Assert something
you know *is* in `$x` first.

**5. Pinning a helper's behaviour does not pin the call site that depends on it.** A test proving
`rawHash()` distinguishes line endings while `normalisedHash()` does not stays green after
someone swaps the *caller* back to the forgiving one. If a safety argument rests on which
function a particular branch calls, assert that too — reading the file's own source with
`File::get(__FILE__)` is legitimate when the branch cannot be invoked directly. Then
mutation-test it: flip the call site, confirm red with a message naming the defect, restore, and
verify the restore is byte-identical with `diff`.

**Why:** all four were caught in T-004, whose stated defining risk was a suite that quietly
measures less than it claims. QA found one of them (a de-duplicated list compared against its
own `array_unique()`), which is a fair catch; the scan for siblings found three more.

**6. A registry check with a hand-kept list on *both* sides is not a check.** Comparing
`tools/clean-probes.php`'s array against a trio written out in the test passed while the suite
planted eight probes, and its comment claimed completeness. Every such check needs a
*discovered* half, and the discovery key must be something the planters already carry. Measured
on T-005: QA's proposed key (string literals matching `Probe\.php`) found **zero** matches,
because the paths are `DIRECTORY_SEPARATOR` concatenations and two planted files are named
`*ProbeTest.php`; the function-name convention (`/function\s+(\w*ProbePath)\s*\(/`) found all
eight. Resolve each discovered path from the *source* and scope the requirement by rule - inside
a PHPStan path, a Deptrac path or a phpunit suite directory - so the one probe under `storage/`
is excluded by the rule and not by an exemption list.

Item 1's trap returns here and is easy to walk into twice: the file holding the discovery regex
must not contain `function <name>ProbePath(` in a comment or heredoc. Name synthetic control
functions off-convention (`syntheticBaseTarget`), or the guard fails on a phantom probe whose
file never exists.

**7. `function_exists()` around an assertion is an assertion count that moves with collection.**
A planter declared in another test file exists in a full run and in a `--filter` run, and not in
a single-file run, so the case reports 2 assertions or 1 depending on what else was collected.
Two fixes, both better: read what you need out of the other file's *source*, or put the helper in
`tests/Helpers.php`, which Pest's BootFiles loads in every run mode.

**How to apply:** whenever the deliverable is "prove coverage did not change", diff the
per-test assertion counts from a `--log-junit` log taken *before* touching anything against
one taken after, per test name — not the totals. Totals cannot tell "two dropped, two added"
from "nothing changed". Related: [[project-backend-toolchain-constraints]],
[[feedback-clean-up-probe-files-immediately]], [[feedback-never-edit-files-during-a-test-run]].
