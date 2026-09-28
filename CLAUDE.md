# FlexiPOS — project guide

Multi-tenant SaaS POS platform. Service-oriented Laravel backend, Angular POS/admin, C#/.NET Print Agent.

**Read before any design or code:** [docs/architecture.md](docs/architecture.md) (services, contracts, events, sagas), [docs/database.md](docs/database.md) (schema per service), [docs/backlog.md](docs/backlog.md) (task queue), [docs/deployment-vps.md](docs/deployment-vps.md).

## Tech stack

Backend PHP 8.2+ / Laravel (service packages booted by `services/host`) · MySQL 8.4 in Docker and CI, MariaDB 10.4 in XAMPP mode · Redis 7 (cache, queues, Streams event bus) · Angular 21 PWA (`frontend/`) · .NET 8 Worker Service (`print-agent/`) · Traefik gateway · Docker Compose.

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

- Services are Composer path packages under `services/<name>/`, booted by `services/host` from the `SERVICES` env var. A service owns its own database schema; **never** query another service's tables. Cross-service calls go through interfaces in `packages/contracts`; everything else is an event on Redis Streams.
- Branches `ai/<ID>-<slug>`; commits `<ID>: <title>`; conventional-commit prefixes for non-task work.
- Never commit `.env`, real secrets or `vendor/`. Never push, merge, deploy or delete branches.
