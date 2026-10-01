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

**How to apply:** whenever the deliverable is "prove coverage did not change", diff the
per-test assertion counts from a `--log-junit` log taken *before* touching anything against
one taken after, per test name — not the totals. Totals cannot tell "two dropped, two added"
from "nothing changed". Related: [[project-backend-toolchain-constraints]],
[[feedback-clean-up-probe-files-immediately]], [[feedback-never-edit-files-during-a-test-run]].
