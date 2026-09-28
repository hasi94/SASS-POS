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

## Adding a service

```bash
cd services/host
php artisan svc:make billing
```

This scaffolds `services/billing/` with a composer package, a service provider, a routes
file mounted at `/api/v1/billing`, a `svc_billing` database connection, empty
`database/migrations/` and `src/Consumers/` directories, and a passing feature test. Add
`billing` to `SERVICES` to boot it; no file inside `services/host/` needs to change.

## More detail

- [CLAUDE.md](CLAUDE.md) — tech stack, local environment paths, commands, conventions
- [docs/architecture.md](docs/architecture.md) — services, contracts, events, sagas
- [docs/backlog.md](docs/backlog.md) — task queue and status
