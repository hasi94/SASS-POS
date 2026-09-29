# Plan: T-002 Service host hardening

Spec: `docs/specs/T-002-service-host-hardening.md` (AC-1..AC-19, landed after this plan was
started; cross-checked against every step below — one gap found and fixed before handoff,
see Step 8/AC-17).
Decisions: `docs/decisions/T-002-service-host-hardening.md` (D-13..D-24) — D-13/D-14/D-15/
D-16/D-18/D-19/D-20/D-22/D-23/D-24 match this plan's rulings exactly; D-17 and D-21 leave
the mechanism to `architect` and are resolved below (Steps 3 and 6, and the "Judgement
calls" section).
Source: `docs/reviews/T-001-monorepo-service-host.md`, `docs/backlog.md` rows FP-146..FP-153.
Branch: `ai/T-002-service-host-hardening` (from the T-001 tip).
All files owned by `dev` except Step 9, owned by `qa`.

## Approach summary

Nine review findings, folded into eight backlog rows (FP-146..FP-153), all land inside
`services/host/` as either production-code hardening (`ServiceRegistry`, `SvcMakeCommand`,
`ServiceHostServiceProvider`, routing) or host-side configuration/documentation
(`phpunit.xml`, `phpstan.neon.dist`, `deptrac.yaml`, `README.md`). Every fix is verified
against this machine's installed Composer 2.8.4 / PHP 8.2.12 / Laravel 12 / PHPStan 2.x /
Deptrac 2.0.4 rather than assumed — four things below (the PCRE `$`-newline bypass, the
`require vendor/autoload.php` singleton-caching behaviour, the `CachesConfiguration`
contract, and the built-in `/up` route's middleware-free registration) were empirically
checked against the actual installed code before being written into this plan. No new
Composer dependency is introduced. `services/demo`'s committed manifest already satisfies
every new validation rule this plan adds, so the one real service package in the repo needs
no changes and stays the regression anchor for the happy path.

## Judgement calls — architect rulings

These are binding. `dev` implements exactly this; a plan problem goes back to `architect`,
not a silent reinterpretation.

**1. M-1/M-2 manifest trust rule — no escape hatch.** For a package at `services/<name>/`,
`ServiceRegistry` requires, with no configuration and no allow-list:
- the manifest's single `autoload.psr-4` key is **exactly** `Flexipos\Service\<Studly>\`
  where `<Studly> = Str::studly($name)` — the identical call `SvcMakeCommand` already uses
  for `{{studly}}`, not a re-derived transform;
- `extra.flexipos.provider` **starts with** that exact same namespace string;
- the resolved provider class `is_a(..., Illuminate\Support\ServiceProvider::class, true)`.

  Any other value throws `UnknownServiceException` naming the expected namespace/prefix.
  This is not too rigid for a future "vendor-published" or "plugin-supplied" service,
  because `services/*` is architecturally reserved for first-party FlexiPOS business
  services already — the monorepo layout (`CLAUDE.md`, architecture.md §5, T-001 D-11)
  puts a genuinely external integration in `plugins/*` (its own, not-yet-built boot
  mechanism, free to define its own trust rule when it exists) or behind a first-party
  `services/<name>/` wrapper that still declares `Flexipos\Service\<Studly>\` and delegates
  to vendor code internally. A foreign namespace here would also have no Deptrac layer and
  no `pos_<name>`/`svc_<name>` connection convention, so it would break the rest of the
  skeleton anyway. **No configurable trusted-namespace list. One required form, always.**

**2. M-3 anchor bug — audited, isolated to one constant.** Searched every non-vendor
`.php` file under `services/` and `packages/` for `preg_match`: exactly two call sites
(`ServiceRegistry::boot()`, `SvcMakeCommand::handle()`), and `SvcMakeCommand::NAME_PATTERN`
is already a direct reference to `ServiceRegistry::NAME_PATTERN` (`private const NAME_PATTERN
= ServiceRegistry::NAME_PATTERN;`), not a copy. Every other string validation in the
codebase (`manifest()`, `psr4()`, `providerClass()`) uses `is_array`/`is_string` type
checks, not regex, so this exact bug class cannot recur there. One `D`-modifier edit to the
shared constant closes both call sites. Verified live: `preg_match('/^[a-z]...$/', "billing\n")`
→ `1` (bypasses); with `/D` → `0` (blocked), on this machine's PHP 8.2.12.

**3. M-5 mechanism — separate route file, registered outside every middleware group,**
mirroring a pattern already present in this exact `bootstrap/app.php`: Laravel 12's own
built-in `health: '/up'` route is registered via a bare `Route::get($health, ...)` call with
no `Route::middleware(...)->group(...)` wrapper at all (confirmed by reading
`ApplicationBuilder::buildRoutingCallback()` in the installed framework) — i.e. the
framework's own idiom for "this route must not depend on anything" is exactly "don't wrap it
in a group." Rejected the alternative (stripping session middleware from the two routes
while leaving them in `routes/web.php`'s `web` group): fragile, because it depends on
knowing today's exact `web` group contents and re-auditing it every time that group changes;
a route that was never in a group in the first place cannot regress this way.

**4. M-6 cache guard — precise throw/silent rule.**
- Configuration **not** cached (the normal dev/test path, and what `composer test` already
  runs under — its `test` script's first line is `@php artisan config:clear --ansi`): the
  guard is a no-op, zero overhead, exactly today's behaviour.
- Configuration **cached** and the real process environment does not set `SERVICES` at all
  (`Illuminate\Support\Env::getRepository()->get('SERVICES') === null`): **silent**. Nobody
  is attempting to override the baked-in value for this container — this is "a container
  legitimately runs with a cached config and the same environment."
- Configuration **cached** and the real process environment sets `SERVICES` to a value that,
  once parsed with `ServiceRegistry::parse()` (trim, dedupe, sort — the exact same
  normalisation `SERVICES` always gets), differs from `config('servicehost.services')`
  parsed the same way: **throws**, naming both the frozen and the ambient value and the fix
  (`config:clear` then re-`config:cache` with the right `SERVICES`, or don't cache where
  `SERVICES` must vary per container from one image).
- Configuration **cached** and the ambient value parses equal to the cached value: silent
  (redundant-but-consistent is fine).

  This also closes the `mergeConfigFrom()`-is-a-no-op-under-cache half of M-6 structurally,
  not by a second mechanism: a service that was already booted when `config:cache` ran had
  already merged its connection config into the repository that got cached, and this guard
  makes it impossible to reach "a service boots for the first time under a cached config
  with a disagreeing SERVICES" — that path now throws before `bootAll()` runs. Documented,
  not separately re-implemented.
- **`route:cache` still throws today** (`routes/web.php`'s closure route for `/`, stock
  Laravel scaffolding) — this is a real, currently-broken thing, but fixing it means editing
  the stock welcome route, which the task brief explicitly excludes ("container/CI parts of
  FP-149 ... general cleanup of the stock Laravel scaffolding"). **Deliberately left broken
  and documented**, not silently dropped — see Step 5 and Risks.

**5. M-9 classmap-authoritative — documented only, no detection code. Reversed from my
initial reading.** The orchestrator's brief gave `architect` latitude here ("decide between
documenting it, detecting it..., or both"), and `Composer\Autoload\ClassLoader` does expose
a public `isClassMapAuthoritative()` that would make detection cheap (confirmed in the
installed `vendor/composer/ClassLoader.php`) — I drafted a runtime throw for it before the
spec existed. Once `ba`'s spec landed, it turned out to conflict: AC-11 item 4 states this
constraint is "documented only," matching `docs/decisions/T-002-service-host-hardening.md`
D-20 ("no detection code is added for `--classmap-authoritative`") and backlog.md's own
FP-149 line ("add **a** [singular] boot-time guard and document all four"). Handing `dev` a
plan that contradicts the spec `qa` will test against is worse than my original preference
being overruled, so this plan follows the spec: **documentation only**, in
`config/servicehost.php`'s comment block and the README (judgement call 7) — no runtime
check, no new exception path, no test asserting a throw. If this should be revisited, that
goes back through `ba` to amend the spec, not a silent code addition.

**6. The reviewer's answer to my own T-001 judgement call — accepted.** Replace the
`spl_autoload_functions()` scan with `require $this->app->basePath('vendor/autoload.php')`.
Verified empirically on this machine, not assumed: `vendor/composer/autoload_real.php`'s
`getLoader()` guards on a `private static $loader` and returns the same cached instance on
every call; calling `require` (never `require_once` — `require_once` would return `true`,
not the loader, on every call after Laravel's own bootstrap already required the same file)
on `vendor/autoload.php` a second time is safe and returns the identical `ClassLoader`
object (`$a === $b` verified `true` via a scratch script against this project's installed
autoloader). This removes the "takes the first matching loader" ambiguity the review flagged
ahead of a second loader (a future plugin system) ever existing, and is simpler than the
scan it replaces.

**7. AC-11 documentation location (spec's Open Question #2) — both.** Primary, detailed
technical documentation (all four cache/autoloader facts) goes in
`config/servicehost.php`'s comment block, colocated with the `SERVICES` config it governs —
matching this codebase's own established pattern (`ServiceRegistry.php`'s class docblock
already documents the runtime PSR-4 mechanism the same way). A short paragraph is *also*
added to root `README.md` (a new "Caching in production" note near "Getting started"),
because AC-11 frames this as something "a developer reads... before running `config:cache`"
— a developer about to do that is far more likely to open the README first. Not a new file
under `docs/`: the content is short enough (four bullet points) that a second, separate doc
would be more indirection than the material needs.

**8. FP-147 mechanism (spec's Open Question #1a, D-17 leaves it to `architect`) —
delete-on-throwable.** Simpler than render-to-temp-then-move, no cross-directory move
logic, and the review's own "Fix" column lists it first.

**9. FP-150 mechanism (spec's Open Question #1b, D-21 leaves it to `architect`) — collapse
to one `tests` suite**, not an assertion inside the guard test. See "Alternatives rejected."

**10. AC-17 (Deptrac must catch a boundary violation inside test code, via the real,
shipped `deptrac.yaml`) — extend `deptrac.yaml`'s `paths`, no new layer.** My first pass at
this plan under-scoped FP-152: I reasoned that extending Deptrac's `paths` to test
directories would be inert today, since every committed test file is a namespace-less Pest
closure file with no class-like declarations for Deptrac to collect. That is true for
*permanent* content, but AC-17 asks for a *temporary* probe (the same idiom T-001's own
`DeptracBoundaryEnforcementTest` already uses for AC-15: a deliberately-violating class,
added for one assertion, then removed) — and Deptrac's layers are matched by **namespace
regex, not by directory**, so a temporary class declared under `Flexipos\Service\Demo\...`
inside `services/demo/tests/` is already collected by the *existing* `Service_Demo` layer
(`^Flexipos\\Service\\Demo\\.*`) with zero new layer or ruleset entry. See Step 8.

## Alternatives rejected

- **A per-service `paths`/layer glob in `phpstan.neon.dist`/`deptrac.yaml`.** Already proven
  technically impossible during T-001 (both tools reject `*` wildcards outright); not
  re-litigated here.
- **A new Deptrac layer dedicated to test code** (e.g. `Service_Demo_Tests`), instead of
  relying on the existing `Service_Demo` layer's regex to also match test classes. Rejected:
  Deptrac layers match by namespace, not by directory, so a class declared under
  `Flexipos\Service\Demo\...` is already inside the `Service_Demo` layer regardless of
  whether the file lives in `src/` or `tests/` — a second layer would only be needed if
  service tests used a *different* namespace root than their own service, which nothing in
  this codebase's conventions does. See Step 8 / judgement call 10.
  `services/host/tests/**` itself is left out of both `phpstan.neon.dist`'s and
  `deptrac.yaml`'s `paths` for this task — see Step 8 for why (risk of a new finding
  surfacing inside a `Qa*.php` file `dev` cannot edit).
- **Render-to-temp-directory-then-move for M-4 (SvcMakeCommand atomicity)**, one of the two
  options the review offered. Rejected in favour of "wrap the write loop and delete the
  target directory on any throwable" — the simpler of the two acceptable fixes, and the one
  that requires no cross-directory move logic.
- **A new, dedicated exception class for the config-cache guard.** Rejected: it is a
  host/environment-level boot failure, not "this SERVICES entry is invalid," so reusing the
  existing `RuntimeException` (already the type `ServiceRegistry::classLoader()` throws for
  "loader not registered") is more honest than forcing it through `UnknownServiceException`,
  which always names one specific service.
- **A single collapsed `Unit`+`Feature` PHPUnit suite named to preserve the old names as two
  suites.** Rejected: nothing in the repo references `--testsuite=Unit`/`=Feature` by name
  (checked), so there is no compatibility reason to keep two suites, and one recursive
  `<directory>tests</directory>` suite is what makes a *future* host test directory
  self-covered — the actual M-7 gap.

## Files to change / create

All under `D:\Flexiata\NEW-FLEXIPOS`. Files under `services/host/tests/Feature/Qa*.php`
belong to `qa` — `dev` must never edit them, even to fix a message string that a `dev` change
makes stale (see Step 1's note and Risks).

### Production code (dev)
- `services/host/app/Support/ServiceHost/ServiceRegistry.php` — edit. M-1, M-2, M-3, and the
  accepted judgement-call rewrite of `classLoader()` (M-9's own fix is documentation only —
  see judgement call 5 — so this file gets no classmap-authoritative code). Add
  `use Illuminate\Support\Str;`.
- `services/host/app/Console/Commands/SvcMakeCommand.php` — edit. M-4 (atomic generation).
  No change needed for M-3 (inherits the constant fix automatically).
- `services/host/app/Providers/ServiceHostServiceProvider.php` — edit. M-6 cache guard.
- `services/host/routes/health.php` — new. The two health routes, zero middleware.
- `services/host/routes/web.php` — edit. Remove the two health route lines (only the stock
  `/` welcome route remains — not a scaffolding cleanup, just relocating the two lines this
  task owns).
- `services/host/bootstrap/app.php` — edit. Add a `then:` callback to `withRouting()` that
  requires `routes/health.php`.

### Host-side configuration and docs (dev)
- `services/host/phpunit.xml` — edit. M-7 (collapse `Unit`+`Feature` into one `Host` suite).
- `services/host/phpstan.neon.dist` — edit. FP-152 dev half (AC-16): add `../demo/tests` to
  `paths`.
- `services/host/deptrac.yaml` — edit, functional. FP-152 dev half (AC-17): add
  `../demo/tests` to `paths`. No new layer — the existing `Service_Demo` layer's namespace
  regex already covers it (judgement call 10).
- `services/host/config/servicehost.php` — edit. Extend the doc comment with the four-point
  boot-time cache/autoloader contract (M-6, M-9, FP-149's "document all four", AC-11).
- `README.md` (root) — edit. M-8 (AC-14): correct the "no file inside `services/host/`
  needs to change" line (line 55) to name `phpstan.neon.dist` and `deptrac.yaml` and point
  at `QaToolchainRegistryCoverageTest`, while keeping the true part (booting via `SERVICES`
  needs no edit) intact and clearly distinguished. Also add a short "Caching in production"
  note (AC-11, judgement call 7).
- `services/host/README.md` — replace. M-8 (AC-15): this is still the stock
  `composer create-project laravel/laravel` README (MIT statement), contradicting
  `composer.json`'s `"license": "proprietary"`. Replace with a short, project-specific
  README. This is a one-time hand edit, unrelated to `svc:make`'s runtime behaviour — does
  not touch AC-27 (see "AC-27 boundary" note below).

### Tests (dev, new/edited files — none of these are `Qa*.php`)
- `services/host/tests/Feature/ServiceRegistryManifestTrustTest.php` — new. M-1, M-2, M-3
  (AC-1, AC-2, AC-3, AC-4's `ServiceRegistry`-level half).
- `services/host/tests/Feature/SvcMakeValidationTest.php` — edit (already `dev`-owned from
  T-001 Step 7). Add AC-4's CLI-level half: `svc:make` with a trailing-newline name is
  rejected, no directory created.
- `services/host/tests/Feature/SvcMakeAtomicGenerationTest.php` — new. M-4 (AC-5, AC-6).
- `services/host/tests/Feature/HealthEndpointTest.php` — edit (already `dev`-owned from
  T-001 Step 6). M-5: add the dead-database-liveness cases (AC-7, AC-8).
- `services/host/tests/Feature/ConfigCacheGuardTest.php` — new. M-6 (AC-9, AC-10).
- `services/host/tests/Feature/DeptracBoundaryEnforcementTest.php` — edit (already
  `dev`-owned from T-001 Step 10). FP-152 dev half (AC-17): add the test-code boundary
  violation case.
- `services/host/tests/Feature/HostTestSuiteCollectionTest.php` — new. M-7 (AC-12, AC-13).
- `services/host/tests/Feature/SvcMakeDemoRegenerationTest.php` — new. FP-153 (AC-19).

### Tests (qa — Step 9 only, `dev` does not touch this file)
- `services/host/tests/Feature/QaToolchainRegistryCoverageTest.php` — narrow the
  `str_contains($name, 'fixture')` skip rule (FP-152 qa half).

### AC-27 boundary — generator behaviour vs. host-side configuration
AC-27 ("no file inside `services/host/` is edited by the generator") governs
`SvcMakeCommand`'s **runtime** write behaviour only. Every file this plan edits by hand
(`phpstan.neon.dist`, `deptrac.yaml`, `phpunit.xml`, both READMEs, `config/servicehost.php`,
routing files) is a one-time change `dev` makes to the committed host, exactly like every
file T-001 itself created inside `services/host/`. None of it is written by
`php artisan svc:make`, so none of it is in scope for AC-27. The one step that touches
`SvcMakeCommand.php` itself (Step 3, M-4) changes error-path behaviour only (cleanup on
failure) — the happy-path file set the command writes is unchanged, verified in that step's
test strategy.

## Data / API changes

- No new endpoints. `/health/live` and `/health/ready` keep their exact paths, route names
  (`health.live`, `health.ready`) and JSON shapes (D-7/T-001's Decision section) — only their
  middleware exposure changes (none, instead of inherited from `web`).
- No new config keys. `config/servicehost.php`'s existing `services` key is unchanged in
  shape; its doc comment grows.
- New (tightened) contract on `services/<name>/composer.json`, enforced at boot: the single
  `autoload.psr-4` namespace must equal `Flexipos\Service\<Studly>\` and
  `extra.flexipos.provider` must start with it. `services/demo`'s committed manifest already
  satisfies this exactly — no data migration, no change to the demo package.
- No new exception types. The one new failure mode (config-cache/environment mismatch)
  throws `RuntimeException`, the same type `classLoader()` already throws for "loader not
  registered." Manifest-trust failures (M-1, M-2, M-3) continue to throw the existing
  `UnknownServiceException`. No exception at all for classmap-authoritative — documented
  only (judgement call 5).

## Implementation steps

- [x] **Step 1 (dev) — Harden `ServiceRegistry`'s manifest trust and name pattern (M-1, M-2,
  M-3).** In `ServiceRegistry.php`:
  - Add the `D` modifier to `NAME_PATTERN` (fixes both call sites via the shared constant).
  - In `boot()`, after `psr4()`/`providerClass()` resolve `$namespace`/`$provider` but
    *before* `addPsr4()` is called: compute
    `$expectedNamespace = sprintf('Flexipos\\Service\\%s\\', Str::studly($name))`; throw
    `UnknownServiceException` if `$namespace !== $expectedNamespace`; throw
    `UnknownServiceException` if `! str_starts_with($provider, $expectedNamespace)`.
  - Move `addPsr4()` to run only after both of the above pass (it already ran before
    `class_exists()`; it must still run before `class_exists()`/`is_a()`, since those need
    the autoloader registered — "after validation" means after the two checks above, not
    after every possible failure).
  - After the existing `class_exists($provider)` check, add
    `is_a($provider, \Illuminate\Support\ServiceProvider::class, true)`, throwing
    `UnknownServiceException` naming the class if false.
  - New test `ServiceRegistryManifestTrustTest.php`: generate throwaway fixture packages
    (same pattern as T-001's `SvcMakeGeneratedServiceReachableTest` — via the real
    `svc:make` command, cleaned up in `afterEach`) whose `composer.json` is then hand-edited
    to (a) declare a foreign/mismatched `autoload.psr-4` namespace (AC-2) — assert the throw
    *and* assert nothing was registered for that namespace afterward, via
    `$loader->getPrefixesPsr4()[$badNamespace] ?? null` being absent/empty (the exact proof
    AC-2 asks for), where `$loader` is obtained the same way `ServiceRegistry` does
    (`require base_path('vendor/autoload.php')`); (b) declare a correctly-scoped namespace
    but a provider class outside it (AC-3); (c) name a real, autoloadable class that is not a
    `ServiceProvider` subclass, e.g. a plain class in the fixture's own `src/` (AC-1); (d)
    pass a name with a trailing `"\n"` to `ServiceRegistry::parse()`/`boot()` directly and
    confirm it is rejected (AC-4's registry-level half — `preg_match(ServiceRegistry::NAME_PATTERN,
    "billing\n")` returns `0`). Also extend `SvcMakeValidationTest.php` with AC-4's
    CLI-level half: `Artisan::call('svc:make', ['name' => "billing\n"])` exits non-zero,
    creates no directory. Assert each manifest-trust case throws `UnknownServiceException`
    with a message naming the specific problem (service name + the expected vs. declared
    namespace/prefix, per the spec's Non-functional Requirements). **Before writing this
    test, read (do not edit) `QaServiceRegistryContractTest.php` to check whether its five
    existing malformed-manifest fixtures still fail at the same line/message they did before
    this step.** If any of them now fails earlier (at the new namespace/prefix check) with a
    different message than the one that test asserts, do not edit
    `QaServiceRegistryContractTest.php` — message `qa` with the specific `it(...)`
    description, old vs. new firing point, and let `qa` update its own fixture/assertion.
    (Covers FP-146, AC-1, AC-2, AC-3, AC-4. At risk: T-001 AC-18, AC-19, AC-20, AC-21,
    AC-22, AC-23, AC-24, AC-28, AC-32 per the spec's own FP-146 regression line — `demo`'s
    real manifest must still boot cleanly under the new rule; confirm via a full
    `composer test` run at the end of this step, not just the new test file.)

- [x] **Step 2 (dev) — Replace the autoloader lookup (accepted judgement call).** In
  `ServiceRegistry.php`, rewrite `classLoader()`:
  ```php
  private function classLoader(): ClassLoader
  {
      // require, never require_once: Composer's own vendor/autoload.php caches the
      // ClassLoader in a static property (vendor/composer/autoload_real.php's
      // getLoader()) and returns the same instance on every call. require_once would
      // return true, not the loader, on every call after Laravel's own bootstrap
      // already required this same file.
      $loader = require $this->app->basePath('vendor/autoload.php');

      if (! $loader instanceof ClassLoader) {
          throw new RuntimeException('Composer\'s class loader is not registered, so service packages cannot be autoloaded at runtime.');
      }

      return $loader;
  }
  ```
  No `isClassMapAuthoritative()` check here — per AC-11 item 4 / D-20, that constraint is
  documented only in this task (judgement call 5), not code-guarded. Add a test case to
  `ServiceRegistryManifestTrustTest.php` (Step 1's file) confirming a normal boot (`demo`)
  still works after this rewrite — this is a pure refactor of *how* the loader is obtained,
  with no intended behaviour change, so the existing boot-mechanism tests are the real
  proof; no new test file is needed for this step alone. (Refactor only, not a spec AC by
  itself — enables Step 1's checks to run before `addPsr4()` cleanly. At risk: T-001 AC-18,
  AC-21, AC-24, AC-26, AC-27 — the whole dynamic-boot mechanism changed how it finds the
  loader, so re-run the full existing boot-mechanism test files, not just the new one.)

- [x] **Step 3 (dev) — Atomic `svc:make` generation (M-4).** In `SvcMakeCommand.php`, wrap
  the "render six stub files" + "create five empty directories" block (after the
  reserved-name/already-exists checks, which must keep failing exactly as before with zero
  filesystem writes) in `try { ... } catch (\Throwable $e) { File::deleteDirectory($target);
  $this->error(...); return self::FAILURE; }`. The happy path's file set and content are
  unchanged. New test `SvcMakeAtomicGenerationTest.php`: temporarily rename or delete one
  stub file under `stubs/service/` (e.g. the last one written,
  `PingRouteTest.php.stub`) so generation fails partway through for a throwaway name after
  several files are already written; assert non-zero exit, and assert
  `services/<throwaway-name>` does not exist afterward; restore the stub file in
  `afterEach()` regardless of test outcome. Then, still inside the same test, restore the
  stub file and re-run `svc:make` with the **same** throwaway name — assert exit 0 and a
  complete, passing package this time (AC-6's "retry after cleanup succeeds" half; do not
  rely on AC-5's directory-removal assertion alone to imply this). (Covers FP-147, AC-5,
  AC-6. At risk: T-001 AC-25, AC-26, AC-27, AC-28, AC-29 per the spec's own FP-147
  regression line — re-run `SvcMakeValidationTest.php` in full to confirm the happy path and
  the existing rejection paths are byte-for-byte unchanged, and confirm the try/catch wrapper
  itself writes nothing outside `services/<name>/` even on the failure path.)

- [x] **Step 4 (dev) — Move health endpoints out of the `web` middleware group (M-5).**
  Create `services/host/routes/health.php`:
  ```php
  <?php

  use App\Http\Controllers\HealthController;
  use Illuminate\Support\Facades\Route;

  // Deliberately outside the `web` group (see bootstrap/app.php's `then` callback):
  // an orchestrator's liveness/readiness probe must not depend on the session store.
  Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
  Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
  ```
  Remove the two matching lines from `routes/web.php`. In `bootstrap/app.php`, add
  `then: function () { require __DIR__.'/../routes/health.php'; }` to the existing
  `withRouting(...)` call (alongside `web`, `commands`, `health: '/up'` — do not touch those
  three). Extend `tests/Feature/HealthEndpointTest.php` with:
  ```php
  it('answers liveness without touching the session store', function () {
      $this->bootHostWith('');
      // bootHostWith() calls refreshApplication(), which resets runtime config — set this
      // AFTER, not before, or the override is silently discarded and the test proves nothing.
      config(['session.driver' => 'database']);

      // No migration ever runs in this suite, so a `sessions` table genuinely does not
      // exist. If this route were still in the `web` group, StartSession would try to
      // query it and this would 500 (M-5) instead of returning 200.
      $this->get('/health/live')->assertOk()->assertExactJson(['status' => 'ok']);
  });
  ```
  and the same shape for `/health/ready` (AC-7, AC-8 — both endpoints must survive, per
  D-18). (Covers FP-148, AC-7, AC-8. At risk: T-001 AC-20, AC-22, AC-23 — re-run the full
  existing `HealthEndpointTest.php` file to confirm the exact JSON shapes, status codes and
  route names are unchanged, just relocated.)

- [x] **Step 5 (dev) — Config-cache guard (M-6).** In `ServiceHostServiceProvider.php`, add
  imports `Illuminate\Contracts\Foundation\CachesConfiguration`, `Illuminate\Support\Env`,
  `RuntimeException`. Add a private method (called from `register()` before `bootAll()` is
  invoked):
  ```php
  private function guardAgainstStaleCachedServices(string $cachedRaw): void
  {
      if (! ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
          return;
      }

      $ambient = Env::getRepository()->get('SERVICES');

      if ($ambient === null) {
          return;
      }

      $registry = $this->app->make(ServiceRegistry::class);

      if ($registry->parse((string) $ambient) !== $registry->parse($cachedRaw)) {
          throw new RuntimeException(sprintf(
              'Cached configuration disagrees with the process environment: config:cache froze '.
              'SERVICES="%s", but the environment now sets SERVICES="%s". A cached config never '.
              're-reads SERVICES; run "php artisan config:clear && php artisan config:cache" after '.
              'setting the SERVICES this image/container should boot with, or do not cache '.
              'configuration where SERVICES must vary per container from one image.',
              $cachedRaw,
              $ambient
          ));
      }
  }
  ```
  Use `Env::getRepository()->get()`, not the global `env()` helper or `Env::get()` — both of
  those apply `env()`'s "true"/"false"/"null"/"empty" string-literal coercion, which would
  corrupt this raw comparison (verified: `Env::getRepository()->get()` returns the raw
  string or `null`, no coercion). Extend `config/servicehost.php`'s doc comment with all four
  boot-time cache/autoloader facts, stating plainly which are code-guarded and which are
  documentation-only, per AC-11: (1) `SERVICES` freeze — **guarded by this step** (AC-9,
  AC-10); (2) `mergeConfigFrom()` no-op under cache — **documented only**, closed
  structurally by this same guard (a service can no longer boot for the first time under a
  disagreeing cached `SERVICES`); (3) `route:cache` currently throws on the stock closure
  route — **documented only**, out of scope, tracked separately (Risks R-5); (4)
  `--classmap-authoritative` breaks runtime PSR-4 registration — **documented only**, per
  judgement call 5 (no code change in Step 2). Add the same four-point summary as a short
  "Caching in production" note in root `README.md` (judgement call 7, AC-11). New test
  `ConfigCacheGuardTest.php` (covers AC-9, AC-10 only — items 2-4 have no test, they are
  prose),
  following the existing `Process::path(base_path())->run([PHP_BINARY, ...])` pattern from
  `DeptracBoundaryEnforcementTest.php`/`PintLintFixRoundTripTest.php` (never hardcode a PHP
  path — `PHP_BINARY` is already how this codebase gets it right cross-machine):
  1. Assert `bootstrap/cache/config.php` does not already exist (catches a leftover from a
     previously crashed run before it causes a confusing failure).
  2. Child process: `[PHP_BINARY, base_path('artisan'), 'config:cache']` with
     `->env(['SERVICES' => 'demo'])` — assert exit 0.
  3. Child process: `[PHP_BINARY, base_path('artisan'), 'route:list']` with
     `->env(['SERVICES' => 'billing'])` — assert non-zero exit, output contains both
     `SERVICES="demo"` and `SERVICES="billing"`.
  4. Child process: same command with `->env(['SERVICES' => 'demo'])` (matching) — assert
     exit 0.
  5. **In a `finally` block**, run `[PHP_BINARY, base_path('artisan'), 'config:clear']` and
     assert `bootstrap/cache/config.php` no longer exists — this file is real (not virtual
     test state) and every other test in the suite boots uncached; a skipped cleanup here
     would silently break unrelated tests in the same run, not just a future one.
  (Covers FP-149's M-6 half, AC-9, AC-10, AC-11 (all four points documented). At risk: none
  of the existing suite — `composer test`'s own `test` script already runs
  `artisan config:clear` as its first line, so this guard is a no-op for every pre-existing
  test, matching the spec's own FP-149 regression line. Confirm that remains true by running
  `composer test` once, unmodified, right after this step.)

- [x] **Step 6 (dev) — Collapse the host's PHPUnit suites (M-7).** In `phpunit.xml`, replace
  the separate `Unit` and `Feature` `<testsuite>` blocks with one:
  ```xml
  <testsuite name="Host">
      <directory>tests</directory>
  </testsuite>
  ```
  (PHPUnit's default `<directory>` suffix filter is `Test.php`; `TestCase.php` and
  `Pest.php` do not match it, and this recurses into every present and future subdirectory
  automatically — the same self-maintaining property the `Services`/`Packages` suites
  already have.) Update the `Services` suite's neighbouring XML comment, which currently
  says "the Unit and Feature suites above already cover [`../host/tests`]," to say "the
  Host suite above already covers it." No other suite changes. Confirmed no script or doc
  in the repo passes `--testsuite=Unit`/`=Feature` (checked). New test
  `HostTestSuiteCollectionTest.php`, following the exact pattern of the existing
  `QaGeneratedServiceTestDiscoveryTest.php` (write a probe, shell a filtered child
  `[PHP_BINARY, base_path('vendor/bin/pest'), '--filter=...']` via `Process`, assert it was
  found and passed, clean up in `afterEach`): write a temporary
  `tests/Integration/HostSuiteProbeTest.php` (a directory that is neither `Unit` nor
  `Feature`), assert a filtered child run finds and passes it, delete it afterward (AC-12).
  In the same file, assert the *unfiltered* full child run reports no duplicate-collection
  warning in its output (AC-13). (Covers FP-150, AC-12, AC-13. At risk: T-001 AC-16, AC-26
  and the exact 74-passed/222-assertion counts per the spec's own FP-150 regression line —
  run `composer test` and confirm the total test count matches expectations (74 plus
  whatever this task's own new test files add, minus nothing) with zero
  duplicate-collection warnings.)

- [x] **Step 7 (dev) — README accuracy (M-8).** In root `README.md`, replace the "Adding a
  service" paragraph's closing sentence (currently: "Add `billing` to `SERVICES` to boot it;
  no file inside `services/host/` needs to change.") with two sentences that keep the true
  part and fix the false part — e.g.: "Add `billing` to `SERVICES` to boot it; no file inside
  `services/host/` needs to change for that. Before it is a real, permanent service, add
  `services/billing/src` to `phpstan.neon.dist`'s `paths` and to `deptrac.yaml`'s `paths` +
  a `Service_Billing` layer + ruleset entry — `svc:make` prints this reminder, and
  `QaToolchainRegistryCoverageTest` fails the gate if it is forgotten." Replace
  `services/host/README.md` (still the stock `composer create-project laravel/laravel`
  file, MIT statement) with a short project-specific one: what this app is (the FlexiPOS
  service host), a link back to the root `README.md`, the `composer check` command, and
  `"license": "proprietary"` stated to match `composer.json`. This is a one-time hand edit
  of committed content — not written by `svc:make`, so it does not touch AC-27 (see the
  "AC-27 boundary" note above). (Covers FP-151, AC-14, AC-15. At risk: T-001 AC-3 — confirm
  the root README still states purpose + the top-level layout table and still links
  `CLAUDE.md`, `docs/architecture.md`, `docs/backlog.md`, all unchanged by this edit.)

- [x] **Step 8 (dev) — Extend PHPStan and Deptrac to service test code (FP-152, dev
  half).**
  1. **PHPStan (AC-16).** Add `../demo/tests` to `phpstan.neon.dist`'s `paths` (packages
     have no test directories yet; `services/host`'s own `tests/` is deliberately left out
     of this addition — it includes files `qa` owns, and adding it now would risk a *new*
     PHPStan level-6 finding surfacing inside a `Qa*.php` file `dev` is not allowed to fix
     by editing it; out of scope for this task). Run `composer stan` immediately after this
     one-line change, in isolation, before any other Step 8 edit; if it surfaces a real
     finding inside `services/demo/tests/Feature/PingRouteTest.php`, fix it in the **stub
     template** (`services/host/stubs/service/PingRouteTest.php.stub`) and in the committed
     `services/demo/tests/Feature/PingRouteTest.php` together, so they do not drift — Step
     10's new regression test (FP-153) will catch it if they do. Prove AC-16 itself with a
     temporary probe: add a deliberate level-6 violation inside
     `services/demo/tests/Feature/` (e.g. an untyped parameter), confirm `composer stan`
     exits non-zero and names it, remove the probe, confirm green again.
  2. **Deptrac (AC-17) — revised from my first draft of this plan.** Add `../demo/tests` to
     `deptrac.yaml`'s `paths` too. **No new layer or ruleset entry** — see judgement call 10:
     Deptrac's `Service_Demo` layer matches by namespace (`^Flexipos\\Service\\Demo\\.*`),
     not by directory, so a class declared under that namespace inside `tests/` is already
     covered. Prove it with a temporary, class-based probe (the same idiom
     `DeptracBoundaryEnforcementTest.php` already uses for AC-15, extended in place — add a
     new `it(...)` block to that file, do not create a second Deptrac test file): write
     `services/demo/tests/Feature/DeptracProbeTest.php` declaring
     ```php
     namespace Flexipos\Service\Demo\Tests;

     use App\Support\ServiceHost\ServiceRegistry;

     class Probe
     {
         public function borrow(ServiceRegistry $registry): ServiceRegistry
         {
             return $registry;
         }
     }
     ```
     (a `use` + type-hint on `HostApp`'s `App\` namespace, matching exactly the shape T-001's
     own QA already proved trips a violation against the shipped, unmodified `deptrac.yaml`
     for a `src/` file — reusing a proven pattern here rather than an untested variant); run
     `[PHP_BINARY, base_path('vendor/bin/deptrac'), 'analyse', '--config-file='.base_path('deptrac.yaml'), ...]`
     (the same real, shipped config the existing "exits zero on the shipped configuration"
     test in that file already runs against) via `Process`, assert non-zero exit and output
     naming `Service_Demo` and `HostApp`; delete the probe file, re-run, assert exit 0 and
     `Violations           0` again. This is the one place in this plan that temporarily adds
     a file under `services/demo/tests/` — clean it up in `afterEach()`, and note the mild
     coordination risk with Step 10's `SvcMakeDemoRegenerationTest.php`, which also touches
     `services/demo` (see Risks).
  (Covers FP-152's dev half, AC-16, AC-17. At risk: T-001 AC-9, AC-12, AC-14 per the spec's
  own FP-152 regression line — `composer lint`/`stan`/`deptrac` must all stay green on the
  shipped, unmodified tree once test directories are included; T-001 AC-16 — test discovery
  unaffected by these two `paths` additions.)

- [ ] **Step 9 (qa) — Narrow the coverage guard's fixture skip rule (FP-152, qa half,
  AC-18).** Owned entirely by `qa`, in
  `services/host/tests/Feature/QaToolchainRegistryCoverageTest.php` — `dev` does not touch
  this file. The guard currently skips any package whose name *contains* `fixture`, which a
  legitimately-named future service could also match (the review's own example, and the
  spec's own `data-fixtures` example). `architect`'s direction, not a mandated
  implementation, per D-23: replace the substring match with something anchored to QA's own
  throwaway-fixture naming convention (e.g. an explicit list of the exact names QA's tests
  generate, or a stricter, documented prefix/suffix convention only QA's own generated
  packages use) so a real service is never silently exempted from the coverage guard. `qa`
  proves AC-18 with a probe package whose name contains "fixture" as a real word but is not
  one of QA's own reserved names, and re-runs the full suite after this change.

- [x] **Step 10 (dev) — Demo regeneration regression test (FP-153).** New
  `tests/Feature/SvcMakeDemoRegenerationTest.php`. This is the one test in this plan that
  manipulates real committed content — implement carefully, wrapped in `try { ... } finally
  { ... }` inside the test body (not relying on `afterEach` alone):
  1. Move the real `services/demo` directory aside to a scratch path outside `services/`
     (e.g. `storage_path('app/demo-regen-backup')`), not delete it.
  2. `Artisan::call('svc:make', ['name' => 'demo'])` — assert exit 0.
  3. Recursively diff every file under the freshly generated `services/demo` against the
     backup — assert byte-identical file lists and byte-identical contents (mirrors exactly
     what QA proved by hand for T-001's AC-31).
  4. In `finally`: delete the freshly generated `services/demo`, move the backup back to
     `services/demo`. This restoration must run even if step 3's assertion fails, so a
     failing test never leaves the working tree without a `services/demo`.
  Run this test in isolation first (`vendor/bin/pest --filter=SvcMakeDemoRegenerationTest`)
  and confirm `git status` shows no diff on `services/demo` afterward, before ever running it
  as part of the full suite. (Covers FP-153, AC-19. At risk: none structurally — this test
  only reads and temporarily relocates existing content; the real risk is operational, see
  Risks — including the Step 8/Step 10 coordination note above.)

- [x] **Step 11 (dev) — Full gate and T-001/T-002 regression pass.** From `services/host`,
  run `composer check` (lint → stan → deptrac → test) from a clean state (`config:clear`
  first, matching the `test` script's own first line) and confirm exit 0. Then specifically
  re-confirm, by name, every T-001 acceptance criterion a T-002 step's own regression line
  names as at risk: T-001 AC-3 (Step 7); AC-9, AC-12, AC-14, AC-16 (Step 8); AC-16, AC-26
  (Step 6); AC-18, AC-19, AC-20, AC-21, AC-22, AC-23, AC-24, AC-25, AC-26, AC-27, AC-28,
  AC-29, AC-32 (Steps 1-4); AC-31 (Step 10, plus `qa`'s own manual re-check if `qa` chooses
  to). Confirm all nineteen of this plan's own AC-1..AC-19 pass together, not just
  individually per step (in particular: AC-2/AC-3's namespace trust, Step 2's autoloader
  rewrite, and AC-9/AC-10's cache guard all touch the same boot path — run the full suite,
  not a per-step subset, before calling this step done). This is not a new test file — it is
  the closing verification pass before handing to `qa`, exactly as T-001's own Step 13 was.

## Test strategy

- **Unit-level**: none needed — same as T-001, all behaviour here is boot/generation-time
  orchestration, proven through Pest feature tests.
- **Feature (Pest)**: one new file per hardening behaviour (Steps 1, 3, 5, 6, 10), matching
  T-001's existing convention of one file per boot-mechanism concern. `HealthEndpointTest.php`
  and `DeptracBoundaryEnforcementTest.php` are extended in place (Steps 4, 8) because both
  are already `dev`-owned from T-001 and the new cases are a natural extension of what they
  already test, not a new concern; `SvcMakeValidationTest.php` likewise (Step 1's AC-4
  CLI-level half).
- **Process-shelling integration tests** (Steps 5, 8): `ConfigCacheGuardTest.php` shells out
  to real `artisan config:cache`/`config:clear`/`route:list` child processes; the extended
  `DeptracBoundaryEnforcementTest.php` shells a real `vendor/bin/deptrac analyse` child
  process against the shipped `deptrac.yaml` after adding a temporary probe file under
  `services/demo/tests/`. Both follow the same `PHP_BINARY` + `Process::path()` pattern
  already established by that file and `PintLintFixRoundTripTest.php`, and both must restore
  real, shared state in a `finally` (the cached config file; the probe file and, for Step
  10's neighbouring test, the whole `services/demo` directory). Flagged in Risks as the
  tests in this plan with the largest blast radius if cleanup is skipped.
- **Reading, not editing, QA's files**: Step 1 explicitly requires `dev` to read (never edit)
  `QaServiceRegistryContractTest.php` to check whether the new manifest-trust checks change
  which line an existing malformed-manifest fixture fails at, and to message `qa` rather than
  patch it directly if so.
- **AC → risk map**: see each step's "At risk" line above; Step 11 is the single point where
  every one of them is re-confirmed together via `composer check`.

## Risks & rollback

- **R-1 `ConfigCacheGuardTest` mutates a real file on disk**
  (`bootstrap/cache/config.php`) rather than only in-memory test state. Step 5 specifies a
  `finally`-block and pre-flight assertion (no leftover cache file) precisely because a
  half-finished run here is worse than a normal failed assertion — it can break the *next*
  `composer test` run for every other test, not just this one. Run this test in isolation
  first (`--filter`), and confirm `bootstrap/cache/config.php` is absent afterward, before
  trusting it in the full suite.
- **R-2 `SvcMakeDemoRegenerationTest` (Step 10) and the Deptrac test-code probe (Step 8)
  both temporarily touch `services/demo`.** Step 10 moves the whole directory aside and
  back; Step 8 adds and removes one file inside `services/demo/tests/Feature/`. Run each in
  isolation first and confirm `git status` shows no diff on `services/demo` afterward before
  trusting either in the full suite; if both ever ran concurrently (not the case with Pest's
  default sequential execution, but worth stating), Step 10 moving the directory away mid-run
  would break Step 8's probe cleanup. Sequence them in the same order as this plan (Step 8
  before Step 10) during development so a failure is easier to attribute.
- **R-3 Step 1's manifest-trust rule can shift which line a QA malformed-manifest fixture
  fails at.** `dev` must not edit `QaServiceRegistryContractTest.php`; message `qa` with
  specifics per Step 1. If unresolved after `qa`'s own pass, this is normal
  dev<->qa<->architect traffic, not an escalation trigger.
- **R-4 Step 8's new PHPStan/Deptrac paths could surface a real, pre-existing finding** in
  `services/demo/tests/Feature/PingRouteTest.php` (never analysed or boundary-checked
  before). Not expected to be severe (a Pest closure file with no class declarations gives
  PHPStan little to flag and gives Deptrac nothing to collect at all), but not asserted here
  without running it — Step 8 explicitly directs `dev` to run `composer stan` and
  `composer deptrac` immediately after each one-line `paths` addition, in isolation, and fix
  forward in the stub + committed demo together if anything appears.
- **R-5 `route:cache` remains broken** (stock closure route in `routes/web.php`) after this
  task. Explicitly ruled out of scope (task brief excludes "container/CI parts of FP-149";
  spec's AC-11 item 3 and D-20 confirm "documented only"); documented in
  `config/servicehost.php`'s comment block and the README per Step 5 so it is a known,
  written-down gap, not a silent one, for whoever picks up Docker/CI work later.
- **R-6 Backlog scope discipline.** FP-143 (Deptrac package migration), FP-144/FP-145 (ERP
  scope), and the Minor items that did *not* get their own FP row (killed-run fixture
  residue, `servicePath()`'s unvalidated string concatenation, the DB connection's
  root/global user fallback, the unauthenticated readiness endpoint, remaining stock Laravel
  scaffolding beyond the one README, the `.dist` filename note) are all explicitly out of
  scope for this plan, matching the task brief. `dev` should resist folding any of them in
  even where the code is already open for a related edit.
- **R-7 Judgement call reversal (M-9).** This plan's first draft added runtime
  classmap-authoritative detection code before the spec existed; the final version removes
  it to match AC-11 item 4 / D-20 ("documented only"). If `qa` or `reviewer` independently
  expected code here (e.g. from reading the T-001 review's own "consider detecting it" line
  in isolation, without the spec/decisions file), point them at AC-11 item 4 and D-20 rather
  than re-adding it unilaterally — a second reversal goes through `ba`/`architect`, not a
  silent code change.
- **Rollback**: every step is independently revertible — Steps 1-2 touch one file
  (`ServiceRegistry.php`) but two distinct, separately-tested methods; Steps 4-7 touch
  disjoint files; a step that fails review can be reverted without unwinding the others,
  same as T-001's own step structure.

## Questions for the human

None. Every judgement call the task asked for is ruled on above with the codebase's own
existing patterns and this machine's verified installed behaviour as the basis, not
preference, and cross-checked against `ba`'s landed spec and decisions file (one gap found
and corrected: AC-17's Deptrac test-code coverage in Step 8; one of my own initial rulings
reversed to match the spec: M-9/AC-11 item 4's "documented only," see judgement call 5 and
Risk R-7). Both of the spec's own "Open questions" (mechanism choices, left to `architect`)
are resolved in judgement calls 8 and 9. If `dev` or `qa` finds a further contradiction
between this plan and the spec while implementing, that goes to `architect` via the normal
channel, not to the human.
