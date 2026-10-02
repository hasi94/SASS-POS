---
name: gate-split-and-shared-state
description: How to verify the T-004 fast/full test split and the root line-ending rule in this repo - the measured numbers, the techniques that make a coverage proof independent of the developer's report, and the four files that make a whole run red for reasons that have nothing to do with the change
metadata:
  type: project
---

T-004 split the gate into `composer test` (the gate, no group flag), `composer test:fast`
(`--exclude-group=toolchain`) and `composer test:slow`, plus a root `.gitattributes`. Measured on
this machine, **run 2, 2026-10-01 (these supersede the run-1 figures)**. **After T-005 (2026-10-02)
the same three commands measure 314 / 1414, 257 / 965 and 57 / 449, with `composer check` at 114 s
wall; the registry is 16 files, not 15.** The T-005 additions are 3 FP-169 cases (24 assertions), the
5-case / 20-assertion vocabulary sweep, and 2 probe-cleanup cases (12 assertions) - 10 / 56 in total,
so `314 − 10 = 304` / `1414 − 56 = 1358` lands on the T-004 pair, which is the cheapest reconciliation
this project has:

| | cases | assertions | time |
|---|---|---|---|
| full (`pest`) | **303** | **1352** | 80–103 s warm |
| fast (`--exclude-group=toolchain`) | 250 | 927 | 27–31 s |
| slow (`--group=toolchain`) | 53 | 425 | 51 s |
| `composer check` whole gate | — | — | 145 s wall |

250+53 = 303 and 927+425 = 1352. The registry is 15 files. The pre-T-004 baseline is
**279 / 1224** at `e6c1934` (not the 277 / 1219 my own T-003 report first claimed). Run 1
measured 299 / 1331; the +4 cases / +21 assertions are the BUG-3 regression test plus the
bug-fix edits, all inside files T-004 adds.

**Prove coverage from git, never from the developer's report.** A baseline JUnit is not
obtainable cheaply (a `git worktree` shares this tree's `vendor/`, and PHP resolves `__DIR__`
through the symlink, so baseline *test* files run against post-task production code). What works,
and is stronger for the files that changed:
1. `pest --log-junit` three times (full, `--group`, `--exclude-group`); parse `classname::name`
   **and the per-case `assertions` attribute**; assert union == full, intersection == empty, and
   zero per-case assertion-count differences between full and each half.
2. File-level set diff, `git ls-tree -r HEAD --name-only` vs `find` — but **exclude `vendor/`
   explicitly**: `find -path ./vendor -prune` only prunes the *root* vendor, and
   `modules/host/vendor` ships ~18 files matching `*Test.php`.
3. Per surviving file, diff the HEAD blob against the working copy **twice**: once on
   `^(it|test)\(` declarations, once on every line containing `expect(`, `->and(`,
   `$this->assert` or `->assert`. In T-004 both diffs reduced 23 modified test files to the same
   two differences (one addition, one declared rename), which is a by-name set difference
   obtained from git. `^(it|test)\(` only matches column-0 declarations - indented ones here live
   inside heredocs that generate fixture packages, so they are not real cases.
4. Reconcile in two directions: full − additions + removals must land on the known baseline pair.
   303−27+3 = 279 and 1352−132+4 = 1224. Two independent derivations meeting on one pair is the
   evidence.

**Line endings: use a real clone, and always measure the parent commit as the control.**
`git checkout` honours an **untracked** working-tree `.gitattributes`, so a rule that was never
committed makes every in-tree check pass while a fresh clone stays broken (T-004 BUG-1).
`git clone --local --no-hardlinks <repo> <tmp>` is the right instrument - it is what a developer
actually gets, and untracked files do not follow it. Then
`git -C <tmp> ls-files --eol | grep -c w/crlf`: **0** at `643e220`, **88** at its parent
`e6c1934`. Also check `i/crlf`/`i/mixed` is empty (no blob needs renormalising) and that
`git add --renormalize .` **inside the clone stages 0 files** - that is what proves the
renormalisation is complete rather than merely declared. To test staging in the *live* tree
without touching the real index, `cp .git/index <tmp>` and run
`GIT_INDEX_FILE=<tmp> git add -A .`, then grep the output for `will be replaced by`;
`git add -A` handles deleted-but-unstaged files, whereas `--renormalize` aborts with
`fatal: unable to stat` while any tracked file is deleted-but-unstaged. Pint is the first thing a
CRLF checkout breaks: `pint --test --format=json` on a CRLF PHP file reports
`"fixers":["line_ending",…]`, so `composer check` dies at step 1 of 4.

**Raw bytes, not the shipped test.** `ModuleMakeDemoRegenerationTest` and
`QaTestCodeAnalysisCoverageTest` hash `md5(str_replace("\r\n", "\n", …))` on purpose, so they
cannot prove a byte-for-byte regeneration. Do it by hand as a **three-way** comparison:
clone's `modules/demo` → live tree → `rm -rf modules/demo && git checkout -- modules/demo` →
`mv` aside, `artisan module:make demo`, then `find . -type f | sort | xargs md5sum` on each (11
files). All four states must agree; the same comparison against a checkout of the *parent*
commit must differ on exactly the 5 files that were CRLF there. Restore from the moved-aside copy
and re-verify the md5 list, not just `git status`.

**Two shared files turn one mistake into a hundred meaningless failures.**
`modules/host/bootstrap/cache/config.php` is created deliberately by `ConfigCacheGuardTest`,
`QaCacheGuardBranchesTest` and `QaCredentialSurfaceSweepTest`, and every other test needs it
absent: with it present, `ModuleHostServiceProvider::register()` throws `Cached configuration
disagrees with the process environment` during *boot*, so each later test fails with an error
naming configuration caching and nothing about itself. Seen twice (T-003 run 3, T-004 run 1: 106
failures); both times the cause was **a second suite running in the same working tree**, most
plausibly the task-completion hook's `composer check` overlapping a QA run. Diagnose without
guessing: the frozen `MODULES` value in the message names the test that wrote the file
(`demo,qacachefixture` is `QaCacheGuardBranchesTest`); if that test *passed* in the same run, its
closing `expect(File::exists(...))->toBeFalse()` proves the file came from another process.
Serialise every suite run and it does not happen - T-004 run 2 ran 40 suite executions back to
back with zero poisoning. The second such file is `bootstrap/cache/routes-v7.php`
(`RouteCachingTest`, same discipline).

**The Windows fixture-delete race, measured.** With a read handle open on a file inside a
directory: `unlink()` on the file **succeeds**, `rmdir()` on the directory **fails**, and
`rmdir()` succeeds immediately once the handle closes. So the files go, the empty directory
stays, and `module:make` then refuses the name ("already exists. Nothing was created or modified"),
failing the *next* case for an unrelated reason. `tryDeleteFixtureDirectory()` /
`deleteFixtureDirectory()` in `modules/host/tests/Helpers.php` are the shared fix (12 attempts,
`gc_collect_cycles()` + 50 ms before each retry ≈ 550 ms of grace). Reproduce the condition with
`fopen` on a file in the directory - no mocking needed.

**A third stray file, and it is not the suite's fault.** `vendor/bin/deptrac analyse` **without**
`--cache-file` writes a ~1 MB `.deptrac.cache` into the current directory. T-004 BUG-6 was that
`.gitignore` had no entry for it, so it showed as untracked-and-not-ignored and `git add -A` would
have committed it; that was fixed inside T-004 (`/.deptrac.cache` is in `modules/host/.gitignore`
at `main`, verified with `git show`), so it is now ignored rather than committable - the stray file
itself still appears. `composer deptrac` passes
`--cache-file=storage/framework/cache/.deptrac.cache` and every test that spawns Deptrac passes its
own path under `storage/framework/cache/`, so this only happens when a human or an agent runs the
bare binary. Check `git status --porcelain` for `??` entries before certifying a tree as
committable - counting entries is not enough, read them.

**Two more stray files, and these ones kill the gate before Pest ever starts.** T-005 added
`HostStanProbe.php` and `HostDeptracProbe.php`, planted inside `modules/host/tests/Feature` to prove
`composer stan` and `composer deptrac` can really fail. A killed process (composer's **300 s
per-script timeout**, measured at least once) runs no `finally` and no `afterEach`, so a probe
survives. `dev` moved the cleanup into `beforeEach` because that is the only hook a kill cannot skip -
sound reasoning about PHP teardown, but it **does not protect the gate**: `composer check` is
`@lint @stan @deptrac @test`, so a leftover `HostDeptracProbe.php` aborts at step 3 of 4 and
`HostStanProbe.php` at step 2, long before any Pest hook runs. Measured, T-005 QA: `Violations 2`,
`Tests\Feature\HostDeptracProbe must not depend on …PingController` - which reads exactly like a real
defect. The sibling `modules/demo/tests/Feature/QaStanProbe.php` (from `QaTestCodeAnalysisCoverageTest`,
T-002) is worse: no `beforeEach` at all, and it is **not** git-ignored, so `git add -A` would commit
it. Diagnostic order before blaming any change: `git status --porcelain`, `ls modules`, and look for
`*Probe.php` under every analysed tests directory. Backlog row: FP-172.

**Fixed in T-005 by position, and the shape generalises: a cleanup only protects the steps that run
after it.** `modules/host/tools/clean-probes.php` is dependency-free, boots no framework (it must work
when the application cannot), holds the one registry of all three probe paths, and is the **first**
entry of `composer check`; all three paths are git-ignored. Verified by planting all three and running
the whole gate to exit 0 from that state, having first watched the same tree give PHPStan `Found 4
errors` and Deptrac `Violations 2`. **Mutate position, not just existence:**
`expect($check[0])->toBe('@probes:clean')` is what catches a cleanup drifting to second place, and it
is the strongest available form because first means before everything - moving it to index 1 and
deleting it both go red. **But the registry half is two hand-kept lists** (the script's array, and a
`$planted` literal in `HostTestAnalysisEnforcementTest`), so a probe a *future* test plants and nobody
registers is invisible - measured: the case passes, `probes:clean` exits 0 leaving the file, and
`composer stan` then reports it. The fix shape this project already owns is `toolchainGroupFiles()` vs
`filesSpawningChildProcesses()`: compare the hand-kept registry against a **discovered** set (scan
every `*Test.php` for `/['"][^'"]*Probe\.php['"]/`). Rule: *a registry guard is only as strong as the
discovered side of its comparison; two hand-kept lists check spelling, not completeness.* Also note
`composer` IS resolvable on this machine (`composer.bat` in `C:\ProgramData\ComposerSetup\bin`), so a
test may spawn `['composer', 'run', …]` - CLAUDE.md's "not on PATH" is about the bare `php` invocation
convention, not about `Process::run`.

**A QA report certifies a tree, so re-measure after any fix round.** T-004 run 2 passed at
303/1352; the BUG-4 fix then added a case, and the report was certifying a tree that no longer
existed - wrong addition list (27/132 vs 28/138) and a now-false claim that no surviving file's
`expect(` lines had changed. Cheap rule: after the last fix lands, re-run `pest --log-junit` ×3 and
the two git diffs before signing off, and label every figure with which tree it describes.

**A repo-wide text sweep reads the working tree, not the commit (T-005 review S-5).** The
vocabulary sweep's corpus is every file not git-ignored, so an *untracked scratch note* that quotes
what the sweep forbids turns `composer check` red on one machine and green in CI. It caught a real
one: the lead's own uncommitted backlog edit. Fix by describing the thing rather than quoting it;
widening the sweep is the wrong reflex. Before certifying, `git status --short` and read every `??`
and ` M` entry as potential corpus. Same family: the sweep must exempt ALL SIX hand-off folders -
the release folder was missed (R-3) and would have gone red at the next role's step, so an
exemption list is checked against the *set it is meant to mirror*, not against the folders that
happen to hold a file today.

**A diff of two empty outputs proves nothing.** Comparing a function's output before and after a
refactor, I got IDENTICAL on the first try - because both runs had died on a bad require path and
written nothing (stderr was sent to /dev/null). Always print the count and check it is the known
number (17 spawning test files here) and that the "old" artefact genuinely lacks the new symbol.

**How to apply.** (1) Reproduce the split from your own JUnit logs; never check their arithmetic.
(2) Before believing any FP-156-style claim, `git log` first - T-004's whole line-ending
deliverable existed only as an untracked file in run 1. (3) Serialise suite runs, and check the
frozen `MODULES` value before blaming the change under test. (4) Re-derive mechanism claims in a
docblock rather than quoting them. Full evidence: `docs/qa/T-004-gate-speed-and-hygiene.md`.
Related: [[module-host-fragile-areas]], [[qa-negative-control-every-guard]].
