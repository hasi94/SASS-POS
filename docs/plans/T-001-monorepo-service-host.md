# Plan: T-001 Monorepo, backend toolchain and service host skeleton

Spec: `docs/specs/T-001-monorepo-service-host.md` (AC-1..AC-32)
Decisions: `docs/decisions/T-001-monorepo-service-host.md` (D-1..D-11)
Single developer: all files below are owned by `dev`.

## Amendment (post-QA, BUG-1 / BUG-5 ruling)

QA (`docs/qa/T-001-monorepo-service-host.md`, Run 1: FAIL) found AC-26 does not hold:
`composer test` silently does not execute a freshly generated service's test, because
`phpunit.xml`'s `Services` testsuite listed `../demo/tests` literally. QA measured a
`<directory>../*/tests</directory>` glob as a working fix and asked `architect` to rule,
since it reverses this plan's original "no globs" stance, and to rule on whether the same
applies to `phpstan.neon.dist`/`deptrac.yaml.dist` (BUG-5).

**Ruling, empirically verified today** (scratch Composer project, `phpstan/phpstan:^2.2` and
`qossmic/deptrac:^2.0`, the exact versions this plan pins, fixture directories
`services/alpha/src`, `services/beta/src`, `packages/one/src`):

1. **`phpunit.xml` `<directory>` — glob accepted.** PHPUnit resolves `../*/tests` and
   `../../packages/*/tests` correctly; this is a different code path from PHPStan's/Deptrac's
   path resolution and does support wildcards. **The "no globs" decision is reversed for
   `phpunit.xml` only.** See updated `phpunit.xml` section below.
2. **`phpstan.neon.dist` `paths` — glob rejected, confirmed technically impossible.** Pointing
   `paths` at `services/*/src` fails immediately: `Path …\services\*\src does not exist`.
   PHPStan's `paths` resolver does not expand wildcards at all (unlike PHPUnit's).
3. **`deptrac.yaml.dist` `paths` — glob rejected, confirmed technically impossible.** Same
   experiment against Deptrac 2.0.4: `Path "…/services/*/src" is not a directory or is not
   readable` (`InvalidPathException`). Deptrac's `paths` resolver has the same limitation.
   Beyond `paths`, Deptrac's `layers` cannot be globbed *by design* even if `paths` could be:
   a layer is a named, ruleset-addressable architectural unit, not a filesystem match — one
   `classLike` collector per service is what makes "service A may not depend on service B"
   representable at all (this was already the "Alternatives rejected" reasoning against a
   single combined `Flexipos\Service\*` layer; it applies equally to a hypothetical globbed
   layer generator, which Deptrac doesn't offer anyway).

**Conclusion: this is not a follow-up backlog row.** It is a permanent, structural
constraint of the two tools as they exist today, not a temporary gap closed by adopting a
technique later — there is no glob syntax to adopt. The literal-path maintenance burden for
`phpstan.neon.dist` and `deptrac.yaml.dist` (one `paths` line, and for Deptrac one layer +
one ruleset line, per new service/package) stays exactly as this plan originally specified.
What changes is that BUG-5's "silent" danger is closed the same way BUG-1's was: QA's
`QaToolchainRegistryCoverageTest` (already written, already passing) stays in the suite
permanently as the enforcement backstop — a missed `paths`/layer registration now fails the
gate loudly instead of passing green with a real service unanalysed. `dev` should also add
one line to `svc:make`'s success message reminding the operator to add the new
`paths`/layer/ruleset entries (cheap, closes the loop for a human who isn't yet relying on
the guard test) — this is a wording-only change to the command's own output, not a file edit
outside `services/<name>/`, so it does not touch AC-27.

**Incidental finding, not part of this ruling, flagged for a separate fast-follow:**
`composer install` reports `qossmic/deptrac` is abandoned upstream in favour of
`deptrac/deptrac` (`deptrac/deptrac` 4.7.2 requires `php:^8.2`, same constraint, installs
fine on this machine). Do not fold that swap into the BUG-1/BUG-5 fix — it's an unrelated
package rename with its own config-compatibility check to do; log it as a new backlog note
for `dev`/`ba` to schedule separately.

## Approach summary

Greenfield monorepo skeleton. `services/host` is installed as a plain Laravel 12
application (`composer create-project laravel/laravel`, PHP `^8.2`, verified installable
on this machine's PHP 8.2.12). `packages/*` get composer.json stubs only. A single custom
class, `App\Support\ServiceHost\ServiceRegistry`, does all the work described in
architecture.md §4: it parses `SERVICES`, validates each name against the filesystem,
and — instead of relying on Composer's static `require` graph — **dynamically** registers
each service package's PSR-4 namespace with Composer's already-booted `ClassLoader`
(discovered via `spl_autoload_functions()`) and registers its service provider with
`$app->register()`. This is what makes `svc:make` + `SERVICES=<new-name>` work with **zero
edits to any file inside `services/host/`** (AC-27): the registry never depends on the
new package being listed in `composer.json`. Each service package still declares itself
normally as a Composer path package (own `composer.json`, own PSR-4 autoload, own
`extra.flexipos` block naming its provider and DB connection) so it can *also* be `composer
require`d later when a real, permanent business service is built — that formal `require`
step is what we do, once, for the committed `demo` package (needed for AC-8's "composer
install resolves the demo package" and for `composer.lock` to pin its dependency graph).
Toolchain: Pint (default Laravel preset), PHPStan level 6 + Larastan (Laravel-aware
rules), Deptrac 2.x (`deptrac:` root key, `classLike` collectors, one layer per service +
per `packages/*` package), Pest 3.8 + `pest-plugin-laravel`. All four gates are wired as
Composer scripts and chained by `composer check` (Composer's own script-array semantics
already stop at the first non-zero step, so no extra shell scripting is needed).

## Toolchain versions verified on this machine (PHP 8.2.12, Composer 2.8.4, packagist reachable)

| Package | Constraint to use | Verified via packagist p2 metadata |
|---|---|---|
| `laravel/laravel` (skeleton) | `^12.0` | latest 12.69.2 requires `php: ^8.2`; Laravel 13.x requires `^8.3` (won't install here) — pin 12, matches "current major that installs on 8.2" |
| `pestphp/pest` | `^3.8` | 3.8.7 requires `php: ^8.2.0`; Pest 4/5 require `php: ^8.4` (too new, excluded) |
| `pestphp/pest-plugin-laravel` | `^3.2` | 3.2.0 requires `php: ^8.2.0` |
| `laravel/pint` | default (no explicit constraint beyond what `laravel/laravel` pulls) | versions ≤1.30.4 require `php: ^8.2.0`; ≥1.31 require `^8.3.0`. Composer's platform check will not select ≥1.31 when run under 8.2.12, so no explicit pin is needed — but see Risk R-1 |
| `larastan/larastan` | `^3.0` | 3.12.x requires `php: ^8.2` |
| `qossmic/deptrac` | `^2.0` | 2.0.4 requires `php: ^8.1` |

**Rule for `dev`: always run `composer install`/`composer update` in `services/host` using
`"E:\xampp\php\php.exe" "C:\ProgramData\ComposerSetup\bin\composer.phar" ...` (the XAMPP
8.2.12 binary), never a PHP 8.3 binary, so `composer.lock` is resolved against the lower
PHP-version bound. A lock resolved on 8.2 stays installable under Docker's PHP 8.3 later
(8.3 satisfies `^8.2`); a lock resolved on 8.3 first could pick newer sub-dependencies
(e.g. Pint ≥1.31) that then fail to install back on XAMPP's 8.2.12.**

## Decision: `/health/ready` field name (the one thing left open by the spec)

```json
GET /health/live  -> 200 { "status": "ok" }
GET /health/ready -> 200 { "status": "ok", "services": ["demo"] }
GET /health/ready -> 200 { "status": "ok", "services": [] }        // SERVICES empty (AC-23)
```

Field name is **`services`**, value is a flat array of booted service identifier strings,
**sorted alphabetically, deduplicated**. This matches the spec's own illustrative example
(`["demo"]`) exactly, so `qa` can assert `expect($json['services'])->toBe(['demo'])` with
no ambiguity. (Rejected: an array of `{"name": ...}` objects for future extensibility —
more "correct" for later per-service health depth, but the spec explicitly scopes that to
FP-012/FP-008; adding fields later stays additive either way since `status`/other keys can
be added at the top level without touching `services`. Simplicity wins for a skeleton.)

## Alternatives rejected

- **Composer `require` per service, edited by `svc:make`.** Rejected because it directly
  contradicts the architect brief's stated preference and AC-27 ("no file inside
  `services/host/` edited" when toggling `SERVICES`). Runtime PSR-4 registration is a
  well-established technique (used by Laravel module/plugin packages) and lets a package
  be requirable later without changing how boot works.
- **Wildcard PSR-4 mapping in `services/host/composer.json`** (e.g. `"Flexipos\\Services\\":
  "../"`) instead of per-package dynamic `addPsr4`. Rejected: requires the on-disk
  directory name to case-match the namespace segment, which breaks on Linux's
  case-sensitive filesystem (Docker/CI) even though it silently "works" on Windows/XAMPP.
- **Deptrac layer defined once with a single regex covering all of `Flexipos\Service\*`.**
  Rejected: collapses every service into one layer, so it can never detect a
  service-to-service violation (AC-13/AC-15 require *per-service* layers).
- **Glob entries in `phpstan.neon.dist`/`deptrac.yaml.dist` `paths`** (e.g. `../*/src`)
  instead of listing each package/service path literally. Rejected, now with an empirical
  test to back it rather than caution alone (see "Amendment" above): both tools reject a
  `*` wildcard outright (`Path … does not exist` / `InvalidPathException`). Literal paths
  also match the deliberate, low-magic maintenance pattern already used for the layer list,
  and the omission risk is now covered by a permanent guard test
  (`QaToolchainRegistryCoverageTest`) rather than left to memory.
- **Glob entry in `phpunit.xml`'s `Services`/`Packages` testsuites** — *originally* rejected
  alongside the two above for the same "undocumented, don't gamble" reasoning. **Reversed**
  after QA measured it working and I re-verified PHPStan/Deptrac still reject it: PHPUnit's
  `<directory>` resolution is not the same code path and does expand wildcards. See
  "Amendment" above.
- **Static, permanently-committed second "unlisted" package for AC-24.** Rejected in favour
  of generating-and-deleting it inside the test itself via the real `svc:make` command:
  this also doubles as an integration test of the generator, and avoids a confusing
  second sample package sitting in the tree forever.

## Files to change / create

All under `D:\Flexiata\NEW-FLEXIPOS`, all owned by `dev`.

### Root
- `README.md` — new. Purpose, top-level layout, links to `CLAUDE.md`, `docs/architecture.md`, `docs/backlog.md`.
- `.editorconfig` — new.
- `.gitignore` — new (root-level; covers `vendor/`, `node_modules/`, `.env`, `.phpunit.cache/`, IDE/OS files).
- `gateway/.gitkeep`, `frontend/.gitkeep`, `print-agent/.gitkeep`, `plugins/.gitkeep`, `infra/.gitkeep` — new, empty placeholders (AC-1, D-11).
- `CLAUDE.md` — **do not touch** (AC-4).

### packages/* (7 stub packages, AC-2, D-10)
For `<pkg>` in `contracts, kernel, eventbus, auth, http-client, plugin-sdk, testing`:
- `packages/<pkg>/composer.json` — new. `"name": "flexipos/<pkg>"`, `"type": "library"`,
  `"require": {"php": "^8.2"}`, `"autoload": {"psr-4": {"Flexipos\\<Studly>\\": "src/"}}}`
  (`<Studly>` = `Str::studly($pkg)`, e.g. `http-client` → `HttpClient`).
- `packages/<pkg>/src/.gitkeep` — new (empty dir placeholder; no business logic).

### services/host (Laravel 12 app)
- Created by `composer create-project laravel/laravel services/host "^12.0" --prefer-dist --no-interaction` — full default Laravel skeleton, not hand-authored file by file.
- `services/host/composer.json` — edit: `name` → `flexipos/host`; `require.php` → `^8.2`;
  add `repositories` (`{"type":"path","url":"../../packages/*"}`, `{"type":"path","url":"../*"}`);
  add `require`: `flexipos/contracts`, `flexipos/kernel`, `flexipos/eventbus`,
  `flexipos/auth`, `flexipos/http-client`, `flexipos/plugin-sdk` (all `"*"`, path-resolved);
  add `require-dev`: `larastan/larastan:^3.0`, `qossmic/deptrac:^2.0`, `flexipos/testing:"*"`,
  and later (Step 8) `flexipos/service-demo:"*"`; confirm `pestphp/pest` (^3.8) and
  `pestphp/pest-plugin-laravel` present (Laravel+Pest installers usually add these; verify
  version, downgrade constraint if the installer picked something newer than 3.x);
  add `scripts`: `lint`, `lint:fix`, `stan`, `deptrac`, `test`, `check` (below).
- `services/host/phpstan.neon.dist` — new. Level 6, explicit `paths` list, `includes:
  vendor/larastan/larastan/extension.neon`.
- `services/host/deptrac.yaml.dist` — new. `deptrac:` root key, one `classLike` layer per
  service + per `packages/*` package, ruleset allowing each service layer to depend only on
  `Package_*` layers.
- `services/host/.env.example` — edit: add `SERVICES=demo`.
- `services/host/phpunit.xml` — edit: add `<env name="SERVICES" value="demo"/>` (see BUG-4
  for the `force="true"`/`$_SERVER` caveat, owned by `dev`, not re-litigated here); add two
  **self-maintaining** testsuites — `Services` → `<directory>../*/tests</directory>`,
  `Packages` → `<directory>../../packages/*/tests</directory>` (post-QA amendment above; ***
  no per-package `<directory>` line is ever added again***; `../*/tests` also matches
  `../host/tests` itself, which PHPUnit de-duplicates against the existing `Unit`/`Feature`
  testsuites — verified by QA, harmless).
- `services/host/tests/Pest.php` — edit: bind `Tests\TestCase` for every directory the two
  testsuites above can match. Because Pest refuses to bind the same class twice to the same
  path, and because a generated service's own test stub already does its own
  `uses(Tests\TestCase::class)` (see the `svc:make` generator section — this is what makes
  AC-26 self-contained), `tests/Pest.php` itself only needs
  `uses(Tests\TestCase::class)->in(__DIR__)` for the host's own tests; do not add a
  `../../demo/tests` line here (this reverses the original plan text, which predated the
  glob decision and duplicated what the generated stub already does).
- `services/host/config/servicehost.php` — new. `return ['services' => env('SERVICES', '')];`
- `services/host/app/Support/ServiceHost/ServiceRegistry.php` — new. Parsing, validation,
  dynamic PSR-4 registration, provider registration, `booted()` accessor (see mechanism
  below).
- `services/host/app/Support/ServiceHost/UnknownServiceException.php` — new. Thrown by
  `ServiceRegistry` for AC-19.
- `services/host/app/Providers/ServiceHostServiceProvider.php` — new. Calls
  `ServiceRegistry::bootAll(config('servicehost.services'))` from `register()`.
- `services/host/bootstrap/providers.php` — edit: add `App\Providers\ServiceHostServiceProvider::class`.
- `services/host/app/Http/Controllers/HealthController.php` — new. `live()`, `ready()`.
- `services/host/routes/web.php` — edit: add `GET /health/live`, `GET /health/ready`.
- `services/host/app/Console/Commands/SvcMakeCommand.php` — new. `svc:make {name}` (Laravel
  11+/12 auto-discovers commands under `app/Console/Commands`, no Kernel edit needed).
- `services/host/stubs/service/composer.json.stub` — new.
- `services/host/stubs/service/ServiceProvider.php.stub` — new.
- `services/host/stubs/service/routes.api.php.stub` — new.
- `services/host/stubs/service/PingController.php.stub` — new.
- `services/host/stubs/service/connection.config.php.stub` — new.
- `services/host/stubs/service/PingRouteTest.php.stub` — new.
- `services/host/tests/Fixtures/DeptracViolation/deptrac.yaml` — new (Step 10, AC-15).
- `services/host/tests/Fixtures/DeptracViolation/ServiceA/Widget.php` — new.
- `services/host/tests/Fixtures/DeptracViolation/ServiceB/Gadget.php` — new.
- `services/host/tests/Feature/DeptracBoundaryEnforcementTest.php` — new (Step 10, AC-15).
- `services/host/tests/Feature/UnlistedServiceReturns404Test.php` — new (Step 11, AC-24).
- `services/host/tests/Feature/SvcMakeGeneratedServiceReachableTest.php` — new (Step 11, AC-27).
- `services/host/tests/Feature/PintLintFixRoundTripTest.php` — new (Step 12, AC-10).
- `services/host/tests/Feature/HealthEndpointTest.php` — new (Step 6, AC-22/AC-23).
- `services/host/tests/Feature/ServicesEnvParsingTest.php` — new (Step 5, AC-18/19/20/21).
- `services/host/tests/Feature/SvcMakeValidationTest.php` — new (Step 7, AC-28/29/30).

### services/demo (generated by `svc:make demo`, then committed, AC-31)
- `services/demo/composer.json`, `services/demo/src/DemoServiceProvider.php`,
  `services/demo/src/Http/Controllers/PingController.php`,
  `services/demo/src/{Models,Services,Consumers,Hooks}/.gitkeep`,
  `services/demo/routes/api.php`, `services/demo/database/migrations/.gitkeep`,
  `services/demo/tests/Feature/PingRouteTest.php` — all generated by the command built in
  Step 7, committed as-is (AC-26/AC-32 proven by this generated test).

## Data / API changes

- New config key: `SERVICES` (env, comma-separated service names; empty/unset = valid,
  D-2).
- New endpoints: `GET /health/live` → `{"status":"ok"}`; `GET /health/ready` →
  `{"status":"ok","services":[...]}` (shape fixed above).
- New per-service convention (contract every future service package must satisfy, read by
  `ServiceRegistry`):
  - `composer.json` → `autoload.psr-4` has exactly one namespace → `"src/"` mapping.
  - `composer.json` → `extra.flexipos.provider` = fully-qualified service provider class
    name (custom key; deliberately **not** `extra.laravel.providers`, so Laravel's own
    package auto-discovery never double-boots a service regardless of `SERVICES`).
  - `composer.json` → `extra.flexipos.connection` = DB connection name, convention
    `svc_<name>` (e.g. `svc_demo`), schema name convention `pos_<name>` (matches
    architecture.md §6); connection array merged into `config('database.connections')` by
    the service's own provider via `mergeConfigFrom()` — no host file touched.
  - Provider registers routes under prefix `/api/v1/<name>` (D-9 default) and loads
    `database/migrations/` (may be empty).
- New generator: `php artisan svc:make <name>` (name must match `^[a-z][a-z0-9-]*$`,
  D-5; rejects `host`, D-4; rejects existing names, AC-29).

## Host boot mechanism (detail for `dev`)

`ServiceRegistry`:
1. `parse(string $raw): array` — split on `,`, `trim()` each, drop empty strings, dedupe,
   **sort alphabetically** (deterministic `/health/ready` output). Empty/unset `SERVICES`
   → `[]` (D-2/D-8/AC-20/AC-21).
2. `bootAll(string $raw): void` — for each parsed name already in `$this->booted`, skip
   (idempotent, safe to call twice — needed by the AC-27 test, which calls it again mid-test
   after generating a new package). For each new name:
   - Reject `host` and anything without `services/<name>/composer.json` containing an
     `extra.flexipos.provider` key by throwing `UnknownServiceException` with the offending
     name in the message (AC-19) — **during `register()`, i.e. before routes are dispatched
     (fail fast at boot, not lazily on first request)**.
   - Read that `composer.json`, take its single `autoload.psr-4` pair
     (`$namespace => "src/"`), resolve absolute path `services/<name>/src`.
   - Get Composer's `ClassLoader` via
     `collect(spl_autoload_functions())->first(fn ($fn) => is_array($fn) && $fn[0] instanceof \Composer\Autoload\ClassLoader)[0]`
     and call `addPsr4($namespace, $absoluteSrcPath)`.
   - `$this->app->register($providerClassFromExtraFlexipos)`.
   - Append `$name` to `$this->booted` (kept sorted).
3. `booted(): array` — returns the sorted, deduped list for the health controller.

`ServiceHostServiceProvider::register()` calls
`$this->app->singleton(ServiceRegistry::class)` then
`$this->app->make(ServiceRegistry::class)->bootAll(config('servicehost.services', ''))`.

This keeps boot cost at "read N small JSON files, N = count(SERVICES)" — no directory
scan of all of `services/*`, so an unlisted package costs nothing at boot (AC-24 is then
just "no route was ever registered for it," no special-case code needed).

## `svc:make` generator (detail for `dev`)

`SvcMakeCommand::handle()`:
1. Validate `$name` against `/^[a-z][a-z0-9-]*$/` → else `error()` + `return self::FAILURE`
   **before touching the filesystem** (AC-28).
2. Reject `$name === 'host'` → `error('host is a reserved name')` + `return self::FAILURE`
   (AC-30, D-4).
3. Reject `File::exists(base_path("../{$name}"))` → `error("services/{$name} already
   exists")` + `return self::FAILURE`, no files touched (AC-29).
4. `$studly = Str::studly($name)` for the PHP namespace segment and class names.
5. Render the six stubs (listed above) into `services/<name>/...`, substituting `{{name}}`,
   `{{studly}}`, `{{connection}}` (`svc_<name>`), `{{prefix}}` (`api/v1/<name>`).
6. Create empty `src/Models/.gitkeep`, `src/Services/.gitkeep`, `src/Consumers/.gitkeep`,
   `src/Hooks/.gitkeep`, `database/migrations/.gitkeep` (AC-25's Consumers/migrations
   requirement, plus the two extra structural dirs architecture.md §5 lists for symmetry).
7. `info("Created services/{$name}. Add '{$name}' to SERVICES to boot it. Also add
   'services/{$name}/src' to phpstan.neon.dist and deptrac.yaml.dist (paths, plus a new
   Deptrac layer + ruleset entry) so it is statically analysed and boundary-checked — see
   QaToolchainRegistryCoverageTest for the gate that catches a missed step.")` — post-QA
   addition (BUG-5 ruling): this is a wording-only change to the command's own console
   output, writes no file outside `services/<name>/`, and does not touch AC-27.

Generated `composer.json.stub` does **not** touch `services/host/composer.json` — nothing
in the command writes outside `services/<name>/` (AC-27's "no edit" guarantee holds by
construction, not by discipline).

Generated `PingRouteTest.php.stub` boots the real app (`services/host`'s `Tests\TestCase`)
and asserts `GET /api/v1/{{name}}/ping` → 200 + JSON containing `service => {{name}}`. For
this to pass **immediately** for a package that isn't yet in `.env`'s `SERVICES`, the test
must call `app(ServiceRegistry::class)->bootAll('{{name}}')` in its own `beforeEach()`
rather than relying on the ambient `.env` — this is what makes AC-26 ("passes out of the
box, no further edits") true for *any* generated name, not just `demo`.

## Deptrac layers and ruleset (detail for `dev`)

One `classLike` layer per **namespace root**, not per directory:
`HostApp` (`^App\\.*`), `Service_Demo` (`^Flexipos\\Service\\Demo\\.*`), and
`Package_Contracts` / `Package_Kernel` / `Package_Eventbus` / `Package_Auth` /
`Package_HttpClient` / `Package_PluginSdk` / `Package_Testing` (`^Flexipos\\<Studly>\\.*`
each). Ruleset: `HostApp` and `Service_Demo` may depend on any `Package_*` layer, never on
each other's or another service's layer; `Package_*` layers get `~` (depend on nothing).
**Every future real service package needs one new layer + one new ruleset entry added to
`deptrac.yaml.dist`, and one new `paths` line in both `deptrac.yaml.dist` and
`phpstan.neon.dist`.** Post-QA (see "Amendment" above): this is *confirmed permanent*,
empirically-verified-impossible-to-glob maintenance, not a temporary gap — `phpunit.xml`'s
two testsuites are the only registry of the three that no longer needs this. The omission
is caught by `QaToolchainRegistryCoverageTest` (red gate) and `svc:make`'s own reminder
message, not left to memory alone.

## Health endpoint shape

See "Decision" section above — fixed now so `dev` and `qa` do not diverge.

## Implementation steps

- [x] **Step 1 — Root monorepo skeleton.** Create `README.md`, `.editorconfig`,
  `.gitignore`, and `.gitkeep` in `gateway/`, `frontend/`, `print-agent/`, `plugins/`,
  `infra/`. Do not create `services/` or `packages/` placeholders — they get real content
  in Steps 2–3. (AC-1, AC-3, AC-5; verify AC-4 by inspection only.)

- [x] **Step 2 — `packages/*` stubs.** Create the 7 `packages/<pkg>/composer.json` +
  `src/.gitkeep` pairs per the table above. (AC-2, D-10.)

- [x] **Step 3 — Install `services/host`.** Run
  `"E:\xampp\php\php.exe" "C:\ProgramData\ComposerSetup\bin\composer.phar" create-project laravel/laravel services/host "^12.0" --prefer-dist --no-interaction`.
  Confirm `php -v` via the documented path still reports 8.2.12+ (AC-7, already verified by
  `architect`, re-confirm locally). (AC-7; scaffolds AC-6's app.)

- [x] **Step 4 — Composer workspace + dev tooling.** Edit `services/host/composer.json`
  (name, `require.php`, `repositories`, `require` for `packages/*`, `require-dev` for
  `larastan/larastan`, `qossmic/deptrac`, `flexipos/testing`; verify/pin
  `pestphp/pest:^3.8` + `pestphp/pest-plugin-laravel:^3.2`; add the six `scripts`). Create
  `phpstan.neon.dist` and `deptrac.yaml.dist` (paths = `app` + the 7 `packages/*/src`, no
  service layer yet — added in Step 9). Run `composer install` with the XAMPP PHP; confirm
  `composer.lock` is produced. (AC-6, AC-9, AC-11, AC-13 partial, AC-17 script wiring;
  partial AC-8 — full AC-8 completes in Step 8.)

- [x] **Step 5 — `SERVICES` parsing and dynamic boot.** Create `config/servicehost.php`,
  `ServiceRegistry`, `UnknownServiceException`, `ServiceHostServiceProvider`; register it in
  `bootstrap/providers.php`. Write `tests/Feature/ServicesEnvParsingTest.php` covering: valid
  single name boots only that provider (AC-18); unknown name fails fast with the name in the
  message (AC-19); empty/unset `SERVICES` boots with zero services, HTTP still up (AC-20);
  `" demo , demo "` registers once, no duplicate-route error (AC-21) — this last one needs at
  least one throwaway fixture package generated via `Artisan::call('svc:make', ...)` inside
  the test (Step 7 must land first — see ordering note below) *or* can initially be tested
  against `demo` once Step 8 lands; if run before Step 8, stub this one assertion with a
  fixture package generated inline. (AC-18, AC-19, AC-20, AC-21.)

- [x] **Step 6 — Health endpoints.** Create `HealthController`, add the two routes to
  `routes/web.php`, write `tests/Feature/HealthEndpointTest.php` asserting the exact shape
  from the Decision section for both the empty-`SERVICES` case and (once Step 8 lands) the
  `demo`-booted case. (AC-22, AC-23.)

- [x] **Step 7 — `svc:make` command + stubs.** Create `SvcMakeCommand` and the six stub
  files under `stubs/service/`. Write `tests/Feature/SvcMakeValidationTest.php` covering:
  invalid name → non-zero exit, no files created (AC-28); `host` → non-zero exit, no files
  (AC-30); re-running with an existing name (generate a throwaway fixture first) → non-zero
  exit, existing files untouched (AC-29). (AC-25 structural shape, AC-28, AC-29, AC-30.)

- [x] **Step 8 — Generate and commit `services/demo`.** Run `php artisan svc:make demo`.
  Commit the generated tree. Edit `services/host/composer.json` to add
  `"flexipos/service-demo": "*"` to `require-dev`; run `composer update flexipos/service-demo`
  (refresh `composer.lock`, completes AC-8). Edit `.env.example` (`SERVICES=demo`),
  `phpunit.xml` (`<env name="SERVICES" value="demo"/>`, `Services` testsuite directory),
  `tests/Pest.php` (`uses()->in()` for `services/demo/tests`). Run `composer test`; the
  generated `PingRouteTest.php` must pass unedited. (AC-8 (complete), AC-16 (partial),
  AC-25 (concrete instance), AC-26, AC-31, AC-32.)

- [x] **Step 9 — Extend Deptrac/PHPStan for `demo`.** Add `../demo/src` to both
  `phpstan.neon.dist` and `deptrac.yaml.dist` `paths`; add the `Service_Demo` layer +
  ruleset entry to `deptrac.yaml.dist`. Run `composer stan` and `composer deptrac`; both
  exit 0 on the shipped tree. (AC-11 (complete), AC-12, AC-13 (complete), AC-14.)

- [x] **Step 10 — Deptrac boundary-violation proof (AC-15).** Create the isolated
  `tests/Fixtures/DeptracViolation/` tree (its own `deptrac.yaml`, `ServiceA\Widget`,
  `ServiceB\Gadget` where `Gadget` imports `Widget`, ruleset forbidding it) and
  `tests/Feature/DeptracBoundaryEnforcementTest.php`, which shells out to
  `vendor/bin/deptrac analyse --config-file=tests/Fixtures/DeptracViolation/deptrac.yaml`
  via Laravel's `Process` facade and asserts a non-zero exit code and output naming both
  layers. Keep the fixture's own two PHP files Pint-clean (they're inside `services/host`,
  so the ordinary `composer lint` run will still lint them). (AC-15.)

- [x] **Step 11 — Reachability proofs for `svc:make` + `SERVICES` (AC-24, AC-27).** Write
  `tests/Feature/UnlistedServiceReturns404Test.php` (generate a throwaway package via
  `Artisan::call('svc:make', ...)`, do **not** add it to `SERVICES`, assert its route 404s,
  delete the generated directory in `afterEach()`) and
  `tests/Feature/SvcMakeGeneratedServiceReachableTest.php` (generate another throwaway
  package, call `app(ServiceRegistry::class)->bootAll($name)` directly instead of editing
  any file, assert its route 200s and it appears in `/health/ready`'s `services` array,
  delete the directory in `afterEach()`). (AC-24, AC-27.)

- [x] **Step 12 — Pint round-trip proof (AC-10) + full local pass.** Write
  `tests/Feature/PintLintFixRoundTripTest.php` (write a badly-formatted temp file under
  `storage/app/pint-fixture/`, outside Pint's normal scan paths so it never pollutes the
  repo; assert `pint --test <file>` fails, `pint <file>` fixes it, `pint --test <file>` then
  passes; delete the temp file in `afterEach()`). Run `composer lint`, `composer lint:fix`,
  `composer stan`, `composer deptrac`, `composer test` individually; fix anything the
  skeleton itself fails on. (AC-9, AC-10, AC-12 (final), AC-16 (final).)

- [x] **Step 13 — `composer check` end-to-end.** Run `composer check` from a clean
  `composer install`; confirm it runs lint → stan → deptrac → test in order and exits 0;
  confirm introducing a deliberate failure in any one stage makes `check` stop at that
  stage (manual spot-check, not a committed test — checking Composer's own script-chaining
  behaviour, not FlexiPOS code). (AC-17.)

- [x] **Step 14 — Fix BUG-1 (AC-26) and the `phpunit.xml` half of BUG-5, per the
  "Amendment" ruling above.** In `services/host/phpunit.xml`: replace the `Services`
  testsuite's literal `<directory>../demo/tests</directory>` with
  `<directory>../*/tests</directory>`; add a `Packages` testsuite with
  `<directory>../../packages/*/tests</directory>`. Re-run QA's own
  `QaGeneratedServiceTestDiscoveryTest` (currently RED) — it must go green. Re-run
  `composer test`; expect it to keep passing (32 → 35, matching QA's measurement, plus
  `QaGeneratedServiceTestDiscoveryTest` and any other now-passing QA tests). Also add the
  one-line reminder to `SvcMakeCommand`'s success message (see "`svc:make` generator"
  section above) so a human generating a *real* future service is told to register it with
  PHPStan/Deptrac, since that half of BUG-5 has no glob fix available. Do **not** touch
  `phpstan.neon.dist` or `deptrac.yaml.dist`'s `paths` format — confirmed impossible to
  glob, no change needed there; `QaToolchainRegistryCoverageTest` already guards it. Leave
  BUG-2 (routed to `ba`), BUG-3 and BUG-4 for their own fix cycles — out of scope for this
  step. (AC-26, BUG-1, BUG-5 phpunit portion.)

## Test strategy

- **Unit-level**: none needed beyond what Pest feature tests already cover — the skeleton
  has no domain logic yet.
- **Feature (Pest, inside `services/host/tests/Feature`)**: one file per boot-mechanism
  behaviour (Steps 5, 6, 7, 11, 12) as listed above; each maps 1:1 to the AC(s) named in
  its step.
- **Process-shelling integration tests** (Steps 10, 12) prove the *tooling itself*
  (Deptrac, Pint) enforces what it's configured to enforce — these are the only two ACs
  (AC-10, AC-15) that describe "introduce a violation, watch the tool fail" as their own
  acceptance test, so they get a durable, repeatable, git-committed test rather than a
  one-off manual check.
- **Generated-package self-test** (`services/demo/tests/Feature/PingRouteTest.php`,
  produced by Step 7's stub, exercised in Step 8): proves AC-26 and AC-32 together — the
  template is self-testing, and the concrete `demo` instance proves routing + DB-connection
  naming end to end.
- **Full-suite gate**: `composer check` (Step 13) is what `qa` and future CI (FP-019) call;
  every AC in this plan is exercised by the time `composer check` passes once, except AC-1
  through AC-5 (filesystem/document checks, verified by inspection, not Pest) and AC-7
  (environment fact, verified once by `architect` and re-confirmed by `dev`).
- **AC → test map**: AC-1/3/5 → manual inspection (Step 1). AC-2 → manual inspection (Step
  2). AC-4/7 → manual inspection (Steps 3, ongoing). AC-6/8/9/11/16/17 → `composer install`
  + script wiring (Steps 4, 8, 12, 13). AC-10 → `PintLintFixRoundTripTest`. AC-12/13/14 →
  `composer stan`/`composer deptrac` clean runs (Step 9). AC-15 →
  `DeptracBoundaryEnforcementTest`. AC-18–21 → `ServicesEnvParsingTest`. AC-22/23 →
  `HealthEndpointTest`. AC-24 → `UnlistedServiceReturns404Test`. AC-25/26/31/32 → generated
  `services/demo` + its own test. AC-27 → `SvcMakeGeneratedServiceReachableTest`. AC-28/29/30
  → `SvcMakeValidationTest`.

## Risks & rollback

- **R-1 Composer path-repo self-reference.** `services/host/composer.json`'s
  `{"type":"path","url":"../*"}` glob also matches `services/host` itself (harmless
  metadata cataloguing in every Composer version encountered so far, never actually
  required). If `dev` sees a resolution error or warning from this during Step 4/8's
  `composer install`, fall back to an explicit path entry per real, committed service
  (`../demo`, and one more line per future real service) — this only affects the
  `repositories` convenience, not the dynamic-boot mechanism (which never depends on
  Composer's `require` graph for a service to be loadable).
- **R-2 Deptrac/PHPStan config schema drift.** Verified live against Deptrac 2.0.4's actual
  docs (root key `deptrac:`, `classLike` collector, `paths`/`layers`/`ruleset` keys) — low
  risk, but `dev` should run `vendor/bin/deptrac init` once after `composer install` to
  diff against the exact installed version's expected schema before trusting this plan's
  YAML verbatim.
- **R-3 Pint/PHPStan/Pest version drift between XAMPP (8.2) and future Docker (8.3).**
  Mitigated by always locking from the 8.2.12 binary (see toolchain table); if Docker
  compose (FP-015) later fails to install from the committed lock, that's a signal the lock
  needs regenerating from 8.2, not that 8.3 needs a different lock.
- **R-3b Hyphenated package names produce non-obvious namespace segments.**
  `Str::studly('http-client')` → `HttpClient`, `Str::studly('plugin-sdk')` → `PluginSdk` —
  the Deptrac layer regexes and PSR-4 namespaces for those two `packages/*` stubs use these
  StudlyCase forms, not a literal transliteration of the hyphen. Add a one-line comment in
  `phpstan.neon.dist`/`deptrac.yaml.dist` next to those two entries so it isn't mistaken for
  a typo later.
- **R-4 `spl_autoload_functions()` PSR-4 registration is a less common pattern than a
  plain Composer `require`.** If `dev` hits an edge case where the class loader can't be
  found this way (e.g. a differently-ordered autoloader stack), the documented fallback is
  to require the specific service package formally in `composer.json` (as already done for
  `demo`) — this loses the "zero-edit" property from AC-27 for that one package only, which
  would then need re-discussion with `architect` before being accepted as a spec deviation.
- **R-5 Offline/flaky packagist.** Verified reachable at plan time; if `composer install`
  fails mid-task, retry with `--no-cache` before assuming a real outage; do not vendor
  packages into git as a workaround.
- **R-6 Windows path/case quirks.** All namespace-to-directory mappings in this design are
  self-declared per package (never inferred from casing), specifically to avoid the
  Windows-works/Linux-breaks class of bug described in "Alternatives rejected." No further
  mitigation needed, but `dev` should still eyeball that generated `composer.json` PSR-4
  keys and actual folder names agree exactly in case.
- **Rollback**: every step produces an independently committable, working state (the
  skeleton boots with zero services after Step 5, `demo` is optional until Step 8). If a
  later step needs to be reverted, earlier steps remain valid on their own.

## Questions for the human

None. Nothing in the spec is unrealistic on PHP 8.2.12 — all pinned tooling (Laravel 12,
Pest 3.8, Pint ≤1.30, Larastan 3.x, Deptrac 2.0.4) installs cleanly on this machine's
verified toolchain, so there is nothing to refer back to `ba`.
