# T-004: Gate speed and repository hygiene

Covers backlog rows **FP-155** (gate speed), **FP-156** (line endings), **FP-157** (stock
scaffolding removal) and **FP-164** (shared test helpers). Infrastructure only: this task
adds no feature and must change no assertion's meaning. It does not touch
`services/`→`modules/` (FP-202/T-005), the master schema or anything after it (FP-203+),
the guard consolidation/unforgeable marker (FP-160/FP-161), the two document corrections
(FP-158/FP-163), or the package manifest dependency (FP-159).

## Problem / goal

Three tasks (T-001, T-002, T-003) have shipped onto this skeleton, and the full gate
(`composer check` in `services/host`) has grown from 5 seconds to a measured **152–156
seconds cold** (91 s warm; the reviewer's own numbers). The reviewer's analysis: roughly
forty tests spawn a child process that boots the framework and runs a whole tool (Pint,
PHPStan, Deptrac, Pest itself, `svc:make`, `config:cache`/`route:cache`), and a **filtered**
child run still performs full test collection before the filter is applied — proven in this
repository by `HostTestSuiteCollectionTest`'s own comment and by its
`--filter=zzz no test is named this zzz` probe, which still walks and registers every suite.
That makes each of those forty tests' cost proportional to the number of test files in the
whole repository, so the gate's growth is multiplicative with every later task, not additive,
and invisible until someone measures it (backlog FP-155, "before the second module lands").

Two further, unrelated hygiene gaps have each already cost a round of rework:

- **FP-156.** Only `services/host/.gitattributes` declares `* text=auto eol=lf`; nothing at
  the repository root does, so every text file outside that one directory is checked out
  with Windows line endings while the generator (which is not a checkout, it writes files
  directly) produces Unix ones. T-003's own regeneration-diff test passed only because the
  compared file had never actually been checked out from git in that run — a critical
  review finding — and would fail a fresh clone. Every commit today warns on about fifty
  files.
- **FP-157.** The framework's stock example tests, welcome view, and front-end build
  scaffolding are still present, along with the closure-based `/` welcome route. **The
  premise that this closure route breaks `php artisan route:cache` — stated in the backlog
  row itself and repeated in this task's original brief — is false.** The architect measured
  it before planning: `route:cache` already succeeds today, closure and all, because the
  framework now serialises closures; the host in fact registers four closure-based routes
  once it boots, not the single welcome-route closure the premise assumed, so removing only
  that one route was never going to be the fix even if the premise had held. `README.md` and
  `services/host/config/servicehost.php` are themselves wrong on this point — both currently
  document the opposite ("`route:cache` ... currently fails outright because of the stock
  closure route on `/`", T-002 FP-149 item 3) — and that documentation is what needs
  correcting. This task still retires the stock scaffolding, including the welcome route,
  because it is dead weight in a backend-only host, not because doing so fixes a defect in
  route caching.

A fourth, smaller item rides along because it is cheap and in the same "test suite hygiene"
family: **FP-164** — `packages/kernel/tests/Feature/TenancyTenantSwitchTest.php` and
`TenancyCredentialLeakTest.php` both call `tenancyBootFixture()`, a bare function declared
inside a third file, `TenancyResolutionTest.php`. Running either of the first two files
directly fails on an undefined function while a full-suite run passes, because PHPUnit/Pest
only loads the file that happens to declare the function as a side effect of collecting it
first. This wastes whoever next tries to run one file in isolation.

**The defining risk of this task is silently losing coverage.** A faster suite that runs
fewer assertions is a failure, not a success — nothing below is satisfied by a criterion
that only shows the fast command is faster.

## Users affected

- Backend developers (`dev`) — pay the 152 s cold gate on every `[CODE]`/`[FIX]` task from
  now on, and are the ones who hit the FP-164 undefined-function trap when isolating a
  failing test.
- `qa`/`reviewer` — depend on the full gate remaining the thing that actually proves
  correctness; a fast command that silently drops coverage would make every later QA report
  built on a false green.
- `architect` — every later plan (T-005's rename, and everything in Phase 0) inherits
  whatever the gate costs; FP-155 is explicitly scheduled before the rename because the
  rename is the largest mechanical churn in the backlog and benefits from a fast gate.
- Anyone doing a fresh clone or fresh checkout (a new machine, CI once FP-218 exists) — is
  the person FP-156 protects; the defect only shows up on a checkout that never had the
  file in the working tree before.

## Current behaviour

- `services/host/composer.json`'s `test` script runs unfiltered Pest
  (`@php artisan config:clear --ansi` then `@php vendor/bin/pest`); `check` runs `lint`,
  `stan`, `deptrac`, `test` in sequence. Neither has a faster variant. No Pest test in the
  repository currently uses `->group()`; there is no tagging mechanism in use today.
- At least 14 test files (`services/host/tests/Feature/QaModuleConnectionRemovalTest.php`,
  `SvcMakeAtomicGenerationTest.php`, `QaRegenerationGuardTest.php`,
  `QaManifestTrustAttackTest.php`, `QaTestCodeAnalysisCoverageTest.php`,
  `QaHostSuiteCollectionProofTest.php`, `QaCacheGuardBranchesTest.php`,
  `DeptracBoundaryEnforcementTest.php`, `HostTestSuiteCollectionTest.php`,
  `ConfigCacheGuardTest.php`, `QaTestSuiteIsolationTest.php`,
  `QaGeneratedServiceTestDiscoveryTest.php`, `PintLintFixRoundTripTest.php`, plus
  `packages/kernel/tests/Feature/QaCredentialSurfaceSweepTest.php`) spawn a child process
  (`Illuminate\Support\Facades\Process`) that boots a second instance of the framework and
  runs a whole tool — this is the "roughly forty tests" (individual cases across these
  files) the reviewer measured.
- `services/host/.gitattributes` is the only `.gitattributes` in the repository (besides one
  inside `vendor/`); there is no root-level `.gitattributes`.
- `README.md` and `services/host/config/servicehost.php` both currently document, as
  "documented only" / out of scope, that `route:cache` fails because of the stock closure
  route on `/` (T-002 decision D-20) — **measured false by the architect**: `route:cache`
  already succeeds today with that route (and three other, framework-registered closure
  routes, including the `/up` health-check route the framework itself installs) still
  present. `services/host/tests/Feature/QaCacheGuardBranchesTest.php` — not
  `QaDocumentationAccuracyTest.php`, a misattribution corrected in this revision — asserts
  both files contain the phrases `route:cache`, `mergeConfigFrom`, `--classmap-authoritative`,
  "guarded" and "documented only" (presence only, for both files), and separately asserts
  that `config/servicehost.php` alone (not `README.md`) contains the phrase "documented
  only" **exactly 3 times**.
- `services/host/tests/Feature/ExampleTest.php` and
  `services/host/tests/Unit/ExampleTest.php` are the unmodified framework scaffolding tests
  (one assertion each: `assertStatus(200)` against `/`, and `assertTrue(true)`).
  `services/host/routes/web.php` declares one closure route, `Route::get('/', function ()
  { return view('welcome'); })`; the other three the architect measured are registered by
  the framework itself (for example the `/up` health-check route created because
  `bootstrap/app.php` passes `health: '/up'` to `->withRouting()`), not by anything this
  task owns or is removing. `services/host/resources/views/welcome.blade.php`,
  `resources/css/app.css`, `resources/js/app.js`, `resources/js/bootstrap.js` and
  `vite.config.js` are the unmodified framework front-end scaffolding.
- `services/host/tests/Feature/HealthEndpointTest.php` has its own test, *"keeps the stock
  welcome route inside the web group"* (T-002 decision D-18), whose entire purpose is
  proving that the health-route registration code adds `/health/live` and `/health/ready`
  to the router without replacing whatever else `routes/web.php` declares — it does this
  today by asserting the `/` welcome route is still present and still inside the `web`
  middleware group.
- `packages/kernel/tests/Feature/TenancyResolutionTest.php:167` declares
  `function tenancyBootFixture(): void { ... }` at file scope (not inside a class or a
  shared support file) and calls it itself at line 260;
  `TenancyTenantSwitchTest.php:24` and `TenancyCredentialLeakTest.php:128` both call the
  same function without declaring or requiring it themselves.
- Per the T-003 QA report (`docs/qa/T-003-tenant-connections.md`), the gate currently stands
  at **277 passed, 1219 assertions, exit 0** (two consecutive confirming runs), measured at
  88–144 s across six clean runs.

## User stories

- As a backend developer, I want a fast command for my inner development loop that still
  runs every test that is not itself a whole-toolchain child-process run, so that I get
  feedback in a small fraction of the current 152 s while I am iterating.
- As a backend developer, I want the full gate to still be the thing that gates task
  completion, so that nothing merges on the strength of a command that skips tests by
  design.
- As `qa`/`reviewer`, I want the credential-leak tests to run in every command, fast or
  full, so that the one surface nobody measured before (T-003's BUG-1) can never again be
  the one a faster command quietly excludes.
- As a backend developer isolating a single failing test, I want every test file in
  `packages/kernel/tests` to run on its own, so that I do not have to run the whole suite
  just to reproduce one failure.
- As anyone doing a fresh clone, I want every text file to check out with the line endings
  the repository declares for it, so that a regeneration or a diff never disagrees with git
  only because of how the working tree happened to get there.
- As a backend developer, I want `php artisan route:cache`'s already-working state pinned
  by an automated test, so that this task's own scaffolding removal — or any later change —
  cannot silently break it again without the gate catching it immediately.
- As a new developer reading `README.md`/`config/servicehost.php`, I want the route-caching
  constraint documented accurately, so that I do not believe a defect exists (and avoid a
  command) that in fact works fine.
- As a new developer, I want no stock example test, welcome page, or front-end build file
  left in a backend-only host, so that the repository does not suggest capabilities
  (a rendered page, a JS build) that do not exist here.

## Acceptance criteria

Numbering starts fresh at AC-1 for T-004.

### Coverage preservation (the task's defining risk)

- **AC-1** (baseline recorded by name, not just by count): Given the branch at the start of
  this task's implementation, when `composer test` (or an equivalent enumeration, e.g.
  `vendor/bin/pest --list-tests` or the full `Tests:` output with every test name) is run,
  then `dev` captures the complete, itemised set of test names/identifiers Pest reports —
  not merely a total count — as this task's baseline test report, kept where `qa` can diff
  against it later (expected to be the 277 tests behind
  `docs/qa/T-003-tenant-connections.md`'s final run's count, unless the branch has
  independently changed since that report — in which case the set actually observed is what
  is captured).
- **AC-2** (coverage proven by name against the baseline, not by a bare count): Given the
  AC-1 baseline set, when the full gate (`composer check`, running `composer test`
  unfiltered) is run after every change this task makes (the FP-155 tagging and fixture
  hoisting, the FP-156 renormalisation, the FP-157 scaffolding removal, the FP-164 helper
  relocation), then the resulting test set equals exactly the AC-1 baseline set with three
  kinds of named change applied, and no other change:
  1. **Removals** — the two named stock example tests removed by AC-12
     (`Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` and
     `Tests\Unit\ExampleTest::test_that_true_is_true`) are gone, with no replacement.
  2. **Declared rename pairs** — a baseline test's old name is gone and a new test's name
     takes its place **only** where the plan or the implementing commit explicitly declares
     the pair and states that the new test proves the same guarantee the old one did (at
     minimum: `HealthEndpointTest`'s "keeps the stock welcome route inside the web group",
     retired per decision D-39 because the route it names no longer exists — the same
     false-documentation failure AC-16 exists to delete — and replaced by a successor probe
     that still proves D-18's guarantee). An undeclared disappearance paired with an
     unrelated appearance is not a rename; it is exactly the silent loss this criterion
     exists to catch.
  3. **Additions** — an explicit, named list of every genuinely new test this task adds,
     with no baseline counterpart (at minimum: the route-caching regression pin required by
     AC-14, and the documentation-correction proof required by AC-16, and any other test the
     plan introduces to prove an acceptance criterion in this spec).

  Proven by a **set difference** against the AC-1 baseline report, never by comparing
  totals. Every baseline test not named in (1) or (2) above still exists, unchanged; every
  test present after this task that was not in the baseline is accounted for, by name,
  either as a declared rename's new name (2) or in the addition list (3). A bare count
  comparison cannot tell "two tests were silently dropped and two unrelated ones silently
  added" apart from "nothing was lost", cannot express a rename at all (it reads D-39's
  replacement as one unexplained disappearance plus one unexplained appearance), and would
  forbid this task from adding the regression tests AC-3, AC-14 and AC-16 require — this is
  why the proof is by name, with renames declared rather than left to be inferred.

### FP-155 — a fast command for the inner development loop, the full gate for task completion

- **AC-3** (fast command's exclusion set is exact): Given the set of tests tagged into the
  child-process toolchain group (decision D-34) and the set of tests the new fast command
  (decision D-36) does not run, when the two sets are compared, then they are identical —
  the fast command excludes every tagged test and no test that is not tagged; it excludes
  at least one test (proving the exclusion is real, not vacuous).
- **AC-4** (full gate still runs the tagged group, unchanged outcome): Given the full gate,
  when it runs, then every test tagged into the toolchain group is collected and executed
  as part of it, with the same pass/fail result and the same assertion count it had before
  tagging — tagging changes only which command selects a test, never whether the full gate
  runs it or what it asserts.
- **AC-5** (credential-leak tests are never excluded — the explicit "never trim"
  instruction): Given `packages/kernel/tests/Feature/TenancyCredentialLeakTest.php` and
  `QaCredentialSurfaceSweepTest.php` (and any other test whose purpose is proving a
  credential does not leak), when the toolchain group is defined, then none of these tests
  is a member of it, **even where a case inside one of them itself spawns a child process**
  (for example `QaCredentialSurfaceSweepTest`'s "keeps the credential out of the dump()
  helper the application actually installs" case) — membership in the toolchain group is
  decided by "is this proving the toolchain/generator mechanism itself works," never merely
  by "does this test use `Process::`." Every credential-leak test runs, and must pass, under
  both the fast command and the full gate.
- **AC-6** (fixture hoisting preserves outcomes): Given a test file whose cases each
  currently create and delete fixture files/directories via a per-test hook (`beforeEach`/
  `afterEach`) where the same setup and teardown is safe to share once per file instead
  (i.e., no case's fixture state depends on a previous case in the same file having left it
  in a particular shape), when that file's fixture is hoisted to run once per file, then
  every case in the file produces the same pass/fail result and the same assertion values
  it produced before the hoist — hoisting changes only *when* the fixture is built and torn
  down, never a test's outcome.
- **AC-7** (fixture hoisting does not leak state between cases): Given the hoisted fixture
  from AC-6, when the file's cases run in sequence, then a case that asserts something did
  **not** yet exist (for example, before generating a package under a given name) still
  observes that true starting state — proving the shared, per-file fixture does not let one
  case's side effect silently satisfy or corrupt a later case's own setup expectation.

### FP-156 — a root line-ending rule, and the one-time renormalisation it requires

- **AC-8** (root rule exists): Given the repository after this task, when inspecting the
  repository root, then a `.gitattributes` file exists there containing `* text=auto
  eol=lf` — the same rule already proven at `services/host/.gitattributes` — so every
  tracked text file in the repository, not only those under `services/host/`, is subject to
  it.
- **AC-9** (renormalisation is its own commit, and changes only line endings): Given the
  root rule from AC-8, when the working tree is renormalised (`git add --renormalize .` or
  equivalent) and committed, then that commit is a single commit containing **only**
  line-ending changes and **nothing else** — every file it touches, when diffed against its
  pre-renormalisation content with line-ending differences ignored, shows no remaining
  difference — and it is separate from every other commit this task makes (the FP-155,
  FP-157 and FP-164 changes are not in it, and it is not in them).
- **AC-10** (the original defect is fixed — the fresh-clone proof): Given a genuinely fresh
  checkout of a generated, committed file after this task's line-ending commit (a fresh
  clone, or the equivalent used by the existing regeneration-diff regression test —
  `git stash`/discard the working copy and `git checkout` it back, never reusing a working
  tree left over from a previous test run), when the generator (`svc:make`) regenerates the
  same package and the two are diffed byte-for-byte, then they match exactly, including line
  endings — proving the specific defect the reviewer found in T-003 (the regeneration-diff
  test passing only because the compared file had never actually been checked out from git
  in that run) cannot recur. This is proven by re-running the existing regeneration-diff
  test (`SvcMakeDemoRegenerationTest`/`QaRegenerationGuardTest`'s successor, T-002
  FP-153/AC-19, T-003 AC-22) against a file freshly checked out for that run, not against
  whatever the working tree already happened to contain.
- **AC-11** (every commit stops warning): Given a commit made after AC-8/AC-9 land, when
  `git add`/`git commit`/`git status` runs, then no "LF will be replaced by CRLF" (or the
  reverse) warning is printed for any file in the repository — proving the root cause is
  fixed, not merely one instance of it.

### FP-157 — retire the remaining stock scaffolding

- **AC-12** (scaffolding files no longer exist): Given the repository after this task, when
  checking for `services/host/tests/Feature/ExampleTest.php`,
  `services/host/tests/Unit/ExampleTest.php`,
  `services/host/resources/views/welcome.blade.php`, `services/host/resources/css/app.css`,
  `services/host/resources/js/app.js`, `services/host/resources/js/bootstrap.js`, and
  `services/host/vite.config.js`, then none of them exists.
- **AC-13** (the welcome route's closure is gone, as scaffolding — not to fix a defect):
  Given `services/host/routes/web.php` after this task, when inspecting it, then it declares
  no route whose handler is an inline closure — in particular the stock `Route::get('/',
  function () { ... })` no longer exists in that form; `/` either does not exist as a route,
  or is implemented as a named controller/invokable-class action. This is retired as dead
  stock scaffolding in a backend-only host, the same reason as the example tests and the
  front-end build files (AC-12) — not because it is blocking route caching, which it is not
  (see AC-14).
- **AC-14** (route caching's already-working state is pinned, as an ongoing guarantee): Given
  the repository — both before and after this task's other changes — when running `php
  artisan route:cache`, then it completes successfully (exit 0), with no "Uses Closure" /
  "for serialization" error. This is **not** something this task fixes: the architect
  measured `route:cache` already succeeding today, closure routes included. The requirement
  is that an automated regression test makes this assertion as part of the gate from this
  task onward, so that this task's own scaffolding removal (AC-12/AC-13) — or any later
  change — cannot silently break it again without the gate catching it immediately.
- **AC-15** (route cache does not pollute the rest of the gate): Given the successful run in
  AC-14, when the test proving it finishes, then the route cache file it produced is
  cleared (`php artisan route:clear` or equivalent) before any other test runs, so no
  process-wide cached route table leaks into the rest of the gate — the same precedent as
  clearing the configuration cache before the test step (T-002 AC-9/AC-10's regression
  note).
- **AC-16** (documentation corrected to match measured reality): Given `README.md` and
  `services/host/config/servicehost.php`'s FP-149 constraint-3 text, when read after this
  task, then neither states that `route:cache` "fails outright" or is blocked by "the stock
  closure route on `/`" (both false, per the architect's measurement) — each instead states
  the constraint that is actually true and still unguarded: `route:cache` freezes the route
  table, so a module added to `SERVICES` after the cache is built is unreachable until the
  cache is rebuilt, with no code-level guard for that specific hazard (unlike the
  `config:cache`/`SERVICES` staleness guard, T-002 AC-9/AC-10). This remains one of the
  "documented only" items — `services/host/tests/Feature/QaCacheGuardBranchesTest.php`'s
  assertion that `config/servicehost.php` contains the phrase "documented only" **exactly 3
  times** continues to pass **unchanged**; only the false clause inside point 3 is
  corrected, not the "documented only" label itself, so the count does not drop to 2.

### FP-164 — a shared test helper file, not a bare function in a third file

- **AC-17**: Given `packages/kernel/tests/Feature/TenancyCredentialLeakTest.php`, when it is
  run directly and in isolation (for example `vendor/bin/pest
  packages/kernel/tests/Feature/TenancyCredentialLeakTest.php`), then it runs to completion
  with no "undefined function" fatal and the same pass/fail result it has inside a full
  suite run.
- **AC-18**: Given `packages/kernel/tests/Feature/TenancyTenantSwitchTest.php`, when it is
  run directly and in isolation the same way, then it too runs to completion with no
  undefined-function fatal and the same pass/fail result it has inside a full suite run.
- **AC-19**: Given `packages/kernel/tests/Feature/TenancyResolutionTest.php` (the file that
  declares the helper today), when it is run directly and in isolation after the helper is
  relocated, then it still passes unchanged — the relocation must not produce a
  duplicate-declaration fatal or otherwise change this file's own outcome.
- **AC-20** (the whole directory, not just the two named files): Given every test file
  under `packages/kernel/tests/Feature/` (at minimum `TenancyResolutionTest.php`,
  `TenancyTenantSwitchTest.php`, `TenancyCredentialLeakTest.php`,
  `QaTenantIsolationProofTest.php`, `QaCredentialSurfaceSweepTest.php` — the five the T-003
  QA report already checked file-by-file), when each is run individually by file path, then
  each passes on its own — closing this hazard for the whole directory, not only the two
  files the QA report happened to name.

## Out of scope

- **FP-202** — the `services/`→`modules/` rename (T-005). Nothing here renames a directory,
  a namespace, or `svc:make`/`SERVICES` vocabulary.
- **FP-203 and everything after it** — the master schema, tenant schema, migration runner,
  provisioning, authentication, and every later Phase 0+ row.
- **FP-160/FP-161** — the guard consolidation and the unforgeable tenant marker.
- **FP-158/FP-163** — the two `docs/database.md` corrections and the escape-hatch
  documentation.
- **FP-159** — declaring the framework dependency in the shared package manifests.
- **Parallel test execution.** The backlog is explicit that this must wait until fixtures
  are namespaced per worker: several existing tests write a shared cache file, generate
  packages into a shared directory, and mutate process environment variables and a static
  map (the `WeakMap`-based connection guard de-duplication, per the T-003 QA report).
  Parallelising before that produces flakes indistinguishable from real tenancy bugs. This
  task's fixture-hoisting (AC-6/AC-7) only moves *when* per-file fixtures run; it does not
  make them safe to run concurrently, and no change in this task enables `--parallel` or
  equivalent.
- **The container image build and CI pipeline** (FP-217/FP-218) — route caching's
  already-working state (pinned by AC-14) is a precondition they need, not something this
  task fixes.
- **Exact wording of any new error/documentation text** beyond what AC-16 (README/
  `servicehost.php` correction) requires, and **whether the pre-existing
  `services/host/.gitattributes` is removed or left in place** once the root rule exists
  (both satisfy the same rule; removing it is not required) — left to `dev`/`architect`.
- **Any change to test assertions' meaning.** Every existing assertion keeps asserting
  exactly what it asserted before; only which command runs it (FP-155), when its fixture is
  built (FP-155), how it is checked out (FP-156), whether its file exists at all (FP-157,
  and only for the six named files plus their two example tests), and where its helper
  function lives (FP-164) may change.

## Regression — what T-001, T-002 and T-003 criteria this task could break

- **T-001 AC-16/AC-17** (`composer test`/`composer check` exit 0): re-prove end to end —
  every change in this task touches the test suite's structure, file list, or fixture
  timing.
- **T-002 AC-12/AC-13** (every host test directory collected exactly once, no
  duplicate-collection warning — `HostTestSuiteCollectionTest`,
  `QaHostSuiteCollectionProofTest`): re-prove that adding a `toolchain` group and a
  `test:fast` script does not change which directories `phpunit.xml` collects, only which
  subset a given command executes.
- **T-002 AC-16/AC-17** (test code analysed by PHPStan/boundary-checked by Deptrac at level
  6 — `QaTestCodeAnalysisCoverageTest`, `DeptracBoundaryEnforcementTest`): re-prove that the
  FP-164 helper's new home and every file left after the FP-157 removals stay covered and
  clean.
- **T-002 AC-18** (`QaToolchainRegistryCoverageTest`'s narrow fixture-skip rule — "the
  toolchain-registry criterion", named explicitly in this task's brief): re-prove unchanged
  — a Pest group tag and a new Composer script are not a new service package and must not
  be misread as one needing registration in `phpstan.neon.dist`/`deptrac.yaml`/`phpunit.xml`.
- **T-002 AC-11/AC-14** (documentation accuracy — asserted by
  `services/host/tests/Feature/QaCacheGuardBranchesTest.php`, **not**
  `QaDocumentationAccuracyTest.php`; corrected attribution): **this one regresses by design
  and must be updated, not merely re-proved — but not for the reason first assumed while
  writing this spec.** `README.md` and `services/host/config/servicehost.php` currently
  document FP-149 constraint 3 ("`route:cache` ... currently fails outright because of the
  stock closure route on `/` ... Documented only; removing stock Laravel scaffolding is out
  of scope for now") as one of three "documented only" items — and that text was already
  false before this task touched anything, per the architect's measurement (`route:cache`
  already succeeds). AC-16 corrects the false clause. **The "documented only" phrase count
  does not change**: `route:cache` freezing the route table for a module added to `SERVICES`
  after the cache is built remains a real, unguarded hazard independent of whether the
  `route:cache` command itself throws, so point 3 keeps its "documented only" label —
  `QaCacheGuardBranchesTest`'s `expect(substr_count(strtolower($config), 'documented
  only'))->toBe(3)` assertion is re-proved **unchanged**, not updated to 2. (An earlier
  revision of this spec predicted the count would drop to 2 and misattributed this
  assertion to `QaDocumentationAccuracyTest`; both are corrected here.)
- **T-002 D-18 / `HealthEndpointTest`'s "keeps the stock welcome route inside the web
  group"**: **this test's own premise disappears under AC-13.** Its purpose is not "the
  welcome route must exist" — it is proving that registering `/health/live` and
  `/health/ready` outside the `web` group does not accidentally replace whatever else
  `routes/web.php` declares (D-18: "if this ever comes back empty, the `then` callback has
  swallowed `routes/web.php` instead of adding to it"). Once the welcome route is gone,
  this test needs a different probe that still proves the same thing (for example, a
  placeholder route added for exactly this purpose, or an assertion on the route count
  before/after) — it must not simply be deleted, or T-002's D-18 guarantee goes unproven.
- **T-003 AC-19 through AC-23** (the "generator" criteria, named explicitly in this task's
  brief: no stub produces a per-module connection; a generated module declares none; its
  generated test passes out of the box; the committed demo equals a fresh regeneration;
  demo's own test passes with no removed connection dependency): re-prove all five — the
  tests that exercise them (`QaModuleConnectionRemovalTest`, `SvcMakeAtomicGenerationTest`,
  `QaRegenerationGuardTest`, `SvcMakeDemoRegenerationTest`, `services/demo`'s
  `PingRouteTest`) are among the ones whose per-test fixtures FP-155 hoists to per-file, and
  T-003 AC-22 specifically is the test FP-156's fresh-clone proof (AC-10 above) re-runs for
  real.
- **T-001 AC-31/AC-32** (`services/demo` committed and included by default; its ping route
  returns 200): confirm the demo package's own route and test are unaffected by the FP-157
  scaffolding removal (they share no file with the welcome view/closure route, but re-prove
  rather than assume).

## Non-functional requirements

- No fix or addition in this task may cause `composer check` to regress from its current
  exit-0 state once this task's own changes are included (same standing requirement as
  T-001/T-002/T-003).
- Configuration and script changes stay OS-agnostic — no Windows-specific absolute paths in
  `composer.json` or config files, consistent with the existing non-functional requirement.
- The full gate (decision D-35) remains serial; nothing in this task introduces parallel
  test execution (see "Out of scope").
- Every file in the repository, after the FP-156 fix, has the line endings its own
  `.gitattributes` rule declares — proven negatively by AC-11 (no warning on commit) rather
  than by enumerating every file.

## Assumptions

- The reviewer's measurements (152 s/156 s cold, 91 s warm; roughly forty child-process
  tests; growth proportional to total test-file count) are accepted as given; this task
  does not re-derive them.
- **Correction (this revision):** the backlog's own FP-157 wording, and this spec's first
  revision, both stated that the closure route on `/` "is what makes route caching throw"
  and that removing it was the point of AC-14. The architect measured this false before
  planning: `php artisan route:cache` already succeeds today, and four closure-based routes
  are registered once the host boots (the welcome route plus three the framework itself
  registers, including the `/up` health-check route), not the one the premise assumed. This
  is accepted as given, the same way the reviewer's timing measurements are; `ba` does not
  re-derive it independently beyond confirming the welcome route and the framework's `/up`
  route both exist as closures in the current source. AC-14 is written accordingly as an
  ongoing regression guarantee, not a fix, and AC-16 corrects the documentation that still
  asserts the false version.
- The AC-1 baseline (277 passed, 1219 assertions) is taken from
  `docs/qa/T-003-tenant-connections.md`'s final run and is expected to still hold at the
  start of this task's implementation, since `git status` at spec time shows only
  `docs/backlog.md` modified on this branch; `dev` re-confirms the exact number when
  implementation starts rather than trusting this document if the branch has moved.
- "The child-process toolchain tests" (FP-155 item 1) means tests whose purpose is proving
  the toolchain/generator mechanism itself (linting, static analysis, boundary checking,
  test collection, code generation, cache-guard behaviour) — not every test that happens to
  use `Illuminate\Support\Facades\Process` for an unrelated reason. This distinction is what
  keeps AC-5 (credential-leak tests are never excluded) satisfiable even though at least one
  credential-leak case itself spawns a child process.
- Whether the pre-existing `services/host/.gitattributes` is left in place (redundant but
  harmless once the root rule exists) or removed is an implementation detail with no
  observable difference to any acceptance criterion; left to `dev`.
- All ambiguous implementation choices encountered while writing this spec were resolved by
  `ba` and logged in `docs/decisions/T-004-gate-speed-and-hygiene.md` (D-34 onward) per the
  team protocol, rather than left as blocking questions.

## Open questions

None blocking. All three items the task brief asked `ba` to decide (fast command: group vs.
directory exclusion; the full gate's name; which command the task-completion hook uses) are
resolved and logged as D-34, D-35 and D-36 in
`docs/decisions/T-004-gate-speed-and-hygiene.md`.
