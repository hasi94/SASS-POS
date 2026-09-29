# T-002: Service host hardening from the T-001 review

Covers backlog rows FP-146 to FP-153, all raised by the reviewer against T-001
(`docs/reviews/T-001-monorepo-service-host.md`, findings M-1 to M-9 and the Minor list).
T-001 passed QA 32/32 and was **approved** with no Critical findings; T-002 clears the
nine Major findings and the relevant Minor findings before Phase 1 builds real services
(identity, tenant, …) on this foundation.

## Problem / goal

T-001 delivered a working service host, generator and toolchain, but the reviewer found
nine places where the mechanism trusts input it should not, fails silently where it
should fail loudly, or documents itself incorrectly:

1. The service registry instantiates whatever class a manifest names, checks its type
   too late, and trusts an unrelated PSR-4 namespace before validating it (FP-146).
2. The generator's six-file write loop is not atomic, so a failure mid-generation
   leaves a package that is both broken and impossible to regenerate (FP-147).
3. The health endpoints depend on the session store, so a database outage kills a
   liveness check that should say the process is fine (FP-148).
4. Laravel's configuration/route caching and `--classmap-authoritative` autoloading are
   silently incompatible with the host's whole reason for existing — different
   `SERVICES` per deployment stage from one image (FP-149).
5. A future host test directory would be collected by nothing (FP-150).
6. The root README and the stock Laravel README both say things that are false
   (FP-151).
7. Test code is outside the static-analysis and boundary tooling, and the coverage
   guard's fixture-skip rule is broader than it needs to be (FP-152).
8. The proof that regenerating the demo package is byte-identical to the committed
   tree exists only as a QA procedure, not a regression test (FP-153).

None of these is a correctness defect in what T-001 shipped — the reviewer approved it —
but each is a real hazard for the first real service built on top of it. T-002 closes
all eight rows before that happens.

## Users affected

Same as T-001: backend developers (`dev`) building every service from Phase 1 onward,
`qa`/`reviewer` who depend on the toolchain gates being trustworthy, `architect` who
depends on the Deptrac boundary rule holding for test code too, and operators/CI
(future FP-019) who depend on `/health/live` reporting the true liveness of the process
regardless of database state.

## Current behaviour

(As shipped and QA-proven by T-001; see `docs/qa/T-001-monorepo-service-host.md` for
what is already proven and must not be re-specified here.)

- `App\Support\ServiceHost\ServiceRegistry::boot()` (`services/host/app/Support/ServiceHost/ServiceRegistry.php:104-131`)
  reads a service's `composer.json`, registers its declared PSR-4 namespace with
  Composer's class loader **unconditionally**, then checks only `class_exists($provider)`
  before calling `$this->app->register($provider)`. `NAME_PATTERN` (line 37) has no `D`
  modifier.
- `App\Console\Commands\SvcMakeCommand::handle()` (`services/host/app/Console/Commands/SvcMakeCommand.php:86-96`)
  writes six files and five empty directories in a loop with no rollback on failure.
- `services/host/routes/web.php` registers `/health/live` and `/health/ready` in the
  default `web` group, which loads `EncryptCookies`, `StartSession` and friends.
- `services/host/config/servicehost.php` and `App\Providers\ServiceHostServiceProvider`
  have no awareness of `config:cache`, `route:cache` or `--classmap-authoritative`.
- `services/host/phpunit.xml` has one `Services` suite (`../*/tests`, excluding
  `../host/tests`) plus literal `Unit`/`Feature` suites for the host; nothing collects a
  new, differently-named directory under `services/host/tests/`.
- `README.md` (root) states "no file inside `services/host/` needs to change";
  `services/host/README.md` (the unmodified `laravel/laravel` scaffold) states an MIT
  licence while `services/host/composer.json:7` declares `"license": "proprietary"`.
- `phpstan.neon.dist` and `deptrac.yaml` list only `src/` paths per package; no `tests/`
  path is analysed or boundary-checked anywhere.
- `services/host/tests/Feature/QaToolchainRegistryCoverageTest.php:35` skips any package
  directory whose name `str_contains(..., 'fixture')`.
- No automated test regenerates `services/demo` and diffs it against the committed tree;
  T-001's QA report proves this only as a manual, one-off procedure (AC-31).

## User stories

- As a backend developer, I want a manifest naming the wrong kind of class or a
  namespace that is not its own to fail loudly and specifically, so that a copy-paste
  mistake in a new service package cannot silently corrupt another service's classes or
  crash the host with a fatal that does not say which service is at fault.
- As a backend developer, I want `svc:make` to either fully succeed or leave nothing
  behind, so that a failed generation is safely retriable instead of stuck in a false
  "already exists" state.
- As an operator, I want `/health/live` to report the process is alive even when the
  database is down, so that my orchestrator does not kill a healthy process.
- As a backend developer, I want the host to refuse to boot on a stale cached
  configuration instead of silently running the wrong `SERVICES` list, so that I find out
  at deploy time, not in production traffic.
- As a future host contributor, I want any test directory I add under
  `services/host/tests/` to actually run, so that a passing gate means what it claims.
- As a new developer, I want the README to tell me the truth about which files change
  when I add a real service, so that I do not discover the guard files by surprise.
- As `qa`, I want the coverage guard's fixture-skip rule to only skip QA's own throwaway
  packages, so that a legitimately named future service is never silently unchecked.
- As `qa`, I want an automated test proving the demo package regenerates byte-identical
  to the committed tree, so that a stub edit that changes the template is caught by
  `composer test`, not by a human diffing by hand.

## Acceptance criteria

Numbering starts fresh at AC-1 for T-002 (T-001's AC-1..AC-32 keep their own numbers and
still hold; see the "Regression" line under each group below for which of them a `dev`
change in this task could plausibly break, so `qa` re-proves them explicitly).

### FP-146 — registry manifest trust (M-1, M-2, M-3)

- **AC-1** (M-1 — wrong kind of class): Given a service package whose manifest's
  `extra.flexipos.provider` names a real, autoloadable class that does **not** extend
  `Illuminate\Support\ServiceProvider` (for example the package's own controller class,
  pasted into the manifest by mistake), when that service is listed in `SERVICES` and the
  host boots, then boot fails with an `UnknownServiceException` that names the service
  and states the configured class is not a service provider — a message distinct from
  the existing "could not be autoloaded" message and distinct from an unhandled PHP
  fatal/`TypeError` bubbling out of the container. This prevents a manifest naming any
  autoloadable class from being constructed with the container and crashing the whole
  host process with an error that does not identify the offending service (D-1).

- **AC-2** (M-2 — namespace not tied to the service name): Given a service package (for
  example `services/billing`) whose manifest declares a PSR-4 namespace that is **not**
  exactly `Flexipos\Service\<Studly>\` for its own name (e.g. it declares
  `Flexipos\Service\Sales\` — another service's namespace, copy-pasted — or `App\` — the
  host's own namespace), when the host boots that service, then boot fails with an
  `UnknownServiceException` naming the service, the namespace it declared, and the one
  namespace it is allowed to declare, **and** the declared (mismatched) namespace is
  never registered with Composer's class loader — proven by asserting, immediately after
  the failed boot, that no PSR-4 prefix was added for that namespace (e.g. via the class
  loader's own `getPrefixesPsr4()`, or by confirming a probe class under that namespace
  still cannot be autoloaded). This prevents a copy-pasted or malicious package from
  silently adding a second source directory for another service's namespace, or for the
  host's own `App\` namespace, before it is ever checked.

- **AC-3** (M-2 — provider outside its own namespace): Given a service package whose
  manifest declares a correctly-scoped namespace (`Flexipos\Service\<Studly>\`, matching
  AC-2) but names a provider class that does **not** start with that namespace (for
  example package `billing` declaring provider
  `Flexipos\Service\Sales\BillingServiceProvider`), when the host boots, then boot fails
  with an `UnknownServiceException` naming the service and stating that its provider
  class must be declared inside its own namespace, and (as in AC-2) nothing is
  registered with the class loader for a namespace this package is not entitled to.

- **AC-4** (M-3 — trailing-newline bypass): Given the shared `NAME_PATTERN` constant used
  by both `SvcMakeCommand` and `ServiceRegistry` (decision D-12), when a candidate name
  ends with a trailing newline (e.g. the raw string `"billing\n"`), then the pattern does
  **not** match — proven two ways: (a) `php artisan svc:make "billing\n"` (or the shell
  equivalent) exits non-zero with the standard invalid-name error and creates no
  directory under `services/`; (b) a direct assertion that
  `preg_match(ServiceRegistry::NAME_PATTERN, "billing\n")` returns `0`, not `1`. Today
  this bypasses the check because PCRE's `$` matches before a final newline, so the
  generator (which does not trim) can create a directory whose name the registry (which
  does trim in `parse()`) can never boot — the same defect class as T-001's BUG-2.

- **Regression**: this group changes `ServiceRegistry::boot()`, the one method every
  boot-mechanism criterion depends on. `qa` re-proves T-001 AC-18, AC-19, AC-20, AC-21,
  AC-22, AC-23, AC-24 and AC-32 unchanged (the committed `services/demo` manifest is
  already correctly scoped, so it must still boot and its route must still respond), and
  re-proves AC-28 (invalid-name rejection) still rejects every case it rejected before,
  now also including a trailing newline (AC-4 above).

### FP-147 — atomic `svc:make` generation (M-4)

- **AC-5**: Given `svc:make` fails partway through generating a package for a valid,
  unused name — for example because a stub file is temporarily missing or unreadable, or
  a target subdirectory cannot be written to — when the command run is observed
  afterward, then no partial `services/<name>/` directory is left on disk: either the
  target directory is removed on any throwable during generation, or generation renders
  into a location outside `services/<name>/` and is moved into place only on complete
  success. Proven by simulating such a failure and asserting `services/<name>/` does not
  exist afterward.

- **AC-6**: Given the failed-and-cleaned-up state from AC-5, when `svc:make <name>` is
  run again with the same name, then it succeeds and produces a complete, passing
  package (per T-001 AC-25/AC-26) — not a refusal with "already exists. Nothing was
  created or modified," which would be false in this state.

- **Regression**: `qa` re-proves T-001 AC-25, AC-26, AC-28 and AC-29 (a **genuinely**
  already-existing package, e.g. `demo`, must still be refused and must still be
  byte-unmodified) still hold, and re-proves **AC-27** specifically — the rollback/temp
  mechanism must not write or leave anything inside `services/host/` at any point during
  a successful or a failed generation.

### FP-148 — health endpoints independent of the session store (M-5)

- **AC-7**: Given `SESSION_DRIVER=database` (the shipped `.env.example` default) and the
  sessions table unavailable (not migrated, or the database itself unreachable), when
  calling `GET /health/live`, then the response is HTTP 200 with body
  `{"status":"ok"}` — not HTTP 500 from an attempt to open a database-backed session.
  This is the attack/mistake being prevented: an orchestrator polling `/health/live`
  must never kill a process that is actually alive just because its database is
  temporarily down.

- **AC-8**: Given the same conditions as AC-7, when calling `GET /health/ready`, then the
  response is still HTTP 200 listing the booted services (or an empty list) exactly as
  under a healthy database — proving readiness reporting (per D-7, "which services are
  booted", not a deep health check) does not regress into depending on the session
  store either.

- **Regression**: `qa` re-proves T-001 AC-20, AC-22, AC-23 (the exact `/health/ready`
  JSON shapes under the test suite's own `SESSION_DRIVER=array` configuration) are
  unaffected by moving the routes out of the `web` middleware group.

### FP-149 — cache and autoloader contract (M-6, M-9)

Out of scope within this row (see "Out of scope"): the container image build and any CI
checklist. In scope: a boot-time guard for the `SERVICES`/`config:cache` hazard, and
documentation of all four constraints the review names.

- **AC-9**: Given the host's configuration has been cached (`config:cache`) with a
  `SERVICES` value baked into the cache file, and the ambient `SERVICES` environment
  variable present at process start does **not** equal that cached value, when the host
  boots, then it fails fast with an error stating that the cached configuration's
  `SERVICES` value disagrees with the ambient `SERVICES` value and that the cache must be
  rebuilt (naming both values) — rather than silently booting the stale, cached service
  list. This is the headline guarantee `config:cache` would otherwise defeat: different
  deployment stages running different service subsets from one image.

- **AC-10**: Given the same cached-configuration state as AC-9, but the ambient
  `SERVICES` value is identical to the cached value (the legitimate case — an image built
  and started with the same `SERVICES` throughout), when the host boots, then it boots
  normally with no error from the guard.

- **AC-11**: Given the project's documentation (the root README and/or a dedicated
  operations note, at the architect's discretion), when a developer reads it before
  running `config:cache`, `route:cache`, or building the autoloader with
  `--classmap-authoritative`, then all four constraints the review named are documented
  in one place, stating plainly which are guarded by code (AC-9/AC-10) and which are
  documented only, with no code fix in T-002:
  1. `config:cache` freezes `SERVICES` — guarded by AC-9/AC-10.
  2. `mergeConfigFrom()` for a service's own configuration becomes a no-op under a
     cached configuration, so a service booted that way never contributes its
     connection config to the running process — documented only.
  3. `route:cache` freezes the route list (a service added to `SERVICES` after caching
     is unreachable until the cache is rebuilt) and currently fails outright because of
     the stock closure route on `/` — documented only; the closure route itself is not
     changed by T-002 (see "Out of scope").
  4. Building the Composer autoloader with `--classmap-authoritative` breaks
     `ServiceRegistry`'s runtime PSR-4 registration entirely, because an authoritative
     classmap loader returns "not found" for anything outside the classmap it was built
     with, regardless of `addPsr4()` calls made afterward — documented only.

- **Regression**: `qa` re-proves `composer test` and `composer check` still exit 0
  end-to-end (the "Done well" note that config cache is cleared before the test step
  must keep working), and confirms the AC-9 guard does not fire during the normal test
  run (which never caches configuration).

### FP-150 — collect every host test directory (M-7)

- **AC-12**: Given a new, top-level test directory is added under `services/host/tests/`
  that is neither `tests/Unit` nor `tests/Feature` (for example `tests/Integration`),
  when `composer test` is run, then test files inside it are collected and executed —
  proven by adding a probe test file under such a directory, confirming it appears in
  the run's results, then removing the probe. This closes the gap where a future host
  test directory would be collected by nothing — T-001's BUG-1, relocated to the host.

- **AC-13**: Given the fix for AC-12, when `composer test` is run against the currently
  committed test tree, then no test file is collected twice (no duplicate-collection
  warning or error) — preserving the reason the original
  `<exclude>../host/tests</exclude>` existed.

- **Regression**: `qa` re-proves T-001 AC-16 and AC-26 (package and generated-service
  test discovery) and reconfirms the exact test/assertion counts reported in
  `docs/qa/T-001-monorepo-service-host.md` (74 passed / 222 assertions) are not silently
  changed by the suite restructuring, beyond the tests T-002 itself adds.

### FP-151 — documentation accuracy (M-8)

- **AC-14**: Given the root `README.md`'s "Adding a service" section, when read by a
  developer, then it no longer states unconditionally that "no file inside
  `services/host/` needs to change." It states instead that booting an
  **already-generated** service is configuration-only (adding the name to `SERVICES`),
  while making that service statically analysed and boundary-checked requires editing
  two named files inside `services/host/` — `phpstan.neon.dist` (its `paths` list) and
  `deptrac.yaml` (its `paths` list, plus a `Service_<Studly>` layer and its ruleset
  entry) — matching exactly what `svc:make`'s own printed reminder says, and pointing at
  the guard test (`QaToolchainRegistryCoverageTest`) that fails the gate if either edit
  is forgotten.

- **AC-15**: Given `services/host/README.md` (the unmodified `laravel/laravel` scaffold
  README), when inspecting the repository after T-002, then it no longer states an MIT
  licence anywhere that contradicts `services/host/composer.json`'s
  `"license": "proprietary"` — whether by removing the MIT statement, correcting it, or
  removing the file in favour of the root README is left to `dev`/`architect` (decision
  D-22); the observable requirement is that no file in the repository asserts an MIT
  licence for this codebase once T-002 is done.

- **Regression**: `qa` re-proves T-001 AC-3 (README states purpose, layout and links to
  `CLAUDE.md`, `docs/architecture.md`, `docs/backlog.md`) still holds after the README
  edit.

### FP-152 — analysis/boundary coverage of test code, narrower fixture skip (Minor)

This row splits by file ownership. AC-16 and AC-17 touch `phpstan.neon.dist` and
`deptrac.yaml`, owned by `dev`. **AC-18 touches
`services/host/tests/Feature/QaToolchainRegistryCoverageTest.php`, which matches
`Qa*.php` under `services/host/tests/Feature/` and is owned by `qa`, not `dev` — `qa`
implements AC-18, not `dev`.**

- **AC-16** (`dev`): Given `services/demo/tests` (and, from then on, any package's
  `tests/` directory), when `composer stan` runs, then those test directories are
  included in PHPStan's analysed paths — proven by planting a deliberate level-6
  violation inside `services/demo/tests/`, confirming `composer stan` exits non-zero and
  reports it, then removing the probe.

- **AC-17** (`dev`): Given a temporary fixture where a service's **test** file (not a
  `src/` file, unlike T-001 AC-15's fixture) imports a class from another service
  package's namespace, when `composer deptrac` runs, then it exits non-zero and reports
  a boundary violation naming both layers — proving test code is now boundary-checked,
  not only production code. Today this passes silently because no `tests/` path is
  listed in `deptrac.yaml`.

- **AC-18** (`qa`): Given `QaToolchainRegistryCoverageTest`'s package-discovery helper
  currently skips any `services/*` directory whose name `str_contains(..., 'fixture')`
  (line 35, so that throwaway packages other tests generate mid-run do not trip the
  guard), when a legitimately named, permanent service package's name happens to contain
  the substring "fixture" as a real word (for example a hypothetical `data-fixtures`
  service), then the guard must **not** skip it — it must be required to be registered in
  `phpstan.neon.dist`, `deptrac.yaml` and a `phpunit.xml` testsuite like any other real
  package, and the guard test must fail if it is not. Proven by `qa` adding a probe
  package whose name contains "fixture" as a real word but is not one of QA's own
  reserved throwaway names, and asserting the guard does not skip it. The replacement
  skip rule (an exact list of QA's own fixture names, or a single reserved prefix QA
  commits to using for every fixture package it generates from then on) is `qa`'s
  implementation choice (decision D-23).

- **Regression**: `qa` re-proves T-001 AC-9, AC-12, AC-14 (lint/stan/deptrac still exit 0
  on the shipped, unmodified tree once test directories are included — existing test
  files must already be level-6-clean and boundary-clean, or `dev` fixes what the new
  coverage surfaces before this is DONE) and AC-16 (test discovery unaffected).

### FP-153 — regression test for demo-package regeneration (Minor)

- **AC-19**: Given `services/demo` as committed in the repository, when an automated
  test regenerates a package named `demo` via `svc:make` (following the same
  move-aside-and-restore pattern QA used by hand for T-001 AC-31, never overwriting the
  committed tree in place) and diffs the result byte-for-byte against the committed
  `services/demo` tree, then the test asserts the two are identical, naming the
  differing file(s) if they are not, and restores the committed tree afterward
  regardless of pass or fail. This replaces the manual QA procedure from T-001 AC-31
  with a permanent automated test in `composer test`, so a future stub edit that drifts
  from the committed reference is caught by the gate, not by a human diffing by hand.

- **Regression**: `qa` confirms this new test itself passes on the current, unmodified
  `services/demo` (i.e. it is not accidentally red from day one), and that it leaves the
  worktree exactly as clean afterward as T-001's QA report recorded.

## Out of scope

- **FP-143** (migrate Deptrac to the maintained `deptrac/deptrac` package) — a separate,
  already-logged backlog row for `architect`/`dev`, not part of T-002.
- **FP-144** and **FP-145** (ERP scope questions Q-1..Q-6 and reserving ERP extension
  points) — product-owner decisions, blocked on FP-144; not `ba`'s or T-002's to resolve.
- **The container image build and CI checklist parts of FP-149** — M-9's suggestion to
  "add it to the container work's checklist" and to "consider detecting [the
  `--classmap-authoritative` flag] and naming the flag in the error" are deferred to
  FP-015/FP-019, where the container and CI actually exist. T-002 only adds the AC-9/AC-10
  boot-time guard and the AC-11 documentation.
- **Everything T-001's spec already listed as out of scope**: real business services,
  real contents of the shared `packages/*` packages, database schema creation and
  `platform:init-databases`, the API envelope/exception handler/error-code
  catalogue/OpenAPI, the Traefik gateway, Docker Compose, the XAMPP provisioning
  script, the Angular workspace, the .NET Print Agent, CI workflows, and
  Docker-based PHP 8.3 verification. T-002 hardens the existing skeleton; it does not
  extend its scope.
- **No "while we are here" cleanup of the stock Laravel scaffolding** beyond the README
  licence contradiction in AC-15. The welcome view, the closure route on `/`, and the
  front-end build files remain exactly as T-001 shipped them; the closure route's effect
  on `route:cache` is documented (AC-11 item 3), not fixed.
- **The exact wording of every new error message** beyond what each AC above requires
  (naming the service and the specific reason) is left to `dev`/`architect`, following
  the existing `UnknownServiceException` convention (see
  `services/host/app/Support/ServiceHost/UnknownServiceException.php`).

## Non-functional requirements

- Every new or changed error message must name the offending service (and, for FP-146,
  the specific namespace/provider/class in question) — a criterion that only asserts "it
  throws" without a distinguishing message is not sufficient, per the reviewer's own
  criticism of a vaguer version of this pattern in T-001.
- All fixes stay OS-agnostic in `composer.json`/config files, consistent with T-001's
  existing non-functional requirement.
- No fix in this task may cause `composer check` to regress from its current exit-0
  state on the shipped, unmodified tree once T-002's own new/changed files are included
  (formatting, static analysis, boundary rules and tests all still pass).

## Assumptions

- The PCRE trailing-newline behaviour the reviewer verified on PHP 8.2.12 (M-3) holds
  unchanged on this machine's PHP; no separate re-verification of the PCRE engine itself
  is needed, only of the `D`-modified pattern's behaviour.
- The boot-time guard in AC-9/AC-10 only needs to compare the `SERVICES` value — not the
  entire cached configuration — because `SERVICES` is the one value the review calls out
  as the "headline guarantee" the host exists for (decision D-19).
- "Documented" for FP-149 items 2-4 (AC-11) means committed prose a developer will read
  before hitting the hazard; it does not require a runtime check, error message, or CI
  gate for those three items, per the task's explicit "container and CI checklist items
  are not in T-002" scope line.
- All ambiguous implementation choices encountered while writing this spec were resolved
  by `ba` and logged in `docs/decisions/T-002-service-host-hardening.md` (D-13 onward)
  per the team protocol, rather than left as blocking questions.

## Open questions

None blocking. Two non-blocking items for `architect` to fix once, in `docs/plans/`, so
`dev` and `qa` do not each guess independently:

1. The exact mechanism for FP-147's atomicity (delete-on-throwable vs. render-to-temp-
   then-move) and for FP-150's suite restructuring (collapse to one `tests` suite vs.
   assert collection in the guard test) — both are technical design choices the review
   itself leaves open; AC-5/AC-6 and AC-12/AC-13 are written to be satisfied by either
   mechanism. [NOT BLOCKING]
2. Whether AC-11's documentation lives in the root `README.md`, a new file under
   `docs/`, or both — the spec requires the content to exist in one findable place, not
   a specific file. [NOT BLOCKING]
