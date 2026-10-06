# T-005: Rename services to modules

Covers backlog rows **FP-202** (the rename) and **FP-169** (bring the host's test directory into
static analysis and the boundary check). Infrastructure only: no feature, no behaviour change,
no schema. Decisions D-43 to D-56 are in `docs/decisions/T-005-rename-modules.md`.

## Problem / goal

`docs/architecture.md` v0.4 calls the units **modules** and already says `modules/`. The code still
says **services**, from the pre-v0.4 design: the directory `services/`, the generator `svc:make`,
the environment variable `SERVICES`, the namespace `Flexipos\Service\`, and the classes and
files that follow. Every later phase will be written against the code, so each task would
otherwise add a second vocabulary beside the first. The backlog measured 57 tracked files: 26
under `services/`, 19 under `docs/`, 7 in agent memory, 5 at the root (accepted as given, not
re-derived). It is deliberately scheduled now, after the gate was made fast, because it is the
largest mechanical change in the backlog and will be run many times.

**The defining risk is a silent partial rename**: a stale reference that nothing executes, so
the suite stays green while the repository is inconsistent. Several of the places it can hide are
literal strings, not code: `toolchainGroupFiles()` and `filesSpawningChildProcesses()` in
`services/host/tests/Helpers.php` name `services/...` paths; `deptrac.yaml` and
`phpstan.neon.dist` list paths and layer names; stubs embed the namespace; comments and
prose name the old word. Nothing below is satisfied by a green suite alone.

FP-169 rides along because the host's own `tests/` directory is in neither PHPStan's nor
Deptrac's path list (the demo's and the kernel's tests already are), and the rename is exactly
the edit that breaks a reference in that directory without anything noticing. T-004 also moved
shared infrastructure into it (`Helpers.php`, `Pest.php`) that many test files depend on.

## Users affected

- `dev`: performs a very large mechanical change and must not leave a half-renamed tree.
- `architect`: plans the sequencing so the gate is meaningful at each commit.
- `qa` and `reviewer`: prove absence of the old vocabulary and unchanged behaviour.
- Every later task (FP-203 onward): inherits whatever vocabulary exists after this one.
- The lead: owns `CLAUDE.md` and the backlog status.

## Current behaviour

- Directory `services/` holds `host/` (the Laravel application, reserved name, not a generated
  module) and `demo/` (the committed demo module).
- `SERVICES` is read through `config/servicehost.php` (`'services' => env('SERVICES', '')`) by
  `App\Providers\ServiceHostServiceProvider`, which boots packages through
  `App\Support\ServiceHost\ServiceRegistry`; failures throw `UnknownServiceException`
  (`service()` accessor, message "Unknown service ... listed in SERVICES").
- `php artisan svc:make <name>` (`SvcMakeCommand`) reads `stubs/service/*.stub` and generates
  `services/<name>/` with namespace `Flexipos\Service\<Studly>\`, Composer name
  `flexipos/service-<name>`, manifest key `extra.flexipos.service`, and prints a reminder naming
  a `Service_<Studly>` layer.
- `deptrac.yaml` names layers `Service_Demo` and matches `^Flexipos\\Service\\Demo\\.*`;
  `phpunit.xml` has a suite named `Services` and `<env name="SERVICES">`;
  `GET /health/ready` returns `{"status":"ok","services":[...]}`.
- Tests encode the old word in file names (`SvcMake*Test`, `ServiceRegistry*Test`,
  `ServicesEnvParsingTest`, `QaServiceNameValidationTest`, `QaGeneratedServiceTestDiscoveryTest`,
  `UnlistedServiceReturns404Test`, ...), in the deptrac fixture (`ServiceA`, `ServiceB`,
  `Service_A`, `Service_B`), and in a helper `servicesPath()`.
- **FP-169 gap:** `phpstan.neon.dist` and `deptrac.yaml` list `../demo/tests` and
  `../../packages/kernel/tests` but not the host's own `tests`. The Deptrac `HostApp` layer
  matches `^App\\.*`, so the `Tests\` namespace belongs to no layer.
- Baseline from `docs/qa/T-004-gate-speed-and-hygiene.md`'s final run: `composer check` exit 0,
  **304 passed, 1358 assertions**. `git status` shows only `docs/backlog.md` modified, so this
  should still hold; `dev` re-confirms it before touching anything.
- `docs/architecture.md` and `docs/database.md` already use the new vocabulary. `docs/backlog.md`,
  `docs/deployment-vps.md` (`SERVICES=all`, "13 services"), `README.md` and
  `services/host/README.md` do not.
- `.claude/agent-memory/**` is tracked and describes current mechanics in the old words.
- **Trap, named on purpose:** `services/host/config/services.php` is the stock Laravel file for
  third-party credentials. It is unrelated to this rename and must not be renamed or edited.
  The same goes for `Illuminate\Support\ServiceProvider` and the `ServiceProvider` suffix
  (D-44).

## The new names (D-43 to D-51)

| Old | New |
|---|---|
| `services/`, `services/host`, `services/demo` | `modules/`, `modules/host`, `modules/demo` |
| `SERVICES` | `MODULES` |
| `config/servicehost.php`, key `'services'` | `config/modulehost.php`, key `'modules'` |
| `svc:make`, `SvcMakeCommand` | `module:make`, `ModuleMakeCommand` |
| `stubs/service/` | `stubs/module/` |
| `Flexipos\Service\<Studly>\` | `Flexipos\Module\<Studly>\` |
| `App\Support\ServiceHost\ServiceRegistry` | `App\Support\ModuleHost\ModuleRegistry` |
| `UnknownServiceException`, `service()` | `UnknownModuleException`, `module()` |
| `ServiceHostServiceProvider` | `ModuleHostServiceProvider` |
| `flexipos/service-<name>`, keyword `service-host` | `flexipos/module-<name>`, `module-host` |
| `extra.flexipos.service` | `extra.flexipos.module` |
| layer `Service_<Studly>` (and fixtures) | `Module_<Studly>` |
| `/health/ready` field `"services"` | `"modules"` |
| phpunit suite `Services`, env `SERVICES` | `Modules`, `MODULES` |

Unchanged on purpose: `<Studly>ServiceProvider`, `Illuminate\Support\ServiceProvider`,
`config/services.php`, `flexipos/host`, `Package_*` layers, the reserved name `host`.

## User stories

- As a developer, I want the code to say "module" wherever the architecture does, so that I do
  not have to translate between two vocabularies on every task.
- As a developer, I want `module:make` to generate a working module and `MODULES` to boot it,
  so that the workflow I already know still works under the new names.
- As `qa` and `reviewer`, I want a proof that no old identifier survives, with a control showing
  the search can find one, so that a green suite cannot hide an inconsistent repository.
- As anyone reading `git log` or `git blame`, I want history to follow the moved files, so that
  the record of who changed what and why survives.
- As a developer, I want the host's own tests statically analysed and boundary-checked, so that a
  broken reference or an illegal dependency in shared test infrastructure fails the gate.
- As the lead, I want a complete list of the `CLAUDE.md` lines that need changing, so that I can
  update the owner's file without searching.

## Acceptance criteria

Numbering starts at AC-1 for T-005.

### Behaviour is unchanged (counts)

- **AC-1** (baseline captured first): Given the branch before any change, when `composer check`
  is run, then `dev` records exit code, passed-test count and assertion count (expected 304 and
  1358, exit 0), kept where `qa` can compare. If the branch has moved, the observed figures are
  the baseline.
- **AC-2** (the rename alone adds and removes nothing): Given every FP-202 change applied and
  before any FP-169 addition, when the full gate runs, then it exits 0 with the same passed count
  and the same assertion count as AC-1. A changed expected value (for example the renamed
  `/health/ready` key) keeps its assertion; it does not add or drop one.
- **AC-3** (FP-169 additions are declared, not silent): Given the final state of the task, when
  the full gate runs, then it exits 0 and the counts equal AC-1 plus exactly the tests FP-169
  adds, which the plan or commit lists by name (at least those required by AC-19 and AC-20).
  No other test is added, removed, skipped or weakened.

### Absence of the old vocabulary (the defining risk)

- **AC-4** (what counts as an old identifier): For AC-5 and AC-6 the old identifiers are: the
  path `services/` as the former top-level directory; `SERVICES`; `svc:make`; `SvcMake`;
  `Flexipos\Service\` (and its escaped forms `Flexipos\\Service\\`); `ServiceHost`;
  `ServiceRegistry`; `UnknownServiceException`; `servicehost`; `flexipos/service-`;
  `service-host`; `Service_` as a layer prefix; `ServiceA`/`ServiceB`; `stubs/service`;
  `servicesPath`; the JSON field `"services"` in the health response; the phpunit suite
  `Services`. Not old identifiers: the framework's `ServiceProvider` and any class with that
  literal suffix, `config/services.php` and its content, and ordinary English "service"
  unconnected to the module concept (for example a deployment "service" in a Docker context).
  `architect` finalises the exact search patterns in the plan.
- **AC-5** (zero matches): Given the repository after this task, when every tracked file is
  searched for the AC-4 identifiers, then there are no matches, excluding only: `Refference/**`;
  the T-001 to T-004 records under `docs/specs`, `docs/plans`, `docs/qa`, `docs/reviews` and
  `docs/decisions` (D-52); and `CLAUDE.md` (AC-22). File names and directory names are searched
  as well as contents. Includes `.claude/agent-memory/**` (D-53), both READMEs,
  `docs/backlog.md`, `docs/deployment-vps.md`, `.env.example`, `composer.json`,
  `composer.lock`, `bootstrap/providers.php`, stubs and every test.
- **AC-6** (positive control): Given the same search, when run against at least one excluded
  historical file (for example `docs/qa/T-003-tenant-connections.md`) or against the pre-rename
  commit `0e9a1a2`, then it finds matches for at least three distinct AC-4 identifiers. This shows
  an empty AC-5 result is evidence of absence and not a broken pattern.
- **AC-7** (the literal path lists are updated, not merely present): Given `Helpers.php`'s
  `toolchainGroupFiles()`, `toolchainGroupExemptions()`, `qaFixturePackageNames()` and the
  directory list in `filesSpawningChildProcesses()` (today `['services', 'packages']`), when the
  gate runs, then every path they name exists on disk under its new name, and the existing
  self-checks (`ToolchainGroupCoverageTest`, `QaToolchainRegistryCoverageTest`,
  `toolchainMissingPaths()`) pass. A negative control: temporarily pointing one entry at a
  non-existent path makes a self-check fail, proving it still guards the list.
- **AC-8** (`Refference/` untouched): Given the branch, when diffed against `main`, then there is
  no change under `Refference/`.
- **AC-9** (historical records keep their substance): Given each T-001 to T-004 file under
  `docs/specs`, `docs/plans`, `docs/qa`, `docs/reviews` and `docs/decisions`, when diffed against
  `main`, then the only change, if any, is one short dated note at the top saying the vocabulary
  changed in T-005. No other line differs.

### The new names exist and work

- **AC-10**: Given the repository root, when listed, then `modules/` exists with `host/` and
  `demo/` in the shape `services/` had, and `services/` does not exist.
- **AC-11**: Given `MODULES=demo`, when the host boots, then the demo module is booted and
  `GET /api/v1/demo/ping` returns 200. Given `MODULES` empty or unset, the host boots with zero
  modules. Given `MODULES=` naming a package with no directory, the host fails fast at boot and
  the message names the module and `MODULES`. Setting `SERVICES` has no effect.
- **AC-12**: Given `GET /health/ready` with `MODULES=demo`, then the body is
  `{"status":"ok","modules":["demo"]}`; with `MODULES` empty, `"modules":[]`; neither contains
  `"services"`.
- **AC-13**: Given the demo module and a freshly generated one, when their `composer.json` and
  classes are read, then the namespace is `Flexipos\Module\<Studly>\`, the package name is
  `flexipos/module-<name>`, the manifest has key `extra.flexipos.module`, and the provider class
  is `<Studly>ServiceProvider` inside that namespace.
- **AC-14** (Composer consistency): Given the regenerated `composer.lock`, when
  `composer install` runs in `modules/host`, then it exits 0 and the renamed namespaces
  autoload; `flexipos/service-demo` appears nowhere in the lock file.
- **AC-15** (the registry's refusals still work under the new names): Given the T-002 manifest
  trust cases (wrong kind of class, namespace not equal to `Flexipos\Module\<Studly>\`, provider
  outside its namespace, extra namespace entries, name with a trailing newline or hyphen,
  `host`), when each is attempted, then each is still refused with a message naming the module,
  exactly as before apart from the vocabulary.

### Generator fidelity

- **AC-16**: Given `php artisan module:make <valid-name>`, when run, then `modules/<name>/` is
  created and its own generated test passes with no manual fix-up. Given `svc:make`, the command
  does not exist. Given an invalid name, an existing name, `host`, or a failure part way through,
  then the refusal or the all-or-nothing rollback behaves as T-001 and T-002 specified.
- **AC-17** (byte for byte, fresh checkout): Given a genuinely fresh checkout of `modules/demo`
  from git, not a tree left over from a run (the T-004 AC-10 discipline), when
  `module:make demo` regenerates it, then the two are byte-identical, including line endings.
- **AC-18**: Given the generator's closing reminder, then it names `modules/<name>/src`,
  `modules/<name>/tests` and a `Module_<Studly>` layer, never `services/` or `Service_`.

### Git history follows the moved files

- **AC-19**: Given a representative file from each moved tree (at least
  `modules/host/app/Support/ModuleHost/ModuleRegistry.php`, a renamed test file, and a file in
  `modules/demo`), when `git log --follow` is run on it, then it shows commits that predate the
  rename (for example `ServiceRegistry.php`'s T-001 and T-002 history).
- **AC-20**: Given the commit or commits that perform the move, when inspected with rename
  detection (`git show --stat -M`), then the moved files are reported as renames, not as deletions
  plus additions; a content-heavy edit and a pure move are not mixed in a way that defeats
  detection for files that only needed moving. `dev` states in the commit message which commit is
  the move.

### FP-169: the host's tests join analysis and the boundary check

- **AC-21** (static analysis): Given the host's `tests` directory, when `composer stan` runs,
  then it analyses it (listed in the PHPStan paths as `../demo/tests` already is). A planted
  level 6 defect (for example an untyped parameter) in a host test file makes `composer stan` exit
  non-zero and name that file; the clean tree exits 0. The planted file is removed afterwards.
- **AC-22** (boundary check): Given the host's `tests` directory, when `composer deptrac` runs,
  then it is checked. A planted host test file that imports a module's internal class makes
  Deptrac exit non-zero and name both layers; the shipped tree exits 0 with 0 violations. The
  planted file is removed afterwards. The legitimate shared test infrastructure (`TestCase`,
  `Helpers.php`, `Pest.php`) produces no violation on the clean tree.
- **AC-23** (registry coverage): Given `QaToolchainRegistryCoverageTest`'s sweep, when it runs,
  then it passes and no longer leaves the host's tests unregistered by exemption; deleting the new
  registration in a scratch copy makes it fail.
- **AC-24** (collection unchanged): Given `phpunit.xml`, then every test directory is still
  collected exactly once with no duplicate-collection warning (T-002 AC-12 and AC-13).

### `CLAUDE.md`, the owner's file

- **AC-25**: Given `CLAUDE.md`, then this task leaves it byte-identical, and the plan or the final
  commit message lists every line that still needs changing for the lead: the "Vocabulary note"
  paragraph (which says the rename is pending), the nine `cd services/host` commands in the
  Commands table, and the Conventions bullet naming `SERVICES`.

### Forward-looking documents

- **AC-26**: Given `docs/backlog.md`, `docs/deployment-vps.md`, `README.md` and
  `modules/host/README.md`, when read, then they describe the new layout and commands accurately
  (for example "Adding a module" uses `php artisan module:make`; the README's `/health/ready`
  example shows `"modules"`; the deployment table says `MODULES`), and every link and every path
  in them resolves. `docs/architecture.md` and `docs/database.md` are checked and only changed
  if the AC-5 search finds a match (expected: none). The backlog marks FP-202 and FP-169 done
  only when the lead says so; `dev` does not set status.

## Out of scope

- Hoisting the host to a top-level `app/` as in `docs/architecture.md` section 4 (D-43).
- Any change under `Refference/`; any edit of `CLAUDE.md` (AC-25).
- Rewriting the substance of the T-001 to T-004 records (AC-9).
- `config/services.php`, `Illuminate\Support\ServiceProvider`, the `ServiceProvider` suffix.
- FP-203 onward, the per-module connection model (already removed in T-003), the dependency and
  manifest hardening rows (FP-143, FP-154, FP-159), and gate-speed work (FP-165, FP-170).
- The stale `{{connection}}` and `{{schema}}` replacement keys in `QaTestCodeAnalysisCoverageTest`:
  unrelated leftovers, not renamed or cleaned here.
- Parallel test execution; changing any assertion's meaning.

## Non-functional requirements

- `composer check` must end at exit 0 on the final tree, and each intermediate commit should be
  one the gate can meaningfully run (sequencing is `architect`'s).
- Config and scripts stay OS-agnostic, no Windows absolute paths.
- The root `.gitattributes` rule from T-004 governs moved files: no line-ending warnings on commit
  and no whole-file diffs that defeat rename detection.
- The shipped default still boots the demo module (`MODULES=demo` in `.env.example`).
- Never commit `.env`, `vendor/` or secrets.

## Regression: T-001 to T-004 criteria at risk

The identifiers these assert on are changing, so each must be re-proved under the new names and
the tests that prove them updated in step. Their descriptions in the historical records are not
edited.

- **T-001 AC-15 and AC-18 to AC-32**: the host boot, `SERVICES` parsing, unknown-name failure,
  health, generator, no-edit-to-host and demo criteria. Essentially all.
- **T-002 AC-1 to AC-6** (manifest trust, name validation, atomic generation and retry),
  **AC-9 and AC-10** (the `config:cache` staleness guard, whose environment variable changes),
  **AC-11 and AC-14** (documentation accuracy, asserted by `QaDocumentationAccuracyTest` and
  `QaCacheGuardBranchesTest`, including the exact count of the phrase "documented only" in the
  renamed `config/modulehost.php`, which must still be 3), **AC-12 and AC-13** (suite
  collection), **AC-15** (the host README), **AC-16 and AC-17** (test directories in analysis and
  boundary check, the pattern FP-169 extends), **AC-18** (registry coverage), **AC-19**
  (the demo regenerates).
- **T-003 AC-19 to AC-23**: a generated module declares no connection, its test passes, and the
  committed demo equals a regeneration; plus the guard and tenancy criteria only incidentally, as
  the tenant files do not change.
- **T-004 AC-3 to AC-5** (the toolchain group, whose registry is a list of `services/...` paths),
  **AC-10** (the fresh-checkout regeneration proof) and the line-ending criteria.
- The `RouteCachingTest` pin (T-004 AC-14) and `HealthEndpointTest` probe (T-004 D-39) change
  only vocabulary.

## Open questions

None blocking. Every ambiguity is resolved and logged as D-43 to D-56 in
`docs/decisions/T-005-rename-modules.md`. For the lead's attention, because they extend or
interpret the brief: D-43 (the host is not hoisted), D-52 (decisions files treated as historical
records), D-53 (agent memory treated as forward-looking), D-50 (the health field is renamed).

## Assumptions

- The baseline (304 passed, 1358 assertions) is taken from the T-004 QA report and re-confirmed
  by `dev` at the start (AC-1).
- "Agent memory" means the tracked `.claude/agent-memory/**`.
- The brief's "eighteen" records does not equal the sixteen files in the four named folders; the
  rule is applied by folder and task (D-52).
- Exact search patterns, the file-by-file rename list, the new Deptrac layer for the host's tests
  and the commit sequencing are decided in `architect`'s plan, not here.
- The AC-4 list is believed complete from reading the tree; the AC-5 search is the check on it.
  If it finds an identifier not in AC-4, that is a defect in the list, and the identifier is
  renamed by the same rule.
- Composer package names are local path-repository names, never published.
