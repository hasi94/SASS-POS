# QA Report: T-005 — rename services to modules

Spec `docs/specs/T-005-rename-modules.md` (AC-1 to AC-26) · Plan `docs/plans/T-005-rename-modules.md`
(9 steps; **step 6 was QA's and is implemented by this run**) · Decisions D-43 to D-56.
Branch `ai/T-005-rename-modules`, 7 commits, nothing pushed, nothing committed by `qa`.
Measured on this machine (Windows 11, PHP 8.2.12, MariaDB 10.4) on **2026-10-02**.

## Verdict: PASS (run 2, after `dev`'s BUG-1 / BUG-2 fix at `174061a`)

Every one of the 26 acceptance criteria passes with evidence I produced myself. `composer check`
exits 0 at **314 passed / 1414 assertions** — and, separately, **also exits 0 at 314 / 1414 starting
from a tree with all three probes planted**, which is the reproduction of BUG-1 I supplied.
The absence of the old vocabulary is proven with three controls and eight mutations, not by a green
suite.

**BUG-1 and BUG-2 are verified fixed**, mutation-proven five ways, not accepted from the report.
**One new minor finding, BUG-3:** the new registry assertion does *not* catch the regression its
commit message names, because both sides of its comparison are hand-kept lists. Demonstrated, not
argued. It fails no acceptance criterion and does not block the reviewer.

Run-1 figures (312 / 1402) are kept below where they were measured, labelled with the tree they
describe.

---

## What `qa` implemented (plan step 6)

`modules/host/tests/Feature/QaVocabularyRenameSweepTest.php` — new, 5 cases / 20 assertions, in
`composer test:fast` (spawns no child process, so deliberately **not** in `toolchainGroupFiles()`).
Cost measured 2026-10-02: 234 files enumerated, 201 swept after exemptions, 1.26 MB read, 0.41 s for
the enumeration; 0.82 s for the whole file — under 0.5 % of a full run.

Six `.claude/agent-memory/qa-engineer/**` files updated, one moved with `git mv` so history follows:
`project_service-host-fragile-areas.md` → `project_module-host-fragile-areas.md`, plus the
`MEMORY.md` index line and the three `[[service-host-fragile-areas]]` back-links.

### Where the implementation improves on the plan's design, with reasons

1. **The corpus respects `.gitignore`** (`Finder::ignoreVCSIgnored()`), which the plan did not
   specify. Measured: with the plan's name-only exclusion list the corpus swept
   `.claude/settings.local.json` — machine-local, git-ignored, and it **contains `services/`** in a
   stale permission entry. The sweep would have been red on one developer's machine and green in CI.
   With `ignoreVCSIgnored()` the corpus is exactly `git ls-files` plus whatever is untracked and not
   ignored (measured: `comm -13` and `comm -23` against `git ls-files` → 232 = 229 tracked + the 3
   untracked T-005 records, which the exemptions remove). That is AC-5's scope exactly, and it is
   *stronger* than a tracked-file list: a stray `services/` directory left untracked in a working
   tree is still caught.
2. **`vendor` and `node_modules` are still pruned by name**, because `ignoreVCSIgnored()` alone
   leaves the 8 `vendor/flexipos/*` path-repository symlinks in the corpus and walks the whole
   dependency tree first — measured 4.38 s against 0.41 s.
3. **Build directories get no on-disk existence assertion.** The plan asked for one on every
   exclusion including `node_modules/`, `.angular/`, `bin/`, `obj/`. Measured: `frontend/`,
   `print-agent/`, `gateway/`, `infra/` and `plugins/` each hold only a `.gitkeep`, so those
   assertions would fail on a tree where nobody has run `npm ci`. A name-based prune cannot go stale
   the way a path exemption can, so the existence assertion is kept for exactly the seven semantic
   exemptions (`Refference`, the five record folders, `CLAUDE.md`), where a stale entry really would
   hide a hit.
4. **The token-by-token proof comes from historical records, not from a synthetic string.** The
   plan's control 1 reassembles the tokens from the same fragments the sweep uses, so it cannot
   detect a mis-assembled fragment — the producer guarantees the match by construction. The sweep
   therefore also asserts that `docs/plans/T-001-monorepo-service-host.md` (prose written before this
   token list existed) still demonstrates **17 of the 19** labels and
   `docs/qa/T-002-service-host-hardening.md` the 18th, and that the only label with no independent
   tracked witness is the phpunit suite label — stated in the file, with the compositional argument
   for why its fragments are nonetheless covered. **Proven necessary:** mutation C3 below breaks the
   abbreviation fragment, control 1 passes, control 2 names the three affected tokens.
5. **The allowance list covers the prefix, not the one full name — and the measured count is 3
   files / 5 occurrences, not `dev`'s "four files".** Verified independently with
   `git grep -n 'svc_'`: `QaTestCodeAnalysisCoverageTest.php:249` (the dead replacement key the plan
   names), `QaAssertionShapeGuardTest.php:136` (a bad-shape specimen held as a fixture), and
   `QaModuleConnectionRemovalTest.php:49,90,245` (three **live** assertions that the prefix is
   absent — renaming them would delete the assertion rather than update it). `dev`'s substance is
   right; the file count is 3.
6. **Every allowance must be *needed*.** Beyond "the literal is still on disk", a fourth assertion
   fails any allowance row that redacts nothing the sweep would otherwise report — a justification
   with nothing left to justify is dead weight pretending to be a reason.

### Token list as implemented

16 case-sensitive literals, 1 case-insensitive literal (`svc`), 2 regular expressions = **19
labels**. The brief's "sixteen literal tokens plus two regular expressions" counts only the
case-sensitive literals; the architect's R7 table lists 17 literal entries, the 17th being
case-insensitive `svc`. Implemented as the architect specified; the count discrepancy is naming, not
coverage.

---

## Acceptance criteria coverage

| AC | Test(s) / evidence I ran | Result |
|---|---|---|
| **AC-1** baseline captured | Baseline is `main` = **304 passed / 1358 assertions**, exit 0, confirmed three ways: `docs/qa/T-004-gate-speed-and-hygiene.md` lines 102/231 (the tree `main` points at), architect's step-0 measurement of 304 `<testMethod>` entries, and my own arithmetic below (full 312/1402 minus the three declared new classes' 8 cases / 44 assertions = **exactly 304 / 1358**) | PASS |
| **AC-2** the rename alone adds and removes nothing | Independent, from git, not from `dev`'s report. Rename map for test files via `git diff --name-status -M --find-renames=20% main..HEAD` → a complete 1:1 map, 2 additions, **0 deletions**. For each of the 40 surviving pairs I diffed the column-0 `^(it\|test)\(` declarations of the `main` blob against the `HEAD` blob, with the old→new vocabulary applied to the baseline text: **every remaining difference is an artefact of my own normaliser** (baseline "service package" → my naive map produces "module package" where `dev` wrote "module"). Zero cases added, removed or renamed in any pre-existing file. The same diff over every line containing `expect(`, `->and(`, `$this->assert` or `->assert` reduces to three explicable classes: `$this->get(…)` → `get(…)` (the FP-169 fix, 1:1), the fixture rename `Svcmakefixture` → `Modulemakefixture`, and the one declared chain split at `QaHostSuiteCollectionProofTest` — `expect($replaced)->toBeString()->not->toBe($xml)` became two expectations, **both assertions kept** | PASS |
| **AC-3** FP-169 additions declared, not silent | **Run 2 (current tree): 314/1414**, `314 − 10 = 304` and `1414 − 56 = 1358`; the two extra cases are named in `174061a` and verified by a by-name JUnit set diff against run 1 (+2, −0, 0 changed). Run 1 evidence, still valid for the tree it describes: full run 312/1402. Per-`testClass` JUnit table: `HostTestAnalysisCoverageTest` 1 case / 12 assertions, `HostTestAnalysisEnforcementTest` 2 / 12, `QaVocabularyRenameSweepTest` 5 / 20 (mine, declared here, required by AC-5/AC-6). 8 cases / 44 assertions in total; `312 − 8 = 304` and `1402 − 44 = 1358` land exactly on AC-1. The three FP-169 cases are named by the plan's step 2 and by `grep -nE '^(it\|test)\(' ` on the two files. No pre-existing class changed its case count or assertion count; nothing skipped (`<skipped>`/`<warning>`/`<error>` element count in the JUnit: **0**) | PASS |
| **AC-4** definition of an old identifier | Implemented as 19 labelled matchers in `qaVocabLiteralTokens()`, `qaVocabCaselessTokens()`, `qaVocabPatternTokens()`. The named non-identifiers are proven not to match: a synthetic subject holding `Illuminate\Support\ServiceProvider`, `Flexipos\Module\Demo\DemoServiceProvider`, `config/services.php`, `src/Services/`, `Module_Demo`, `flexipos/module-demo`, `stubs/module` and `MODULES=demo` yields **zero** labels, and `modules/host/config/services.php` is swept rather than exempted (0 hits) | PASS |
| **AC-5** zero matches | `QaVocabularyRenameSweepTest::it has no file whose name or contents carries the old vocabulary` → `[]` over **201 swept files / 1.26 MB** (234 enumerated − 33 exempt, measured with this report in place). Reproduced by hand before writing the test: `git ls-files` (229) minus the exclusions (200 files) fed to `grep -F -f` with the 16 literals, `grep -i svc`, and both regexes → the only hits were the 6 qa-engineer memory files (step 6's own work, now fixed), `docs/backlog.md:28-29` (the two branch names) and two record-filename citations — i.e. exactly the allowance list. Paths searched as well as contents | PASS |
| **AC-6** positive control | Three, all measured. (a) The sweep's own witness case: 17 of 19 labels found in `docs/plans/T-001-monorepo-service-host.md`, the 18th in `docs/qa/T-002-service-host-hardening.md`. (b) By hand on `docs/qa/T-003-tenant-connections.md`: `services/` ×13, `SERVICES` ×1, `SvcMake` ×9, `ServiceRegistry` ×2, `svc` ×10 — five distinct identifiers against AC-6's "at least three". (c) Against the spec's named pre-rename commit: `git grep -c -F -f <patterns> 0e9a1a2` → `deptrac.yaml` 4, `phpunit.xml` 3, `tests/Helpers.php` 30 | PASS |
| **AC-7** literal path lists updated, not merely present | `filesSpawningChildProcesses()` now iterates `['modules', 'packages']`; `toolchainGroupFiles()` is 16 `modules/host/...` paths (was 15 — `HostTestAnalysisEnforcementTest.php` is the addition); `toolchainGroupExemptions()` and `qaFixturePackageNames()` current. `ToolchainGroupCoverageTest` (4), `QaToolchainRegistryCoverageTest` (9) and `HostTestAnalysisCoverageTest` (1) all pass — 14 cases / 64 assertions. **Two negative controls**, in place, then restored and verified with `md5sum -c`: pointing one registry entry at `RouteCachingTestGONE.php` turns 2 cases red; changing the directory list to `['servicez', 'packages']` turns 1 case red | PASS |
| **AC-8** `Refference/` untouched | `git diff --stat main -- Refference` → empty | PASS |
| **AC-9** historical records keep their substance | `git diff --name-only main -- Refference docs/specs docs/plans docs/qa docs/reviews docs/decisions` → **empty**. Zero notes added, which plan R4 elects under AC-9's "if any". (T-005's own four records are untracked, so they do not appear; see the note for the lead) | PASS |
| **AC-10** `modules/` exists, `services/` does not | `ls -d modules modules/host modules/demo` all present; `test -e services` false. Also proven by the sweep's path half: 0 path hits over the whole corpus | PASS |
| **AC-11** `MODULES` boots, `SERVICES` does not | Independent probe outside the suite (`bootstrap/app.php` + the real HTTP kernel): `MODULES=demo` → `/api/v1/demo/ping` **200** `{"module":"demo","status":"ok"}`; `MODULES=` → zero modules, ping **404**, `/health/live` 200; `MODULES=nosuchmodule` → **fails fast** with `App\Support\ModuleHost\UnknownModuleException :: Unknown module "nosuchmodule" listed in MODULES: no module found at …\modules\nosuchmodule.` (names both the module and `MODULES`); **`SERVICES=demo` boots zero modules** — the old variable has no effect. Plus 61 cases / 310 assertions in the renamed registry tests | PASS |
| **AC-12** `/health/ready` field | Measured bodies above: `{"status":"ok","modules":["demo"]}` and `{"status":"ok","modules":[]}`. Neither contains `"services"`, and the regex `['"]services['"]` finds nothing anywhere in the corpus, which covers the field name wherever it is written | PASS |
| **AC-13** namespace, package name, manifest key, provider | Committed demo: `flexipos/module-demo`, `Flexipos\Module\Demo\`, `extra.flexipos.module`, provider `Flexipos\Module\Demo\DemoServiceProvider`. Freshly generated `qasweepfixture`: `flexipos/module-qasweepfixture`, `Flexipos\Module\Qasweepfixture\`, `extra.flexipos.module`, `Flexipos\Module\Qasweepfixture\QasweepfixtureServiceProvider` | PASS |
| **AC-14** Composer consistency | `composer install` in `modules/host` → **exit 0**, autoload dumped, `package:discover` clean. `ls vendor/flexipos` → `module-demo` present, no `service-demo`. Read through PHP rather than grep (shell backslash quoting gives false zeroes here): of 108 PSR-4 prefixes, `Flexipos\Module\` = 1, `Flexipos\Service` = **0**; `class_exists('Flexipos\Module\Demo\DemoServiceProvider')` true, `class_exists('Flexipos\Service\Demo\DemoServiceProvider')` false. `grep -c 'flexipos/service-' composer.lock` → **0**, and the sweep reads `composer.lock` as part of its corpus | PASS |
| **AC-15** the registry's refusals still work | 61 cases / 310 assertions across `ModuleRegistryManifestTrustTest`, `QaManifestTrustAttackTest`, `QaModuleRegistryContractTest`, `QaModuleNameValidationTest`, `ModulesEnvParsingTest` — every T-002 case present under the new vocabulary: wrong kind of class (enum, trait, interface, abstract, private/protected constructor), namespace not equal to `Flexipos\Module\<Studly>\`, provider outside its namespace, smuggled second PSR-4 entry (either order), duplicated JSON key, trailing newline, trailing/doubled hyphen, wrong case, path traversal, underscore, and `host` | PASS |
| **AC-16** generator works, `svc:make` gone | `artisan module:make qasweepfixture` created 11 files; its own generated test ran with **no manual fix-up**: 3 passed / 6 assertions. `artisan svc:make foo` → `There are no commands defined in the "svc" namespace.` Refusals re-proved by hand: `Bad_Name` → invalid-name message naming the pattern; `host` → reserved-name message naming `modules/host`; `demo` → `modules/demo already exists. Nothing was created or modified.`; `trailing-` → invalid. Fixture deleted afterwards | PASS |
| **AC-17** byte for byte, fresh checkout | T-004 AC-10 discipline, raw bytes, no line-ending normalisation. `git ls-files --eol modules/demo` → `i/lf w/lf` on all 11 entries. `rm -rf modules/demo && git checkout -- modules/demo` → `md5sum` list A; `cp -a` aside, `rm -rf`, `artisan module:make demo` → list B; **`diff A B` empty**, `git status --porcelain modules/demo` empty. Restored from the moved-aside copy and re-verified: list C == list A | PASS |
| **AC-18** generator reminder | Verbatim: `Created modules/qasweepfixture. Add 'qasweepfixture' to MODULES to boot it.` / `Also register modules/qasweepfixture/src and modules/qasweepfixture/tests in phpstan.neon.dist (paths) and in deptrac.yaml (paths, plus a Module_Qasweepfixture layer and its ruleset entry), or it is neither statically analysed nor boundary-checked.` No `services/`, no `Service_` | PASS |
| **AC-19** history follows | `git log --follow --oneline` reaches pre-rename commits on all five files I checked: `ModuleRegistry.php` → `ad7fb27` (T-002), `741307c` (T-001); `ModuleMakeValidationTest.php` → `bcd7595`, `e6c1934`, `ad7fb27`, `741307c`; `QaModuleNameValidationTest.php` → `bcd7595`, `741307c`; `modules/demo/composer.json` → `e6c1934`, `741307c`; `UnknownModuleException.php` → `741307c`. **Positive control:** the same command on `HostTestAnalysisEnforcementTest.php` (created in step 2) shows only its own 4 T-005 commits and reaches nothing older — so the check is not printing history for everything | PASS |
| **AC-20** reported as renames | Move commit `7775908`, stated as such in its own message. `git show --stat -M --find-renames=100%` → **117 files changed, 0 insertions(+), 0 deletions(-)**; `--diff-filter=A` → 0 lines; `--diff-filter=D` → 0 lines; `--name-status -M --find-renames=100%` → 117 entries, **all `R100`**, 0 non-rename entries. The identifier edits are a separate commit (`99996d4`), so no content-heavy edit is mixed in | PASS |
| **AC-21** static analysis of the host's tests | `phpstan.neon.dist` lists `- tests`. `HostTestAnalysisEnforcementTest` case 1 passes: a planted untyped **function** parameter makes `composer stan` exit non-zero and name `HostStanProbe.php` and the message, then the clean tree exits 0; the probe is gone afterwards. Independently: FP-169 caught a real level-6 error in **my own new test file** on first run (`Finder::followLinks() invoked with 1 parameter, 0 required`) — the clearest possible evidence the directory is really analysed. Negative control on the registration: removing `- tests` from `phpstan.neon.dist` turns `HostTestAnalysisCoverageTest` red at the exact assertion; restored, `md5sum -c` OK | PASS |
| **AC-22** boundary check of the host's tests | `deptrac.yaml` lists `- tests` and a `HostTests` layer collecting `^Tests\\.*`, with a ruleset granting `HostApp` and the seven `Package_*` layers and **no** `Module_*` layer. Enforcement case 2 passes. Confirmed by hand: with `HostDeptracProbe.php` planted, Deptrac reports `DependsOnDisallowedLayer  Tests\Feature\HostDeptracProbe must not depend on Flexipos\Module\Demo\Http\Controllers\PingController`, `Violations 2`; with it removed, **Violations 0 / Warnings 0 / Errors 0** on the shipped tree, so `TestCase`, `Helpers.php`, `Pest.php` and the `Tests\Fixtures\DeptracViolation\*` stand-ins produce no violation. Negative control: removing `- tests` from `deptrac.yaml` turns the coverage test red; restored, `md5sum -c` OK | PASS |
| **AC-23** registry coverage | `QaToolchainRegistryCoverageTest` 9 cases pass and no longer leave the host's tests unregistered. Both halves of the new registration mutation-tested (PHPStan and Deptrac separately), each turning `HostTestAnalysisCoverageTest` red | PASS |
| **AC-24** collection unchanged | `HostTestSuiteCollectionTest`, `QaHostSuiteCollectionProofTest`, `QaTestSuiteIsolationTest` → 6 passed / 33 assertions, including "it collects no test file into two suites at once" and "a nothing-matching filter still performs the whole collection, then that collection is duplicate free". `phpunit.xml` keeps one recursive `Host` suite plus globbed `../*/tests` with `<exclude>../host/tests</exclude>`; suite renamed to `Modules`, env renamed to `MODULES`. The full run's JUnit holds no `<warning>` element | PASS |
| **AC-25** `CLAUDE.md` byte-identical | `git diff main -- CLAUDE.md` → **empty**. The line list for the lead is in plan step 8: delete line 15 (the "Vocabulary note" paragraph), change the nine `cd services/host` rows (lines 39–47), change the `SERVICES` bullet (line 53). I re-measured `CLAUDE.md` against the token list: `services/` ×10, `SERVICES` ×1, `svc:make` ×1 — consistent with that list and with nothing else | PASS |
| **AC-26** forward-looking documents | `README.md`: `GET /health/ready -> {"status":"ok","modules":["demo"]}`, "Adding a module" uses `php artisan module:make billing`, `MODULES` throughout, "no file inside `modules/host/` needs to change". `modules/host/README.md`: `MODULES`, `modules/<name>/`, `cd modules/host`, `module:make billing`. `docs/deployment-vps.md`: `all 13 modules, MODULES=all` and the diagram's "13 modules". `docs/backlog.md` rows FP-169/FP-202 renamed. I resolved every link and bracketed path in all six documents: the only non-resolving strings are prose placeholders (`modules/billing/`, `modules/<name>/`, `plugin.json`, `docs/releases/vps-preflight-<date>.md`) and one runtime artefact (`bootstrap/cache/config.php`) — no broken reference. `docs/database.md` unchanged; `docs/architecture.md` changed, see OBS-1. T-002 AC-11 regression re-checked: `substr_count(strtolower($config), 'documented only')` in `config/modulehost.php` is still **3** (the phrase is upper-case in the file; a case-sensitive grep returns 0 and is not the assertion). `QaDocumentationAccuracyTest` 4 passed / 42 assertions | PASS |

---

## Test run summary (run 1 — the tree before `dev`'s BUG-1/BUG-2 fix; run 2 is in its own section below)

Every suite run was serialised — no two in the same working tree at once (the `bootstrap/cache/config.php`
poisoning lesson from T-003/T-004).

| # | Command | Result |
|---|---|---|
| 1 | `pest --log-junit pre-step6.xml` (tree before step 6) | exit 0 — **307 passed, 1382 assertions**, 92.00 s |
| 2 | `pest tests/Feature/QaVocabularyRenameSweepTest.php` | exit 0 — 5 passed, 20 assertions, 0.82 s |
| 3 | `pint --test .. ../../packages` | `{"tool":"pint","result":"passed"}` |
| 4 | `phpstan analyse` (first run with my new file) | **1 error** — `Finder::followLinks()` arity, in my file. Fixed, then `[OK] No errors` |
| 5 | `deptrac analyse` | Violations 0 / Skipped 0 / Uncovered 58 / Warnings 0 / Errors 0 |
| 6 | `pest --log-junit post-step6.xml` | exit 0 — **312 passed, 1402 assertions**, 91.34 s |
| 7 | **`composer check`** (after step 6) | **exit 0** — Pint passed, PHPStan `[OK] No errors`, Deptrac 0/0/0, Pest 312/1402, 120 s wall |
| 8 | `pest --exclude-group=toolchain --log-junit fast.xml` | exit 0 — **257 passed, 965 assertions**, 29.76 s |
| 9 | `pest --group=toolchain --log-junit slow.xml` | exit 0 — **55 passed, 437 assertions**, 55.10 s |
| 10 | `composer install` in `modules/host` | exit 0 |
| 11 | `composer check` after `composer install` | exit 0 — Pint passed, PHPStan `[OK] No errors`, Deptrac 0/0/0, Pest 312/1402, 90.45 s; 100 s wall |
| 12 | **`composer check`, final certification of the exact tree handed off** (after the memory edits and the sweep's docblock edit) | **exit 0** — Pint `{"result":"passed"}`, PHPStan `[OK] No errors`, Deptrac `Violations 0 / Warnings 0 / Errors 0`, Pest **312 passed / 1402 assertions**, 83.33 s; **97 s wall** |

Plus 10 single-file / filtered runs for the mutations and negative controls below, 2 generator runs,
4 out-of-suite boot probes, and 1 regeneration cycle. **Zero failures anywhere that was not a
deliberate mutation.**

**Split reproduced from my own JUnit logs, not from arithmetic in anyone's report**
(`scratchpad/split.php`, parsing `classname::name` and the per-case `assertions` attribute):

```
full=312/1402  fast=257/965  slow=55/437
union(fast,slow)=312/1402      union == full: YES
intersection(fast,slow)=0      per-case assertion mismatches: 0
declared additions: 8 cases / 44 assertions
full minus additions: 304 cases / 1358 assertions   <- lands exactly on the AC-1 baseline
```

257 + 55 = 312 and 965 + 437 = 1402. Two independent derivations (JUnit arithmetic, and the
by-name declaration diff from git) meet on the same baseline pair.

### Mutations run against the new sweep, each restored and verified with `md5sum -c`

| # | Mutation | Must go red | Observed |
|---|---|---|---|
| A | A probe file containing `ServiceRegistry` and `services/host` planted in the corpus | AC-5 case | red, naming the file, the line and both labels |
| B | A probe file whose **name** is `ServiceRegistryProbe.txt` and whose contents are clean | AC-5 case (path half) | red, `… (in its path): the former registry class` |
| B2 | Not a mutation — a real catch. While updating my own agent memory at the end of this run I wrote `services/` and the old abbreviation into two sentences of `project_negative-control-every-guard.md`. The standing sweep turned red on both, naming file and line. Rephrased, not allowance-listed | — | the sweep works on live content, not only on planted probes |
| C | `'lower' => 'ser'.'vce'` (typo in a fragment) | witness control | 3 cases red, witness names the missing tokens |
| C2 | `'screaming' => 'SER'.'VICES'` | witness control | red: `Missing: the former environment variable` |
| C3 | `'short' => 'sv'.'k'` | witness control **only** — control 1 cannot see it | control 1 **passed**, witness red: `Missing: the former abbreviation, the former generator class prefix, the former generator signature`. This is the proof that the synthetic control alone would be a tautology |
| D | An allowance literal that is no longer on disk | allowance liveness case | red, naming the file and the justification |
| E | `.claude` added to the exemption list | corpus case | red: "the sweep is not reading files it is supposed to read" |
| F | `modules` added to the pruned directory names | corpus case | red |

---

## Defects

### BUG-1 (major) — the cleanup-before-planting fix does not protect the gate, because `composer check` runs Deptrac and PHPStan *before* Pest

- **Steps:** plant `modules/host/tests/Feature/HostDeptracProbe.php` with the exact heredoc
  `HostTestAnalysisEnforcementTest` writes (simulating the killed run that was measured), then run
  the gate's steps in order.
- **Expected:** per the comment in `modules/host/tests/Feature/HostTestAnalysisEnforcementTest.php`
  (`beforeEach`) and `.claude/agent-memory/developer/feedback_cleanup_must_survive_a_killed_process.md`
  — "cleaning up before planting is the only thing that survives a kill, because it moves the
  guarantee out of the dying process and into the next one" — the next run should not be poisoned.
- **Actual:** measured. `@lint` passes, `@stan` passes, **`@deptrac` reports**
  `DependsOnDisallowedLayer  Tests\Feature\HostDeptracProbe must not depend on Flexipos\Module\Demo\Http\Controllers\PingController` / `Violations 2`
  and the gate aborts at step 3 of 4. The `beforeEach` hook never runs, because Pest is step 4.
  A surviving `HostStanProbe.php` does the same at step 2. The *root cause analysis* is correct (no
  PHP teardown runs when a process is killed) and the fix is a genuine improvement for a bare
  `composer test` run — but it does not close the incident that was actually measured, and both the
  code comment and the memory note state the guarantee as closed.
- **Suspected location:** `modules/host/tests/Feature/HostTestAnalysisEnforcementTest.php`
  (`beforeEach`, lines ~96-110) and `modules/host/composer.json`'s `check` script.
- **Cheapest fix:** the only step that runs before `@stan` in the gate is the gate itself — prepend
  a probe-removal step to the `check` script, e.g.
  `"@php -r \"foreach ([__DIR__.'/tests/Feature/HostStanProbe.php', __DIR__.'/tests/Feature/HostDeptracProbe.php'] as $p) { is_file($p) && unlink($p); }\""`.
  If that is judged out of scope for T-005, then the comment and the memory note must say what they
  actually guarantee, and FP-172's detail paragraph should name this mechanism — otherwise the next
  reader will debug the same red gate.
- **Mitigations that do exist:** both probe names are git-ignored (added by this task), so a leftover
  cannot be committed; and any `composer test` run clears them.
- **FIXED at `174061a`, verified by me — see "Run 2" below.**

### BUG-2 (minor, pre-existing from T-002, same family — in scope as a regression risk)

- **Steps:** plant `modules/demo/tests/Feature/QaStanProbe.php` (the probe
  `QaTestCodeAnalysisCoverageTest` writes) and run `composer stan`.
- **Expected:** the same discipline `dev` just established for the host probes.
- **Actual:** `QaTestCodeAnalysisCoverageTest` has **no `beforeEach`** at all — `grep -n beforeEach`
  returns nothing — so nothing cleans up before it plants. `composer stan` then reports
  `modules/demo/tests/Feature/QaStanProbe.php:3  Function qaStanProbeUntyped() has parameter $value with no type specified.`
  and the gate dies at step 2 of 4. Worse than BUG-1 in one respect: the path is **not git-ignored**
  (`git check-ignore` finds no rule, `git status` shows `?? modules/demo/tests/Feature/QaStanProbe.php`),
  so `git add -A` would commit a file carrying a deliberate level-6 defect.
- **Suspected location:** `modules/host/tests/Feature/QaTestCodeAnalysisCoverageTest.php`
  (`afterEach` at line 114, `qaStanProbePath()` at line 25) and the absence of a `modules/demo/.gitignore`
  rule. The new rule `dev` wrote should be applied to the sibling with the identical shape, or the
  `check`-level cleanup in BUG-1 should cover this path too.
- **FIXED at `174061a`, verified by me — see "Run 2" below.**

### BUG-3 (minor, new in run 2) — the probe registry cannot catch the regression its commit message names

- **Steps:** create `modules/host/tests/Feature/QaUnregisteredProbe.php` carrying a level-6 defect —
  a stand-in for a probe some future test plants and nobody registers. Run
  `pest --filter='names every probe'`, then `composer run probes:clean`, then `composer stan`.
- **Expected:** per `174061a`'s commit message and the case's own comment — "the script's `--list`
  output must equal the set the suite plants, so adding a probe without registering it fails the gate
  instead of reintroducing BUG-2".
- **Actual:** measured. The registry case **passes** (1 passed, 1 assertion). `composer run
  probes:clean` exits 0 and leaves the file on disk. `composer stan` then reports
  `Found 2 errors` naming `modules\host\tests\Feature\QaUnregisteredProbe.php`, so the gate dies at
  its analysing step exactly as in BUG-1. The guarantee holds only if the author *also* adds the new
  path to the hard-coded `$planted` array inside `HostTestAnalysisEnforcementTest` — which is the very
  step a forgetful author skips. **Both sides of the comparison are hand-kept lists in two files;
  neither is discovered from the suite.**
- **Suspected location:** `modules/host/tests/Feature/HostTestAnalysisEnforcementTest.php`,
  `it names every probe the suite can plant in the gate cleanup registry` — the `$planted` literal.
- **Fix, and the project already owns the pattern:** this is precisely the
  `toolchainGroupFiles()` / `filesSpawningChildProcesses()` shape, where the hand-kept registry is
  compared against a **discovered** set. Give the probe registry a discovery half: enumerate every
  `*Test.php` under every `modules/*/tests` and `packages/*/tests` (the `filesSpawningChildProcesses()`
  enumeration already exists), collect string literals matching `/['"][^'"]*Probe\.php['"]/`, resolve
  them relative to the planting file's package, and require the discovered set to be a subset of the
  script's `--list`. A new planting test then fails the gate without anyone editing two lists.
- **Not blocking:** it needs someone to add a *new* probe-planting test before it bites, and it fails
  no acceptance criterion. It is reported because it is the same family as BUG-1 — a prose guarantee
  the code does not provide — and because `dev`'s own newly recorded rule ("before claiming a guard
  closes a hole I must execute the failing case and watch it pass") was not applied to this case.

---

## Observations and deviations (not defects)

- **OBS-1 — `docs/architecture.md` was edited, which AC-26 said should happen only if the AC-5
  search found a match (it found none).** The edit replaces the §4 diagram's top-level `app/` with
  `modules/host/` and adds a note that the host is a sibling of the modules (D-43). The content is
  correct and the architect ruled on it explicitly in plan R1, inviting `ba` to disagree. Recorded
  for the lead and the reviewer because it touches a document the spec said to leave alone; it is a
  sanctioned deviation, not an error.
- **OBS-2 — `dev`'s allowance correction is right in substance, wrong in count.** The old connection
  prefix is in **3 files / 5 occurrences**, not four files. Verified with `git grep -n 'svc_'`. The
  sweep's allowance covers the prefix and names all three files.
- **OBS-3 — `.claude/settings.local.json` still contains a `services/` path.** Git-ignored and
  machine-local, so outside AC-5's scope and deliberately outside the sweep's corpus (see
  improvement 1 above). It will simply never match a real directory again; whoever owns that file
  may want to update it.
- **OBS-4 — spec cross-reference nit.** AC-3 says the FP-169 additions are "at least those required
  by AC-19 and AC-20"; AC-19/AC-20 are the git-history criteria. It reads as though AC-21/AC-22 were
  meant. No effect on the verification.
- **OBS-5 — `composer validate` warnings.** Three `unbound version constraints (*) should be
  avoided` for the `flexipos/*` path packages. Pre-existing: `main`'s manifest has the same `*`
  constraints, with only the demo package's name changed by this task.
- **Shell quoting trap, for the next reader.** `grep -c -F 'Flexipos\\Module\\Demo\\'` returns 0 on
  a file that visibly contains that text in Git Bash here. Both the "old prefix is absent" and "new
  prefix is present" claims in AC-14 are therefore taken from PHP reading
  `vendor/composer/autoload_psr4.php`, plus a backslash-free regex with a demonstrated positive
  control. Never certify an absence from a backslash-bearing shell pattern in this environment.
- **OBS-6 (run 2) — one assertion in the new registry case is conditional, so its count depends on
  what else was collected.** `if (function_exists('qaStanProbePath'))` makes
  `it names every probe the suite can plant in the gate cleanup registry` report **2** assertions in
  a full or `--group=toolchain` run and **1** in a single-file or filtered run (measured:
  `HostTestAnalysisEnforcementTest` standalone is 4 cases / 23 assertions, in the full run 4 / 24).
  Harmless today — both `HostTestAnalysisEnforcementTest` and `QaTestCodeAnalysisCoverageTest` are in
  the `toolchain` group, so full and slow agree and my full-vs-halves check shows 0 per-case
  mismatches — but it is a count that moves with collection on a task whose contract is "counts do not
  change". Reading the path out of the other file's source instead of depending on its function being
  loaded would make it unconditional.
- **OBS-7 (run 2) — `probes:clean` guards `check`, not the individual commands.** A developer who
  runs `composer stan` or `composer deptrac` directly after a killed run still sees the probe. That is
  the documented residual and belongs with FP-172; the gate is what the task contracts on.

---

## Run 2 — re-test of `dev`'s BUG-1 / BUG-2 fix (`174061a`)

Nothing below is taken from `dev`'s report; every line is a command I ran.

### The fix as shipped

`modules/host/tools/clean-probes.php` — dependency-free, boots no framework, holds the one registry
of three probe paths, supports `--list` with marked output. Wired as
`"probes:clean": "@php tools/clean-probes.php"` and
`"check": ["@probes:clean", "@lint", "@stan", "@deptrac", "@test"]`. `tools` added to the PHPStan
paths. `/modules/demo/tests/Feature/QaStanProbe.php` added to the root `.gitignore`. Two new cases in
`HostTestAnalysisEnforcementTest`.

### BUG-1 — verified FIXED

| Check | Result |
|---|---|
| The script is **genuinely tracked** (the `**/bin/` trap `dev` reported) | `git ls-files --error-unmatch modules/host/tools/clean-probes.php` → the path; `git check-ignore -v` → no rule. Tracked and not ignored |
| **Control first:** all three probes planted by hand, then the gate's analysing steps | PHPStan `Found 4 errors` naming `modules\demo\tests\Feature\QaStanProbe.php` and `modules\host\tests\Feature\HostStanProbe.php`; Deptrac `Violations 2`. This is the red gate BUG-1 described, reproduced |
| **The reproduction `dev` claims:** `composer check` from that same planted state | **exit 0** — Pint passed, PHPStan `[OK] No errors`, Deptrac 0/0/0, Pest **314 passed / 1414 assertions**, 117 s wall. All three probes gone afterwards. Since PHPStan reported 4 errors on the identical tree minutes earlier and now reports none, the cleanup demonstrably ran **before** `@stan` |
| `composer check` from a clean tree | exit 0 — same four results, 314 / 1414, 114 s wall |

### Do the two new assertions catch the regressions they name? Mutation-tested, five ways

Each mutation applied in place, then restored and verified with `md5sum -c` on `composer.json` and
`tools/clean-probes.php`.

| # | Mutation | Claim under test | Observed |
|---|---|---|---|
| M1 | `@probes:clean` moved to **second** place: `["@lint","@probes:clean","@stan","@deptrac","@test"]` | "a cleanup that drifts to second place is silently useless again" | **red** at `expect($check[0] ?? null)->toBe('@probes:clean')`. The claim holds |
| M2 | `@probes:clean` removed from `check` entirely, script left in place | the wiring, not merely the script's existence | **red**, same assertion |
| M3 | the demo probe dropped from the script's registry | "adding a probe without registering it fails the gate" (the BUG-2 direction) | **both** new cases red; the registry diff names the missing path |
| M5 | `@unlink()` commented out, wiring left correct | the behavioural half, not just the position | **red** — the script's own survivor report exits 1 and the existence assertions fail |
| M4 | an **unregistered** probe (`QaUnregisteredProbe.php`) planted, as a future test would | the same claim as M3, from the other side | **the registry case PASSES.** `composer run probes:clean` exits 0 and leaves it; `composer stan` then reports 2 errors naming it. **This is BUG-3** |

So: the **position** assertion is sound and catches exactly the drift it names — `$check[0]` being
`@probes:clean` is the strongest form available, since being first is being before everything. The
redundant-looking `array_search('@stan', $check, true) > 0` is implied by it, but is not useless: it
also goes red if `@stan` is removed from the gate altogether. The **registry** assertion is sound in
three of its four directions and blind in the fourth (BUG-3).

### Counts, re-derived

| | cases | assertions |
|---|---|---|
| full (`pest`) | **314** | **1414** |
| fast (`--exclude-group=toolchain`) | 257 | 965 |
| slow (`--group=toolchain`) | 57 | 449 |
| fast ⊎ slow | **314** | **1414** |

`union == full: YES`, `intersection = 0`, **per-case assertion mismatches full vs halves: 0**.
Against my run-1 JUnit: **exactly 2 cases added** (`it removes every planted probe before the gate
reaches an analysing step`, 10 assertions; `it names every probe the suite can plant in the gate
cleanup registry`, 2), **0 removed**, and **0 surviving cases whose assertion count changed**.
All T-005 additions together are 10 cases / 56 assertions, so `314 − 10 = 304` and `1414 − 56 = 1358`
— still exactly the AC-1 baseline. My 5 sweep cases are present and green inside the 314 (6 / 5 / 5 /
3 / 1 assertions), and the two original FP-169 cases are unchanged at 5 and 7.

### My own files, and the rename `dev`'s commit picked up

`174061a` includes my staged `git mv` as
`{…project_service-host-fragile-areas.md => …project_module-host-fragile-areas.md} | 0`. Verified a
**pure rename, zero bytes changed**, by blob identity rather than by the stat line:
`git rev-parse main:…/project_service-host-fragile-areas.md HEAD:…/project_module-host-fragile-areas.md`
→ the same SHA `63c4f01`. My content edits to that file are still unstaged, the other five memory
files are untouched by the commit, and the sweep test and this report remain untracked. Nothing of
mine was altered.

### Corrections `dev` accepted

Re-verified on the current tree: the allowance is still 3 files / 5 occurrences and the sweep still
reports `[]`; `.gitignore` now ignores all three probe paths
(`modules/host/.gitignore:28,29` and `.gitignore:74`, each confirmed with `git check-ignore -v`); and
`dev`'s memory note no longer claims the `beforeEach` closes the gate hole.

---

## Worktree state at hand-off

`qa` committed nothing and pushed nothing. `git status --porcelain --untracked-files=all` after the
final certification run:

```
 M .claude/agent-memory/qa-engineer/MEMORY.md
 M .claude/agent-memory/qa-engineer/feedback_assert-contract-not-symptom.md
 M .claude/agent-memory/qa-engineer/project_gate-split-and-shared-state.md
 M .claude/agent-memory/qa-engineer/project_module-host-fragile-areas.md
 M .claude/agent-memory/qa-engineer/project_negative-control-every-guard.md
 M .claude/agent-memory/qa-engineer/project_tenancy-boundary-leak-surfaces.md
 M docs/backlog.md
?? docs/decisions/T-005-rename-modules.md
?? docs/plans/T-005-rename-modules.md
?? docs/qa/T-005-rename-modules.md
?? docs/specs/T-005-rename-modules.md
?? modules/host/tests/Feature/QaVocabularyRenameSweepTest.php
```

(In run 1 the first memory entry was `RM`; `174061a` committed the rename half, so only my content
edit is left.)

Clean in the sense that matters: **no stray artefacts.** No `.deptrac.cache`, no `HostStanProbe.php`,
`HostDeptracProbe.php`, `QaStanProbe.php` or `QaUnregisteredProbe.php`, no leftover generated module
(`ls modules` → `demo host` only), `modules/demo` byte-identical to its committed blob, and
`composer.json`, `tools/clean-probes.php`, `tests/Helpers.php`, `phpstan.neon.dist` and `deptrac.yaml`
all `md5sum -c` OK after their mutations. Every entry above is intended task output.

**For the lead.** My five content-edited memory files and the new sweep test still need a commit; and
`docs/specs`, `docs/plans`, `docs/decisions/T-005-rename-modules.md`, this report and the
`docs/backlog.md` edit are still untracked or unstaged from the other roles. Plan step 7 expected
`git diff --name-only main -- … docs/specs docs/plans …` to list T-005's four files; it lists none,
because they have never been committed. That makes AC-9's proof stronger, not weaker, but the records
do need to land.

---

Run 1: PASS — all 26 AC pass, 2 defects (1 major, 1 minor), neither failing an AC. `composer check`
exit 0 at 312 passed / 1402 assertions; baseline reconciliation 312 − 8 = 304 and 1402 − 44 = 1358;
fast ⊎ slow = full with 0 per-case assertion mismatches; absence proven with 3 controls and 8
mutations.
Run 2: PASS -> PASS — BUG-1 and BUG-2 verified fixed at `174061a`, mutation-proven five ways (M1 M2
M3 M5 red at the named assertion; M4 exposed BUG-3). `composer check` exit 0 at 314 passed / 1414
assertions from a clean tree **and** from a tree with all three probes planted, which is the
reproduction I supplied; the pre-fix control on that same planted tree is PHPStan `Found 4 errors` and
Deptrac `Violations 2`. +2 cases / +12 assertions, both named, 0 removed, 0 surviving case changed;
`314 − 10 = 304` and `1414 − 56 = 1358` still land on the AC-1 baseline; fast ⊎ slow = full, 0
per-case mismatches. The cleanup script is genuinely tracked and all three probe paths are ignored.
New: BUG-3 (minor, non-blocking) — the probe-registry assertion compares two hand-kept lists, so it
cannot see a probe a future test plants; demonstrated by planting an unregistered one and watching the
case pass while `composer stan` reported it.
