---
name: gate-split-and-shared-state
description: How to verify the T-004 fast/full test split and the root line-ending rule in this repo - the measured numbers, the techniques that make a coverage proof independent of the developer's report, and the two shared files that make a whole run red for reasons that have nothing to do with the change
metadata:
  type: project
---

T-004 split the gate into `composer test` (the gate, no group flag), `composer test:fast`
(`--exclude-group=toolchain`) and `composer test:slow`, plus a root `.gitattributes`. Measured on
this machine, **run 2, 2026-10-01 (these supersede the run-1 figures)**:

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
   `services/host/vendor` ships ~18 files matching `*Test.php`.
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

**Raw bytes, not the shipped test.** `SvcMakeDemoRegenerationTest` and
`QaTestCodeAnalysisCoverageTest` hash `md5(str_replace("\r\n", "\n", …))` on purpose, so they
cannot prove a byte-for-byte regeneration. Do it by hand as a **three-way** comparison:
clone's `services/demo` → live tree → `rm -rf services/demo && git checkout -- services/demo` →
`mv` aside, `artisan svc:make demo`, then `find . -type f | sort | xargs md5sum` on each (11
files). All four states must agree; the same comparison against a checkout of the *parent*
commit must differ on exactly the 5 files that were CRLF there. Restore from the moved-aside copy
and re-verify the md5 list, not just `git status`.

**Two shared files turn one mistake into a hundred meaningless failures.**
`services/host/bootstrap/cache/config.php` is created deliberately by `ConfigCacheGuardTest`,
`QaCacheGuardBranchesTest` and `QaCredentialSurfaceSweepTest`, and every other test needs it
absent: with it present, `ServiceHostServiceProvider::register()` throws `Cached configuration
disagrees with the process environment` during *boot*, so each later test fails with an error
naming configuration caching and nothing about itself. Seen twice (T-003 run 3, T-004 run 1: 106
failures); both times the cause was **a second suite running in the same working tree**, most
plausibly the task-completion hook's `composer check` overlapping a QA run. Diagnose without
guessing: the frozen `SERVICES` value in the message names the test that wrote the file
(`demo,qacachefixture` is `QaCacheGuardBranchesTest`); if that test *passed* in the same run, its
closing `expect(File::exists(...))->toBeFalse()` proves the file came from another process.
Serialise every suite run and it does not happen - T-004 run 2 ran 40 suite executions back to
back with zero poisoning. The second such file is `bootstrap/cache/routes-v7.php`
(`RouteCachingTest`, same discipline).

**The Windows fixture-delete race, measured.** With a read handle open on a file inside a
directory: `unlink()` on the file **succeeds**, `rmdir()` on the directory **fails**, and
`rmdir()` succeeds immediately once the handle closes. So the files go, the empty directory
stays, and `svc:make` then refuses the name ("already exists. Nothing was created or modified"),
failing the *next* case for an unrelated reason. `tryDeleteFixtureDirectory()` /
`deleteFixtureDirectory()` in `services/host/tests/Helpers.php` are the shared fix (12 attempts,
`gc_collect_cycles()` + 50 ms before each retry ≈ 550 ms of grace). Reproduce the condition with
`fopen` on a file in the directory - no mocking needed.

**A third stray file, and it is not the suite's fault.** `vendor/bin/deptrac analyse` **without**
`--cache-file` writes a ~1 MB `.deptrac.cache` into the current directory, and
`services/host/.gitignore` has no entry for it, so it shows as untracked-and-not-ignored and
`git add -A` would commit it. `composer deptrac` passes
`--cache-file=storage/framework/cache/.deptrac.cache` and every test that spawns Deptrac passes its
own path under `storage/framework/cache/`, so this only happens when a human or an agent runs the
bare binary. Check `git status --porcelain` for `??` entries before certifying a tree as
committable - counting entries is not enough, read them.

**A QA report certifies a tree, so re-measure after any fix round.** T-004 run 2 passed at
303/1352; the BUG-4 fix then added a case, and the report was certifying a tree that no longer
existed - wrong addition list (27/132 vs 28/138) and a now-false claim that no surviving file's
`expect(` lines had changed. Cheap rule: after the last fix lands, re-run `pest --log-junit` ×3 and
the two git diffs before signing off, and label every figure with which tree it describes.

**How to apply.** (1) Reproduce the split from your own JUnit logs; never check their arithmetic.
(2) Before believing any FP-156-style claim, `git log` first - T-004's whole line-ending
deliverable existed only as an untracked file in run 1. (3) Serialise suite runs, and check the
frozen `SERVICES` value before blaming the change under test. (4) Re-derive mechanism claims in a
docblock rather than quoting them. Full evidence: `docs/qa/T-004-gate-speed-and-hygiene.md`.
Related: [[service-host-fragile-areas]], [[qa-negative-control-every-guard]].
