# FlexiPOS

Multi-tenant SaaS POS platform: a service-oriented Laravel backend, an Angular POS/admin
PWA and a C#/.NET print agent, developed in a single monorepo.

The backend is built as independent **service packages** under `services/`. A single
Laravel application, the **service host** (`services/host`), boots only the service
packages named in its `SERVICES` environment variable, so the same code base can run as
one process or as many, depending on deployment stage — a configuration change, not a
rewrite.

## Top-level layout

| Path           | Contents                                                                              |
|----------------|---------------------------------------------------------------------------------------|
| `services/`    | `host/` (the Laravel service host) plus one Composer path package per business service |
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
cd services/host
composer install
composer check     # lint + stan + deptrac + test
```

`services/host/.env.example` ships with `SERVICES=demo`, which boots the demonstration
service package in `services/demo`.

- `GET /health/live` -> `{"status":"ok"}`
- `GET /health/ready` -> `{"status":"ok","services":["demo"]}`
- `GET /api/v1/demo/ping` -> the demo service's own sample route

Both health endpoints are registered outside every middleware group, so they answer even
while the database is down — an orchestrator must never kill a process that is alive.

## Caching in production

The host boots service packages at runtime, from `SERVICES` and from each package's own
`composer.json`. Laravel's optimisation commands and Composer's authoritative classmap all
freeze part of that at build time. Read this before running any of them in an image; the
same four points, in more detail, are in `services/host/config/servicehost.php`.

1. **`php artisan config:cache` freezes `SERVICES`** into `bootstrap/cache/config.php`, so
   one image can no longer run a different service subset per stage. **Guarded in code**:
   the host refuses to boot when the configuration is cached *and* the process environment
   sets a `SERVICES` value that differs from the frozen one, naming both values. It stays
   silent when the configuration is not cached, when the environment sets no `SERVICES`,
   and when the two agree.
   **The guard reads the process environment only.** While the configuration is cached the
   framework does not load `.env` at all, so a `SERVICES` you change there afterwards is
   invisible both to the application and to the guard — the process boots the frozen list
   without complaining. If `SERVICES` lives in `.env` rather than in the container's
   environment, run `php artisan config:clear && php artisan config:cache` after every
   edit, or do not cache configuration on that host.
2. **`mergeConfigFrom()` is a no-op under a cached configuration**, so a service booted
   that way never contributes its database connection. *Documented only* — point 1's guard
   closes the dangerous half structurally.
3. **`php artisan route:cache` freezes the route table** the same way, and currently fails
   outright because of the stock closure route on `/`. *Documented only*; removing stock
   Laravel scaffolding is out of scope for now.
4. **`--classmap-authoritative` breaks the boot mechanism entirely** — such a loader
   answers "not found" for anything outside the classmap it was built with, whatever is
   registered with `addPsr4()` afterwards. *Documented only*, no detection code.

## Adding a service

```bash
cd services/host
php artisan svc:make billing
```

This scaffolds `services/billing/` with a composer package, a service provider, a routes
file mounted at `/api/v1/billing`, a `svc_billing` database connection, empty
`database/migrations/` and `src/Consumers/` directories, and a passing feature test.

Add `billing` to `SERVICES` to boot it; **no file inside `services/host/` needs to change
for that** — booting a generated service is a configuration change only, and `svc:make`
itself writes nothing into `services/host/`.

**Before it is a real, permanent service, two files inside `services/host/` do need one
edit each**, because neither tool expands wildcards in its path list:

- `services/host/phpstan.neon.dist` — add `../billing/src` (and `../billing/tests`) to `paths`
- `services/host/deptrac.yaml` — add `../billing/src` (and `../billing/tests`) to `paths`,
  plus a `Service_Billing` layer and its ruleset entry

`svc:make` prints this reminder when it generates the package, and
`QaToolchainRegistryCoverageTest` fails the gate if either edit is forgotten, so a service
cannot quietly end up unanalysed or unchecked. `phpunit.xml` needs no edit: its suites are
globbed.

## More detail

- [CLAUDE.md](CLAUDE.md) — tech stack, local environment paths, commands, conventions
- [docs/architecture.md](docs/architecture.md) — services, contracts, events, sagas
- [docs/backlog.md](docs/backlog.md) — task queue and status
