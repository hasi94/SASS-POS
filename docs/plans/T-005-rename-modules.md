# Plan: T-005 Rename services to modules

Spec: `docs/specs/T-005-rename-modules.md` (AC-1 to AC-26) · Decisions: `docs/decisions/T-005-rename-modules.md`
(D-43 to D-56) · Covers **FP-202** and **FP-169** · Branch `ai/T-005-rename-modules` from `main`.

Everything numbered below was measured on this working tree on 2026-10-02, not inferred. Where a
measurement contradicts the brief, the spec or an earlier plan of mine, it is marked **MEASURED**
and the contradiction is named.

---

## Approach summary

Nine steps. FP-169 goes **first** (steps 1 and 2), because the path it adds is `tests`, relative to
the host's own directory, and is therefore untouched by the directory move — so the "do it last
because the rename moves its paths" argument is false by measurement, while the "do it first
because it catches stale references" argument holds. The rename itself is then split into a
**move-only commit** (step 3: `git mv` and nothing else, so rename detection is trivially 100 %) and
a **content commit** (step 4: every identifier edit plus the regenerated lock file). The repository
is red between those two commits and nowhere else; it is green at the end of every other step. The
proof of completeness is a standing Pest test that searches every tracked file, by path and by
content, for a fixed list of old identifiers, with a positive control that makes a broken pattern
fail loudly instead of looking clean. `composer.lock` is regenerated — this is the one task where
that is expected (AC-14, D-48), and it must land in the same commit as the manifests it matches.

## Alternatives rejected

- **FP-169 last.** Rejected: measured, the paths it adds are `tests` (relative to the host dir), which
  the move does not change; and 99 of its findings are exactly the class of error a rename creates.
- **Hoisting the host to a top-level `app/`** as `docs/architecture.md` §4 shows. Rejected: see the
  ruling below. The document changes, not the tree.
- **One commit for move + edits.** Rejected: rename similarity is computed at display time from
  content, and several small files (`composer.json.stub`, `routes.api.php.stub`,
  `config/servicehost.php`) change a large fraction of their bytes; mixing would push some below the
  50 % default and record them as delete+add.
- **A PHPStan baseline file to absorb FP-169's 99 findings.** Rejected: a baseline keys on file path
  and message, so the move would invalidate every entry and the stale baseline would then hide new
  errors — the exact failure mode this task exists to prevent.
- **`sed -i` across the tree.** Rejected: measured collateral — `modules/host/config/services.php`
  (stock Laravel), `storage/framework/.gitignore`'s `services.json` (the framework's own cached
  manifest), `src/Services/` inside a generated module (service classes, not modules), and the
  immutable branch names `ai/T-001-monorepo-service-host` / `ai/T-002-service-host-hardening` in the
  backlog all contain the string and must survive.
- **A dated note at the top of each historical record** (AC-9 allows one). Rejected: see the ruling.

---

## Rulings the lead asked for

### R1. The host stays at `modules/host`; `docs/architecture.md` §4 is what changes

I agree with the lead and keep **D-43**. Measured reason, stronger than "it is a bigger change":
the whole toolchain is built on the host being a **sibling** of the modules at the same depth, and
that is precisely why the move costs so little —

| Reference | Value | Survives `services/` → `modules/`? |
|---|---|---|
| `composer.json` repositories | `../*` and `../../packages/*` | yes, depth unchanged |
| `composer.lock` path dist urls | `../demo`, `../../packages/testing` | yes |
| `phpstan.neon.dist` / `deptrac.yaml` paths | `../demo/src`, `../../packages/kernel/src` | yes |
| `phpunit.xml` suites | `../*/tests`, exclude `../host/tests` | yes |
| `ModuleRegistry::modulePath()` | `dirname($app->basePath()).'/'.$name` | yes |
| `toolchainRepositoryRoot()` | `dirname(__DIR__, 3)` | yes |
| `servicesPath()` (Pest.php) | `dirname(base_path()).'/'.$name` | yes |

Hoisting to `app/` breaks every row of that table at once and collides with Laravel's own `app/`
inside the application. It is a restructure, not a rename.

So **`docs/architecture.md` §4 is corrected in this task** (step 5). Exact replacement for the first
two lines of the layout block, and one sentence after it — dev applies this literally:

```text
├── modules/                  the host application and one package per module above
│   ├── host/                 the Laravel host: bootstrap, tenant resolver, module loader
│   └── <module>/
│       ├── src/ (Http, Models, Services, Listeners, Hooks, Console)
│       ├── routes/  database/migrations/  config/  tests/
│       └── composer.json + <Module>ServiceProvider
```

> The host application is a sibling of the modules under `modules/`, not a top-level `app/`
> (T-005, D-43): the generator, the boundary check and every relative path in the toolchain are
> built on that sibling relationship. The two migration trees under `database/` below are the
> FP-203 / FP-204 target, not the shipped tree.

That last clause is deliberate: §4 diverges from the tree in a second place (the root
`database/master` and `database/tenant` trees do not exist yet), and leaving it unlabelled is how
this divergence happened the first time.

### R2. The lock file is regenerated — this is the one task where that is expected

**Yes, and it must land inside step 4's commit**, never in a commit of its own, because a commit
whose `composer.json` and `composer.lock` disagree is a broken commit.

Three measured reasons:

1. `modules/host/composer.json` requires `flexipos/service-demo` in `require-dev`. Renaming the
   package to `flexipos/module-demo` changes `composer.json`, which invalidates the lock's
   `content-hash` (`6db0c76095f3c227355661fdc3c7d108` today, line 7). An unregenerated lock means
   `composer install` warns and installs the *old* package set — AC-14 fails.
2. **The lock is already stale**, which nobody has noticed: its `flexipos/service-demo` entry (line
   6519) still records `extra.flexipos.connection: "svc_demo"` and `extra.flexipos.schema:
   "pos_demo"`, which T-003 / FP-201 removed from `modules/demo/composer.json`. Regenerating also
   retires that drift.
3. `vendor/` can **mask** a missed rename. The path package is installed as
   `vendor/flexipos/service-demo` with a PSR-4 prefix `Flexipos\Service\Demo\`; after the directory
   move its relative target still resolves, so a stale reference could keep working and the suite
   stay green. The lock and `composer install` have to be done before the absence proof runs.

Procedure, in order, with the measurement that decides it:

```
# in modules/host, after composer.json and every module manifest are renamed
composer update --lock           # recomputes content-hash + the renamed package entry
git diff -U0 composer.lock | grep -E '"version"|"reference"'
```

The diff must touch only: `content-hash`; the `flexipos/service-demo` → `flexipos/module-demo`
block (name, description, `dist.reference`, `extra.flexipos.module`, `autoload.psr-4`); and
possibly that block's position in `packages-dev` (it sorts between `filp/whoops` and
`flexipos/testing` either way, so expect no move). **No third-party `"version"` may change.** If
one does, revert the lock, try `composer update flexipos/module-demo --no-install`, and if that
also moves a version, stop and message `architect` — a dependency bump must not ride inside a
rename.

Then `composer install` and verify what the gate will actually load:

```
ls vendor/flexipos                                   # module-demo present, service-demo absent
grep -c 'Flexipos\\\\Service' vendor/composer/autoload_psr4.php   # expect 0
```

### R3. FP-169 goes first, and its boundary half needs a layer, not just a path

**First**, as steps 1 and 2, before anything moves.

- The counter-argument evaporates on measurement: the entries FP-169 adds are the literal strings
  `tests` in `phpstan.neon.dist` and `deptrac.yaml`, relative to the host directory. The move does
  not change them.
- **MEASURED: `composer stan` over `modules/host/tests` reports 99 errors today** (0 before,
  because the directory is in neither registry). 11 files, two dominant causes:
  40 × `$this->bootHostWith(...)` and 27 × `$this->get(...)` → *Call to an undefined method
  PHPUnit\Framework\TestCase::…* (Pest types a test closure's `$this` as the plain PHPUnit case);
  30 consequential `argument.unresolvableType` / `*.unresolvableReturnType`; plus two singletons.
  So this is **not** a one-line change, and it must be its own commit before the churn.
- From step 3 onward, every stale reference in host test code — including one on a line nothing
  executes — is a hard `composer stan` failure instead of a silent survivor. That is the single best
  instrument this task has, and it only helps if it is installed first.

**MEASURED fix forms** (probed with the shipped `phpstan.neon.dist`, four candidate forms, in the
scratchpad — do not re-litigate these by reasoning):

| Form | Result |
|---|---|
| `$this->bootHostWith('demo')` | error (`method.notFound`) |
| `test()->bootHostWith('demo')` | error — `test()` is typed `TestCall\|HigherOrderTapProxy` |
| free function wrapping `test()` with `@var` | **two** errors: `varTag.nativeType`, and `bootHostWith()` is `protected` so it is not callable from outside |
| `/** @var TestCase $this */` immediately inside the closure | **clean** |
| `use function Pest\Laravel\get;` then `get(...)` | **clean** (already the project's convention, T-002, asserted for the generated stub) |

The deptrac half, **MEASURED on the shipped configuration**:

| Configuration | Violations | Uncovered | Exit |
|---|---|---|---|
| shipped today | 0 | 57 | 0 |
| shipped + `tests` in `paths` | 0 | **57** | 0 |
| shipped + `tests` in `paths` + a `HostTests` layer collecting `^Tests\\.*` | 0 | 58 | 0 |

Adding the path alone changes **nothing** — it is inert, because the host's Pest files declare no
classes and `Tests\…` belongs to no layer, so nothing in that directory is ever the *depender* in a
rule. So FP-169's boundary half is: **the path and a `HostTests` layer**, ruleset
`[HostApp, Package_Auth, Package_Contracts, Package_Eventbus, Package_HttpClient, Package_Kernel,
Package_PluginSdk, Package_Testing]` — deliberately **not** any `Module_*` layer, which is what makes
AC-22's planted violation (a host test importing a module internal) reportable. Intra-layer
dependencies are allowed by deptrac, so the `Tests\Fixtures\DeptracViolation\*` classes stay silent
on the clean tree (measured: 0 violations with the layer in place).

### R4. No dated note on the historical records

AC-9 permits "one short dated note, if any". **Zero notes.** Twenty files whose entire point is to
be untouched would each carry a diff, and the note's content is discoverable from this plan, the
T-005 records and the backlog. The gain is a stronger, cheaper proof: AC-8 and AC-9 collapse to

```
git diff --name-only main -- Refference docs/specs docs/plans docs/qa docs/reviews docs/decisions
```

which must list **only** the four T-005 files. One command, no judgement call about what "only one
line differs" means. The forward-looking statement that the T-001 to T-004 records use the
pre-T-005 vocabulary goes in this plan (here) and in the release notes. `ba`: this is conformant
with AC-9's "if any", not a deviation — tell me if you disagree.

### R5. The repository is red between step 3 and step 4, and only there

Plainly, so nobody panics halfway: **after the move commit the gate fails, and that is expected.**
What specifically happens, measured rather than assumed:

- `composer install` / the autoloader still work (every relative path in the table in R1 survives).
- **The suite still starts.** I read Pest's `UsesCall::in()`: each target is passed through
  `glob()` and only existing matches are kept, so the 15 stale `services/host/tests/...` entries in
  `toolchainGroupFiles()` bind to nothing and throw nothing. Consequences: the `toolchain` group is
  empty, so `composer test:fast` silently becomes the full suite (slower, never less complete — the
  fail-safe direction T-004 measured), and `ToolchainGroupCoverageTest`'s `toolchainMissingPaths()`
  reports 15 missing paths.
- Every test that names `App\Support\ServiceHost\ServiceRegistry` fails: the class moved.

Steps 3 and 4 are therefore **one task** for the completion hook and **two commits** for history.
Nothing is pushed between them. Green at the end of steps 0, 1, 2, 4, 5, 6, 7.

### R6. How the move is performed, and how history is verified rather than assumed

Git stores no rename; `--follow` and `-M` infer it from content similarity at display time. The
only thing that matters is therefore that **the commit which changes paths changes no bytes**.
`git mv` is preferred (it keeps the index tidy and drags the ignored `vendor/`, `storage/` and
`.env` along with the directory, so no reinstall is needed) but a copy-plus-delete in the same
commit is detected identically — say so in the commit message if a Windows handle forces that
route, and do not panic.

Verification, in the commit message of step 3:

```
git show --stat -M --find-renames=100% <move-sha> | tail -3     # N renames, 0 other lines
git show --diff-filter=A --name-only <move-sha>                 # empty
git show --diff-filter=D --name-only <move-sha>                 # empty
git log --follow --oneline -- modules/host/app/Support/ModuleHost/ModuleRegistry.php
git log --follow --oneline -- modules/host/tests/Feature/ModuleMakeValidationTest.php
git log --follow --oneline -- modules/demo/composer.json
```

Each `--follow` must reach commits older than the move (`741307c` for `ModuleRegistry.php`).
**Positive control for the verification itself:** run the same `--follow` on
`modules/host/tests/Feature/HostTestAnalysisEnforcementTest.php`, created in step 2, and confirm it
shows exactly its own commits. A `--follow` that printed history for everything could otherwise
look like success.

### R7. Proving absence, with a control

A standing Pest test, QA-owned: `modules/host/tests/Feature/QaVocabularyRenameSweepTest.php`.
It enumerates files with Symfony Finder (**not** `git ls-files`: spawning a child would force the
file into the `toolchain` group, per T-004 D-2, and push the one cheap completeness check out of
`composer test:fast`), from the repository root, and matches **both the relative path and the
contents** of each file.

**The pattern list** — AC-4 made concrete. These are literals unless marked regex:

```
services/            SERVICES              svc:make          SvcMake
ServiceHost          ServiceRegistry       servicehost       UnknownServiceException
flexipos/service-    service-host          Service_          ServiceA
ServiceB             stubs/service         servicesPath      name="Services"
svc                  (case-insensitive)
regex  Flexipos.{1,2}Service        — catches both `Flexipos\Service\` and `Flexipos\\Service\\`
regex  ['"]services['"]             — the config key and the JSON field, not the English word
```

Three design points, each the product of a trap I hit while measuring:

- **`ServiceProvider` is deliberately absent** (D-44), and so is bare `Services`: `src/Services/`
  inside a generated module means *service classes* and stays (add a one-line comment in
  `ModuleMakeCommand::EMPTY_DIRECTORIES` saying so, or the next reader will "finish the job").
- **The tokens are assembled from fragments inside the sweep file**, never written whole, so the
  sweep does not flag itself — the same technique as `toolchainSpawnTokens()`. The positive control
  reassembles them, which is what proves the fragments are right.
- **Bare `svc` is included**, which forces one allowance: see below.

**The allowance list.** Literals subtracted from a file's contents before matching, each with a
one-line justification, and **each asserted to still be findable on disk** so a stale allowance
fails loudly instead of hiding a real hit (the `qaFixturePackageNames()` discipline):

| Allowed literal | Where | Why |
|---|---|---|
| `'{{connection}}' => 'svc_demo',` and `'{{schema}}' => 'pos_demo',` | `QaTestCodeAnalysisCoverageTest` | dead replacement keys, explicitly out of scope in the spec; the allowance keeps them visible and will fail when a later task deletes them |
| `ai/T-001-monorepo-service-host`, `ai/T-002-service-host-hardening` | `docs/backlog.md` lines 28–29 | branch names are facts about the repository and cannot be rewritten |
| `T-00[1-4]-*-service-host.md` style record filenames | wherever a swept file links to one | the record filenames are immutable for the same reason |

**The excluded paths**, each asserted to exist on disk: `Refference/**`;
`docs/{specs,plans,qa,reviews,decisions}/**` (the T-001 to T-004 records by D-52, and T-005's own
records, which necessarily quote the old names); `CLAUDE.md` (AC-25 — the owner's file is not
edited); `vendor/`, `node_modules/`, `storage/`, `bootstrap/cache/`, `.angular/`, `bin/`, `obj/`.
Everything else is swept, including `.claude/agent-memory/**` (D-53), both READMEs,
`docs/backlog.md`, `composer.json`, `composer.lock`, `.env.example`, `.gitignore`, `.gitattributes`
and every stub and test. Measured: `modules/host/config/services.php` has **0** hits under this
pattern, so it is swept rather than excluded — the trap AC-4 names is handled by the pattern, not by
an exemption.

**The controls**, all three in the same file:

1. **Positive, on the pattern:** a synthetic string containing every token, and a synthetic path
   `services/host/app/Support/ServiceHost/ServiceRegistry.php`, are both reported as hits. A broken
   regex fails here.
2. **Positive, on the corpus:** the enumeration is non-empty and contains known files
   (`modules/host/composer.json`, `README.md`, `.claude/agent-memory/developer/MEMORY.md`), and an
   excluded historical file (`docs/qa/T-003-tenant-connections.md`) is shown to contain at least
   three distinct tokens when the exclusion is lifted. A Finder that silently scanned nothing fails
   here. This is AC-6.
3. **Negative:** the live sweep over the real corpus returns an empty list. This is AC-5.

**Known limitation, stated rather than discovered later:** the sweep catches *identifiers*, not
English. `docs/deployment-vps.md`'s "all 13 services" is a prose use of the old word that no
pattern can safely catch, and `MODULES=all` on the same line is one it does. AC-26 (a human reading
the four documents) is what covers the prose; the sweep is not claimed to.

---

## Measured surface — the worklist

The spec inherited "57 tracked files: 26 under `services/`, 19 under `docs/`, 7 in agent memory, 5
at the root". **MEASURED, with the AC-4 pattern, excluding `Refference/`, the 20 records and
`CLAUDE.md`: 78 files, not 57.**

| Area | Files | Note |
|---|---|---|
| `services/**` | **58** | brief said 26 |
| `.claude/agent-memory/**` | **12** | brief said 7; 7 developer + 5 qa-engineer |
| root | **3** | `README.md` (34 hits), `.gitignore` (4), `.gitattributes` (2); `.editorconfig` has none. Brief said 5; with `CLAUDE.md` it is 4 |
| `docs/**` outside the records | **2** | `backlog.md` (5), `deployment-vps.md` (1). `architecture.md` and `database.md` are already clean — AC-26's expectation of no match is confirmed |
| `packages/**` | **2** | see below — the two nobody would look for |
| **total** | **78** | + `CLAUDE.md`, handed to the lead |

The brief's "19 under `docs/`" reconciles as 18 records + the backlog, so only the code and memory
counts were low. The 20-record figure in D-52 is confirmed: 16 files in the four named folders plus
4 in `docs/decisions`, of which exactly **18 actually carry the old vocabulary** (`docs/reviews/T-003`
and `T-004` carry none) — which is where the lead's "eighteen" came from.

**The two outside `services/` that a `services/`-scoped search would miss, both measured:**

- `packages/kernel/src/Tenancy/Exceptions/TenancyException.php:15` — a docblock naming
  `App\Support\ServiceHost\UnknownServiceException` as the convention it follows.
- `packages/kernel/tests/Feature/QaTenantIsolationProofTest.php:470` — a literal
  `$repository.DIRECTORY_SEPARATOR.'services'` path segment.

**Paths that change** (all in step 3, all `git mv`):

| From | To |
|---|---|
| `services/` | `modules/` |
| `modules/host/app/Support/ServiceHost/` | `.../app/Support/ModuleHost/` |
| `ServiceRegistry.php` · `UnknownServiceException.php` | `ModuleRegistry.php` · `UnknownModuleException.php` |
| `app/Providers/ServiceHostServiceProvider.php` | `ModuleHostServiceProvider.php` |
| `app/Console/Commands/SvcMakeCommand.php` | `ModuleMakeCommand.php` |
| `config/servicehost.php` | `config/modulehost.php` |
| `stubs/service/` | `stubs/module/` (stub file names unchanged, D-47) |
| `tests/Feature/SvcMakeValidationTest.php` | `ModuleMakeValidationTest.php` |
| `tests/Feature/SvcMakeAtomicGenerationTest.php` | `ModuleMakeAtomicGenerationTest.php` |
| `tests/Feature/SvcMakeDemoRegenerationTest.php` | `ModuleMakeDemoRegenerationTest.php` |
| `tests/Feature/SvcMakeGeneratedServiceReachableTest.php` | `ModuleMakeGeneratedModuleReachableTest.php` |
| `tests/Feature/ServiceRegistryManifestTrustTest.php` | `ModuleRegistryManifestTrustTest.php` |
| `tests/Feature/ServicesEnvParsingTest.php` | `ModulesEnvParsingTest.php` |
| `tests/Feature/QaServiceNameValidationTest.php` | `QaModuleNameValidationTest.php` |
| `tests/Feature/QaServiceRegistryContractTest.php` | `QaModuleRegistryContractTest.php` |
| `tests/Feature/QaGeneratedServiceTestDiscoveryTest.php` | `QaGeneratedModuleTestDiscoveryTest.php` |
| `tests/Feature/UnlistedServiceReturns404Test.php` | `UnlistedModuleReturns404Test.php` |
| `tests/Fixtures/DeptracViolation/ServiceA/`, `ServiceB/` | `ModuleA/`, `ModuleB/` |
| `.claude/agent-memory/developer/project_servicehost_boot_contract.md` | `project_modulehost_boot_contract.md` |
| `.claude/agent-memory/qa-engineer/project_service-host-fragile-areas.md` | `project_module-host-fragile-areas.md` — **qa's own file, moved in step 6 by qa** |

`QaModuleConnectionRemovalTest.php` already says Module; it is not renamed.

**Identifiers that must NOT change** — the survivors, each one a plausible mistake:
`Illuminate\Support\ServiceProvider` and every `*ServiceProvider` class name (D-44) ·
`modules/host/config/services.php` and its contents (the named trap) ·
`storage/framework/.gitignore`'s `services.json` (the framework's own cached manifest) ·
`src/Services/` in a generated module (service classes) · `flexipos/host` · `Package_*` layers ·
the reserved name `host` · `ServiceRegistry::NAME_PATTERN`'s value · route names (`demo.ping`),
prefixes (`api/v1/demo`) and the `extra.flexipos.prefix`/`provider` keys · the two branch names in
the backlog · the `{{connection}}`/`{{schema}}` dead keys (out of scope, allowance-listed).

---

## Files to change / create

Only paths that need a reason are listed; the mechanical identifier edits in the 78 files are
covered by the worklist above and by the sweep.

**Created**

- `modules/host/tests/Feature/HostTestAnalysisCoverageTest.php` — dev. FP-169's configuration half:
  both registries list the host's own `tests`. Spawns nothing, so it stays in `test:fast` and
  **must not** be added to `toolchainGroupFiles()` (T-004 D-2: tagging is per file).
- `modules/host/tests/Feature/HostTestAnalysisEnforcementTest.php` — dev. FP-169's behavioural half:
  the planted level-6 defect (AC-21) and the planted boundary violation (AC-22). Spawns, so it
  **must** be added to `toolchainGroupFiles()`; `ToolchainGroupCoverageTest` will demand it.
- `modules/host/tests/Feature/QaVocabularyRenameSweepTest.php` — qa. AC-5 and AC-6, per R7.

**Changed, with a reason beyond the rename**

- `modules/host/phpstan.neon.dist` — add `- tests` (step 1). Keep the comment honest: it says paths
  are listed literally because the resolvers reject wildcards.
- `modules/host/deptrac.yaml` — add `- tests`, add the `HostTests` layer and its ruleset entry
  (step 2); rename `Service_Demo` → `Module_Demo` and its collector (step 4).
- `modules/host/tests/Helpers.php` — the 15 literal paths in `toolchainGroupFiles()`, the directory
  list in `filesSpawningChildProcesses()` (`['services','packages']` → `['modules','packages']`),
  and two entries in `qaFixturePackageNames()`: `svcmakefixture` → `modulemakefixture`,
  `svc-make-fixture` → `module-make-fixture` (with the matching edit in
  `ModuleMakeValidationTest`). The docblock that lists which test owns which fixture name follows.
- `modules/host/tests/Pest.php` — `servicesPath()` → `modulesPath()`; ~85 call sites follow.
- `modules/host/config/modulehost.php` — key `'services'` → `'modules'`, `env('MODULES')`, prose.
  **The phrase "documented only" must still appear exactly 3 times** (T-002 AC-11, asserted by
  `QaCacheGuardBranchesTest`).
- `modules/host/composer.json` — `require-dev` package name, the `service-host` keyword, the
  description, and the `deptrac` script description.
- `modules/host/composer.lock` — regenerated, in step 4's commit (R2).
- `modules/host/app/Support/ModuleHost/ModuleRegistry.php` — class, `servicePath()` → `modulePath()`,
  `Flexipos\Module\` expected namespace, `extra.flexipos.module`, and every message string
  ("Unknown module … listed in MODULES").
- `modules/host/app/Http/Controllers/HealthController.php` — the `services` field → `modules`
  (D-50); `HealthEndpointTest` and `QaHealthProbeResilienceTest` change the expected key and keep
  their assertion count.
- `modules/host/tests/TestCase.php` — `$originalServices`/`$servicesOverridden` and the three
  `$_ENV`/`$_SERVER`/`putenv` writes move to `MODULES`. This class is why an ambient variable cannot
  beat `phpunit.xml`; the docblock explaining that must name `MODULES`.
- `README.md` and `modules/host/README.md` — **must be edited in step 4, not step 5**:
  `QaDocumentationAccuracyTest` asserts literal README strings (`services/`, `Service_Billing`,
  "no file inside `services/host/` needs to change for that", `services/qadocfixture/src`), and
  those assertions and the README have to move together or the gate is red.
- `docs/architecture.md` — §4 only, per R1.
- `docs/backlog.md` — **vocabulary only, never status** (AC-26). Exactly two rows: FP-169 and
  FP-202. Lines 28–29's branch names stay. The lead flips the status.
- `docs/deployment-vps.md` — line 53: `SERVICES=all` → `MODULES=all`, and "13 services" →
  "13 modules".
- `.gitignore` — three paths: `/services/host/public/hot`, `/services/host/public/storage`,
  `/services/host/plugins/*` (and its `!` negation).
- `.gitattributes` — the FP-156 comment names `services/host/` and `svc:make`.
- `.claude/agent-memory/developer/**` (7 files) — dev's own, step 5.
  `.claude/agent-memory/qa-engineer/**` (5 files) — qa's own, step 6.

**Not changed:** `CLAUDE.md` (AC-25, hand-off below) · `Refference/**` (AC-8) · the 20 records
(AC-9, R4) · `modules/host/config/services.php`.

---

## Data / API changes

- **Environment variable:** `SERVICES` → `MODULES`, read through `config('modulehost.modules')`.
  `.env.example` ships `MODULES=demo`. A `SERVICES` left in a developer's `.env` or CI runner is
  silently ignored (AC-11); the cached-configuration guard's message names `MODULES`.
- **API:** `GET /health/ready` → `{"status":"ok","modules":[…]}` (D-50). No other endpoint changes;
  `/api/v1/<module>/ping`'s `service` field in the generated sample controller becomes `module`.
- **Module manifest contract:** `extra.flexipos.service` → `extra.flexipos.module`; package name
  `flexipos/module-<name>`; PSR-4 root `Flexipos\Module\<Studly>\`. `provider` and `prefix` keys
  unchanged.
- **Boundary layers:** `Service_<Studly>` → `Module_<Studly>`; new `HostTests` layer.
- **No schema change, no migration, no stored data touched.**
- `composer.lock` regenerated (R2). No dependency added or removed.

---

## Implementation steps

Owner is `dev` unless stated. Each step ends with the stated gate state; nothing is pushed.

- [x] **Step 0 — Baseline (AC-1).** No file changes. In `modules/host`: run `composer check`,
      record exit code, passed count and assertion count. Also capture the collected set:
      `vendor/bin/pest --list-tests-xml=<scratchpad>/collected-baseline.xml`. **MEASURED by
      architect today: 304 `<testMethod>` entries across 46 `<testClass>` entries, exit 0, 27.7 s.**
      That matches the spec's 304/1358 expectation for tests. Keep both files outside the
      repository and give the figures to `qa`.
      *Note for the collected-set instrument, correcting my own T-004 §12 note:* `--list-tests-xml`
      cost **27.7 s** here, not the 0.5–0.6 s recorded there. Still cheap against an 82 s run, but
      not free — quote it as "a collection, roughly a third of a run" from now on. The XML nests
      `<testMethod>` inside `<testClass name="P\Tests\Feature\…">`; for Pest the `file` attribute
      points at `TestCaseFactory`'s eval'd code and must be ignored, and kernel-package classes
      appear as `P\D\Flexiata\NEWFLEXIPOS\packages\…`, so group by `testClass name`.
      *Gate: green.*

- [x] **Step 1 — FP-169 static analysis, on the old names (AC-21 part, AC-3).** Add `- tests` to
      `phpstan.neon.dist` paths with a comment. Fix the **99** measured findings by the measured
      forms in R3: `use function Pest\Laravel\get;` for the 27 `$this->get(...)` calls (the T-002
      convention); `/** @var TestCase $this */` as the first line inside each closure that calls
      `$this->bootHostWith(...)` for the 40; then **re-run and confirm the 30 consequential
      unresolvable-type errors are gone rather than assuming it**. Two singletons:
      `qaSuiteChildRun(array $arguments)` gets `@param list<string>`;
      `QaHostSuiteCollectionProofTest:82`'s `expect($replaced)->toBeString()->not->toBe($xml)`
      splits into two expectations **keeping both assertions** (do not cast and drop one).
      11 files: `QaServiceRegistryContractTest` (29), `ServicesEnvParsingTest` (22),
      `HealthEndpointTest` (12), `QaServiceNameValidationTest` (11),
      `QaHealthProbeResilienceTest` (10), `ServiceRegistryManifestTrustTest` (4),
      `UnlistedServiceReturns404Test` (3), `SvcMakeGeneratedServiceReachableTest` (2),
      `QaManifestTrustAttackTest` (2), `QaHostSuiteCollectionProofTest` (2),
      `QaAtomicGenerationProofTest` (2).
      No test added here, so the counts must still be exactly the baseline.
      *Gate: green, counts = baseline.* Commit `T-005: FP-169 analyse the host's own tests`.

- [x] **Step 2 — FP-169 boundary check and its two guard files (AC-21, AC-22, AC-23, AC-3).**
      `deptrac.yaml`: add `- tests` and the `HostTests` layer plus ruleset entry (R3). Create the
      two test files named above. **These are the only tests FP-169 adds, named here as AC-3
      requires — three cases:** (1) a planted untyped parameter in a host test file makes
      `composer stan` exit non-zero and name the file, and the clean tree exits 0; (2) a planted host
      test class importing a module internal makes `composer deptrac` exit non-zero naming
      `HostTests` and `Module_Demo` (`Service_Demo` at this step), and the clean tree reports
      `Violations 0`; (3) both registries list the host's own `tests` path. Every planted file is
      removed in a `finally` and in `afterEach` (FP-168 discipline: nothing that can throw runs
      before something that restores). Add the enforcement file to `toolchainGroupFiles()`, not the
      coverage file. Record the new assertion count delta.
      *Gate: green, counts = baseline + 3 tests + the declared assertion delta.*
      Commit `T-005: FP-169 boundary-check the host's own tests`.

- [x] **Step 3 — The move commit: paths only, zero content edits (AC-10, AC-19, AC-20).** Every
      `git mv` in the worklist table, nothing else. Verify with the six commands in R6 **before**
      moving on, including the positive control on the step-2 file. The commit message states: this
      is the move commit, no file content changed, and pastes the `--stat -M` tail.
      *Gate: **RED, by design** — see R5. Steps 3 and 4 are one task for the hook.*
      Commit `T-005: move services to modules (rename only, no content change)`.

- [x] **Step 4 — Every identifier, plus the lock (AC-11 to AC-18, AC-15, AC-24, AC-2).** In this
      order, because each depends on the one before:
      (a) classes and namespaces — `ModuleRegistry`, `UnknownModuleException` (`module()` accessor),
      `ModuleHostServiceProvider`, `ModuleMakeCommand`, `bootstrap/providers.php`;
      (b) configuration — `config/modulehost.php` key and prose (keep "documented only" at 3),
      `config('modulehost.modules')`;
      (c) the environment variable everywhere — `.env.example`, `phpunit.xml` (`<env name="MODULES">`
      and `<testsuite name="Modules">`), `TestCase.php`, `ConfigCacheGuardTest`, the guard message;
      (d) the generator — signature `module:make`, `stubs/module/*`, the printed reminder
      (`modules/<name>/src`, `modules/<name>/tests`, `Module_<Studly>`), the `src/Services` comment;
      (e) the demo module — manifest (name, `extra.flexipos.module`, PSR-4), provider, controller,
      routes, its own test;
      (f) `HealthController`'s `modules` field and the two tests that assert it;
      (g) the toolchain registries — `deptrac.yaml` layer and collector, the fixture
      `tests/Fixtures/DeptracViolation/deptrac.yaml` (`Module_A`/`Module_B`, paths `ModuleA`/`ModuleB`)
      and the two fixture classes' namespaces, `Helpers.php`'s three lists, `Pest.php`'s
      `modulesPath()` and its ~85 call sites;
      (h) every remaining test body and the two `packages/kernel` references measured above;
      (i) both READMEs **and** `QaDocumentationAccuracyTest`'s literal expectations, together;
      (j) **the lock**: `composer update --lock`, inspect the diff, `composer install`, and run the
      two `vendor/` checks — all per R2, all inside this commit.
      *Gate: green. Counts must equal step 2's exactly — this is AC-2: a changed expected value
      keeps its assertion, it does not add or drop one.*
      Commit `T-005: rename services to modules`.

- [x] **Step 5 — Documents, ignore rules and dev's own memory (AC-26, R1).**
      `docs/architecture.md` §4 (the literal text in R1), `docs/deployment-vps.md` line 53,
      `docs/backlog.md` rows FP-169 and FP-202 (vocabulary only), `.gitignore`'s three paths,
      `.gitattributes`'s comment, and the 7 `.claude/agent-memory/developer/**` files including the
      `MEMORY.md` index line pointing at the renamed boot-contract note.
      *Gate: green.* Commit `T-005: documents and ignore rules follow the rename`.

- [ ] **Step 6 — `qa`: the absence proof and qa's own memory (AC-5, AC-6).** Write
      `QaVocabularyRenameSweepTest.php` exactly as specified in R7: fragment-assembled tokens, the
      allowance list with its on-disk assertions, the exclusion list with its on-disk assertions,
      and all three controls. Then `git mv` qa's `project_service-host-fragile-areas.md` and update
      the 5 `.claude/agent-memory/qa-engineer/**` files, which the new sweep will report as the last
      remaining hits. Report the sweep's assertion delta.
      *Gate: green, sweep empty, control red when inverted.*

- [x] **Step 7 — Verification and the record (AC-1 to AC-3, AC-8, AC-9, AC-19, AC-20, AC-24, AC-25).**
      `composer check` from a clean tree; counts against step 0 (baseline + FP-169's named
      additions + the sweep's, and nothing else); a per-`testClass` collected-set table against
      `collected-baseline.xml` proving no pre-existing file lost a case (the T-004 §12 instrument);
      `git diff --name-only main -- Refference docs/specs docs/plans docs/qa docs/reviews
      docs/decisions` lists only T-005's four files; `git diff main -- CLAUDE.md` is empty; the
      `--follow` checks and their positive control; `ls vendor/flexipos` and the
      `autoload_psr4.php` grep; `php artisan module:make` on a throwaway name followed by the
      byte-for-byte regeneration of `modules/demo` **from a fresh `git checkout`** (T-004 AC-10
      discipline, AC-17); `composer test:fast` + `composer test:slow` counts summing to
      `composer test` (T-004 AC-3 to AC-5 under the new paths).
      *Gate: green. This is the task's evidence; it goes to `qa`.*

- [ ] **Step 8 — Hand-off, not dev's to apply.** `CLAUDE.md` stays byte-identical (AC-25). The
      lead applies, after the human's acknowledgement:
      - **line 15**, the whole "Vocabulary note" paragraph: **delete it** — it exists only to say the
        rename is pending.
      - **lines 39–47**, nine command rows: `cd services/host` → `cd modules/host`.
      - **line 53**, the Conventions bullet: "booted by the host from the `SERVICES` environment
        variable" → "`MODULES`".
      Nothing else in that file carries the old vocabulary (measured). The lead also flips FP-202
      and FP-169 to DONE and T-005's status in `docs/backlog.md`.

---

## Test strategy

Which tests prove which criteria — the point being that almost nothing new is needed, because
T-001 to T-004 already assert on the identifiers that change.

| Criteria | Proven by |
|---|---|
| AC-1, AC-2, AC-3 | `composer check` counts at steps 0, 2, 4 and 7, plus the per-`testClass` collected-set table from `--list-tests-xml` |
| AC-4, AC-5, AC-6 | `QaVocabularyRenameSweepTest` (new, qa) with its three controls |
| AC-7 | existing `ToolchainGroupCoverageTest`, `QaToolchainRegistryCoverageTest`, `toolchainMissingPaths()`; negative control = point one entry at a non-existent path in a scratch copy |
| AC-8, AC-9 | one `git diff --name-only main -- …` in the QA report (R4) |
| AC-10 | the sweep's path half plus `ls` |
| AC-11, AC-15 | `ModulesEnvParsingTest`, `ModuleRegistryManifestTrustTest`, `QaManifestTrustAttackTest`, `QaModuleRegistryContractTest`, `UnlistedModuleReturns404Test`, `ConfigCacheGuardTest`, `QaCacheGuardBranchesTest` — renamed, same cases |
| AC-12 | `HealthEndpointTest`, `QaHealthProbeResilienceTest` (expected key changes, count does not) |
| AC-13, AC-16, AC-18 | `ModuleMakeValidationTest`, `ModuleMakeAtomicGenerationTest`, `QaModuleNameValidationTest`, `QaAtomicGenerationProofTest`, `QaDocumentationAccuracyTest`, `QaGeneratedModuleTestDiscoveryTest`, `ModuleMakeGeneratedModuleReachableTest` |
| AC-14 | `composer install` exit 0 in step 4 + the sweep over `composer.lock` + the two `vendor/` checks |
| AC-17 | `ModuleMakeDemoRegenerationTest` and `QaRegenerationGuardTest`, run after a fresh `git checkout` of `modules/demo` |
| AC-19, AC-20 | the six commands in R6 plus the positive control, pasted into the commit message and the QA report |
| AC-21, AC-22, AC-23 | `HostTestAnalysisCoverageTest` + `HostTestAnalysisEnforcementTest` (new, dev), each with its planted defect and its clean-tree control |
| AC-24 | `HostTestSuiteCollectionTest`, `QaHostSuiteCollectionProofTest`, `QaTestSuiteIsolationTest` |
| AC-25 | `git diff main -- CLAUDE.md` empty; the line list in step 8 |
| AC-26 | `QaDocumentationAccuracyTest` for the two READMEs; human reading for `docs/**` (the sweep's stated limitation) |

- **Unit:** `ToolchainGroupDetectorTest`, `FixtureDirectoryTeardownTest` — vocabulary only.
- **Integration:** everything above; there is no e2e surface in this task.
- **Not added:** no new behavioural test. A rename that needed one would not be a rename.

---

## Risks & rollback

| # | Risk | Mitigation |
|---|---|---|
| 1 | **Silent partial rename** — the defining risk. A stale string nothing executes. | The sweep (R7) over paths *and* contents, with a positive control on both the pattern and the corpus; plus FP-169 first, which turns a stale reference in host test code into a `composer stan` failure even on an unexecuted line. |
| 2 | **`vendor/` masks a missed rename** — the old PSR-4 prefix still resolves after the move. | Measured and handled: the lock is regenerated and `composer install` re-run inside step 4, then `ls vendor/flexipos` and `grep -c 'Flexipos\\Service' vendor/composer/autoload_psr4.php` (expect 0). |
| 3 | **History recorded as delete+add.** | Move-only commit; verified with `--stat -M --find-renames=100%`, `--diff-filter=A`/`D` empty, and three `--follow` checks with a positive control. |
| 4 | **Panic at the red tree between steps 3 and 4.** | R5 says exactly what fails and why, and that the suite still *starts* (measured: Pest's `in()` globs and silently drops missing paths). One task, two commits. |
| 5 | **A third-party version sneaks in with the lock.** | `git diff -U0 composer.lock \| grep '"version"'` must be empty; otherwise revert and escalate to `architect`. |
| 6 | **`sed -i` collateral** on `config/services.php`, `services.json`, `src/Services/`, the two branch names. | Named in the worklist as survivors; the sweep's pattern is built not to demand them; `src/Services` gets an explanatory comment. |
| 7 | **FP-169's 99 fixes change behaviour.** | All three forms are annotation-or-helper only, measured clean, and asserted by unchanged counts at step 1. The one chain split keeps both assertions. |
| 8 | **The `toolchain` group silently empties** and `test:fast` becomes the full suite. | Fails in the safe direction (T-004: more tests, never fewer); `ToolchainGroupCoverageTest` and `toolchainMissingPaths()` both report it; step 7 re-proves fast + slow = full. |
| 9 | **Windows file handles** break `git mv` of a directory holding `vendor/` and caches. | Close editors and watchers; delete `storage/framework/cache/phpstan` and `.phpunit.cache` first (both ignored); if it still fails, copy-plus-delete in one commit is detected identically — say so in the commit message. |
| 10 | **The sweep becomes a nuisance** when a future memory note legitimately quotes history. | The allowance list is designed for exactly that: one justified literal, asserted to still exist, rather than weakening the pattern. |

**Rollback.** Every step is one commit on a branch that is never pushed. `git reset --hard <sha>`
returns to any boundary; after step 3 or 4 a reset must be followed by `composer install` in the
host directory, because `vendor/` is not in git. No data, no migration, no external system is
touched, so there is nothing else to undo.

---

## Questions for the human

None. Two items for the **lead**, neither an escalation trigger:

1. `CLAUDE.md` is left byte-identical per AC-25; step 8 is the exact line list to apply (line 15
   deleted, lines 39–47 and 53 edited). Until it is applied, every command row in the project's own
   entry-point file points at a directory that no longer exists — so this should be applied in the
   same session the branch merges, not later.
2. `docs/backlog.md`: dev changes the vocabulary of rows FP-169 and FP-202 only; the status flips
   and T-005's row are the lead's.

For `ba`: R4 reads AC-9's "one short dated note, if any" as satisfied by zero notes, and this plan
carries the statement instead. Say so if you disagree.
