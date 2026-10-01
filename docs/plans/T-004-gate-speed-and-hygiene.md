# Plan: T-004 Gate speed and repository hygiene

| | |
|---|---|
| Spec | [docs/specs/T-004-gate-speed-and-hygiene.md](../specs/T-004-gate-speed-and-hygiene.md) |
| Decisions | [docs/decisions/T-004-gate-speed-and-hygiene.md](../decisions/T-004-gate-speed-and-hygiene.md) (D-34 … D-39) |
| Covers | FP-155, FP-156, FP-157, FP-164 |
| Branch | `ai/T-004-gate-speed-and-hygiene` (from the T-003 tip) |
| Owner of every step | `dev`, except the three `qa` items marked **[qa]** |
| Architect measured on | 2026-09-29/30, this machine, this branch, before planning |
| Resynced | 2026-09-30 to the spec's renumbering (criteria now AC-1 … AC-20). **Mapping only — no measurement and no design decision changed.** |

> **Resync note for `dev` (read this if you started from the pre-resync copy).**
> `ba` adopted the AC-2 restatement, reframed route caching as an ongoing guarantee, and
> inserted a **new AC-16** for the documentation correction. FP-164's criteria shifted by
> one: old AC-16…AC-19 are now **AC-17…AC-20**. Step numbers 1–11 are **unchanged** so
> nothing you have already picked up moves; the documentation correction has been lifted out
> of Step 8 into a new **Step 8b**. Every reference to `T-001 AC-…`, `T-002 AC-…` or
> `T-003 AC-…` in this plan is another task's numbering and was deliberately left alone.

---

## 0. Measurements taken before planning (not assumptions)

Every number below was produced by running the thing, not by reading a previous report.
Where a measurement contradicts the spec or a previous review, the measurement wins and the
correction is called out.

### 0.1 Baseline, corrected

| | Spec / QA report says | **Measured, twice, exit 0** |
|---|---|---|
| Tests | 277 | **279** |
| Assertions | 1219 | **1224** |

The T-003 **review** header already says 279 / 1224; `docs/qa/T-003-tenant-connections.md`
and therefore spec AC-1 say 277 / 1219. **The plan's baseline is 279 passed, 1224
assertions, exit 0.** `dev` re-confirms on the branch tip at Step 1 and records it; the
spec's own escape clause ("the count actually observed is what is recorded") covers this.

### 0.2 Gate timing — the reviewer's figures confirmed, corrected and extended

| Run | Pest duration | Top-10 share |
|---|---|---|
| First run of a session (OS cache cold, analysis caches warm) | **237.0 s** | 129.5 s (54.6 %) |
| Warm, immediately after | **140.6 s** | 47.3 s (33.6 %) |
| Controlled cold (PHPStan + Deptrac + config caches deleted first) | **175.8 s** | — |
| `composer lint` + `stan` + `deptrac`, warm | **3 s + 3 s + 2 s = 8 s** | — |

The reviewer's "top ten = ~50 s of a 152 s run" is a **warm** profile; my warm run gives
47.3 s of 140.6 s, so that figure is confirmed. The reviewer's *cold* claim of 152–156 s is
now stale — the suite has grown from 148 to 279 tests since it was taken. Cold is
**176–237 s** depending on what is cold. The non-test gate steps are noise (8 s); Pest is
the gate.

### 0.3 Where the time actually is (per-file, from `--log-junit` on the controlled cold run)

13 host test files spawn a child process that boots a whole tool. They account for
**105.2 s of 175.2 s of test-case time (60 %) across 48 of the 279 cases (17 %)**:

| s | cases | file |
|---|---|---|
| 57.81 | 4 | `tests/Feature/QaTestCodeAnalysisCoverageTest.php` |
| 9.79 | 3 | `tests/Feature/QaCacheGuardBranchesTest.php` |
| 7.14 | 3 | `tests/Feature/QaHostSuiteCollectionProofTest.php` |
| 6.07 | 2 | `tests/Feature/ConfigCacheGuardTest.php` |
| 5.65 | 3 | `tests/Feature/DeptracBoundaryEnforcementTest.php` |
| 4.89 | 2 | `tests/Feature/QaRegenerationGuardTest.php` |
| 4.51 | 1 | `tests/Feature/PintLintFixRoundTripTest.php` |
| 2.12 | 19 | `tests/Feature/QaManifestTrustAttackTest.php` |
| 1.77 | 2 | `tests/Feature/HostTestSuiteCollectionTest.php` |
| 1.75 | 2 | `tests/Feature/SvcMakeAtomicGenerationTest.php` |
| 1.54 | 5 | `tests/Feature/QaModuleConnectionRemovalTest.php` |
| 1.11 | 1 | `tests/Feature/QaGeneratedServiceTestDiscoveryTest.php` |
| 1.08 | 1 | `tests/Feature/QaTestSuiteIsolationTest.php` |

A 14th file spawns a child process and is **deliberately not in the group** (D-37 / AC-5):
`packages/kernel/tests/Feature/QaCredentialSurfaceSweepTest.php` (7.26 s / 7 cases).

Second-most expensive file in the whole suite is `TenancyCredentialLeakTest.php`
(27.63 s / 24 cases) — also protected by AC-5, also stays in the fast loop. The fast
command is not fast because it skips slow tests; it is fast because it skips *tool-boot*
tests.

### 0.4 The fast command, simulated end to end

Running the real suite with the 13 files excluded by class name
(`pest --exclude-filter='/(ConfigCacheGuardTest|…|SvcMakeAtomicGenerationTest)/'`):

**231 passed, 837 assertions, 52.2 s, exit 0.**

231 + 48 = 279 and 837 + 387 = 1224, so the split is exact and arithmetically closed
*before* any code is written. Projected effect: **52 s instead of 141 s warm / 176–237 s
cold, with 83 % of the test cases still running.**

### 0.5 Per-test fixture cost (FP-155 item 2), measured

The tenancy fixture (two SQLite files created with `PDO`, then the directory deleted) costs
**95.5 ms per cycle** on this machine (67 cycles benchmarked). 67 test cases pay it →
**~6.4 s**, i.e. 3.6 % of the cold gate but **12 % of the projected fast command**. Hoisting
is worth doing and is *not* where the big win is. Say so in the QA report so nobody reads a
6-second saving as the point of FP-155.

### 0.6 Line endings — the defect reproduced and the fix proven, in a throwaway clone

`git clone --local` of this repository into the scratchpad with `core.autocrlf=true`
(this machine's configured value):

| | files |
|---|---|
| Tracked files | 214 |
| Covered by `services/host/.gitattributes` (`attr/text=auto`) | 102 |
| **Not covered by any rule** | **112** |
| Working-tree CRLF **in a fresh clone** | **88** (36 in `packages/kernel`, 22 in `docs`, 5 in `services/demo`, 12 in `.claude`, the rest root files) |
| Working-tree CRLF **in the live tree right now** | 4 (`Refference/ERP.md`, `docs/eventbus.md`, `docs/qa/T-002-service-host-hardening.md`, `docs/trade-off.md`) |

Then, in the clone: add root `.gitattributes` → `git add --renormalize .` → **zero index
changes** → delete the 88 CRLF working files and `git checkout -- .` → **0 CRLF, working
tree clean**.

Two consequences that change the plan:

1. **There is no renormalisation churn to fear in this repository.** Every index blob is
   already LF (`git ls-files --eol` shows `i/lf` for all 190 text files), because
   `core.autocrlf=true` normalises on check-*in*. The commit AC-9 describes will contain
   `.gitattributes` **and nothing else**. The danger AC-9 guards against is real in general
   and absent here — proven, not assumed.
2. **The live symptom is worse than the spec says.** Pint's `line_ending` fixer rejects a
   CRLF PHP file — measured: `pint --test` on a CRLF copy of
   `packages/kernel/src/Tenancy/TenantDatabase.php` reports
   `"result":"fail","fixers":["line_ending",…]`. So on a fresh clone today, `composer check`
   fails at **step 1 of 4 (`lint`)**, on ~41 files under `packages/` and `services/demo`,
   before the regeneration test is ever reached. FP-156 is not cosmetic.

### 0.7 `route:cache` already succeeds — the documented reason it fails is false

Measured on this branch, **before any change**:

```
$ php artisan route:cache
  INFO  Routes cached successfully.        (exit 0)
```

`Illuminate\Routing\Route::prepareForSerialization()` in Laravel 12 serialises a closure
route with `SerializableClosure::unsigned()`; the `LogicException("Unable to prepare route
[…] Uses Closure.")` that `README.md`, `config/servicehost.php`, the T-002 plan and spec
AC-14 all rest on **no longer exists in this framework version**.

`php artisan route:list --json` shows there are in fact **four** closure routes, not one:
`/` (`routes/web.php:5`), `storage/{path}` GET and PUT (registered by
`FilesystemServiceProvider` because `config/filesystems.php`'s `local` disk sets
`'serve' => true`), and `/up` (registered by `ApplicationBuilder` from `health: '/up'` in
`bootstrap/app.php`). Removing the welcome route alone would never have been sufficient if
the premise had been true.

This is the third time in this project a framework fact was recorded as measured and
contradicted by the source (T-002 R-1, T-003 R-1 and the "flattened trace" fact). The plan
treats it the same way those were treated: delete the false sentence, keep the true one,
and convert the fact into an executing assertion.

### 0.8 A live flake, found while measuring

The controlled cold run came back **1 failed, 278 passed**:

```
FAILED Tests\Feature\ServiceRegistryManifestTrustTest > it refuses a provider class declared
outside the package own namespace
Failed asserting that 1 is identical to 0.
at tests/Feature/ServiceRegistryManifestTrustTest.php:37
  expect(Artisan::call('svc:make', ['name' => $name]))->toBe(0);
```

`svc:make` returns 1 with *"services/trustfixture already exists. Nothing was created or
modified."* (`SvcMakeCommand.php:85`). The cause is already documented in this repository,
in `QaCredentialSurfaceSweepTest::qaCredDeleteFixtureRoot()`: on Windows
`File::deleteDirectory()` opens a `FilesystemIterator` whose handle can outlive the `unset`,
so the files go and the directory stays. That file applies a second pass and a
`clearstatcache()`; the `svc:make` fixture teardowns do not. Eight tests in
`ServiceRegistryManifestTrustTest` create and delete `services/trustfixture` in turn, so it
loses that race occasionally. Step 7 fixes it, because a gate that is red at random cannot
prove AC-2.

---

## 1. Approach summary

Four independent changes, four independent commit groups, ordered so that no commit's diff
is polluted by another's.

1. **FP-156 goes first and alone.** Root `.gitattributes`, then a working-tree refresh of
   the (currently four) CRLF files. Measured: the commit contains one new file and no
   renormalised blobs. Doing it first means every file created later is born under the rule.
2. **FP-164** moves the tenancy fixture functions out of `TenancyResolutionTest.php` into
   `packages/kernel/tests/Support/tenancy-fixture.php`, `require_once`d by the four files
   that use them. They stay *functions with the same names*, so not one call site changes
   and not one assertion can change meaning. They stay under `packages/kernel/tests`, which
   is the only relocation that keeps PHPStan and Deptrac coverage (T-002 AC-16/AC-17).
3. **FP-155** is a group tag declared in **one place** — a registry function in a new
   `services/host/tests/Helpers.php`, applied by one `pest()->group('toolchain')->in(…)`
   call in `tests/Pest.php`. No test file is edited to carry a tag, so no QA-owned file is
   touched for this. `composer test:fast` is a pure addition; `composer test` and
   `composer check` keep their exact current meaning. Then the fixture hoist, limited to
   the three files where it is provably safe.
4. **FP-157** deletes the scaffolding, replaces the welcome route with a real controller so
   T-002 D-18's guarantee survives (D-39), corrects the false `route:cache` documentation,
   and pins the corrected framework fact with a test.

The defining risk — silent coverage loss — is handled structurally rather than by
discipline: **`composer test` passes no group flag and `phpunit.xml` declares no `<groups>`
element**, so no tag can subtract from the gate. The tag can only subtract from the opt-in
fast command, and a lint keeps that subtraction honest.

## 2. Alternatives rejected

- **Directory split (`tests/Toolchain/`) instead of a group tag** — rejected by D-34, and
  independently: it would break `QaHostSuiteCollectionProofTest`'s file-path probes and
  collide with the FP-202 rename.
- **`uses()->group('toolchain')` written into each of the 13 test files** — works (verified
  in Pest's source: `TestRepository::use()` merges rather than throwing, so a second `uses()`
  in a file already bound by `Pest.php` is safe), but 8 of the 13 files are QA-owned and it
  scatters the registry across 13 places. The central `pest()->group()->in(…)` form gives one
  canonical list, which the guard can then check. Rejected.
- **Shared helpers in `packages/testing/src/`** — its manifest literally says "tenant
  fixtures", and it is already in `phpstan.neon.dist`, `deptrac.yaml` and the coverage
  guard. Rejected anyway: its Deptrac ruleset is `Package_Testing: ~` (may depend on
  nothing), so a helper touching `Flexipos\Kernel\Tenancy\*` would be a boundary violation,
  and it declares no framework dependency (FP-159). Changing either is out of scope.
- **Shared helpers in `services/host/tests/Helpers.php` (Pest's own convention)** — correct
  for *host* helpers and used for exactly that below, but `services/host/tests` is
  deliberately outside `phpstan.neon.dist` and `deptrac.yaml`, so moving the kernel's
  tenancy helpers there would lose static-analysis coverage the spec's regression list
  explicitly protects.
- **Converting the tenancy helpers to a class with static methods** — cleaner PSR-4, but it
  rewrites ~150 call sites across four files in a task whose spec says no assertion may
  change meaning. Rejected on risk.
- **Also removing `health: '/up'` and setting `filesystems.disks.local.serve => false`** to
  eliminate the remaining closure routes — unnecessary, since route caching already
  succeeds with them (§0.7), and each is a behaviour change with no criterion behind it.
  Left alone; noted as a possible later row.
- **Parallel execution.** Not planned, not attempted, and not merely overlooked — see §8.

## 3. Files to change / create

### FP-156 (Step 1)

| Path | Change |
|---|---|
| `.gitattributes` | **new.** `* text=auto eol=lf`, plus `*.bat`/`*.cmd`/`*.ps1 text eol=crlf` (so the rule agrees with root `.editorconfig`'s `[*.{bat,ps1}] end_of_line = crlf`, ahead of the .NET print agent) and `*.ico binary`. |
| `services/host/.gitattributes` | **left in place** (spec §Out of scope leaves this to `dev`; leaving it costs nothing and keeps the T-002 change intact). |

### FP-164 (Step 2)

| Path | Change |
|---|---|
| `packages/kernel/tests/Support/tenancy-fixture.php` | **new.** The 13 `tenancy*()` functions moved verbatim from `TenancyResolutionTest.php` (declared at lines 36, 41, 49, 59, 79, 103, 167, 187, 200, 210, 222, 239, 254). |
| `packages/kernel/tests/Feature/TenancyResolutionTest.php` | delete the function block; add `require_once __DIR__.'/../Support/tenancy-fixture.php';` |
| `packages/kernel/tests/Feature/TenancyTenantSwitchTest.php` | add the same `require_once` |
| `packages/kernel/tests/Feature/TenancyCredentialLeakTest.php` | add the same `require_once` |

### FP-155 (Steps 3–7)

| Path | Change |
|---|---|
| `services/host/tests/Helpers.php` | **new.** Loaded by Pest's `BootFiles` bootstrapper *before* `Pest.php` (verified in `vendor/pestphp/pest/src/Bootstrappers/BootFiles.php`, `STRUCTURE` order), for every command including a single-file run. Holds: `toolchainGroupFiles()` (the canonical registry), `toolchainSpawnTokens()` + `filesSpawningChildProcesses()` (the detector), `toolchainGroupExemptions()` (the D-37/AC-5 exact-name exemption list), `deleteFixtureDirectory()` (Step 7), and the relocated `qaFixturePackageNames()` (Step 7). |
| `services/host/tests/Pest.php` | add one block: `pest()->group('toolchain')->in(...toolchainGroupFiles());` |
| `services/host/composer.json` | add `test:fast` and `test:slow` scripts + `scripts-descriptions`. `test` and `check` unchanged. |
| `services/host/tests/Feature/ToolchainGroupCoverageTest.php` | **new.** The decay guard (§5). |
| `services/host/tests/Unit/ToolchainGroupDetectorTest.php` | **new.** Unit test of the detector; also the replacement anchor for `QaHostSuiteCollectionProofTest` once `tests/Unit/ExampleTest.php` goes. |
| `packages/kernel/tests/Support/tenancy-fixture.php` | Step 6: split creation/deletion out so the three safe files can hoist them. |
| `packages/kernel/tests/Feature/{TenancyResolutionTest,TenancyTenantSwitchTest,TenancyCredentialLeakTest}.php` | Step 6: `beforeAll`/`afterAll` for the SQLite files; everything else stays per-test. |
| `services/host/tests/Feature/{ServiceRegistryManifestTrustTest,SvcMakeValidationTest,SvcMakeAtomicGenerationTest,SvcMakeGeneratedServiceReachableTest,SvcMakeDemoRegenerationTest,UnlistedServiceReturns404Test,DeptracBoundaryEnforcementTest,PintLintFixRoundTripTest}.php` | Step 7: `afterEach` uses `deleteFixtureDirectory()` instead of bare `File::deleteDirectory()`. |

### FP-157 (Steps 8–9)

| Path | Change |
|---|---|
| `services/host/routes/web.php` | closure replaced by `Route::get('/', RootController::class)->name('root');` |
| `services/host/app/Http/Controllers/RootController.php` | **new.** `__invoke()` returns `response()->noContent()` (204). |
| `services/host/tests/Feature/HealthEndpointTest.php` | the "keeps the stock welcome route inside the web group" case becomes "keeps the root route inside the web group" — same guarantee (D-18/D-39), new probe. |
| `services/host/tests/Feature/RouteCachingTest.php` | **new.** AC-14/AC-15 + the corrected framework fact, **plus the AC-16 documentation-correction case** (Step 8b). |
| `services/host/tests/Feature/ExampleTest.php` | **deleted** |
| `services/host/tests/Unit/ExampleTest.php` | **deleted** |
| `services/host/resources/views/welcome.blade.php` | **deleted**; keep `resources/views/` with a `.gitkeep` (no `config/view.php` ships, so the framework's default view path must still exist). |
| `services/host/resources/css/app.css`, `resources/js/app.js`, `resources/js/bootstrap.js`, `vite.config.js`, `package.json` | **deleted** (`package.json` is not named in AC-12 but only declares the vite/tailwind build that AC-12 removes; leaving it would leave a manifest for files that no longer exist). |
| `services/host/composer.json` | `setup` drops `npm install` / `npm run build`; `dev` drops `npx concurrently … npm run dev` and keeps `serve`, `queue:listen`, `pail`. No Composer package added or removed — nothing may rewrite `composer.lock` in this task (FP-159 precedent). |
| `services/host/.env.example` | remove `VITE_APP_NAME` |
| `services/host/config/servicehost.php` | **AC-16.** Constraint 3: delete the false second sentence only ("It also fails outright today, because routes/web.php still has the stock closure route on `/` and closures cannot be serialised"). Keep the true clause and the `DOCUMENTED ONLY` label. |
| `README.md` (root) | **AC-16.** Item 3 likewise: drop "and currently fails outright because of the stock closure route on `/`", keep "*Documented only*"; add `composer test:fast` to "Getting started". |
| `services/host/README.md` | add `composer test:fast` to the command block. |
| **[qa]** `services/host/tests/Feature/QaHostSuiteCollectionProofTest.php:152` | anchor `'test_that_true_is_true' => 'tests/Unit/ExampleTest.php'` → the new unit test's name and path. |
| **[qa]** `services/host/tests/Feature/QaCacheGuardBranchesTest.php:145` | **No change expected.** `substr_count(strtolower($config), 'documented only') === 3` must keep passing **unchanged** — spec AC-16 now says so explicitly, agreeing with this plan's original measured prediction: the `DOCUMENTED ONLY` label does not disappear, only the false clause inside point 3 does, because the freeze hazard remains genuinely unguarded whether or not the command throws. `dev` re-runs the assertion after the AC-16 edit; if the count moves, the edit removed too much and the edit is wrong, not the assertion. |
| **[qa]** `services/host/tests/Feature/QaToolchainRegistryCoverageTest.php` | `qaFixturePackageNames()` moves to `tests/Helpers.php` (Step 7); zero call sites change. |

`services/host/tests/Feature/QaDocumentationAccuracyTest.php` needs **no** change: its
narrow MIT-banner exemption for the welcome view's inlined Tailwind becomes dead but stays
correct.

## 4. Data / API changes

None. No schema, no migration, no endpoint contract. Two surface changes worth recording:

- `GET /` changes from `200 text/html` (welcome page) to `204 No Content`. Nothing consumes
  it; `tests/Feature/ExampleTest.php` was its only caller and is deleted.
- Two new Composer scripts, `test:fast` and `test:slow`. Neither is referenced by the
  task-completion hook (D-36).

## 5. The two questions the brief asked me to settle

### 5.1 How the split is expressed, and what stops an untagged slow test rejoining the fast loop

**Expression.** Group tag `toolchain` (D-34), declared in exactly one place:

```php
// services/host/tests/Pest.php
pest()->group('toolchain')->in(...toolchainGroupFiles());
```

with `toolchainGroupFiles()` in `services/host/tests/Helpers.php` returning the 13 paths
relative to `tests/`. Verified against Pest's source: `Pest\Configuration::group()` returns
a `UsesCall` with no class/trait bindings, `UsesCall::in()` globs and `realpath()`s the
targets, and `TestRepository::use()` **merges** into whatever `Pest.php` already registered
for the directory rather than throwing — so this cannot collide with the existing
`uses(TestCase::class)->in(__DIR__)`. `BootFiles` loads `Helpers.php` before `Pest.php`, so
the registry function exists when `Pest.php` runs.

**Commands** (D-35, D-36):

| Script | Meaning |
|---|---|
| `composer check` | **the gate, unchanged** — `lint`, `stan`, `deptrac`, `test` |
| `composer test` | **unchanged** — `config:clear` then unfiltered `pest`. No group flag. |
| `composer test:fast` | new — `config:clear` then `pest --exclude-group=toolchain`. Inner loop only. |
| `composer test:slow` | new — `pest --group=toolchain`. Exists so the split can be *proved* by count and by name without hand-typing flags, and so a later CI job can shard. |

**Why the gate cannot lose a test, structurally.** `composer test` carries no `--group` /
`--exclude-group`, and `phpunit.xml` declares no `<groups>` element. Tagging is therefore
incapable of subtracting from `composer check`; it can only subtract from an opt-in command.
This is asserted, not just stated — see guard layer 1.

**The decay risk is the opposite one:** someone adds a 14th child-process test, forgets the
registry, and it quietly rejoins the fast loop. **Ruling: yes, there is a guard, and it is
modelled on the `qaFixturePackageNames()` precedent — an exact list plus a sweep that fails
loudly when reality and the list disagree.** Three layers, in
`tests/Feature/ToolchainGroupCoverageTest.php` unless noted:

1. **Structural (fast).** `composer.json`'s `test` and `check` scripts contain no
   `--group`/`--exclude-group`; `phpunit.xml` contains no `<groups>` element; `test:fast`
   *does* contain `--exclude-group=toolchain`. Negative control: the same matcher applied to
   the `test:fast` script string must report a hit, so "no flag found" is evidence and not
   absence of evidence.
2. **Registry lint (fast).** Sweep every `*Test.php` under every `*/tests` root (the same
   enumeration `QaAssertionShapeGuardTest::qaShapeTestFiles()` already does). A file
   "spawns" if its source matches `Process::`, `proc_open(`, `shell_exec(`, `popen(`,
   `passthru(` or `\bexec\s*\(`. Assert
   `spawners − toolchainGroupExemptions() === toolchainGroupFiles()` as **sets of paths**,
   plus: the registry is non-empty, every registry path exists on disk, and every exemption
   path exists on disk (a stale exemption must not be able to hide a real gap).
   `toolchainGroupExemptions()` contains exactly one entry today —
   `packages/kernel/tests/Feature/QaCredentialSurfaceSweepTest.php` — with a comment naming
   D-37 and AC-5 and the reason (it spawns a child to inspect the *real* `dump()` handler;
   it is a credential-surface proof, not a toolchain proof). Negative control: write a
   throwaway file under `storage/app/` containing a spawn token and no registry entry, run
   the detector over it, assert it is reported, delete it. No child process, milliseconds.
3. **Live-binding proof (tagged `toolchain`, so it runs in the gate only).** A child
   `pest --list-groups` must list `toolchain`; a child
   `pest --group=toolchain --log-junit` must collect exactly the cases whose `file`
   attribute is in `toolchainGroupFiles()`, and no others. This is the layer that proves the
   *declaration actually binds*, which layers 1–2 cannot. It spawns, so it belongs in the
   group it describes.

Layers 1 and 2 run in `test:fast`, so a developer who forgets the registry sees it inside
the 52-second loop, not at the end of the 3-minute gate.

### 5.2 Proving no coverage is lost — by count and by name, not by wall clock

Three instruments, in increasing strength:

1. **Arithmetic, already closed before implementation.** §0.4: 231 + 48 = 279 and
   837 + 387 = 1224. The split is exact today.
2. **By name, once, as QA acceptance evidence.** `dev` produces three JUnit logs from the
   post-change tree and `qa` re-produces them independently:

   ```
   pest --log-junit full.xml
   pest --group=toolchain      --log-junit slow.xml
   pest --exclude-group=toolchain --log-junit fast.xml
   ```

   Extract the set of `classname::name` from each. Required results, all asserted, not
   eyeballed:
   - `slow ∪ fast === full` (no test lost to the split)
   - `slow ∩ fast === ∅` (no test double-counted, so the counts mean what they look like)
   - `full === baseline_full − {Tests\Feature\ExampleTest::…, Tests\Unit\ExampleTest::…} + additions`,
     where `additions` is the enumerated list in §6 Step 11. `dev` captures
     `baseline_full.xml` at Step 1, **before touching anything**, so the diff is against a
     real artefact rather than against a number in a report.
   - One honest wrinkle, called out rather than netted away: `HealthEndpointTest`'s D-39
     probe is **renamed**, and a by-name diff sees a rename as one removal plus one
     addition. AC-2's letter says nothing disappears except the two example tests, so this
     pair must be declared explicitly, as a pair, with the old and new descriptions written
     out side by side. Leaving the old description in place to keep the diff tidy is not an
     option — it would name a route that no longer exists, which is the same class of false
     documentation AC-16 exists to delete. See §9.
   - `slow` contains no test from `TenancyCredentialLeakTest` or
     `QaCredentialSurfaceSweepTest` (AC-5, asserted by name, not by trust).
3. **Permanently, in the gate.** Guard layers 1–3 above. Layer 1 is the one that matters:
   it makes "the gate runs everything" a property of the configuration.

**Why the by-name equality is not made a permanent test.** It would require the suite to
run itself twice more from inside itself — roughly tripling the gate, which is the opposite
of this task. Layer 3 gets the same guarantee for a single child run by comparing collected
files against the registry, and the full set-equality is a per-task QA artefact. State this
trade-off in `docs/qa/T-004-…md` so a later reviewer sees it was chosen, not missed.

### 5.3 Fixture hoisting — where it is safe and where it is not

**Hoist (Step 6).** `TenancyResolutionTest.php`, `TenancyTenantSwitchTest.php`,
`TenancyCredentialLeakTest.php`. Grepped and confirmed: no case in these three files writes
to a fixture database — the only DDL/DML against them is inside
`tenancyCreateFixtureDatabase()` itself. Move **only** the two SQLite file creations to a
file-scoped `beforeAll` and the `File::deleteDirectory()` to `afterAll`. Everything else
stays in `beforeEach`/`afterEach`: the env writes, `config()->set('tenancy.databases', …)`,
`forgetInstance(TenantDatabaseRepository::class)`, `forgetScopedInstances()` and
`Tenancy::leave()` — all of which are per-application state that Laravel rebuilds between
tests and which would simply be lost if hoisted.

Implementation caveat `dev` must respect: Pest's `beforeAll`/`afterAll` run outside the
application lifecycle, so `storage_path()` and the `File` facade are not available there.
The hoisted code must use native `mkdir`/`file_put_contents`/`PDO`/`unlink` and take the
root directory as a computed constant. If `beforeAll` proves awkward, the sanctioned
fallback is to keep `beforeEach` and make creation idempotent
(`if (! is_file($path)) { … }`) with deletion in `afterAll` — same saving, same proof
obligation.

**Do not hoist — each of these deliberately carries its own fixture so one case's failure
can never be another's teardown:**

- `packages/kernel/tests/Feature/QaTenantIsolationProofTest.php` — line 329 runs
  `create table qa_iso_scratch (v integer)` **against a fixture database, inside a
  transaction whose rollback is the thing under test**. If the rollback regresses, the table
  survives; per-test recreation is what stops that becoming the next case's starting state.
  Hoisting here would convert a caught bug into a mysterious later failure. (Also QA-owned.)
- `packages/kernel/tests/Feature/QaCredentialSurfaceSweepTest.php` — writes
  `bootstrap/cache/config.php` and spawns a child; 7 cases, ~0.7 s at stake. Not worth it.
  (QA-owned.)
- **Every `svc:make` fixture in `services/host/tests`** —
  `ServiceRegistryManifestTrustTest`, `QaManifestTrustAttackTest`, `SvcMakeValidationTest`,
  `QaServiceNameValidationTest`, `QaServiceRegistryContractTest`, `QaCacheGuardBranchesTest`
  and the rest. Each case *mutates the generated package differently* (a different manifest
  mutation per `it()`), and `SvcMakeCommand.php:85` refuses outright when the directory
  already exists. A hoisted fixture would make the second case in every one of these files
  fail. This is also why §0.8's flake is a *teardown robustness* problem and not an argument
  for hoisting.

## 6. Implementation steps

Each step ends green (`composer check` exit 0) before the next begins, unless marked.

- [ ] **Step 1 — Pin the baseline.** (AC-1)
      Run `composer check` on the branch tip. Record exit code, test count, assertion
      count. AC-1 now requires the **itemised set of test names**, not a total, kept
      somewhere `qa` can diff against later — so capture `pest --log-junit baseline_full.xml`
      **and** derive the flat, sorted `classname::name` list from it. Keep both under
      `services/host/storage/app/` (git-ignored) for the run, and paste the sorted list into
      the `[CODE]` hand-off so `qa` can record it in `docs/qa/T-004-…md`; `dev` must not
      write into `docs/qa/`. Expect **279 / 1224**; record whatever is observed and note the
      divergence from spec AC-1's still-cited 277 (AC-1's own escape clause covers it).
      *No file in the repository changes in this step.*

- [ ] **Step 2 — FP-156: root line-ending rule. Its own commit, nothing else in it.**
      (AC-8, AC-9, AC-11)
      1. Confirm `git status` is clean apart from this task's docs.
      2. Create root `.gitattributes` (§3).
      3. `git add .gitattributes && git add --renormalize .`
      4. `git status --porcelain` — **expected: only `.gitattributes`.** If anything else
         appears, it must be line-ending-only: prove with
         `git diff --cached --ignore-cr-at-eol` returning empty before committing.
      5. Commit alone: `T-004: root line-ending rule`.
      6. Refresh the working tree so it matches the rule:
         `git ls-files --eol | grep w/crlf | awk '{print $NF}' > /tmp/crlf.txt`
         **Use `$NF`, not `$4`** — once `.gitattributes` applies, the attr column contains
         two space-separated tokens and `$4` silently yields `eol=lf` instead of the path.
         I lost a cycle to exactly that. Then `xargs -a /tmp/crlf.txt rm -f` and
         `git checkout -- .`.
      7. Assert `git ls-files --eol | grep -c w/crlf` is **0** and `git status --porcelain`
         is clean. Measured expectation: 4 files rewritten, no diff.

- [ ] **Step 3 — FP-156: prove the original defect is fixed, not masked.** (AC-10, AC-11)
      Two proofs, both against a *genuine* checkout, never a leftover working tree:
      1. **Fresh clone.** `git clone --local <repo> <tmp>` with `core.autocrlf=true`, then
         `git ls-files --eol | grep -c w/crlf` → must be **0** (measured before the fix: 88,
         of which 5 are in `services/demo` and 36 in `packages/kernel`). In the same clone,
         `composer lint` must pass — measured before the fix: Pint's `line_ending` fixer
         fails a CRLF PHP file, so `composer check` died at step 1.
      2. **Re-checkout in place.** `rm -rf services/demo && git checkout -- services/demo`,
         then run the regeneration test *and* a raw-byte (non-normalised) comparison of the
         regenerated tree against the checked-out one. Before the fix the raw comparison
         fails on 5 files; after it, it passes. That is what converts "the test is green"
         into "the bytes genuinely match" and closes the T-002 R-1 loophole — the shipped
         test normalises before hashing, which is correct defensively but cannot itself
         prove the underlying defect is gone.
      3. AC-11: `git add --renormalize .` on the fixed tree prints no
         `LF will be replaced by CRLF` warning.
      Record all of it in the QA report. *No production file changes in this step.*

- [ ] **Step 4 — FP-164: relocate the tenancy fixture helpers.** (AC-17, AC-18, AC-19, AC-20)
      Create `packages/kernel/tests/Support/tenancy-fixture.php`; move the 13 `tenancy*()`
      functions verbatim; add `require_once __DIR__.'/../Support/tenancy-fixture.php';` to
      the four files that use them (including the one that used to declare them —
      `require_once` makes a double include impossible).
      Prove per AC-20 by running **each** of the five files in
      `packages/kernel/tests/Feature/` individually by path and checking each passes
      standalone. Before the change, `TenancyTenantSwitchTest.php` and
      `TenancyCredentialLeakTest.php` fatal on an undefined function — capture that as the
      negative control before editing.
      Then re-run `composer stan` and `composer deptrac`: the new file is under
      `packages/kernel/tests`, which both already analyse, so coverage is preserved by
      location (T-002 AC-16/AC-17).

- [ ] **Step 5 — FP-155: the group, the registry and the commands.** (AC-3, AC-4)
      1. `services/host/tests/Helpers.php` with `toolchainGroupFiles()` (the 13 paths from
         §0.3), `toolchainGroupExemptions()` and the detector functions.
      2. `services/host/tests/Pest.php`: `pest()->group('toolchain')->in(...toolchainGroupFiles());`
      3. `services/host/composer.json`: add `test:fast` and `test:slow` plus their
         `scripts-descriptions`. **Do not touch `test` or `check`.**
      4. Prove immediately: `pest --list-groups` lists `toolchain`;
         `composer test:slow` reports **48 passed**; `composer test:fast` reports
         **231 passed, 837 assertions**; `composer test` still reports the Step 1 baseline.
         (Projected from §0.4; record the actual numbers.)

- [ ] **Step 6 — FP-155: the decay guard.** (AC-3)
      `tests/Feature/ToolchainGroupCoverageTest.php` (layers 1–3 of §5.1) and
      `tests/Unit/ToolchainGroupDetectorTest.php` (pure unit test of the detector: a source
      string with `Process::` is reported, one without is not, the exemption list subtracts,
      an exemption naming a non-existent file fails). Tag the layer-3 case `toolchain` and
      add `ToolchainGroupCoverageTest.php` to the registry — the guard's own child run makes
      it a member of the set it polices, which the registry lint will then confirm rather
      than be confused by.

- [ ] **Step 7 — FP-155: fixture hoisting, and the teardown flake.** (AC-6, AC-7)
      1. Hoist the SQLite file creation/deletion in the three safe kernel files (§5.3).
         Prove AC-6 by diffing the per-case pass/fail and assertion counts from
         `--log-junit` before and after — same cases, same results.
         Prove AC-7 by keeping (and if necessary adding) a case that asserts a
         *not-yet-existing* starting state, and confirming it still observes it with the
         hoisted fixture.
      2. Add `deleteFixtureDirectory()` to `tests/Helpers.php` — `File::deleteDirectory()`,
         `clearstatcache()`, second pass, then assert the directory is gone — copying the
         reasoning already written in `QaCredentialSurfaceSweepTest::qaCredDeleteFixtureRoot()`.
         Switch the **dev-owned** `svc:make` fixture teardowns to it (§3).
         **[qa]** message `qa` to switch the `Qa*` files that generate packages
         (`QaManifestTrustAttackTest`, `QaServiceNameValidationTest`,
         `QaServiceRegistryContractTest`, `QaCacheGuardBranchesTest`,
         `QaGeneratedServiceTestDiscoveryTest`, `QaModuleConnectionRemovalTest`,
         `QaAtomicGenerationProofTest`, `QaDocumentationAccuracyTest`,
         `QaRegenerationGuardTest`).
      3. **[qa]** `qaFixturePackageNames()` moves from `QaToolchainRegistryCoverageTest.php`
         to `tests/Helpers.php`. No call site changes; it stops being a cross-file
         dependency, which is the host-side half of FP-164.
      4. Run `composer test` five times consecutively and record five exit-0 results, to
         show §0.8's flake is closed rather than quiet.

- [ ] **Step 8 — FP-157: route caching, measured and pinned.** (AC-14, AC-15)
      1. `tests/Feature/RouteCachingTest.php`, shaped exactly like `ConfigCacheGuardTest`:
         a pre-flight assertion that `app()->getCachedRoutesPath()` does **not** exist
         (derive the path, do not hard-code `routes-v7.php`); run `route:cache` in a **child
         process** so the in-process router is untouched; assert exit 0 and no
         `for serialization` / `Uses Closure` in the output; then `route:clear` in a
         `finally` **and** an `afterEach`, and assert the file is gone (AC-15).
      2. Pin the corrected framework fact (§0.7) as an executing assertion, not a comment:
         `(new Route('GET', '/probe', fn () => null))->prepareForSerialization()` must not
         throw on this framework version. When it starts throwing again, the gate says so.
      Note in the QA report that AC-14 was **already satisfied before this task**, and that
      three closure routes remain (`/up`, `storage/{path}` ×2) which route caching handles
      fine — so AC-14 is the standing regression pin the spec now asks for rather than a
      one-off fix. The documentation that says otherwise is corrected in Step 8b.

- [ ] **Step 8b — FP-157: correct the false `route:cache` documentation.** (AC-16)
      New criterion, inserted by `ba` after this plan's §0.7 measurement. Kept as its own
      step, and its own commit, because it is a factual correction to two documents rather
      than part of the scaffolding removal — a reviewer should be able to read it alone.
      1. `services/host/config/servicehost.php`, constraint 3: delete only the sentence
         *"It also fails outright today, because routes/web.php still has the stock closure
         route on `/` and closures cannot be serialised."* Keep the surrounding true
         statement (route caching freezes the route table, so a module added to `SERVICES`
         after the cache is built is unreachable until it is rebuilt) and keep the
         `DOCUMENTED ONLY (decision D-20)` label — AC-16 requires the label to stay, because
         that hazard is still genuinely unguarded, unlike the `config:cache`/`SERVICES`
         staleness guard.
      2. Root `README.md`, item 3: drop *"and currently fails outright because of the stock
         closure route on `/`"*; keep *"Documented only"*. The removing-scaffolding-is-out-
         of-scope clause also goes, since the scaffolding is now gone.
      3. Add the AC-16 proof as a case in `tests/Feature/RouteCachingTest.php` — this is one
         of the additions AC-2 requires to be named in the addition list. It asserts, over
         **both** files: neither contains `fails outright`, neither contains
         `closure route on`, and each still contains the true freeze statement and the
         phrase `documented only`. Two-directional, like every other guard here: assert the
         false claims are absent *and* the true one is present, so deleting the whole
         paragraph would fail just as loudly as leaving the lie in.
      4. Re-run `QaCacheGuardBranchesTest`. `substr_count(strtolower($config),
         'documented only')` must still be **3** — `ba` confirms this and it matches this
         plan's original measured prediction. **If it moves to 2, the edit removed the
         label as well as the clause: fix the edit, do not touch the assertion.** No QA file
         changes for AC-16.

- [ ] **Step 9 — FP-157: the route and the example tests.** (AC-12 in part, AC-13)
      1. `app/Http/Controllers/RootController.php` — `__invoke()` returning
         `response()->noContent()`.
      2. `routes/web.php` — `Route::get('/', RootController::class)->name('root');` and a
         comment saying why a non-closure route lives here (AC-13 + D-39 + T-002 D-18).
      3. `HealthEndpointTest.php` — rename the case to "keeps the root route inside the web
         group" and probe `root` instead of the welcome route. Same assertion shape, same
         guarantee.
      4. Delete `tests/Feature/ExampleTest.php`.
      5. Delete `tests/Unit/ExampleTest.php` — **only after** Step 6 has landed
         `tests/Unit/ToolchainGroupDetectorTest.php`, so `tests/Unit/` is never empty.
         **[qa]** in the *same commit*, `qa` repoints
         `QaHostSuiteCollectionProofTest.php:152` from
         `'test_that_true_is_true' => 'tests/Unit/ExampleTest.php'` to the new unit test's
         marker and path. If these two land in different commits the gate is red in between.

- [ ] **Step 10 — FP-157: front-end scaffolding.** (AC-12 remainder)
      Delete `resources/views/welcome.blade.php`, `resources/css/app.css`,
      `resources/js/app.js`, `resources/js/bootstrap.js`, `vite.config.js`, `package.json`.
      Keep `resources/views/.gitkeep`. Remove `VITE_APP_NAME` from `.env.example`. Update
      `composer.json`'s `setup` and `dev` scripts to drop every `npm`/`npx` call. Touch no
      Composer requirement — `composer.lock` must not change in this task.
      Confirm `php artisan config:cache`, `route:cache` and `view:cache` all still exit 0.

- [ ] **Step 11 — Coverage proof and documentation.** (AC-2)
      1. Produce `full.xml`, `slow.xml`, `fast.xml` and run the four set assertions of
         §5.2 against `baseline_full.xml` from Step 1.
      2. Enumerate the additions **by name** — this is the explicit addition list AC-2 now
         requires, not a courtesy. Expected: `ToolchainGroupCoverageTest` (3),
         `ToolchainGroupDetectorTest` (n), `RouteCachingTest` (2 for AC-14/AC-15 **plus 1
         for AC-16**), and the renamed `HealthEndpointTest` root-route case (a rename, so it
         appears in both the removal and the addition list and must be flagged as such, not
         quietly netted to zero). Removals: exactly the two named stock example tests.
         Report the exact final counts alongside the named sets.
      3. Add `composer test:fast` to root `README.md` "Getting started" and
         `services/host/README.md`. Do **not** edit `CLAUDE.md`; recommend the command-table
         addition to the lead instead.
      4. `composer check` exit 0, twice, recorded.

## 7. Test strategy

**Unit** (`services/host/tests/Unit/`)
- `ToolchainGroupDetectorTest` — the spawn detector over synthetic source strings; the
  exemption subtraction; a registry entry that does not exist on disk must fail; an
  exemption that does not exist on disk must fail. Also re-inhabits `tests/Unit/` so the
  suite-collection proof keeps a real anchor.

**Integration / feature** (`services/host/tests/Feature/`)
- `ToolchainGroupCoverageTest` — layers 1–3 of §5.1, with a negative control on each.
- `RouteCachingTest` — AC-14/AC-15, the `prepareForSerialization()` framework pin, and the
  AC-16 documentation-correction case (false claims absent **and** true claim present).
- `HealthEndpointTest` — the D-18/D-39 probe on the new root route.

**Whole-suite / procedural** (QA report artefacts, not permanent tests)
- The JUnit set diff of §5.2 (AC-2, AC-3, AC-4, AC-5).
- The fresh-clone and re-checkout proofs of Step 3 (AC-8 … AC-11).
- Five consecutive `composer test` runs (§0.8 flake).
- Each of the five `packages/kernel/tests/Feature/` files run individually (AC-20).

**Which tests prove which criteria**

| AC | Proof |
|---|---|
| AC-1 | Step 1: `baseline_full.xml` **plus the itemised, sorted `classname::name` list** handed to `qa` |
| AC-2 | Step 11 JUnit **set difference** against the AC-1 baseline set, with the named addition list |
| AC-3 | `ToolchainGroupCoverageTest` layers 1–3 + `test:fast`/`test:slow` counts |
| AC-4 | `slow ∪ fast === full`, `slow ∩ fast === ∅`, gate carries no group flag (layer 1) |
| AC-5 | Named assertion that `slow.xml` contains no case from `TenancyCredentialLeakTest` or `QaCredentialSurfaceSweepTest`; `toolchainGroupExemptions()` with its reason written down |
| AC-6 | Per-case JUnit diff across the hoist (same names, same results) |
| AC-7 | The not-yet-existing-state case still observing it after the hoist |
| AC-8, AC-9 | Step 2, `git status` after `--renormalize` showing only `.gitattributes` |
| AC-10 | Step 3 fresh clone + `rm -rf services/demo && git checkout` + raw-byte comparison |
| AC-11 | Step 3 `--renormalize` producing no warning; 0 `w/crlf` in a fresh clone |
| AC-12 | File-absence assertions after Steps 9–10 |
| AC-13 | `routes/web.php` declares an invokable controller; `route:list` shows no closure at `/` |
| AC-14, AC-15 | `RouteCachingTest` (Step 8) |
| AC-16 | Step 8b: `RouteCachingTest`'s documentation case over `config/servicehost.php` and root `README.md`; `QaCacheGuardBranchesTest`'s `'documented only' === 3` still passing unchanged |
| AC-17 … AC-20 | Each kernel Feature file run individually, before and after Step 4 |

## 8. Parallelism — deliberately not planned

Not an oversight. `--parallel`/Paratest is available in `vendor/bin` and is **not** used,
because the suite mutates shared state that is not namespaced per worker:

- generated packages land in one shared `services/<name>` directory, and `svc:make` refuses
  when the directory exists (`SvcMakeCommand.php:85`) — two workers generating
  `trustfixture` would fail each other;
- `bootstrap/cache/config.php` is written and deleted by `ConfigCacheGuardTest`,
  `QaCacheGuardBranchesTest` and `QaCredentialSurfaceSweepTest`;
- fixture roots under `storage/app/` are per-file but not per-worker;
- `$_ENV`, `$_SERVER` and `putenv()` are mutated for `SERVICES` and tenant credentials;
- the tenancy guard's de-duplication `WeakMap` is process-static (T-003 review).

§0.8 is direct evidence: the suite already loses a create/delete race **serially** on
Windows. Parallelising before those fixtures are namespaced would produce flakes
indistinguishable from real tenancy bugs. Nothing in this task enables it, and Step 7's
hoist does not make anything concurrency-safe — it only changes *when* a per-file fixture is
built.

## 9. Spec conflicts and corrections

### Closed by `ba` in the 2026-09-30 spec revision

2. ~~**AC-2 is not satisfiable as written and would block the guard the brief asked for.**~~
   **Adopted as proposed.** AC-2 is now a by-name set difference against the AC-1 baseline,
   minus the two named example tests, union an explicit addition list that AC-2 itself says
   includes the AC-14 pin and the AC-16 documentation proof. Plan §5.2 and Step 11 already
   implement exactly this; only the wording of Step 11's caution was stale and is corrected.
3. ~~**AC-14's premise is false.**~~ **Adopted.** AC-13 is now scaffolding hygiene only and
   says so explicitly; AC-14 is reframed as a standing regression pin over behaviour that
   already works. Step 8 needed no change.
4. ~~**The `'documented only'` count assertion is in the wrong file, and 2 is the wrong
   prediction.**~~ **Adopted, and `ba`'s reasoning is the same as the measurement's:** the
   `DOCUMENTED ONLY` label stays because the freeze hazard stays unguarded regardless of
   whether the command throws; only the false clause inside point 3 goes. The count stays
   **3**. AC-16 now states this in the spec. No step in this plan ever expected it to change
   — see Step 8b.4 and §3.
6. ~~**AC-12 lists `vite.config.js` but not `package.json`.**~~ Still outside AC-12's
   enumeration; Step 10 removes it anyway with the reason recorded. No spec change needed.

### Still live

1. **AC-1 still cites 277.** Measured **279 / 1224**, twice, which is also what the T-003
   *review* header says; `docs/qa/T-003-tenant-connections.md` says 277 / 1219. AC-1's own
   escape clause ("the set actually observed is what is captured") covers it, so this is not
   blocking — but the two T-003 documents still disagree with each other, and one of them is
   wrong. Worth a correction in `docs/qa/T-003-tenant-connections.md` rather than carrying
   the discrepancy into T-005.
5. **The spec's "about fifty files warn per commit" is understated.** 112 tracked files are
   outside any `.gitattributes` rule and a fresh clone writes **88** of them as CRLF (§0.6).
   Cosmetic in the spec's prose; the plan uses the measured figures.
7. **New, raised by the resync: AC-2 and D-39 collide on one test.** AC-2 says nothing in
   the baseline set disappears except the two named example tests. D-39 requires
   `HealthEndpointTest`'s "keeps the stock welcome route inside the web group" to be
   *replaced* by an equivalent probe, and a by-name set diff reads a renamed `it(...)`
   description as a disappearance plus an appearance. Not a design problem — the plan
   declares the pair explicitly in both lists (§5.2, Step 11.2) — but AC-2 as written would
   read that as a violation. **Suggested to `ba`: allow a named rename pair, declared as
   such, alongside the two removals.** Keeping the old description instead would leave a
   test named after a route that no longer exists, i.e. exactly the failure AC-16 removes
   from the documentation. Not blocking: `dev` proceeds with the declared pair.

### The AC-22 the scan found — spec is consistent, `ba`'s count of 20 is correct

Checked. **T-004's own criteria run AC-1 … AC-20, contiguous: no gap, no duplicate, nothing
uncounted.** Every `AC-2x` above 20 in the document is a citation of *another task's*
numbering:

| Spec line | Text | Whose criterion |
|---|---|---|
| 251 | `T-002 FP-153/AC-19, T-003 AC-22` | T-003's, explicitly prefixed |
| 398 | `**T-003 AC-19 through AC-23**` | T-003's, explicitly prefixed |
| 405 | `AC-22 specifically is the test FP-156's fresh-clone proof …` | T-003's — it sits inside the line-398 bullet and inherits that prefix |

So there is no unverified criterion. The only real problem is at **line 405**: a bare
`AC-22` in a document whose own numbering stops at 20, whose prefix lives two lines earlier.
That is what cost the scan, and it will cost the next one. **Suggested to `ba`: write it as
`T-003 AC-22`.** A one-word fix, no renumbering.
5. **The spec's "about fifty files warn per commit" is understated.** 112 tracked files are
   outside any `.gitattributes` rule, and a fresh clone writes **88** of them as CRLF.
6. **AC-12 lists `services/host/vite.config.js` but not `package.json`**, which only
   declares the build for the files AC-12 deletes. The plan removes it and repairs
   `composer.json`'s `setup`/`dev` scripts; flagging it because it is outside AC-12's
   enumeration.

## 10. Risks & rollback

| # | Risk | Mitigation | Rollback |
|---|---|---|---|
| R-1 | A tag silently removes a test from the gate | Gate carries no group flag and `phpunit.xml` declares no `<groups>`; asserted by guard layer 1, with a negative control | Delete the two scripts and the `pest()->group()` block; nothing else references them |
| R-2 | A 14th child-process test is added untagged and the fast loop decays | Registry lint (layer 2) runs in `test:fast` itself, so it fails inside the inner loop | — |
| R-3 | `pest()->group()->in()` does not actually bind, so `test:fast` runs everything and looks correct | Layer 3 proves the binding from `--list-groups` and a `--group` child run; Step 5 records the three counts | Fall back to per-file `uses()->group('toolchain')` (verified safe in Pest's source) |
| R-4 | Fixture hoisting leaks state between cases | Hoisted to exactly three files, each grepped for writes to the fixture; AC-7 case; per-case JUnit diff | Restore `beforeEach`/`afterEach`; the helper keeps both entry points |
| R-5 | The renormalisation commit turns into a whole-tree diff | Measured on a clone: `git add --renormalize .` produces **zero** changes here. Step 2 stops and proves line-ending-only with `--ignore-cr-at-eol` if anything appears | Revert the single commit |
| R-6 | The working-tree refresh (Step 2.6) deletes files it should not | `git ls-files` only ever lists tracked files, and `git checkout -- .` restores them; `git status` clean is the check. Use `$NF` not `$4` | `git checkout -- .` |
| R-7 | Deleting `resources/` breaks view resolution or `view:cache` | Keep `resources/views/.gitkeep`; no `config/view.php` ships, so the default path must exist. Step 10 runs `view:cache` | Restore the directory |
| R-8 | `route:cache` test leaves a cached route table and poisons later tests | Pre-flight assertion + child process + `finally` + `afterEach`, exactly the `ConfigCacheGuardTest` pattern the T-002 review approved | `route:clear` |
| R-9 | Cross-ownership edits (`QaHostSuiteCollectionProofTest`, `QaCacheGuardBranchesTest`, `QaToolchainRegistryCoverageTest`, the `Qa*` teardowns) | Marked **[qa]** and routed as messages; the T-002 R-1 fix has precedent for a coordinated edit in both owners' files. **Lead should confirm who applies them.** Step 9.5 must be one commit | Revert the commit |
| R-10 | §0.8's flake makes a QA run red for unrelated reasons | Step 7.2 fixes the teardown; Step 7.4 requires five consecutive green runs | — |
| R-11 | Deleting `package.json` while `composer.json` still calls `npm` | Both changed in Step 10; `composer setup` and `composer dev` are exercised at least to the point of not referencing a missing manifest | Restore both files together |

## 11. Questions for the human

None blocking. Two items for the lead rather than the human:

- **Ownership.** Four QA-owned files need one-line edits (§3, R-9). Either the lead rules
  that `dev` may make them as mechanical, assertion-free edits — the T-002 R-1 precedent —
  or they become a `[TEST]` task for `qa` that must land in the same commits as Steps 7 and
  9. Steps are written so either works; the sequencing constraint in Step 9.5 is the only
  hard one.
- **`CLAUDE.md`.** Its command table should gain `composer test:fast`, but no agent should
  edit that file. Recommending it to the lead rather than planning it.

---

## 12. Implementation deviations — ruled by the architect, 2026-09-30

`dev` implemented the plan and raised four deviations. **All four are accepted**, and two of
them correct the plan rather than depart from it. Everything below was re-measured on the
implemented working tree before ruling, not taken on report.

### Measured on the implemented tree (warm, this machine)

| Command | Tests | Assertions | Duration |
|---|---|---|---|
| `composer test` (the gate) | **299** | **1331** | **81.8 s** |
| `composer test:fast` | 246 | 909 | **23.0 s** |
| `composer test:slow` | 53 | 422 | 51.7 s |

246 + 53 = 299 and 909 + 422 = 1331. Independently confirmed.

**Coverage is provably intact.** Per-file case counts, baseline (`cold3.xml`, 279 cases)
against the implemented tree (`--list-tests`, 299), differ in **exactly seven files and
nowhere else**:

| File | before | after | delta |
|---|---|---|---|
| the two stock example tests | 2 | 0 | −2 |
| `RouteCachingTest` | 0 | 4 | +4 |
| `StockScaffoldingRemovedTest` | 0 | 1 | +1 |
| `ToolchainGroupCoverageTest` | 0 | 4 | +4 |
| `ToolchainGroupBindingTest` | 0 | 1 | +1 |
| `ToolchainGroupDetectorTest` | 0 | 11 | +11 |
| `TenancyResolutionTest` | 11 | 12 | +1 (the AC-7 case Step 7 asked for) |
| **every other file** | — | — | **0** |

279 − 2 + 22 = 299. Every delta is intended and named; no pre-existing file lost a case.
That table is the AC-2 evidence and it should be pasted into `docs/qa/T-004-…md` as-is.

### D-3 (the `--list-tests` finding) — accepted, and it supersedes §5.2

Verified independently: `--list-tests` and `--list-groups` **honour `--group` /
`--exclude-group`**, exit 0, execute nothing, and cost **535–606 ms each** (three children
≈ 1.7 s). Listed 299 total, 53 with `--group=toolchain`, 246 with `--exclude-group` — exactly
53 + 246 = 299. So §5.2's reason for declining a permanent proof ("it would triple the gate")
was wrong: collection, not execution, is what decides group membership, and collection is
nearly free. **The union-equals-full and disjoint-sets properties are permanent gate
assertions from now on** (`ToolchainGroupBindingTest`), and §5.2's "why the by-name equality
is not made a permanent test" paragraph is withdrawn.

**Carry this forward.** The general rule, for T-005 and everything after: *a coverage
property that can be expressed over the collected set rather than the executed set costs a
collection, not a run, and should therefore be a standing assertion rather than a per-task
artefact.* Two instrument notes, both learned the hard way here:

- **Prefer `--list-tests-xml <file>` over `--list-tests`.** It also honours `--group`
  (verified: 53 entries), and gives structured `testClass name` / `testMethod id` instead of
  prose. `--list-tests` appends a **period to the last line of the listing**, which made a
  naive line-set comparison of mine report a phantom two-entry difference between the union
  and the full set. `dev` avoided the trap by regexing only as far as `::`; the XML form
  removes it. (In the XML, ignore the `file` attribute — for Pest it points at the eval'd
  `TestCaseFactory`. `testClass name` is the usable file proxy.)
- **Both group-name typos fail safe, which is worth knowing before relying on this.**
  `--group=typo` lists nothing and **exits 1** (loud). `--exclude-group=typo` excludes
  nothing, so the fast command silently becomes the full command — slower, never less
  complete. A typo in the `pest()->group()` declaration does the same. Every failure
  direction is toward running more.

### D-2 (the guard split) — confirmed, and the plan was self-contradictory

`dev` is right and this is a defect in the plan. §5.1 required the registry lint to run
inside `test:fast`; Step 6 told `dev` to add the guard file to the registry, and tagging is
**per file**, so that would have pushed the lint out of the fast command and destroyed the
mitigation it exists to provide. Both could not hold for one file. The split is correct:

- `ToolchainGroupCoverageTest.php` — layers 1 and 2, the layer-2 negative control and the
  AC-5 by-name check. Verified to contain **zero** spawn tokens, so it is correctly *absent*
  from the registry and runs in `test:fast`.
- `ToolchainGroupBindingTest.php` — layer 3 only, spawns, correctly *in* the registry.

No circularity and no blind spot: the coverage test does not spawn, so the sweep never
demands its own membership; the binding test does spawn, so the sweep demands it, and it is
there. Read §5.1 and Step 6 as describing these two files from now on.

### D-4 (the development script) — confirmed

Also a defect in the plan. §3 said to drop `npx concurrently` but keep `serve`,
`queue:listen` and `pail`. `concurrently` **was** the thing running them in parallel, it is
an npm binary, and Step 10 deletes the `package.json` that declares it — and a Composer
script array runs sequentially, so `php artisan serve` would block and the other two would
never start. Keeping only `serve` and documenting `queue:listen` and `pail` as their own
commands is the right call. No Composer package was added or removed; `composer.lock` is
untouched, as required.

### The two smaller items — both confirmed, both my errors

- **`DeptracBoundaryEnforcementTest`** was wrong to be in Step 7's list: its `afterEach` is
  `File::delete(deptracProbePath())`, a single **file**. `deleteFixtureDirectory()` is a
  directory helper and does not apply. Making no change was correct.
- **`QaToolchainRegistryCoverageTest`** was missing from Step 7's enumeration and does call
  `svc:make`. Including it was correct and follows the plan's own stated criterion ("the
  files that generate packages"). For the record, the complete set is the 15 files that
  call `svc:make` plus `QaServiceRegistryContractTest` (which builds a package directory by
  hand) and `PintLintFixRoundTripTest` / `ToolchainGroupCoverageTest` (which own fixture
  directories of their own) — 18 files, which is what the tree now has.

### One thing `dev` found that the plan got wrong on the substance

`toolchainSpawnTokens()` matches every bare process function with a `(?<![\w$>:])`
look-behind. §5.1 proposed `\bexec\s*\(`, which **also matches `$pdo->exec('create table …')`
— the tenancy fixture**. Had that shipped, the sweep would have demanded that
`TenancyResolutionTest`, `TenancyTenantSwitchTest`, `QaTenantIsolationProofTest` and
`TenancyCredentialLeakTest` all join the `toolchain` group, which is a direct **AC-5
violation**. `dev` caught it and pinned it with its own unit case ("does not mistake a method
or static call for a bare process function"). Two related traps also closed, neither of which
the plan anticipated: the spawn tokens are exposed as data so the detector's own unit test can
build synthetic sources without the *test file* becoming a false spawner, and the sweep skips
generated fixture packages so its result cannot depend on test order.

### Two follow-ups for the lead

1. **`CLAUDE.md` was edited** (two rows added to the command table). The content is correct
   and is exactly what §11 recommended, but the plan said no agent should touch that file —
   it is the human's. Needs the human's acceptance or a revert; not a defect in the work.
2. **Those rows quote `~22 s vs ~66 s`, which are warm figures.** Warm understates what a
   fresh machine or CI pays — the T-002 reviewer's explicit warning, and my own two baseline
   runs differed by 97 s on cache warmth alone. Label them warm, or quote cold. Related and
   worth one measurement before T-005: the gate fell from **140.6 s warm / 279 tests** to
   **81.8 s warm / 299 tests**, a 59 s drop where the fixture hoist only predicted ~6 s. The
   per-file table above proves no coverage was lost, so this is not tests quietly not
   running; the likely remainder is the delete-retry helper removing Windows delete stalls,
   plus warmth variance. It should still be measured cold rather than left as a pleasant
   surprise.
