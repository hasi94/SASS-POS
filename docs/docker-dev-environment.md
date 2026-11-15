# FlexiPOS — Docker development environment

| | |
|---|---|
| Status | T-023 (FP-217) — the Docker Compose alternative to the native XAMPP path |
| Companion | [CLAUDE.md](../CLAUDE.md) (the XAMPP path, today's default) and [docker-compose.yml](../docker-compose.yml) |

## 1. Two supported paths

This project supports two local-development paths. Neither is deprecated by this
document; a developer may use either, or switch between them, without disrupting the
other.

- **XAMPP (today's default).** The proven path this team already uses on Windows.
  Faster day to day on this team's current Windows machines, where Docker Desktop's
  bind-mount filesystem performance is the main cost of the alternative below. No
  Docker Desktop install required. Documented in [CLAUDE.md](../CLAUDE.md)'s "Local
  environment" table.
- **Docker (the new alternative).** A Docker Compose stack bringing up the
  application, MySQL 8.4, Redis 7 and a mail catcher with one command. Closer to the
  CI and VPS topology (MySQL 8.4, Redis 7 — see [architecture.md](architecture.md) and
  [deployment-vps.md](deployment-vps.md)), works on macOS and Linux as well as
  Windows, and needs nothing installed on the host beyond Docker Desktop itself (no
  native MariaDB, no native Redis).

Pick whichever fits the machine in front of you. Switching later costs nothing: the
two paths use separate env files and separate dependency/cache volumes (section 8).

## 2. The XAMPP path

Restated from [CLAUDE.md](../CLAUDE.md), which is the authority — read it, not this
copy, if the two ever disagree:

| Tool | Path / version |
|---|---|
| PHP | `E:\xampp\php\php.exe` — 8.2.12 (pdo_mysql, mbstring, openssl, intl, bcmath, gd, zip, fileinfo) |
| Composer | `C:\ProgramData\ComposerSetup\bin\composer.phar` — run as `"E:\xampp\php\php.exe" "C:\ProgramData\ComposerSetup\bin\composer.phar" <args>` |
| MariaDB (XAMPP) | `E:\xampp\mysql\bin\` — 10.4.32, local dev only |
| Node / npm | 24.15 / 11.12 (on PATH) |
| Angular CLI | 21.2 (on PATH) |
| .NET SDK | 9.0.302 (on PATH); projects target `net8.0` |
| Docker Desktop | 28.5 |

Commands (from `modules/host`): `composer install`, `composer lint`, `composer stan`,
`composer deptrac`, `composer test`, `composer test:fast`, `composer check` — all
documented in CLAUDE.md's Commands table, unchanged by this document.

## 3. Docker prerequisites

- Docker Desktop with the Compose v2 plugin. Measured on this machine: Docker
  28.5.1, Compose v2.40.0-desktop.1.
- Nothing else: MySQL, Redis and the mail catcher all run as containers, not as
  native services.

## 4. First run

From the repository root:

1. Copy the env profile: `cp modules/host/.env.docker.example modules/host/.env.docker`.
   `modules/host/.env.docker` is git-ignored; the `.example` file is tracked.
2. Build the application image: `docker compose build`.
3. Install dependencies inside the container, once, before the first `up`:
   `docker compose run --rm --no-deps app composer install` (see section 5 for why
   `--no-deps`).
4. Generate the application key, written into `.env.docker` because that is the file
   mounted over the container's `.env`:
   `docker compose run --rm --no-deps app php artisan key:generate`.
5. Bring the stack up: `docker compose up -d`.
6. Run the migration against the containerized MySQL: `docker compose exec app php
   artisan migrate`.

**ENGINE_HOLD note:** step 5 (`up`) and step 6 (the migration) connect to a real MySQL
engine. T-023 authors and statically validates this stack only (`docker compose
config`, no daemon needed) — it does not run `up`, does not run a migration against the
container database, and does not exercise Mailpit end to end. Those steps are **not
exercised by T-023** and stay **NOT VERIFIABLE until G-1**; see section 9.

## 5. Running artisan and Composer inside the container

- `docker compose exec app php artisan <command>`
- `docker compose exec app composer <command>`

`exec` runs inside an already-running `app` container, so the other three services
stay as they are. `run --no-deps` (used only in the one-off first-run steps above,
before anything is up) skips starting `mysql`, `redis` and `mailpit` as a side effect
of a one-off command such as `composer install`, which needs none of them.

## 6. Ports and how to override them

| Service | Host binding | Override variable |
|---|---|---|
| app (HTTP) | `127.0.0.1:8000` | `FLEXIPOS_APP_PORT` |
| mysql | `127.0.0.1:13306` | `FLEXIPOS_MYSQL_PORT` |
| redis | `127.0.0.1:16379` | `FLEXIPOS_REDIS_PORT` |
| mailpit (SMTP) | `127.0.0.1:1025` | `FLEXIPOS_MAILPIT_SMTP_PORT` |
| mailpit (web UI) | `127.0.0.1:8025` | `FLEXIPOS_MAILPIT_UI_PORT` |

Every host binding is loopback-only (`127.0.0.1`), never `0.0.0.0`. **MySQL is
`13306`, never `3306`**: port 3306 is XAMPP's native MariaDB, which holds other
projects' live data, and is the engine `ENGINE_HOLD` protects — a compose file that
published `3306` could collide with it.

The application container's own `command` listens on `0.0.0.0:8000` *inside its
network namespace* — that is the address a process must bind to for Docker's port
publishing to reach it at all, and is not itself a host-visible binding. The host
only ever sees the loopback-bound side in the table above.

## 7. Teardown

- `docker compose down` stops the containers and keeps all four named volumes
  (`mysql-data`, `redis-data`, `app-vendor`, `app-bootstrap-cache`), so data and
  installed dependencies survive a restart.
- `docker compose down -v` additionally deletes those volumes: MySQL and Redis data
  are gone, and the next `up` needs a fresh `composer install`.

## 8. Differences from XAMPP

- **MySQL 8.4 vs MariaDB 10.4.32 — intentional.** This is a deliberate, already-settled
  project decision (`docs/architecture.md`; D-6 in
  `docs/decisions/T-023-docker-dev-environment.md`), not something this task
  reconciles. CI and the VPS profile both run MySQL 8.4; XAMPP stays on MariaDB
  10.4.32.
- **Redis is present** in the Docker path (cache, queues, the event bus transport).
  The XAMPP path's `.env.example` defaults to database-backed cache/queue instead,
  so a developer on XAMPP who wants Redis locally installs it separately.
- **Mailpit** catches outbound mail at `http://127.0.0.1:8025`. XAMPP's
  `.env.example` defaults `MAIL_MAILER=log` instead (mail written to the log file,
  no catcher).
- **Separate `vendor/`.** The container's `modules/host/vendor` and
  `modules/host/bootstrap/cache` are container-only named volumes, never the host's
  own `vendor/bootstrap/cache` directories. This is deliberate, not an oversight: the
  host's `vendor/flexipos/*` entries are Windows junctions into this repository's own
  path packages, which do not resolve inside a Linux container, and a container
  `composer install` must never overwrite the host's XAMPP-targeted `vendor/` tree.
  Do not run `php artisan config:cache` or `route:cache` on the host and expect the
  container to see it, or vice versa — the two trees, and their caches, are entirely
  separate.
- **Root inside the container.** On Linux hosts (not Windows/macOS, where Docker
  Desktop hides this), files the container writes under `storage/` are root-owned on
  the host afterwards. Work around it with `sudo chown -R $(id -u):$(id -g)
  modules/host/storage` from the host if this bites. A build-time UID/GID argument is
  a possible follow-up, not implemented here.

## 9. Verification status

T-023 proves this stack's **structure** only, without starting a real database
engine (`ENGINE_HOLD`, D-7):

- `docker compose config` parses the file without error and lists exactly four
  services (`app`, `mailpit`, `mysql`, `redis`) — verified, exit 0, with Docker
  Desktop stopped.
- The parsed configuration's images, ports, volumes, `depends_on` and build context
  match this document and the spec's acceptance criteria — verified against the
  compose file's own JSON output.
- `modules/host/.env.docker.example`'s `DB_MASTER_*`, `REDIS_*` and `MAIL_*` values
  are consistent with the compose file's `mysql`/`redis`/`mailpit` service
  definitions — verified.
- `git check-ignore` confirms `modules/host/.env.docker` is ignored and
  `modules/host/.env.docker.example` is not.

**Not verified by T-023, and not verifiable until G-1** (a verified dump of the 43
surviving databases on the shared development MySQL instance): `docker compose up`
against the `mysql`/`redis` services, a migration run against the container's MySQL,
and an actual SMTP/HTTP round trip through Mailpit. `docker build` (the application
image only, no `up`) is optional and manual; it was not run while authoring this
document because Docker Desktop was not running on the authoring machine, and
starting it was out of scope. FP-218 (the CI pipeline) is where this stack is
exercised end to end once the hold lifts.
