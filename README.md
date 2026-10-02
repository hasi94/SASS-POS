# FlexiPOS

Multi-tenant SaaS POS platform: a module-oriented Laravel backend, an Angular POS/admin
PWA and a C#/.NET print agent, developed in a single monorepo.

The backend is built as independent **modules** under `modules/`. A single
Laravel application, the **module host** (`modules/host`), boots only the module
packages named in its `MODULES` environment variable, so the same code base can run as
one process or as many, depending on deployment stage — a configuration change, not a
rewrite.

## Top-level layout

| Path           | Contents                                                                              |
|----------------|---------------------------------------------------------------------------------------|
| `modules/`    | `host/` (the Laravel module host) plus one Composer path package per business module |
| `packages/`    | Shared Composer packages: `contracts`, `kernel`, `eventbus`, `auth`, `http-client`, `plugin-sdk`, `testing` |
| `gateway/`     | Traefik static + dynamic configuration and middlewares                                 |
| `frontend/`    | Angular workspace (admin, POS, core SDK, plugin front-ends)                            |
| `print-agent/` | C# / .NET print agent solution                                                         |
| `plugins/`     | Plugin source (`plugin.json` + `backend/` + `frontend/`)                               |
| `infra/`       | Docker images, Compose files, CI and provisioning scripts                              |
| `docs/`        | Architecture, database, backlog, specs, plans and decisions                            |

`gateway/`, `frontend/`, `print-agent/`, `plugins/` and `infra/` are placeholders for now;
their real contents land in later backlog rows.

## Getting started

PHP and Composer paths for this machine are documented in [CLAUDE.md](CLAUDE.md).

```bash
cd modules/host
composer install
composer check     # lint + stan + deptrac + test — the gate, and the only thing that gates a task
composer test:fast # inner development loop: every test except the toolchain group
```

`composer test:fast` skips the `toolchain` group: the test files that spawn a child process to
boot a whole tool (Pint, PHPStan, Deptrac, Pest itself, `module:make`, the cache guards). That is
roughly a sixth of the cases and most of the suite's runtime, so the fast command finishes in
about a third of the time. Measured warm, on one machine, three times — a cold run (the first
of a session, or with the analysis caches cleared) costs considerably more on both sides, so
treat the ratio as the durable part and not the seconds. `toolchainGroupFiles()` in
`modules/host/tests/Helpers.php` is the one place the group is defined.

It is never the gate: `composer check` runs everything, and
`modules/host/tests/Feature/ToolchainGroupCoverageTest.php` asserts that it cannot be
filtered — `composer test` and `composer check` carry no group flag at all.

`modules/host/.env.example` ships with `MODULES=demo`, which boots the demonstration
module in `modules/demo`.

- `GET /health/live` -> `{"status":"ok"}`
- `GET /health/ready` -> `{"status":"ok","modules":["demo"]}`
- `GET /api/v1/demo/ping` -> the demo module's own sample route

Both health endpoints are registered outside every middleware group, so they answer even
while the database is down — an orchestrator must never kill a process that is alive.

## Caching in production

The host boots modules at runtime, from `MODULES` and from each package's own
`composer.json`. Laravel's optimisation commands and Composer's authoritative classmap all
freeze part of that at build time. Read this before running any of them in an image; the
same four points, in more detail, are in `modules/host/config/modulehost.php`.

1. **`php artisan config:cache` freezes `MODULES`** into `bootstrap/cache/config.php`, so
   one image can no longer run a different module subset per stage. **Guarded in code**:
   the host refuses to boot when the configuration is cached *and* the process environment
   sets a `MODULES` value that differs from the frozen one, naming both values. It stays
   silent when the configuration is not cached, when the environment sets no `MODULES`,
   and when the two agree.
   **The guard reads the process environment only.** While the configuration is cached the
   framework does not load `.env` at all, so a `MODULES` you change there afterwards is
   invisible both to the application and to the guard — the process boots the frozen list
   without complaining. If `MODULES` lives in `.env` rather than in the container's
   environment, run `php artisan config:clear && php artisan config:cache` after every
   edit, or do not cache configuration on that host.
2. **`mergeConfigFrom()` is a no-op under a cached configuration**, so anything a module
   contributes that way is missing from the running process. A module no longer contributes
   a database connection at all — it declares none, and business data goes through the
   shared `tenant` connection, which is built at runtime and is therefore unaffected by a
   cached configuration. *Documented only* — point 1's guard closes the dangerous half
   structurally.
3. **`php artisan route:cache` freezes the route table** the same way, so a module added to
   `MODULES` after the cache was built is unreachable until it is rebuilt. *Documented
   only* — nothing detects that. The command itself succeeds: this entry used to say it
   could not run at all while `routes/web.php` used an inline closure, which was never true
   on this framework version (Laravel 12 serialises a closure action).
   `modules/host/tests/Feature/RouteCachingTest.php` now asserts that it succeeds, so it
   cannot break unnoticed.
4. **`--classmap-authoritative` breaks the boot mechanism entirely** — such a loader
   answers "not found" for anything outside the classmap it was built with, whatever is
   registered with `addPsr4()` afterwards. *Documented only*, no detection code.

## Adding a module

```bash
cd modules/host
php artisan module:make billing
```

This scaffolds `modules/billing/` with a composer package, a module provider, a routes
file mounted at `/api/v1/billing`, empty `config/`, `database/migrations/` and
`src/Consumers/` directories, and a passing feature test.

A generated module **declares no database connection of its own.** There are two named
connections in the platform and a module uses them both through the kernel: `master` for
platform data (login identity, tenants, subscriptions, the tenant-to-database mapping), and
`tenant` for one shop's business data. The `tenant` connection has no fixed database — it is
built at runtime, one shop at a time, by `Flexipos\Kernel\Tenancy\Tenancy`, which is the only
code allowed to configure it, and it refuses every query until a tenant has actually been
resolved. See [docs/architecture.md](docs/architecture.md) and
[docs/database.md](docs/database.md).

Add `billing` to `MODULES` to boot it; **no file inside `modules/host/` needs to change
for that** — booting a generated module is a configuration change only, and `module:make`
itself writes nothing into `modules/host/`.

**Before it is a real, permanent module, two files inside `modules/host/` do need one
edit each**, because neither tool expands wildcards in its path list:

- `modules/host/phpstan.neon.dist` — add `../billing/src` (and `../billing/tests`) to `paths`
- `modules/host/deptrac.yaml` — add `../billing/src` (and `../billing/tests`) to `paths`,
  plus a `Module_Billing` layer and its ruleset entry

`module:make` prints this reminder when it generates the package, and
`QaToolchainRegistryCoverageTest` fails the gate if either edit is forgotten, so a module
cannot quietly end up unanalysed or unchecked. `phpunit.xml` needs no edit: its suites are
globbed.

## More detail

- [CLAUDE.md](CLAUDE.md) — tech stack, local environment paths, commands, conventions
- [docs/architecture.md](docs/architecture.md) — modules, contracts, events, sagas
- [docs/backlog.md](docs/backlog.md) — task queue and status
