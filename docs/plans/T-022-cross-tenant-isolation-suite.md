# Plan: T-022 Cross-tenant isolation suite

Spec: `docs/specs/T-022-cross-tenant-isolation-suite.md` (AC-1 to AC-25). Decisions: `docs/decisions/T-022-cross-tenant-isolation-suite.md` (D-1 to D-7). Branch `ai/T-022-cross-tenant-isolation-suite`. This is a QA-stream task, so it ships **test code only** (D-6). The one exception is a single sentence in `docs/architecture.md` §21 (AC-25).

## Approach summary

Four permanent `Qa*CrossTenantSweepTest.php` files go under `modules/host/tests/Feature/`, one per category. A fifth, engine-gated file goes under `modules/host/tests/Engine/` (plan note N-3). Each file **enumerates its surfaces at run time** from the real registry or source tree: `Route::getRoutes()`, reflection over every class in `packages/*/src`, `modules/*/src` and `modules/host/app`, a regex scan for cache-key sites, and `HookPointCatalogue::all()`. Each file then runs one generic attempt function over every enumerated member. The function **returns a list of findings** instead of asserting inline. The positive case asserts `findings === []` and prints a per-member table. Each negative control feeds the *same* function a planted leaking surface and asserts that it reports a finding that names that surface.

The enumeration is generated. Building an attack is **fail-closed** wherever a surface needs inputs that cannot be derived generically. Three such inputs exist: a valid request body for a write route, a seeder for a new path-parameter name, and a driver for a new queued job class. In each case a missing entry in the file's own table fails the sweep and names the surface. The sweep never skips the surface. A route that does not carry a tenant token has to be named in the AC-6 exclusion table with a reason, otherwise the sweep fails. New surfaces therefore default to "swept" rather than "silently uncovered".

Fixtures: `AccessTenantFixture` (two real SQLite tenant databases, reached through `Tenancy`) backs the route, cache and hook sweeps, per D-3. The deterministic job case uses the kernel's own two-SQLite stand-in, `packages/kernel/tests/Support/migrate-tenant-fixture.php` (plan note N-4). `ThreeTenantFixture` is used only in the engine file. No new dependency, no production change, and no change to `phpunit.xml`, `Pest.php`, `composer.json` or the toolchain registry. None of the new files spawns a child process, so all of them run in `composer test:fast` and in the gate (`composer test`), and none joins the `toolchain` group.

## Alternatives rejected

- **One Pest dataset case per route, hook or job.** Pest resolves datasets at collection time, before Laravel boots, so `Route::getRoutes()` and the hook catalogue do not exist yet. Instead, one `it` per attack shape produces a findings table with one row per member (plan note N-8).
- **An AST parser (`nikic/php-parser`) for the cache-key scan.** It is only a transitive dependency (through psysh). Every existing `Qa*SourceRulesTest` uses regex plus planted controls, and this sweep does the same.
- **Runtime key capture as the only cache check.** It still needs a hand-written driver per site to call it, so it cannot discover new sites. It is kept as the runtime half (AC-15, AC-16), and the source scan stays the generator.
- **Hand-written body recipes keyed on nothing, or skipping write routes that have no recipe.** Skipping is the silent-shrink failure that AC-2 forbids, so a missing recipe fails the sweep instead.
- **Adding the engine case to the `toolchain` group.** `ToolchainGroupCoverageTest` requires that registry to equal the set of files that really spawn a child process (N-3).

## Files to change / create

### New (5)

| File | Category | ACs |
|---|---|---|
| `modules/host/tests/Feature/QaRouteCrossTenantSweepTest.php` | A, routes | AC-1 to AC-7, AC-24 |
| `modules/host/tests/Feature/QaJobCrossTenantSweepTest.php` | B, queued jobs (deterministic) | AC-8, AC-9 (deterministic half), AC-10, AC-11, AC-24 |
| `modules/host/tests/Engine/QaJobCrossTenantSweepEngineTest.php` | B, [ENGINE] half | AC-9 [ENGINE] |
| `modules/host/tests/Feature/QaCacheKeyCrossTenantSweepTest.php` | C, cache keys | AC-12 to AC-16, AC-24 |
| `modules/host/tests/Feature/QaPluginHookCrossTenantSweepTest.php` | D, plugin hooks | AC-17 to AC-21, AC-24 |

Each file uses `namespace Tests\Feature;` (as `QaEventbusSourceRulesTest` does), so its helpers are namespaced. Each file also uses one unique prefix (`qaRouteSweep*`, `qaJobSweep*`, `qaCacheSweep*`, `qaHookSweep*`, `qaJobSweepEngine*`). No file requires or calls another `Qa*` file (FP-164). Shared **support** files under `tests/Support` and `packages/*/tests/Support` may be `require_once`d, as dozens of existing files already do.

### Pre-existing files `dev` will modify

| File | Change | Why |
|---|---|---|
| `docs/architecture.md` §21 | One sentence after "A generated cross-tenant suite that must pass to merge." It names the four sweep files and the engine file. It says the suite is permanent and gate-enforced, and that new routes, jobs, cache sites and hook points are covered without a new test file unless they are a new *kind* of surface. Write it as a description, and do not quote any old vocabulary (memory: gate-sweeps-the-working-tree). | AC-25 |

Nothing else pre-existing is modified. Specifically: **no** production file (D-6), **no** `phpunit.xml`, `tests/Pest.php`, `tests/Helpers.php` (`toolchainGroupFiles()`), `composer.json`, `deptrac.yaml` or `phpstan.neon`. The new files sit in directories that are already registered with both analysers and both test suites. `docs/backlog.md` belongs to the lead. If any step seems to need one of these files changed, stop and report it, because it is a plan deviation.

### Pre-existing files the new tests `require_once` or import (read-only)

- `modules/host/tests/Support/organization-http-fixture.php`: `orgHttpBoot`, `orgHttpToken`-style minting, `orgHttpActivated` and `orgHttpCall` patterns. It also pulls in `packages/auth/tests/Support/auth-fixture.php` and `packages/kernel/tests/Support/tenancy-fixture.php`.
- `packages/access/tests/Support/AccessTenantFixture.php` and `AccessSeed.php`.
- `packages/kernel/tests/Support/migrate-tenant-fixture.php` and `migration-run-fixture.php`: `FakeTenantMigrationApplier`, `migrateFixture*` and `runFixtureSet`. A host precedent exists: `BranchForeignKeysContractRunTest`.
- `packages/kernel/tests/Support/counting-mapping-source.php` (AC-15 liveness, if it fits; otherwise the file uses its own counting inner).
- `packages/plugin-sdk/tests/Support/plugin-fixture.php`, `PluginSeed.php`, `FixtureHookPointProvider.php`, `FakeHookClock.php`, and `Fixtures/FixtureEcho/plugin.json` (template manifest).
- `Flexipos\Testing\Engine\ThreeTenantFixture`, `EngineConnection` and `EngineOnHold` (engine file only).
- Production classes under test, all read-only: `CachingTenantDatabaseRepository`, `ApiActor`, `ThrottleApi`, `ActivationThrottle`, `CachingEntitlementSource`, `MigrateTenantQueueJob`, `MigrateTenantHandler`, `HookPointCatalogue`, `HookInvoker`, `Gateway`, `PluginRuntime`, `CircuitBreaker`, `ExecutionLog`, `RequireTenantToken`, `RequireTerminalToken` and `RequirePlatformToken`.

## Data / API changes

None. There are no schema, endpoint, config or migration changes. Test-local config overrides go through `config()->set()` inside a test: `plugins.breaker.threshold`, `api.limits.*` plus `app()->forgetInstance(ApiLimits::class)`, and `tenancy.databases`.

## Measured inventory (architect, 2026-11-06, this worktree)

These are the numbers step 0 must re-measure. Where they disagree with the spec, the plan notes say so.

- **Routes** (`artisan route:list --path=api/v1`): 50 routes. 41 carry `RequireTenantToken` and 1 carries `RequireTerminalToken` (`GET api/v1/terminal/settings`), so 42 tenant-token-gated routes, as the spec says. 1 carries `RequirePlatformToken` (`POST api/v1/auth/tenant`). 7 carry no token middleware: `auth.login`, `auth.logout`, `auth.refresh`, `terminal.activate`, `terminal.refresh`, `demo.ping`, `api.openapi`. **No platform-audit route exists.** The path-parameter names in use are `{employee}`, `{role}`, `{branch}`, `{location}`, `{terminal}` and `{permissionKey}`. `RequireIdempotencyKey` is not mounted on any route today.
- **Queued jobs**: `MigrateTenantQueueJob` is the only `ShouldQueue` implementor, as the spec says. Its `handle()` does not read `$this->tenantId`. The tenant comes from the master `tenant_migration_jobs` row found by `jobId` and `workerId`, and is then entered through `Tenancy::run()` (N-5).
- **Cache-key sites**: **four, not two.** They are `packages/kernel/src/Tenancy/CachingTenantDatabaseRepository.php` (`KEY_PREFIX.$tenantId`), `packages/platform/src/Support/CachingEntitlementSource.php` (`KEY_PREFIX.$tenantId`), `packages/auth/src/Support/ActivationThrottle.php` (`tenantKey()`, `addressKey()`, and a lock key that wraps either one), and `modules/host/app/Http/Middleware/ThrottleApi.php` (`RateLimiter`, whose keys are built by `App\Http\Requests\ApiActor`). Two service providers, `TenancyServiceProvider` and `PlatformServiceProvider`, import cache types only to wire decorators and make no key call. There is one in-process memo, `PluginSdk\Runtime\ManifestCache`, which is an array keyed by `plugin_version_id`. That is a global master id, so the memo is tenant-agnostic by design, and the scan's patterns cannot see it (stated limit) (N-1).
- **Hook points**: in the shipped application `HookPointCatalogue::all()` returns **zero** points, because no production provider implements `DeclaresHookPoints`. With the test-only `FixtureHookPointProvider` registered (as all T-019 gateway tests do), there are three: `fixture.echo`, `fixture.plain` and `fixture.narrow` (N-6).
- Baseline spot run: `ApiRateLimitTest`, `QaOrganizationTenantIsolationTest` and `GatewaySyncModesTest` gave 29 passed in 47.4 s, with no `APP_KEY` wall.

## Implementation steps

- [ ] **Step 0: Baseline.** From `modules/host`, run `composer check` and record the result and the `FLEXIPOS-GATE-MARGIN` line. If `APP_KEY` blocks it, run `composer test` and record that. Re-measure the inventory above: the route list by token class, the count of `ShouldQueue` classes, the files matching the cache scan, and the hook points with and without the fixture provider. Any difference from the inventory goes in the dev notes before step 1. No code.

- [ ] **Step 1: Job sweep, enumeration and payload rules** (AC-8, AC-10, AC-11, AC-24). File `QaJobCrossTenantSweepTest.php`.
  - `qaJobSweepDirectories()`: `modules/host/app` plus every `packages/*/src` and `modules/*/src`. The first `it` asserts that each one `is_dir()` and that the list is non-empty (AC-24).
  - `qaJobSweepQueuedClasses(array $dirs)`: for every `*.php` file, regex the `namespace` and the `class X` declaration, build the FQCN, and call `class_exists()` through the autoloader. Keep classes where `is_subclass_of($fqcn, ShouldQueue::class)`. Reflection catches inheritance from a base job and interfaces that extend `ShouldQueue` (for example `ShouldBroadcast`), which a text scan would miss. A declared class that fails to autoload is a finding, not a silent skip. AC-8 asserts the sorted result equals `[MigrateTenantQueueJob::class]` and prints it.
  - AC-11, generic: every found class needs a constructor parameter named `tenantId` (or ending `TenantId`) that is also a public or promoted property, so it is serialised. The alternative is an entry in `QA_JOB_SWEEP_TENANT_AGNOSTIC` (an empty array plus a docblock that requires a one-line reason per entry). Allow-list liveness: every entry must name an existing queued class.
  - AC-10, generic: no property, including inherited and trait properties, may match `/password|passwd|secret|credential|username|dsn/i`. Then build each class from generic constructor arguments (a string parameter gets `'qa-'.$name`, an int gets 1, an array gets `[]`, anything else fails and names the class), `serialize()` it, and require that the payload contains the tenant-id value and none of `env://`, `password`, `credential` or `secret` (case-insensitive).
  - Negative controls, each feeding the same functions: (a) a temp directory outside the repo (`sys_get_temp_dir()`) holding a `Planted.php` with a randomly named class that implements `ShouldQueue` and has a constructor `(string $runId)` and no tenant. `require_once` it and assert that the scan finds it and AC-11 reports it. (b) A planted class carrying a `$password` property, which AC-10 must report. (c) A positive plant with `tenantId` and no credential, which produces no finding. Delete the temp directory in `finally`. The planted source must contain **no process-spawning token**, so the toolchain detector stays quiet.

- [ ] **Step 2: Job sweep, ambient-worker attack, deterministic** (AC-9, deterministic half). Same file.
  - Fixture: `migrateFixtureBoot()`, `migrateFixtureServer()`, `migrateFixtureRun()`, plus two tenants, `migrateFixtureTenant('tenant-a', …)` and `('tenant-b', …)`, and a job claimed for A by `worker-one`.
  - `qaJobSweepAmbientDrivers()`: `class => Closure(string $ambientTenant): void`. It has one entry, `MigrateTenantQueueJob`, which pushes `MigrateTenantQueueJob::forJob(…tenant A…)` through `dispatch()` on the suite's `sync` connection, so the payload really goes through serialise, unserialise and `handle()`. **Fail-closed:** every class from step 1 must have a driver, or the sweep fails and names the class.
  - `qaJobSweepAmbientAttack(Closure $driver): list<string>`: the attempt, run twice. Each run reads `migrateFixtureApplier()->calls`, the database name of each call, and both mapping rows.
    - Scenario R, the recycled worker: `tenancy()->enter(B)`, then `app()->forgetScopedInstances()` with no `leave()`, which is what a Laravel worker does between jobs and leaves B's stanza stale in config. Then dispatch for A. A finding is any applier call against B's database, or B's `schema_version` changing. Liveness, which must hold: at least one applier call against A's database, A's mapping version moving, and the job row `done`.
    - Scenario L, the leaked scope: `tenancy()->enter(B)` with no reset, then dispatch for A. Expected: the handler's `Tenancy::run(A)` is refused (`TenantAlreadyResolved`), the job row is `failed`, and the applier recorded **nothing** for either database. A finding is any applier call against B, or the job row reading `done` (that would be a silent success against the wrong tenant).
  - Negative control: a test-file class `QaJobSweepAmbientTrustingJob` (implements `ShouldQueue`, so the step-1 scan ignores it because it lives under `tests/`). Its `handle()` calls `app(TenantMigrationApplier::class)->apply(config('tenancy.connection'), …)` without entering its own tenant. `qaJobSweepAmbientAttack()` run with that job in scenario R must report "effect landed in tenant-b".

- [ ] **Step 3: Job sweep, engine half** (AC-9 [ENGINE]). File `modules/host/tests/Engine/QaJobCrossTenantSweepEngineTest.php`. The `engine` group binds it by directory, and it runs in `test:fast` and the gate.
  - First case, the inverted one, following the `MigrateTenantEngineTest` precedent: while the hold is on, `ThreeTenantFixture::build()` throws `EngineOnHold`. Skip it when `! EngineConnection::onHold()`.
  - Second case: build the three tenants and map them into the master. Plan and claim one job for tenant 1. Enter tenant 2, then `forgetScopedInstances()` (the stale stanza). Dispatch the job and assert that FX-1's table exists only in tenant 1's database and that tenants 2 and 3 are untouched. Skip it while `EngineConnection::onHold()`, with the reason `ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold`. It is recorded **NOT VERIFIED** while G-1 holds.
  - Databases are created and dropped only through `ThreeTenantFixture` / `EngineDatabase`, by name, in a `finally`. There is no sweep of any kind, and `DestructiveStatementContainmentTest` must stay green.

- [ ] **Step 4: Cache-key sweep, source scan** (AC-12, AC-13, AC-14, AC-24). File `QaCacheKeyCrossTenantSweepTest.php`.
  - `qaCacheSweepDirectories()`: the same set as step 1. `is_dir()` is asserted for each.
  - **Site detection**, `qaCacheSweepSites(array $files)`. A site is a file that makes at least one **key-taking call** on a cache receiver. Key-taking methods: `get`, `put`, `add`, `forever`, `forget`, `has`, `missing`, `pull`, `remember`, `rememberForever`, `increment`, `decrement`, `lock`, `hit`, `attempt`, `tooManyAttempts`, `attempts`, `availableIn`, `remaining`, `resetAttempts`, `clear`, `many`, `putMany`. Cache receivers:
    - `$this->x` or `$x`, where `x` is declared in the same file with a type that resolves, through that file's own `use … [as …]` imports, to `Illuminate\Contracts\Cache\Repository`, `Illuminate\Cache\Repository`, `Illuminate\Cache\RateLimiter`, `Illuminate\Contracts\Cache\Store` or `Illuminate\Contracts\Cache\LockProvider`;
    - a variable assigned from `->getStore()`;
    - `Cache::`, `RateLimiter::` (facades), or `cache()->`/`cache(` (helper).

    Providers that only *wire* a cache into a decorator make no key call and are therefore not sites. AC-12 asserts the sorted repo-relative list equals the four files in the inventory (N-1) and prints it.
  - **Key resolution**, `qaCacheSweepKeyFindings(string $path, string $contents)`. Take the first argument of every key-taking call with a balanced-parenthesis reader, then classify it:
    1. A string literal, an interpolated string or a `.` concatenation containing a **token variable** (a variable name matching `/tenant|terminal|^ip$|address/i`, including through `{$x}` and `hash(…, $ip …)`): OK.
    2. `$this->m(…)`, `self::m(…)` or `static::m(…)`: look up `m`'s `return …;` in the same file and apply rule 1 to it (this covers `keyFor()`).
    3. An expression with **no token variable but some other variable or property fetch** (such as `$key`, `$bucket` or `'terminal-activation:lock:'.$key`): *delegated*. The site must appear in `qaCacheSweepBuilders()`, which maps a site to `[builderFile, kind: 'methods'|'named-arguments', names]`. Today it has two entries: `ActivationThrottle.php` → itself, methods `tenantKey` and `addressKey`; and `ThrottleApi.php` → `ApiActor.php`, named arguments `tenantBucket`, `userBucket`, `terminalBucket` and `ipBucket`. Every builder expression (each method's `return`, or each `name: EXPR` named argument) must pass rule 1. A delegated site with no builder entry is a finding that names file and line.
    4. A literal with no variable at all, or anything the reader cannot classify: a finding (fail-closed).
  - The allow-list `QA_CACHE_SWEEP_TENANT_AGNOSTIC` holds `[repo-relative file, exact expression text, reason]`. It has **one entry on day one**: `ApiActor.php`, `"u:platform:{$platformUserId}"`, because a platform token carries no tenant by construction and the bucket is per master operator (N-2). Liveness rule: every allow-list entry and every builder entry must still match text in its file, so a stale entry fails.
  - AC-13 asserts `findings === []` over all sites and prints each site with its resolved key expressions.
  - AC-14 negative controls, as planted files in a fresh `sys_get_temp_dir()` directory, deleted in `finally`. Each one runs through the same two functions:
    - (a) a typed `CacheRepository` with `->put('global:thing', 1)`: a finding;
    - (b) `->put('tenant:'.$tenantId, 1)`: detected as a site, **no** finding (proves the resolver does not fail everything);
    - (c) `->get($key)` with no builder entry: a delegated finding;
    - (d) `Cache::put('x:'.$userId, 1)` through the facade: a finding;
    - (e) a `keyFor()` returning `'k:'.$id`: a finding through rule 2;
    - (f) a planted builder declaration with `fooBucket: "f:{$userId}"`: a finding.

    After the plants are deleted, the real scan is green again and still has 4 sites.

- [ ] **Step 5: Cache-key sweep, runtime halves** (AC-15, AC-16). Same file.
  - AC-15: `qaCacheSweepMappingProbe(Closure $factory): list<string>`, where the factory is `fn (TenantDatabaseRepository $inner, Repository $cache): TenantDatabaseRepository`. Use `AccessTenantFixture::boot()` and bind `TenantDatabaseRepository` to `$factory(new InMemoryTenantDatabaseRepository(config mapping), new Repository(new ArrayStore))`, wrapped in a counting inner. Then:
    1. `AccessTenantFixture::switchTo('tenant-a')` and read `marker`: it must be `tenant-a`.
    2. `switchTo('tenant-b')`: the marker must be `tenant-b`.
    3. `switchTo('tenant-a')` again: the marker must be `tenant-a`, and the inner must not be read again (served from cache).
    4. The store holds exactly `tenant:conn:tenant-a` and `tenant:conn:tenant-b`, each entry carrying its own `tenant_id`.
    5. `evict('tenant-a')` leaves B's entry present and unchanged, and the next `find('tenant-b')` is still a cache hit.

    Positive run: the real `CachingTenantDatabaseRepository`, with no findings. Negative control: a test-file double `QaCacheSweepConstantKeyRepository` that stores under one constant key. The probe must report "tenant-b entered tenant-a's database" (or the equivalent marker mismatch).
  - AC-16, generated over `ApiActor`: reflect the public `?string` properties of `ApiActor` that end in `Bucket`. Today these are `tenantBucket`, `userBucket`, `terminalBucket` and `ipBucket`. `qaCacheSweepBucketDrivers()` has one entry per property and is **fail-closed**: a new bucket property with no driver fails and names it. Each driver builds two actors, uses `apiRateLimitSet`-style overrides to set that bucket's limit to 1 and every other limit to 100000, exhausts actor A on a **shipped** route, and asserts that A's next request is 429 while B's is 200:
    - tenant: two `orgHttpWorld()` tenants on `GET api/v1/organization/branches`;
    - user: two owners in two tenants;
    - terminal: two terminals activated with `orgHttpActivated()`, on `GET api/v1/terminal/settings`;
    - ip: `api/v1/demo/ping`, from two `REMOTE_ADDR` values.

    `qaCacheSweepBucketAttack()` returns findings. Negative control: bind `RateLimiter::class` to a test-file subclass `QaCacheSweepCollapsingLimiter` that maps every key to one constant (Laravel's `RateLimiter` is not final). The tenant driver must then report "B throttled by A's bucket".

- [ ] **Step 6: Plugin-hook sweep** (AC-17 to AC-21, AC-24). File `QaPluginHookCrossTenantSweepTest.php`.
  - Set-up follows `GatewaySyncModesTest`: `PluginSeed::migrate()`, `AccessTenantFixture::boot()`, `app()->register(new FixtureHookPointProvider(app()), true)`, `FakeHookClock`, `pluginRegisterFixtureTenant('tenant-a')` and `('tenant-b')`.
  - AC-17: `$points = app(HookPointCatalogue::class)->all()`. Assert `count >= 1`, print the names, and also print the count *without* the fixture provider (0 today, N-6).
  - **Probe plugin generated from the catalogue.** `qaHookSweepProbeManifest(array $points, string $handlerClass)` copies `FixtureEcho/plugin.json` and gives it the name `qa-xtenant-probe` and an empty `migrations/`. For **every enumerated point × every mode the point accepts** (override only where `isOverridable()`), it adds a hook whose handler is `$handlerClass`. It is written to a temp directory and registered through `RegisterPluginVersionHandler`. A hook point added later by a module is therefore swept automatically.
  - Probe handler `QaHookSweepProbe`, declared in the test file, implements `Before`, `Filter`, `Override`, `Add` and `After`. Each call:
    1. records `[point, mode, TenantContext::tenantId(), marker value read through config('tenancy.connection')]` in a static log;
    2. **writes** `marker` row `probe:<observed tenant>:<point>:<mode>` through that same connection;
    3. throws when the observed tenant is listed in a static `$failFor` (the configurable failure mode).

    It returns `null`, the payload, or a value, depending on the mode.
  - `qaHookSweepDispatchAll(array $points)` calls `app(HookGateway::class)->dispatch($point, ['qa' => 1], fn () => 'action')` for every point.
  - `qaHookSweepSnapshot(string $tenant)` captures that tenant's `marker` rows, `plugin_execution_logs` rows for the probe plugin, and `outbox_events` rows of type `plugin.*`, plus the master `tenant_plugins` row (or its absence) for `(tenant, probe plugin)`.
  - `qaHookSweepAttack(string $handlerClass): list<string>` is the AC-19 attempt.
    - Enable the probe for both tenants with `EnablePluginHandler`, enter A and dispatch every point.
    - **Findings:** a log entry whose observed tenant or marker is not `tenant-a`; any change in B's snapshot; no `probe:tenant-a:*` rows in A; no new A `plugin_execution_logs` rows.
    - Run the reverse direction the same way.
  - AC-18: enable the probe for A only, then enter B and dispatch all points. The static log must be empty, B's snapshot unchanged, A's snapshot unchanged, and no `(B, probe)` `tenant_plugins` row created. Breaker state lives on the master `tenant_plugins` row, keyed by `(tenant_id, plugin_id)`, so the claim is "no row for B" (N-6).
  - AC-20: enable for both and set `plugins.breaker.threshold` to 2.
    - Fail A (`$failFor = ['tenant-a']`) and dispatch in A until A's row is `status = error`. Liveness: the next A dispatch does not call the handler.
    - Then enter B and dispatch. The handler is called, B's row stays `enabled` with `failure_count = 0`, and B gets `probe:tenant-b:*` rows.
  - AC-21: a planted handler `QaHookSweepLeakyProbe`. On its first call it captures the tenant connection's PDO and the observed tenant in a static. On later calls it reports that captured tenant and writes through that captured PDO, ignoring the connection it is now handed. `qaHookSweepAttack(QaHookSweepLeakyProbe::class)` with direction A then B must report a finding that names the point, the mode and "wrote into tenant-a while dispatching for tenant-b". In the positive run, `qaHookSweepAttack(QaHookSweepProbe::class)` returns `[]`.

- [ ] **Step 7: Route sweep, enumeration and classification** (AC-1, AC-2, AC-6, AC-24). File `QaRouteCrossTenantSweepTest.php`.
  - `qaRouteSweepRoutes()`: every route in `Route::getRoutes()->getRoutes()` whose URI starts with `api/v1/`. Assert the list is non-empty (AC-24). Call `app(Kernel::class)` before reading middleware (the `ApiRateLimitTest` precedent). Classify each route by `gatherMiddleware()`: `tenant` (`RequireTenantToken`), `terminal` (`RequireTerminalToken`), `platform` (`RequirePlatformToken`) or `none` (N-7). This is never a list of names.
  - AC-2: `QA_ROUTE_SWEEP_FLOOR` lists the 42 tenant-token route **names** measured at step 0. Every name must be found **and** classified `tenant` or `terminal`. A missing or reclassified name fails, and the message names it. The floor is a regression floor. The sweep's input is still the registry, so a new route needs no floor edit.
  - AC-6: `QA_ROUTE_SWEEP_EXCLUDED` is `name => reason` for the 8 routes that are not tenant-gated: `auth.login`, `auth.logout`, `auth.refresh`, `auth.tenant` (platform token, master-level selection), `terminal.activate`, `terminal.refresh`, `demo.ping` and `api.openapi`. It is **fail-closed both ways**. Every `platform` or `none` route must be in the table, so a new tenant-data route that forgets its guard fails here and is never silently excluded. Every table entry must be a live route. The test prints the excluded routes and their reasons.

- [ ] **Step 8: Route sweep, two-tenant world and read attempts** (AC-3, AC-4, AC-7 read half). Same file.
  - `qaRouteSweepWorld()`: the master is migrated through the auth fixture, with two users, two master tenants and memberships. `tenancy.databases` maps `[A_id => mapping['tenant-a'], B_id => mapping['tenant-b'], 'tenant-a' => …, 'tenant-b' => …]` at `topVersion()`. Then forget the repository instances and scoped instances, and call `$route->flushController()` on every route (the `qaT10gWorld` precedent). Without that flush, controllers hold stale handlers after a reseed.
  - `qaRouteSweepSeed(string $fixtureTenant, string $marker)`: inside each fixture tenant it runs `SyncPermissionRegistry`, then creates:
    - an owner employee;
    - a second employee whose `display_name` carries the marker;
    - two custom roles named with the marker, one assigned to that employee and one unassigned;
    - a branch with its store and a warehouse location, named with the marker;
    - a terminal named with the marker;
    - settings carrying the marker at company, branch and terminal scope;
    - an employee permission override and a branch attachment, so that clear, remove and detach routes have something to act on.

    It returns `ids` (`employee`, `role`, `roleUnassigned`, `branch`, `location`, `terminal` and `permissionKey`, where `permissionKey` is a registry key that is the same in both tenants and is excluded from leak tokens) and `tokens` (every seeded id except `permissionKey`, plus the marker). It mints the owner's tenant token through `IssueTokenPairHandler`, with claims read from that tenant. For the terminal route, it activates the seeded terminal through the public activation route (the `orgHttpActivated` pattern) and keeps the terminal access token.
  - `qaRouteSweepParameterSeeders()`: `{param} => id key`. **Fail-closed:** a path parameter with no seeder is a finding that names the route.
  - Actor per class: a `tenant` route uses the owner token, and a `terminal` route uses the terminal token. A class with no actor factory is a finding.
  - `qaRouteSweepLeaks(TestResponse $r, array $victimTokens, array $requestCarried)`: every victim token found in the raw body or the headers, **except** ids the request itself carried in its URI or body (an error message may echo the requested id).
  - **By-id read (AC-3)**: a GET route with path parameters.
    - Own control: A's token and A's ids must return 2xx, and A's response must contain at least one A token.
    - Cross: B's token with A's ids must return **404 or 422** (a 2xx is a leak; a 401, 403 or 5xx is a finding, "not a business refusal", which keeps the attempt from being vacuous), and the response leaks nothing.
    - Reverse: the same with A's token and B's ids.
  - **Collection read (AC-4)**: a GET route with no parameters. B's response is 2xx and contains no A token, and the reverse holds too. Liveness: if A's own response contains no A token, the route goes on a printed "carries no tenant-distinguishing data" list. That list must equal `QA_ROUTE_SWEEP_NO_TENANT_DATA` (route name => reason; expected `access.me.permissions`, measured at step 8). This is fail-closed, so a list route whose seed stops showing up cannot quietly turn vacuous.
  - GET routes share one seed (they do not mutate).
  - AC-7, read half: a test-only case registers `GET api/v1/qa-route-sweep/planted/{branch}` and `GET api/v1/qa-route-sweep/planted-list` behind `['api', RequireTenantToken::class]`. Both are invokable test-file controller classes, not closures, following the `TerminalProbeController` precedent. Each returns a value held in a static, which the case sets to A's marker. The case then calls `refreshNameLookups()` and runs the **same** attempt function on those two routes only. It must report both routes as leaking. The planted routes exist only inside that `it`, because each test boots a fresh application, so the positive run never sees them.

- [ ] **Step 9: Route sweep, write attempts** (AC-5, AC-7 write half). Same file.
  - `qaRouteSweepRecipes()`: `route name => ['body' => Closure(array $ids): array, 'prepare' => ?Closure(array $ids): void]`, with **one entry for each of the 28 non-GET tenant routes**. **Fail-closed:** a non-GET tenant route with no recipe is a finding that names it. Bodies must be *valid* (the "mask shape 1" lesson in `QaAccessGuardIsolationHttpTest`), so that a refusal is a tenant refusal and not a validation refusal. `prepare` runs inside the tenant that owns the resource (for example, it assigns the role before remove-assignment). Every write request also sends a fresh `Idempotency-Key`, so a route that adopts `RequireIdempotencyKey` later stays attackable.
  - A route is **id-bearing** if it has a path parameter **or** its recipe body differs when given A's ids versus B's ids. That is computed, not declared.
  - **Reseed before each write route**: call `AccessTenantFixture::boot()` (a file copy), re-apply the world mapping, seed both tenants, mint fresh tokens and flush controllers.
  - `qaRouteSweepSnapshot(string $fixtureTenant)`: JSON of every row of every table in that tenant's database, read through `AccessTenantFixture::switchTo()` and `leave()`.
  - **Id-bearing write**:
    1. Cross attempt: B's token with A's ids must return 404 or 422, and **both** A's and B's snapshots must be unchanged.
    2. Reverse attempt: A's token with B's ids.
    3. Own control, run **last**: A's token with A's ids must return 2xx, and A's snapshot must change, which proves the write is real.
  - **Non-id write (AC-5 proper)**:
    1. A writes with A's token. It must return 2xx and A's snapshot must change.
    2. B's snapshot must be byte-identical before and after.
    3. Re-read through the matching collection route as B: it carries no A marker.
    4. Then the reverse.
  - AC-7, write half: a planted `POST api/v1/qa-route-sweep/planted-write`. Its controller leaves the current tenant and enters the *other* fixture tenant, then inserts a `marker` row there. This is a genuine cross-database write. The write attempt must report "tenant-b's database changed by tenant-a's write".
  - Print a table with one row per route: class, shape, statuses seen, and finding or "ok". Assert `findings === []` with all findings joined into the failure message, so each offending route is named (AC-23).

- [ ] **Step 10: Suite integrity** (AC-22, AC-24).
  - Run `ToolchainGroupCoverageTest`, `ToolchainGroupBindingTest`, `QaAssertionShapeGuardTest` (no negated multi-needle `toContain`; one needle per call), `QaVocabularyRenameSweepTest`, `DestructiveStatementContainmentTest`, `HostTestSuiteCollectionTest` and `QaTestSuiteIsolationTest`. All must be green, and none of the new files may be in `toolchainGroupFiles()`.
  - Run `composer test:fast` and the new files alone, and record the wall time of each new file. Expect roughly 60–120 s in total (see Risks). If the route file is over about 60 s, apply the mitigation in Risks before going on. Do not move any file into the `toolchain` group.
  - Run `composer stan` and `composer deptrac` on the new files (level 6 types on closures and arrays). The new files may import only HostApp and Package_* layers, which are already granted to HostTests.

- [ ] **Step 11: AC-23 load-bearing proof (qa executes, dev prepares).** The in-file negative controls above already cover AC-3, AC-4, AC-5 and AC-7 (planted routes), AC-9 (ambient-trusting job), AC-13 and AC-14 (planted files), AC-15 (constant-key double), AC-16 (collapsing limiter), and AC-19 and AC-21 (leaky probe). Several classes are `final`, so their mutation cannot be planted in a test without a production change (D-6). For those, `dev` writes this protocol into the dev notes and `qa` runs it. Each item is one temporary edit: run only the named test, confirm it goes **red with a message naming the surface**, revert, and confirm `git diff --stat` is empty before anything else runs (memory: gate-sweeps-the-working-tree).
  - M-1, for AC-20: in `CircuitBreaker::recordFailure`, drop `->where('tenant_id', $tenantId)` from the trip `update`. The AC-20 case must go red ("tenant-b breaker tripped").
  - M-2, for AC-18 and AC-19: in `PluginRuntime::tableFor`, drop `->where('tp.tenant_id', $tenantId)`. AC-18 must go red.
  - M-3, for AC-9 deterministic: in `MigrateTenantHandler::handle`, replace `$this->tenancy->run($tenantId, …)` with a call that uses the current connection without entering the tenant. Scenario R must go red.
  - M-4, for AC-13: in `CachingEntitlementSource::keyFor`, change the key to `self::KEY_PREFIX.'all'`. AC-13 must go red, naming the file and line.
  - M-5, for AC-16: in `ApiActor::from`, change the tenant bucket to `"t:"`. Both the AC-13 scan and the AC-16 runtime must go red.
  - M-6, for AC-6: delete `RequireTenantToken::class` from the organization route group. AC-2 and AC-6 must both go red, naming the routes.

- [ ] **Step 12: Documentation and close-out** (AC-25).
  - Add the one `docs/architecture.md` §21 sentence, and give each new file a header docblock. The header states that the suite is permanent and gate-enforced, that its coverage grows with the registry, and what needs a table entry (recipe, seeder, driver or exclusion reason) versus what needs a new sweep category (a new kind of transport).
  - Each header also lists that file's stated limits: N-1's memo blind spot, the token-name heuristic (a key built from a variable merely *named* `$tenantId` could still hold the wrong value, which is why the runtime halves exist), and AC-9 [ENGINE] being NOT VERIFIED under G-1.
  - Run the full `composer check` and record the result and the `FLEXIPOS-GATE-MARGIN`. Hand the lead the plan-note deviations for the backlog row.

## AC-to-test mapping

| AC | File | Test (`it`) / mechanism | Negative control |
|---|---|---|---|
| AC-1 | Route | classification by `gatherMiddleware()` over `Route::getRoutes()` | M-6 |
| AC-2 | Route | `QA_ROUTE_SWEEP_FLOOR` (42 names) found and tenant-gated | M-6 |
| AC-3 | Route | by-id read attempt, both directions, refusal set {404, 422} | planted `{branch}` route (AC-7) |
| AC-4 | Route | collection read attempt plus `QA_ROUTE_SWEEP_NO_TENANT_DATA` liveness | planted list route (AC-7) |
| AC-5 | Route | write attempts, snapshot of both DBs, reseed per route | planted cross-DB write route (AC-7) |
| AC-6 | Route | `QA_ROUTE_SWEEP_EXCLUDED`, fail-closed both ways, printed | M-6 |
| AC-7 | Route | planted read, list and write routes fed to the same attempt functions | (is the control) |
| AC-8 | Job | reflection `ShouldQueue` scan = `[MigrateTenantQueueJob]` | planted temp class |
| AC-9 det. | Job | ambient-worker scenarios R and L through the sync queue on `migrate-tenant-fixture` | `QaJobSweepAmbientTrustingJob`, M-3 |
| AC-9 [E] | Engine | ThreeTenantFixture, stale-stanza worker, FX-1 table in tenant 1 only (skipped under hold) | inverted hold case |
| AC-10 | Job | property-name rule plus serialised-payload scan, generic | planted `$password` class |
| AC-11 | Job | `tenantId`-shaped promoted property or allow-list (empty) | planted no-tenant class |
| AC-12 | Cache | site detection = 4 files (N-1) | plants (a), (b) and (d) detected |
| AC-13 | Cache | key resolution rules 1–4, builders, allow-list (1 entry, N-2) | plants (a), (c), (e) and (f); M-4, M-5 |
| AC-14 | Cache | planted temp files reported; green after removal | (is the control) |
| AC-15 | Cache | mapping probe through `Tenancy` with an array store, evict isolation | `QaCacheSweepConstantKeyRepository` |
| AC-16 | Cache | per-`*Bucket`-property drivers on shipped routes | `QaCacheSweepCollapsingLimiter`, M-5 |
| AC-17 | Hook | catalogue enumerated (≥1 with fixture provider); probe manifest generated per point × mode | (count printed, with and without provider) |
| AC-18 | Hook | enabled for A only, dispatch in B: no call, no B rows, no `(B, plugin)` row | M-2 |
| AC-19 | Hook | `qaHookSweepAttack(QaHookSweepProbe)` both directions | `QaHookSweepLeakyProbe` (AC-21) |
| AC-20 | Hook | A tripped, B still called and closed | M-1 |
| AC-21 | Hook | leaky probe reported | (is the control) |
| AC-22 | all | file placement, groups, `ToolchainGroupCoverageTest` green, `test:fast` includes them | (N-3) |
| AC-23 | all | in-file controls plus the M-1 to M-6 qa protocol | (is the control) |
| AC-24 | all | `is_dir()` on every scan root, plus non-empty route list and hook catalogue | (asserted first in each file) |
| AC-25 | docs | `architecture.md` §21 sentence plus file headers | n/a |

## Test strategy

- **Unit-level**, inside the sweep files: each scanner and resolver function (route classifier, `ShouldQueue` scan, cache-key resolver, probe manifest builder) is exercised directly against planted temp-directory inputs, with both a positive plant (no finding) and a negative plant (finding). This proves the instrument both passes and fails.
- **Integration**: the route attempts are real HTTP through the full middleware stack against two real SQLite tenant databases. Hook dispatch goes through the real `Gateway`, `HookInvoker` and `CircuitBreaker` against the master SQLite. The job goes through the real sync queue and the real `MigrateTenantHandler`, with only the migrator faked behind its seam. The cache probe goes through real `Tenancy::enter()`.
- **[ENGINE]**: one file, skipped under `ENGINE_HOLD`, with the inverted hold case asserting in the other state.
- **What proves what**: see the mapping table. Every positive assertion is `findings === []`, with the joined findings as the message, so a failure names the route, job, site or hook.

## Plan notes: spec and code conflicts (for the lead to route to `ba`)

- **N-1 (AC-12, AC-13, AC-15 wording).** The code has **four** cache-key sites, not two. They are the kernel mapping cache, `CachingEntitlementSource` (T-016) and `ActivationThrottle` (T-014), plus `ThrottleApi` with `ApiActor` as its key builder. All four already embed a tenant, terminal or IP token. The plan asserts the measured four. Recommendation: `ba` amends AC-12 to "the measured inventory" and leaves AC-15 as written. AC-15 covers only the mapping cache, and the entitlement and activation caches already have their own two-tenant tests (`QaEntitlementCacheIsolationTest` and the auth throttle tests), so they are not duplicated. Stated limit: `ManifestCache` is an in-process array memo keyed by a global `plugin_version_id`. It is tenant-agnostic by design and outside the scan's patterns.
- **N-2 (AC-13 "allow-list empty today").** One entry is needed on day one: `ApiActor`'s `u:platform:{$platformUserId}`. A platform token has no tenant by construction (T-021 AC-53). It goes on the allow-list with that reason. Every other key passes on its own merits.
- **N-3 (AC-22 "[ENGINE] case runs under `test:slow`/the `toolchain` group").** This conflicts with the code. Engine tests are the `engine` group, bound by the directory `tests/Engine`, and `Pest.php` deliberately keeps that group in `test:fast` and the gate (skipped under the hold). The `toolchain` group is reserved for files that spawn a child process, and `ToolchainGroupCoverageTest` enforces that the registry equals that set. So the AC-9 engine case goes in a fifth file, `tests/Engine/QaJobCrossTenantSweepEngineTest.php`. That departs from AC-22's "one file per category" and from D-2's location for this one case.
- **N-4 (AC-9 "deterministic variant using `AccessTenantFixture`").** The job's handler reads `tenant_migration_jobs` and the mapping from the master and enters through the Migrating purpose. The kernel's own `migrate-tenant-fixture.php` is the existing stand-in for exactly that: two SQLite tenants, a real `Tenancy` path, and a migrator faked per database name. This follows D-3's principle (the cheapest fixture that is still real) but not its literal fixture name. **I think D-3's text is wrong for this one case, and I am flagging it rather than overriding it silently.**
- **N-5 (AC-9 "resolves the tenant id it carries").** `MigrateTenantQueueJob::handle()` never reads `$this->tenantId`. The tenant comes from the master job row, located by `jobId` and `workerId`. Isolation still holds (the row is planner-written and authoritative), and the sweep proves it. Observation only, not a defect: a payload whose `tenantId` disagreed with its row would migrate the row's tenant without noticing the disagreement.
- **N-6 (AC-17, AC-18).** In the shipped application, `HookPointCatalogue::all()` returns 0 points, because no production provider declares any. "At least one" holds only with the test-only `FixtureHookPointProvider` registered, as all T-019 tests do. The sweep generates its own probe manifest over every enumerated point and mode rather than reusing fixture-echo's handlers, which neither observe nor write the tenant. AC-18's "circuit-breaker state written under tenant B's connection": breaker state lives on the **master** `tenant_plugins` row keyed by `(tenant_id, plugin_id)`. The assertion is "no `(B, plugin)` row created or changed", together with B's own `plugin_execution_logs` being unchanged.
- **N-7 (AC-1 three-way classification).** "Master-only" and "unauthenticated probe" cannot be told apart by middleware: login, activation, refresh, ping and openapi all carry only `api`. The plan classifies into `tenant`, `terminal`, `platform` and `none` by middleware. AC-6's distinction moves into the reason text of the fail-closed exclusion table. The spec's "platform audit" route does not exist.
- **N-8 ("a case per route").** Pest resolves datasets before Laravel boots, so per-route Pest cases cannot come from the live registry. Each attack shape is one `it` with a per-route findings table that names every route. Coverage is the same, and the reporting granularity is a printed row plus a named failure.
- **N-9 (AC-8, AC-11, AC-25 "no new test file").** That holds. But three kinds of new surface need a **table entry in the existing file**: a new write route (body recipe), a new path-parameter name (seeder), and a new queued job (ambient driver). Each is fail-closed, so forgetting the entry turns the gate red instead of shrinking coverage. Payload rules (AC-10, AC-11), cache keys and hook points need no entry at all.
- **N-10 (AC-23 "planted via a mutation").** `CircuitBreaker`, `PluginRuntime`, `MigrateTenantHandler` and `ApiActor` are `final`. Planting their leaking variant in a test would need a production change, which D-6 rules out. Those items are proved by the qa-run temporary-mutation protocol M-1 to M-6 (step 11). All other items are in-file.

No decision D-1 to D-7 is overridden. N-3 departs from D-2's literal file location for the engine case, and N-4 departs from D-3's literal fixture for the deterministic job case. Both are reported for `ba`.

## Risks & rollback

- **Route-sweep runtime.** About 28 reseeds (a file copy plus seeding two tenants) and about 130 HTTP requests. Estimate: 20–40 s. Mitigation, if step 10 measures more than about 60 s: split the route file's write attempts into two `it` blocks and seed once per *resource kind* rather than per route, with the own-control write always last. Do not tag anything `toolchain`. The gate step limit (7800 s) has ample margin.
- **Brittle refusal codes.** Some by-id routes may refuse with a status outside {404, 422} (for example 409). If step 8 or 9 measures one, record it in the dev notes and add it **per route** with a reason. Never widen the global set silently.
- **Scanner false positives or negatives (cache).** The resolver is regex-based. Fail-closed rule 4 means an unfamiliar shape fails loudly rather than passing. The token-name heuristic is a stated limit, backed by the runtime halves.
- **Cross-suite policing.** Planted sources must not contain process-spawning tokens (toolchain detector), negated multi-needle `toContain` (assertion-shape guard) or old vocabulary (vocabulary sweep). Planted files live only under `sys_get_temp_dir()`, never in the repository, because lint, stan and deptrac sweep the working tree.
- **A real leak found.** That is a defect against the violated AC and is handed to `developer`. It is not patched in this branch's test code, and no assertion is weakened to go green (spec, out of scope).
- **Rollback**: delete the five new files and revert the one `architecture.md` sentence. Nothing else changes.

## Questions for the human

None blocking. The plan notes N-1 to N-10 are for the lead to route to `ba`.
