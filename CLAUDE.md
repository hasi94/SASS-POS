# FlexiPOS — project guide

Multi-tenant SaaS POS **and ERP** platform. Modular Laravel monolith, Angular POS/admin, C#/.NET Print Agent.

**Read before any design or code:** [docs/architecture.md](docs/architecture.md) v0.4 (master and tenant databases, modules, login flow), [docs/database.md](docs/database.md) v0.4 (master schema, tenant schema), [docs/backlog.md](docs/backlog.md) (task queue), [docs/deployment-vps.md](docs/deployment-vps.md). `Refference/ARCH.md` is the product owner's authoritative decision document and outranks anything that contradicts it.

## The architecture in one paragraph

**One master database** holds the login identity, tenants, subscriptions, licensing and the mapping from tenant to database. **One database per shop** holds all of that shop's business data, with no tenant column, because the database itself is the boundary. A user logs in against the master, which resolves which tenant they may use and where its database lives; the backend then builds the tenant connection from server-side configuration. The frontend never names a database. Many tenant databases share a MySQL server, and the mapping carries host and port so they can spread across servers.

## Tech stack

Backend PHP 8.2+ / Laravel, one application with modules booted from configuration · MySQL 8.4 in Docker and CI, MariaDB 10.4 in XAMPP mode · Redis 7 (cache, queues, Streams event bus) · Angular 21 PWA (`frontend/`) · .NET 8 Worker Service (`print-agent/`) · Docker Compose.

**Vocabulary note:** the code still says `services/` and `svc:make` from the pre-v0.4 design. The rename to `modules/` and `module:make` is backlog row FP-202. Until it lands, the paths below are correct as written.

## Local environment (Windows 11, this machine)

PHP and Composer are **not on PATH**. Use these absolute paths in every command:

| Tool | Path / version |
|---|---|
| PHP | `E:\xampp\php\php.exe` — 8.2.12 (pdo_mysql, mbstring, openssl, intl, bcmath, gd, zip, fileinfo) |
| Composer | `C:\ProgramData\ComposerSetup\bin\composer.phar` — run as `"E:\xampp\php\php.exe" "C:\ProgramData\ComposerSetup\bin\composer.phar" <args>` |
| MariaDB (XAMPP) | `E:\xampp\mysql\bin\` — 10.4.32, local dev only |
| Node / npm | 24.15 / 11.12 (on PATH) |
| Angular CLI | 21.2 (on PATH) |
| .NET SDK | 9.0.302 (on PATH); projects target `net8.0` |
| Docker Desktop | 28.5 (currently stopped; start it before compose work) |

In Git Bash use forward slashes: `/e/xampp/php/php.exe`, `/c/ProgramData/ComposerSetup/bin/composer.phar`.

## Commands

Run from the repo root unless stated otherwise. `composer` below means the full PHP + composer.phar invocation above.

| Purpose | Command |
|---|---|
| Install backend deps | `cd services/host && composer install` |
| Lint (format check) | `cd services/host && composer lint` (Pint `--test`) |
| Lint (fix) | `cd services/host && composer lint:fix` |
| Static analysis | `cd services/host && composer stan` (PHPStan) |
| Boundary rules | `cd services/host && composer deptrac` |
| Unit + feature tests | `cd services/host && composer test` (Pest) |
| All checks (CI gate) | `cd services/host && composer check` |
| Frontend install / test | `cd frontend && npm ci` · `npm test` |
| Print agent build / test | `cd print-agent && dotnet build` · `dotnet test` |

## Conventions

- Modules are Composer path packages booted by the host from the `SERVICES` environment variable. Module boundaries are enforced by the boundary check: a module may use the kernel and another module's published contracts, never its internals.
- All modules share the **tenant** connection; there is no connection per module. Foreign keys across modules inside a tenant database are normal. Financial documents still snapshot what they need to be reprinted exactly.
- Every schema change runs against **every tenant database**. Use the per-tenant migration runner, follow expand-and-contract, and never assume one database.
- Branches `ai/<ID>-<slug>`; commits `<ID>: <title>`; conventional-commit prefixes for non-task work.
- Never commit `.env`, real secrets or `vendor/`. Never push, merge, deploy or delete branches.
