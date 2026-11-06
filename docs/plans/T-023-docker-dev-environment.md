# Plan: T-023 Docker development environment (FP-217)

Spec: `docs/specs/T-023-docker-dev-environment.md` (AC-1 to AC-19). Decisions: `docs/decisions/T-023-docker-dev-environment.md` (D-1 to D-7), all of which this plan follows as written.

## Approach summary

- A root `docker-compose.yml` (compose project `flexipos-dev`) with exactly four compose services: `app`, `mysql` (`mysql:8.4`), `redis` (`redis:7-alpine`) and `mailpit` (a pinned `axllent/mailpit:v1.x.y`). The image tags match `docs/deployment-vps.md`.
- `app` is built from a project-owned `infra/docker/app/Dockerfile` (`php:8.2-cli-bookworm` plus the extensions XAMPP has, and phpredis). It serves HTTP with `php artisan serve --no-reload`, the same server `composer dev` uses natively. PHP-FPM would need a fifth service (a web server) to answer HTTP, and AC-1 allows exactly four, so the CLI server is the "or equivalent" that AC-1 permits.
- The **repository root** is bind-mounted at `/app`, not just `modules/host`. `modules/host/composer.json` resolves path repositories `../../packages/*` and `../*`, so the container needs the whole tree. Working directory: `/app/modules/host`.
- Named volumes keep the container's state separate from the host's, so the XAMPP path stays byte-for-byte unaffected (NFR "Reversibility"):
  - `mysql-data` (AC-8)
  - `redis-data` (AC-8; Redis runs with `--appendonly yes`)
  - `app-vendor`, mounted over `modules/host/vendor`. The host's vendor holds Windows junctions for the path packages, which do not resolve on Linux. Without this volume, a container `composer install` would also overwrite the XAMPP vendor tree.
  - `app-bootstrap-cache`, mounted over `modules/host/bootstrap/cache`. Cached config, routes and package manifests built inside the container carry `/app/...` paths and must never land in the host's tree.
- Env wiring: the developer copies `modules/host/.env.docker.example` to `modules/host/.env.docker`, which git ignores because `.env.*` is ignored and only `!.env.*.example` is re-included. Compose bind-mounts that file **over** `/app/modules/host/.env`, inside the container only. Laravel reads the Docker profile as its `.env`, `key:generate` writes into `.env.docker`, and the host's XAMPP `.env` is never read or written by the container.
- Every published host port is bound to `127.0.0.1` (AC-7 requires this for the database and the cache; it is applied to all four for consistency with `deployment-vps.md` rule 2). The defaults are chosen not to collide with XAMPP:
  - MySQL `127.0.0.1:13306`. **Never 3306**: that is XAMPP's MariaDB, which holds other projects' live data, and `phpunit.xml`'s `ENGINE_*` values point at it.
  - Redis `127.0.0.1:16379`
  - Mailpit SMTP `127.0.0.1:1025` and UI `127.0.0.1:8025`
  - app `127.0.0.1:8000`

  Each default can be overridden through a `FLEXIPOS_*_PORT` interpolation variable.
- Proof is static only (D-7, AC-19). `docker compose config` was **measured on this machine with the Docker daemon stopped**: Compose v2.40.0-desktop.1 exits 0, lists the compose services and emits JSON, even with a missing build context and a missing bind source. AC-17 therefore needs no running Docker Desktop. AC-18 (`docker build`) does need a running daemon, and stays optional.
- No change to `composer.json`, CI, any PHP source, `.env.example` or `CLAUDE.md` (AC-15, AC-16, D-2, D-4).

## Alternatives rejected

- **PHP-FPM + nginx:** needs a fifth service, which contradicts AC-1's "exactly four".
- **PHP-FPM alone:** it does not answer HTTP, so a browser cannot reach it.
- **Reusing `vendor/laravel/sail` runtimes:** AC-5 requires a project-owned Dockerfile. Sail's image also bundles Node, Postgres clients and supervisor, none of which we need.
- **`env_file:` instead of the file overlay:**
  - `key:generate` would write to the host's XAMPP `.env`.
  - Keys missing from the Docker profile would leak in from the XAMPP `.env`.
  - `artisan serve`'s reload mode strips `$_ENV` keys from its child process.
- **Interleaving the profile into `.env.example`:** D-5 forbids it.
- **Compose file under `infra/compose/`:** AC-1 names the repository root, and a root file makes `docker compose up` work with no `-f`. The Dockerfile does go under `infra/`, which matches README's description of `infra/` as the home of Docker images.
- **A Pest test that parses the compose file:** D-4 forbids it. It belongs to FP-218.
- **`mlocati/docker-php-extension-installer`:** a third-party dependency. The official image's `docker-php-ext-install` and `pecl` are enough.

## Files to change / create

- `docker-compose.yml` (new, repo root): the four compose services, healthchecks, named volumes, loopback port bindings.
- `infra/docker/app/Dockerfile` (new): the app image. Its build context is `infra/docker/app` and it copies no source (the code is bind-mounted), so no `.dockerignore` is needed and the build context stays tiny.
- `infra/.gitkeep`: delete it once `infra/` has real content (optional, cosmetic; leaving it is also fine).
- `modules/host/.env.docker.example` (new): the full Docker profile (AC-9, AC-10, AC-11).
- `docs/docker-dev-environment.md` (new): the single end-to-end document (AC-12, AC-13, AC-14).
- `README.md`: one pointer line under "Getting started" linking `docs/docker-dev-environment.md`. It does not describe the path, so AC-12's "exactly one document" still holds. Optional; drop it if the reviewer objects.
- **Not touched:** `CLAUDE.md`, `modules/host/.env.example`, `modules/host/composer.json`, `modules/host/phpunit.xml`, `.gitignore` (it already covers `.env.docker`), any PHP file.

## Data / API changes

No schema, endpoint, migration or runtime-code change. The configuration below is new and Docker-only.

### `docker-compose.yml`

**`app`**
- `build.context: ./infra/docker/app`, plus `image: flexipos-dev-app`.
- `working_dir: /app/modules/host`.
- `command: php artisan serve --host=0.0.0.0 --port=8000 --no-reload`. The 0.0.0.0 is the listen address *inside the container's network namespace*, which publishing requires. It is not a host binding; the doc says so explicitly, so AC-7 is not misread.
- `ports: "127.0.0.1:${FLEXIPOS_APP_PORT:-8000}:8000"`.
- `volumes`:
  - `.:/app`
  - a long-syntax bind of `./modules/host/.env.docker` onto `/app/modules/host/.env`, with `bind.create_host_path: false`, so a missing copy fails loudly instead of Docker creating a directory there
  - `app-vendor:/app/modules/host/vendor`
  - `app-bootstrap-cache:/app/modules/host/bootstrap/cache`
- `depends_on` (AC-6): `mysql: {condition: service_healthy}`, `redis: {condition: service_healthy}`, `mailpit: {condition: service_started}`.

**`mysql`**
- `image: mysql:8.4`.
- `environment`, with literal dev placeholders:
  - `MYSQL_ROOT_PASSWORD: flexipos-root-dev-only`
  - `MYSQL_DATABASE: flexipos_master`
  - `MYSQL_USER: flexipos`
  - `MYSQL_PASSWORD: flexipos-dev-only`
- `ports: "127.0.0.1:${FLEXIPOS_MYSQL_PORT:-13306}:3306"`.
- `volumes: mysql-data:/var/lib/mysql`.
- `healthcheck`: `mysqladmin ping -h 127.0.0.1` using the root password, with interval, timeout and retries set.

**`redis`**
- `image: redis:7-alpine`.
- `command: redis-server --appendonly yes`.
- `ports: "127.0.0.1:${FLEXIPOS_REDIS_PORT:-16379}:6379"`.
- `volumes: redis-data:/data`.
- `healthcheck: redis-cli ping`.

**`mailpit`**
- `image: axllent/mailpit:v1.x.y`. A full release tag that `dev` confirms exists on Docker Hub; never `latest`, although the Sail stub uses `latest`.
- `ports`: `"127.0.0.1:${FLEXIPOS_MAILPIT_SMTP_PORT:-1025}:1025"` and `"127.0.0.1:${FLEXIPOS_MAILPIT_UI_PORT:-8025}:8025"`.

**Top level:** `name: flexipos-dev`, and `volumes: {mysql-data, redis-data, app-vendor, app-bootstrap-cache}`.

### `infra/docker/app/Dockerfile`

1. `FROM php:8.2-cli-bookworm`. This matches XAMPP's 8.2 line.
2. `apt-get install` with `--no-install-recommends`: `git unzip libicu-dev libzip-dev libpng-dev`.
3. `docker-php-ext-install pdo_mysql intl bcmath gd zip pcntl`. mbstring, openssl, fileinfo and pdo_sqlite are already built into the official image. pdo_sqlite matters because the suite runs on SQLite.
4. `pecl install redis-<pinned 6.x>` followed by `docker-php-ext-enable redis`. phpredis is needed because `REDIS_CLIENT` defaults to `phpredis` in `config/database.php`.
5. `COPY --from=composer:2 /usr/bin/composer /usr/bin/composer`.
6. `WORKDIR /app/modules/host`.
7. Runs as root (see Risks). No `COPY` of application source.

### `modules/host/.env.docker.example`

A **complete** profile: every key `modules/host/.env.example` sets, in the same order, plus a header comment explaining the copy step and the overlay. A complete profile matters because the file *replaces* `.env` inside the container. The values that differ from `.env.example`:

| Key | Value |
|---|---|
| `APP_URL` | `http://localhost:8000` |
| `DB_CONNECTION` | `master` |
| `DB_MASTER_DRIVER` | `mysql` |
| `DB_MASTER_HOST` | `mysql` |
| `DB_MASTER_PORT` | `3306` (the container port, not the host port 13306) |
| `DB_MASTER_DATABASE` | `flexipos_master` |
| `DB_MASTER_USERNAME` | `flexipos` |
| `DB_MASTER_PASSWORD` | `flexipos-dev-only` |
| `REDIS_HOST` | `redis` |
| `REDIS_PORT` | `6379` |
| `CACHE_STORE` | `redis` |
| `QUEUE_CONNECTION` | `redis` |
| `EVENTBUS_DRIVER` | `redis` |
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | `mailpit` |
| `MAIL_PORT` | `1025` |

- `APP_KEY` stays empty: it is generated into `.env.docker`, never committed.
- `SESSION_DRIVER=database` and `MODULES=demo` are kept as they are.
- `APP_REQUIRED_SCHEMA_VERSION` is kept at **the same value as `.env.example`** (31 today), with a comment that the two must move together.
- The tenancy comments are kept.
- Note on AC-9's wording: the application reads `DB_MASTER_*`. Plain `DB_HOST` is not what the master connection uses, so `DB_MASTER_HOST` is the "MySQL host value" AC-9 means.

### Proposed `CLAUDE.md` addition (AC-15, D-2): held for the human, NOT applied by this task

The lead logs this for the human's sign-off, as with T-005/T-006/T-007. The proposed text is one paragraph under "Local environment", plus one row in the Commands table:

> **Docker alternative.** XAMPP above is the default path. A Docker Compose stack (app, MySQL 8.4, Redis 7, Mailpit) is the supported alternative; see [docs/docker-dev-environment.md](docs/docker-dev-environment.md). Its MySQL listens on `127.0.0.1:13306`, never 3306.
>
> | Docker stack up / down | `docker compose up -d` · `docker compose down` (data kept; `-v` deletes it) |

## Implementation steps

- [ ] **Step 1: Dockerfile.** Write `infra/docker/app/Dockerfile` as specified above, with a short header comment saying what the image is for and that it copies no source. Pin the phpredis version. (AC-5)

- [ ] **Step 2: Compose file.** Write `docker-compose.yml` exactly as specified above, with short comments covering:
  - why 13306 and not 3306;
  - why the container-internal 0.0.0.0 listen is not a host binding;
  - why vendor and bootstrap/cache are volumes;
  - why the `.env` is an overlay.

  Pin the Mailpit tag to a concrete `v1.x.y`. (AC-1 to AC-8)

- [ ] **Step 3: Env profile.** Write `modules/host/.env.docker.example` as specified above. Values are placeholders only, and every password contains `dev-only`. Then check:
  - `git check-ignore -v modules/host/.env.docker` reports it ignored;
  - `git check-ignore modules/host/.env.docker.example` reports it **not** ignored. (AC-9, AC-10, AC-11)

- [ ] **Step 4: Static structural proof** (AC-17; works with the daemon stopped, as measured). From the repo root:
  1. `docker compose config --quiet`: expect exit 0.
  2. `docker compose config --services | sort`: expect exactly `app`, `mailpit`, `mysql`, `redis`.
  3. `docker compose config --format json > <scratch>/compose.json`. With `E:\xampp\php\php.exe -r`, assert from that JSON:
     - every `ports[].host_ip` is `127.0.0.1` (AC-7);
     - the `mysql`, `redis` and `mailpit` images are as pinned and none is `latest` (AC-2, AC-3, AC-4);
     - `app.depends_on` has the other three keys (AC-6);
     - `mysql-data` and `redis-data` are mounted at the right targets (AC-8);
     - `app.build.context` ends in `infra/docker/app` (AC-5).
  4. **Consistency check, AC-9/AC-10:** compare the compose `mysql` environment and container ports to `.env.docker.example`'s `DB_MASTER_*`, `REDIS_*` and `MAIL_*` values, either with the same `php -r` script or by hand.

  If the `docker` CLI is absent from the verifying machine, AC-17 is recorded NOT VERIFIED with that reason. A YAML parse with `symfony/yaml` (already in vendor) can stand in for 1 and 2 only.

  `dev` records the exact commands and outputs in its hand-off. `qa` records them in `docs/qa/T-023-...`. **Never paste the JSON output into a swept file** (see Risks).

- [ ] **Step 5 (optional, manual, AC-18): build only.** Only if Docker Desktop is *already running* or the operator chooses to start it: `docker compose build app`, or `docker build infra/docker/app`. No `up`, no `run`, no `composer install` in the container. If the daemon is stopped, record "AC-18 not run: daemon stopped" and do not start it on the operator's behalf without asking. Either outcome is acceptable for the gate.

- [ ] **Step 6: Documentation.** Write `docs/docker-dev-environment.md` with these sections (AC-12, AC-13, AC-14, AC-19):
  1. *Two supported paths.* XAMPP is today's default and Docker is the alternative; neither is deprecated. Give at least one reason for each:
     - XAMPP: the proven default; faster on this team's Windows machines, where Docker Desktop bind mounts are slow.
     - Docker: closer to CI and VPS parity (MySQL 8.4, Redis 7); works on macOS and Linux; no Redis needed on the host.
  2. *XAMPP path.* Restate CLAUDE.md's Local-environment rows with identical versions and absolute paths, and link CLAUDE.md as the authority. Copy the values; do not paraphrase them.
  3. *Docker prerequisites.* Docker Desktop 28.5 / Compose v2.40, as measured.
  4. *First run:*
     - copy `.env.docker.example` to `.env.docker`
     - `docker compose build`
     - `docker compose run --rm --no-deps app composer install`
     - `docker compose run --rm --no-deps app php artisan key:generate`
     - `docker compose up -d`
     - the migration command

     Mark the migration step and every step that connects to MySQL as **not exercised by T-023 (ENGINE_HOLD, G-1)**.
  5. *Running artisan/composer:* `docker compose exec app php artisan ...` and `docker compose exec app composer ...`. Explain why `run` uses `--no-deps`.
  6. *Ports table and how to override them.* Explain why MySQL is 13306, and that the 0.0.0.0 listen is internal only.
  7. *Teardown:* `docker compose down` keeps data; `docker compose down -v` deletes the MySQL, Redis, vendor and cache volumes.
  8. *Differences from XAMPP.* MySQL 8.4 vs MariaDB 10.4.32 is **intentional** (`docs/architecture.md`; D-6). Also: Redis present; Mailpit at `http://127.0.0.1:8025`; separate vendor; never run `config:cache` on the host for the container, and vice versa.
  9. *Verification status.* What T-023 proved statically, and what stays NOT VERIFIABLE until G-1 (AC-19). FP-218 wires this into CI.

  Optionally add the one README pointer line.

- [ ] **Step 7: Gate.** From `modules/host`:
  1. Fast check first: `E:\xampp\php\php.exe vendor/bin/pest tests/Feature/QaVocabularyRenameSweepTest.php`. It sweeps every new file. Baseline measured green today: 5 passed, 2.79 s.
  2. Then `composer check`, the gate.
  3. Confirm `git diff --stat main...HEAD -- CLAUDE.md modules/host/.env.example modules/host/composer.json` is empty. (AC-15, AC-16, reversibility NFR)

## Test strategy

- **Unit / feature:** no new Pest test (D-4, AC-16, AC-17). The existing suite must stay green. The relevant existing guards:
  - `QaVocabularyRenameSweepTest` reads every non-exempt file, including all four new ones.
  - `QaHealthProbeResilienceTest` and `SchemaCataloguePublishTest` read `.env.example`, which is unchanged.
  - `DestructiveStatementContainmentTest` covers PHP only; no PHP is added.
- **Integration / e2e:** none. `docker compose up`, migrations against the container MySQL, and SMTP/HTTP through Mailpit are all **NOT VERIFIABLE until G-1** (AC-19, D-7).

Which check proves which AC:

| AC | Proof |
|---|---|
| AC-1 | Step 4.1 and 4.2 |
| AC-2, AC-3, AC-4 | Step 4.3 images and ports; port defaults reviewed against the XAMPP list (80/443/3306/8080/21/25/110/143) |
| AC-5 | Step 4.3 `build.context`, plus the file existing outside `vendor/` |
| AC-6 | Step 4.3 `depends_on` |
| AC-7 | Step 4.3 `host_ip` |
| AC-8 | Step 4.3 volumes |
| AC-9, AC-10 | Step 4.4 consistency check |
| AC-11 | Review: only `dev-only` placeholders, empty `APP_KEY`; `git check-ignore` results from Step 3 |
| AC-12 to AC-14 | Review of `docs/docker-dev-environment.md`; `qa` diffs its XAMPP section against CLAUDE.md's table |
| AC-15, AC-16 | Step 7.3 empty diff, plus no `composer.json` or CI change |
| AC-17 | Step 4 recorded output |
| AC-18 | Step 5, optional; "not run, daemon stopped" is acceptable |
| AC-19 | The doc's verification section, plus no step running `up` |

## Risks & rollback

- **Vocabulary sweep (the most likely way to turn the gate red).** All four new files are swept. The sweep forbids:
  - the quoted plural of the compose top-level key, e.g. in a pasted `--format json` output or a prose quote;
  - the plural followed by a slash;
  - the all-caps plural;
  - the three-letter abbreviation, in any case;
  - the hyphenated "-host" compound.

  The bare YAML key `services:` and `--services` are safe. Rule: write prose as "compose services", never quote the key, and keep compose JSON output in `docs/qa/` (exempt) only. Step 7.1 catches a slip in seconds.
- **Port 3306 confusion.** A compose MySQL on host 3306 would shadow or collide with the shared XAMPP MariaDB that the engine hold protects. The plan pins 13306. Reviewers should reject any change to 3306.
- **Unverified runtime (accepted by D-7).** The stack's runtime behaviour is unproven until G-1: `artisan serve --no-reload` env handling, MySQL healthcheck timing, phpredis build, Windows bind-mount performance. The likely first-run defects are a pecl version that fails to build, which AC-18 would catch, and slow first requests.
- **Tenant provisioning grants.** The `flexipos` MySQL user only has privileges on `flexipos_master`. Creating tenant databases needs wider grants. This is deferred to the post-G-1 follow-up together with AC-19's end-to-end proof. The doc says so; nothing is invented now.
- **Root inside the container.** On Linux hosts, files the container writes into `storage/` are root-owned. Docker Desktop on Windows and macOS hides this. Documented with the `chown` workaround; a UID/GID build argument is a follow-up if anyone uses Linux.
- **`APP_REQUIRED_SCHEMA_VERSION` drift.** The value is duplicated between the two env examples. A comment flags it; FP-218 can add a parity check.
- **Empty host `.env` created by the overlay.** If a developer has no `modules/host/.env`, the first `up` creates an empty mount-point file. Laravel treats an empty `.env` exactly like a missing one, so the XAMPP path behaves the same.
- **Rollback:** delete the four new files (and the README line). Nothing else depends on them.

## Questions for the human

None blocking. For the lead to carry forward:

1. The proposed `CLAUDE.md` text above needs the human's sign-off (D-2).
2. Spec observations I could not send to `ba` directly, because this agent has no messaging tool, so I am raising them through the lead rather than reinterpreting the spec silently:
   - **(a)** AC-1's "FPM or equivalent" combined with "exactly four" in practice rules out FPM. The plan uses `artisan serve` as the equivalent.
   - **(b)** AC-9's "MySQL host value" means `DB_MASTER_HOST`, because the application has no plain `DB_HOST` master connection.
   - **(c)** AC-17's premise that the daemon is not needed is now measured true. It does require the `docker` CLI to be installed.
