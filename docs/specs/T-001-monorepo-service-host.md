# T-001: Monorepo, backend toolchain and service host skeleton

Covers backlog rows FP-003 (monorepo skeleton), FP-004 (backend toolchain) and FP-005
(service host and service package template).

## Problem / goal

FlexiPOS is a greenfield, service-oriented Laravel backend (architecture.md §4–§6). Before
any real service (identity, tenant, catalog, …) can be built, the repository needs:

1. The top-level folder layout defined in architecture.md §5, plus root-level project files.
2. A working PHP 8.2+ / Composer toolchain (formatting, static analysis, boundary
   enforcement, tests) wired to the one-line commands already documented in `CLAUDE.md`.
3. A **Service Host** (`services/host`, architecture.md §4) that boots only the service
   packages named in its `SERVICES` configuration, plus a generator (`php artisan
   svc:make <name>`) that scaffolds a new, structurally correct service package so that
   adding a service is a configuration change, not a rewrite.

T-001 delivers the mechanism and proves it works with one demonstration service package
and a health endpoint. It does not deliver any real business service or any shared
package's real contents — those are later, dependent backlog rows.

## Users affected

- Backend developers (`dev`) building every service package from Phase 1 onward — they
  depend on this skeleton, toolchain and generator daily.
- `qa` and `reviewer` — depend on the toolchain commands (`composer lint|stan|deptrac|
  test|check`) existing and being trustworthy gates.
- `architect` — depends on Deptrac boundary rules to enforce the "service package may
  depend only on `packages/*` and its own namespace" rule from every later plan.
- Operators/CI (future FP-019) — depend on the health endpoint and `SERVICES` mechanism
  to know what is running in a given host process.

## Current behaviour

None — this is a greenfield repository. Only `docs/` (specs, backlog, architecture,
decisions, etc.) and the root `CLAUDE.md` exist today. There is no `services/`,
`packages/`, `composer.json`, or any PHP code. T-001 creates all of it.

## User stories

- As a backend developer, I want a standard monorepo folder layout, so that I know where
  new service and package code belongs without asking.
- As a backend developer, I want one wired-up Composer toolchain (Pint, PHPStan,
  Deptrac, Pest), so that I can format, statically analyse, boundary-check and test the
  backend with the commands already documented in `CLAUDE.md`.
- As a backend developer, I want a service host that boots only the packages listed in
  `SERVICES`, so that different deployment stages (architecture.md §4) run different
  service subsets from the same code with no code change.
- As a backend developer, I want `php artisan svc:make <name>` to scaffold a new service
  package, so that I get a structurally correct, already-passing package in seconds
  instead of hand-copying boilerplate.
- As an architect/reviewer, I want an automated boundary rule (Deptrac), so that a
  service package can never accidentally depend on another service's internals.
- As an operator, I want a health endpoint that reports which services are booted, so
  that I can verify a given host process's configuration at a glance.

## Acceptance criteria

### Monorepo skeleton

- AC-1: Given a fresh clone of the repository, when listing top-level directories, then
  `services/`, `packages/`, `gateway/`, `frontend/`, `print-agent/`, `plugins/`,
  `infra/`, `docs/` all exist, matching architecture.md §5. Folders whose real content is
  a later, separate backlog row (`gateway/`, `frontend/`, `print-agent/`, `plugins/`,
  `infra/`) contain only a placeholder file (e.g. `.gitkeep`) — no functional content is
  created for them by T-001.
- AC-2: Given `packages/`, when listing its subdirectories, then `contracts/`, `kernel/`,
  `eventbus/`, `auth/`, `http-client/`, `plugin-sdk/`, `testing/` all exist, each with a
  minimal `composer.json` stub (package name only, empty `src/`) sufficient for the
  Composer workspace and for Deptrac to reference them as layers. No business logic (that
  is FP-006 through FP-011).
- AC-3: Given the repo root, when checking for `README.md` and `.editorconfig`, then both
  exist; the README states the project's purpose, the top-level layout, and links to
  `CLAUDE.md`, `docs/architecture.md` and `docs/backlog.md` for detail.
- AC-4: Given the repo root, when checking `CLAUDE.md`, then it already exists (written
  by the lead) and is left unmodified by this task.
- AC-5: Given a `.gitignore` at the repo root (or under `services/host`), when checking
  its contents, then `vendor/`, `node_modules/`, `.env` and common build/IDE artefacts
  are excluded from version control.

### Backend toolchain

- AC-6: Given `services/host/composer.json`, when inspecting its `require` section, then
  it declares `"php": "^8.2"` and Composer path repositories for `packages/*` and
  `services/*`, forming a Composer workspace.
- AC-7: Given the PHP path documented in `CLAUDE.md` (`E:\xampp\php\php.exe`), when
  running `php -v`, then it reports PHP 8.2.12 or later, confirming "PHP 8.2+ verified"
  for local (XAMPP) development. Docker-based verification against PHP 8.3 is deferred to
  FP-015/FP-019 (Docker compose and CI do not exist yet) and is out of scope here.
- AC-8: Given a clean checkout, when running `composer install` in `services/host` (using
  the documented Composer path), then it completes successfully and produces
  `composer.lock`, resolving the `demo` service package (AC-25+) and all `packages/*`
  stubs through the workspace path repositories.
- AC-9: Given the toolchain, when running `composer lint` in `services/host`, then Pint
  runs in check-only (`--test`) mode across `services/host`, every `services/*` package
  and every `packages/*` package, and exits 0 on the shipped skeleton code.
- AC-10: Given a file with a deliberate formatting violation introduced temporarily for
  the test, when running `composer lint`, then it exits non-zero and reports the
  violation; running `composer lint:fix` corrects it so a following `composer lint`
  exits 0.
- AC-11: Given `services/host/phpstan.neon(.dist)`, when inspecting it, then `level: 6`
  is configured with analysis paths covering `services/host`, every `services/*/src` and
  every `packages/*/src`.
- AC-12: Given the toolchain, when running `composer stan`, then PHPStan runs at level 6
  over those paths and exits 0 on the shipped skeleton code.
- AC-13: Given `services/host/deptrac.yaml(.dist)`, when inspecting it, then a layer is
  defined per service package (from its own namespace) and one layer per `packages/*`
  package, with a ruleset that allows each service layer to depend only on itself and any
  `packages/*` layer, and forbids dependencies on any other service layer.
- AC-14: Given the toolchain, when running `composer deptrac`, then it exits 0 on the
  shipped skeleton (no violations).
- AC-15: Given a temporary fixture where one service package (e.g. `services/demo`)
  imports a class from another service package's namespace, when running `composer
  deptrac`, then it exits non-zero and reports a boundary violation naming both layers.
  This fixture is used only as an automated test/CI scenario proving enforcement and is
  never left as a real violation in the committed skeleton.
- AC-16: Given the toolchain, when running `composer test` in `services/host`, then Pest
  discovers and runs tests from `services/host`, every `services/*` package and every
  `packages/*` package, and all pass.
- AC-17: Given the toolchain, when running `composer check` in `services/host`, then it
  runs lint, stan, deptrac and test in sequence, exits 0 only if all four pass, and exits
  non-zero at the first failing step otherwise.

### Service host boot mechanism

- AC-18: Given `services/host` is a Laravel application, when `SERVICES` in its
  environment configuration is set to `demo`, then only the `demo` service package's
  service provider is registered on boot, and no other, not-yet-existing service package
  is loaded.
- AC-19: Given `SERVICES` names a package that does not exist under `services/` (e.g.
  `SERVICES=demo,ghost`), when the host boots, then it fails fast with a clear error
  identifying `ghost` as an unknown service, rather than booting silently with `ghost`
  skipped.
- AC-20: Given `SERVICES` is unset or empty, when the host boots, then it boots
  successfully with zero service packages registered (a valid, minimal configuration).
- AC-21: Given `SERVICES=" demo , demo "` (duplicated, with surrounding whitespace), when
  the host boots, then `demo` is registered exactly once, with no duplicate-route or
  duplicate-provider errors, and whitespace around names is ignored.
- AC-22: Given `SERVICES=demo`, when calling `GET /health/ready`, then the response is
  HTTP 200 and its body lists exactly the booted service identifiers (equivalent to
  `["demo"]`); the exact JSON field name is fixed by the architect's plan and used
  consistently by `dev` and `qa`.
- AC-23: Given `SERVICES` is empty, when calling `GET /health/ready`, then the response
  is still HTTP 200 and lists zero booted services (an empty list), not an error.
- AC-24: Given `SERVICES=demo`, when calling a route that belongs to a service package
  that exists under `services/` but is **not** listed in `SERVICES` (a second,
  demo-style package created only for this test), then that route returns HTTP 404,
  proving unlisted service packages are not routed at all.

### `svc:make` service package generator

- AC-25: Given a valid, unused, kebab-case name (e.g. `billing`), when running `php
  artisan svc:make billing`, then `services/billing/` is created containing at minimum:
  a `composer.json` declaring the package, a service provider class, a routes file
  mounted under a prefix unique to the service, a database connection configuration
  entry named for the service, an (initially empty) `database/migrations/` directory, an
  (initially empty) `Consumers/` directory, and at least one automated test file.
- AC-26: Given the package generated in AC-25, when running `composer test` without any
  further edits, then the generated test passes out of the box, proving the template is
  self-testing.
- AC-27: Given the package generated in AC-25, when its name is added to `SERVICES` and
  the host is booted, then the new service is reachable (its route responds and it
  appears in `/health/ready`) without any file inside `services/host/` being edited —
  configuration only.
- AC-28: Given an invalid name — uppercase letters, spaces, or characters outside
  `^[a-z][a-z0-9]*(-[a-z0-9]+)*$` (which includes a leading digit, a **trailing hyphen**
  such as `billing-`, and **consecutive/doubled hyphens** such as `bad--name`; see decision
  D-12) — when running `php artisan svc:make "Bad Name!"` (or `svc:make billing-`, or
  `svc:make bad--name`), then the command refuses with a clear validation error, exits
  non-zero, and creates no files under `services/`.
- AC-29: Given a name that already exists as a service package (e.g. `demo`), when
  running `php artisan svc:make demo` again, then the command refuses with a clear
  "already exists" error, exits non-zero, and does not modify any existing file under
  `services/demo/`.
- AC-30: Given a name that collides with a reserved word (`host`), when running `php
  artisan svc:make host`, then the command refuses with a clear "reserved name" error
  and creates no files.

### Demonstration service package and health endpoint

- AC-31: Given the skeleton, when listing `services/`, then at least one demonstration
  service package exists (`services/demo`), created via `svc:make` itself, committed to
  the repository, and included by default in the example environment file
  (`SERVICES=demo`) so it is exercised by `composer test` out of the box.
- AC-32: Given `SERVICES=demo` and the host running, when calling the demo service's own
  sample route (distinct from `/health/*`, e.g. `GET /api/v1/demo/ping` or equivalent
  under its own prefix), then it returns HTTP 200 with a simple JSON body, proving the
  routing/prefix and database-connection-name mechanisms work end to end. (The full API
  envelope, architecture.md §7.1, is FP-013 and out of scope; the demo route does not
  need to use it.)

## Out of scope

- Real business services (identity, tenant, catalog, pricing, inventory, sales, payment,
  customer, sync, notification, reporting, plugin, print) — Phase 1 onward.
- Real contents of the shared packages: `packages/kernel` (FP-006), `packages/contracts`
  (FP-007), `packages/eventbus` (FP-008), `packages/auth` (FP-009),
  `packages/http-client` (FP-010), `packages/testing` (FP-011). T-001 creates only
  structural placeholders (AC-2).
- Database schema creation, `platform:init-databases`, `svc:migrate` running against a
  real schema (FP-012). Generated migrations directories stay empty until a real service
  needs them.
- The API envelope, exception handler, error-code catalogue, OpenAPI generation and
  per-service health depth beyond "which services are booted" (FP-013).
- Traefik gateway config (FP-014), Docker Compose dev/CI environment (FP-015), XAMPP
  provisioning script for the 13 real schemas (FP-016).
- Angular workspace bootstrap (FP-017), .NET Print Agent bootstrap (FP-018).
- CI workflows (FP-019) — T-001 only makes the commands CI will later call exist and
  pass locally.
- Docker-based PHP 8.3 verification (see AC-7 and decision D-3).

## Non-functional requirements

- Composer scripts (`lint`, `lint:fix`, `stan`, `deptrac`, `test`, `check`) must be
  OS-agnostic — no Windows-specific absolute paths inside `composer.json`. The
  Windows-specific absolute paths to PHP/Composer belong only in `CLAUDE.md`, which
  already documents them.
- Code generated by `svc:make` must satisfy the toolchain out of the box: it must pass
  `composer lint`, `composer stan` (level 6) and `composer deptrac` immediately after
  generation, with no manual fix-up required (proven by AC-26 for tests; lint/stan/deptrac
  cleanliness is implied by AC-9/AC-12/AC-14 running across all `services/*`, which
  includes any freshly generated package left in the tree).
- Deptrac boundary rules must run as part of `composer check` so a boundary violation
  blocks the aggregate gate that future CI (FP-019) will call.

## Assumptions

- The exact Laravel version for `services/host` (architecture.md §5 says "current
  Laravel major") is a technical choice left to the architect's plan; this spec does not
  pin it.
- The exact route-prefix convention for real, future business services follows
  architecture.md §7.1 and is decided per service when that service is built; the
  `svc:make` default prefix (decision D-9) only needs to be sane for the template and
  demo package.
- A generated service's "database connection name" only needs to exist as a named
  connection configuration entry; it does not need to connect to a real, provisioned
  schema in T-001 (schema provisioning is FP-012).
- All ambiguous business rules encountered while writing this spec were resolved by `ba`
  and logged in `docs/decisions/T-001-monorepo-service-host.md` per the team protocol,
  rather than left as blocking questions.

## Open questions

None blocking. One non-blocking item for `architect` to fix in the plan (not requiring a
business decision):

1. The exact JSON field name(s) used by `/health/ready` to list booted services (e.g.
   `services` vs `booted_services`) should be fixed once in `docs/plans/` so `dev` and
   `qa` agree on the exact shape. [NOT BLOCKING]
