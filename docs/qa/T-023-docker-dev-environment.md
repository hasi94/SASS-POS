# QA Report: T-023 Docker development environment (FP-217)

## Verdict: FAIL

One concrete, reportable defect (BUG-1, major): the `mysql` service's healthcheck
in `docker-compose.yml` passes the root password with a corrupting leading space,
which would make MySQL healthcheck authentication fail forever once the engine
hold lifts and `docker compose up` is actually run — defeating `app`'s
`depends_on: mysql: condition: service_healthy` and leaving the stack unable to
start the application service. Everything else checked (AC-1 through AC-17,
AC-9/AC-10 consistency, AC-11 secrets hygiene, AC-12 to AC-16, the fencing
language for AC-17/AC-18/AC-19, the full gate) holds.

## Environment / constraints honoured

- `ENGINE_HOLD` respected: no `docker compose up`, no container started, no
  connection to any engine.
- Docker Desktop confirmed stopped before any Docker CLI command:
  `docker ps` / `docker info` returned a daemon-connection error (`open
  //./pipe/dockerDesktopLinuxEngine: The system cannot find the file
  specified.`), confirming the daemon was not running throughout this review.
- The one Docker-CLI check the task permits, `docker compose config`, needs no
  daemon and was run twice (`--quiet`, `--services`) plus once for
  `--format json`, independently of dev's own numbers.

## Acceptance criteria coverage

| AC | Test(s) / evidence | Result |
|----|---------|--------|
| AC-1 | `docker compose config --quiet` exit 0; `docker compose config --services \| sort` → `app`, `mailpit`, `mysql`, `redis` (exactly four) | PASS |
| AC-2 | JSON: `services.mysql.image = "mysql:8.4"` | PASS |
| AC-3 | JSON: `services.redis.image = "redis:7-alpine"` | PASS |
| AC-4 | JSON: `services.mailpit.image = "axllent/mailpit:v1.21.3"` (pinned, not `latest`); SMTP `127.0.0.1:1025`, UI `127.0.0.1:8025`, no collision with app (8000), mysql (13306), redis (16379), or XAMPP's conventional ports (80/443/3306/8080/21/25/110/143) | PASS |
| AC-5 | JSON: `services.app.build.context` ends in `infra/docker/app`; `infra/docker/app/Dockerfile` exists outside `vendor/`; `app` volume `.:/app` mounts the repo root for live-edit reflection | PASS |
| AC-6 | JSON: `services.app.depends_on` has all three of `mysql`, `redis`, `mailpit` | PASS |
| AC-7 | JSON: every `ports[].host_ip` across all four services is `127.0.0.1`, none `0.0.0.0` | PASS |
| AC-8 | JSON: `mysql-data` → `/var/lib/mysql`, `redis-data` → `/data`, both named top-level volumes (not bind mounts), so `docker compose down` (no `-v`) preserves them | PASS |
| AC-9 | `.env.docker.example`: `DB_MASTER_HOST=mysql` (service name, not `127.0.0.1`), `DB_MASTER_PORT=3306` (container-internal, matches `mysql`'s target port), `DB_MASTER_DATABASE=flexipos_master` = compose `MYSQL_DATABASE`, `DB_MASTER_USERNAME=flexipos` = compose `MYSQL_USER`, `DB_MASTER_PASSWORD=flexipos-dev-only` = compose `MYSQL_PASSWORD`. Kept separate from `.env.example` (different file); does not overwrite it (diff below) | PASS |
| AC-10 | `REDIS_HOST=redis` / `REDIS_PORT=6379` matches `redis`'s target port; `MAIL_HOST=mailpit` / `MAIL_PORT=1025` matches `mailpit`'s SMTP target port | PASS |
| AC-11 | Every credential in `.env.docker.example` and the compose `mysql.environment` block carries a `-dev-only` marker (`flexipos-root-dev-only`, `flexipos-dev-only`); `APP_KEY` is empty; no value matches a real credential anywhere else in the repo (grepped) | PASS |
| AC-12 | `docs/docker-dev-environment.md` is the only Docker-path document (`Glob docs/*docker*` → one file); README's addition is one pointer line, not a second description | PASS |
| AC-13 | Section 2's XAMPP table was diffed line-by-line against `CLAUDE.md`'s "Local environment" table — identical tool names, versions and absolute paths; commands line references the same `composer check`/`composer test:fast`/etc. names without contradiction | PASS |
| AC-14 | Section 1 states both paths are supported, neither deprecated, and gives a concrete reason for each (XAMPP: proven default, faster given Windows bind-mount cost; Docker: CI/VPS parity, cross-platform, no native MySQL/Redis needed) | PASS |
| AC-15 | `git diff --stat 98e716c...HEAD -- CLAUDE.md modules/host/.env.example modules/host/composer.json` is empty (`98e716c` = T-022's DONE tip, the correct base per this task's instructions, not `main`); `git log -1 --format=%H -- CLAUDE.md` confirms the file's last touch predates this branch's T-023 commits entirely | PASS |
| AC-16 | `git show 6c0b3cb --stat` touches only `README.md`, `docker-compose.yml`, `docs/docker-dev-environment.md`, `docs/plans/...`, `infra/docker/app/Dockerfile`, `modules/host/.env.docker.example` — no `composer.json`, no CI workflow file, no Pest test file | PASS |
| AC-17 | Fencing language present verbatim in the spec ("**NOT VERIFIABLE by an automated gate test in this task**... it is a manual/CLI check dev performs once and records the output of"); the check itself (`docker compose config`) was independently re-run above with the daemon stopped and exits 0 — the manual check this AC asks for is honoured, not skipped | PASS (fencing accurate; check reproduced) |
| AC-18 | Fencing language present ("optional, manual-only... not something `qa` fails the task over if Docker Desktop is unavailable"); Docker Desktop was confirmed stopped and not started for this review, so AC-18 is correctly left NOT RUN, matching the spec's own permission | PASS (correctly not attempted) |
| AC-19 | Fencing language present and accurate: spec explicitly lists `docker compose up`, a migration against the container MySQL, and an SMTP/HTTP round trip through Mailpit as NOT VERIFIABLE until G-1; the doc's own section 9 repeats this; no step anywhere in the task's files runs `up` | PASS |

## Test run summary

- `docker compose config --quiet` — exit 0.
- `docker compose config --services | sort` — `app`, `mailpit`, `mysql`, `redis`, exit 0.
- `docker compose config --format json` — captured to scratch, inspected directly (not trusted from dev's report); every field cross-checked above.
- `git diff --stat 98e716c...HEAD -- CLAUDE.md modules/host/.env.example modules/host/composer.json` — empty.
- `git status --short` — clean.
- `E:\xampp\php\php.exe vendor/bin/pest tests/Feature/QaVocabularyRenameSweepTest.php` — **5 passed** (35 assertions), 2.86s. No new file tripped it.
- `cd modules/host && composer check` — **2750 passed, 23 skipped, 12 failed** (19233 assertions). The 12 failures are all in `Tests\Feature\QaModuleRegistryContractTest` (6 confirmed by name in this run's output: "it fails fast when a named directory has no composer.json", "...manifest is not valid JSON", "...declares no psr-4 namespace", "...names no provider class", "...provider class cannot be autoloaded", "...names the offending module... rather than the first one in the list") and `Tests\Feature\QaModuleNameValidationTest` (the remaining 6, partially visible in the truncated tail). Re-ran both files **standalone** (`pest tests/Feature/QaModuleNameValidationTest.php tests/Feature/QaModuleRegistryContractTest.php`): **15/15 pass** in isolation — confirming this is the known full-suite-only state-pollution cluster, not a regression. Since `composer check`'s script chain (`@lint`, `@stan`, `@deptrac`, `@test` run via sequential composer sub-scripts) only reached `@test` because the prior three sub-scripts each exited 0, lint/stan/deptrac are confirmed passing. This matches dev's self-report — independently reproduced, not just trusted.

## Defects

### BUG-1 (major)
- **Steps**: Read `docker-compose.yml`'s `mysql.healthcheck.test` array, confirmed via `docker compose config --format json`:
  ```json
  "test": ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1", "-p flexipos-root-dev-only"]
  ```
- **Expected**: The password argument should be `-pflexipos-root-dev-only` (no space — MySQL client tools require the value immediately attached to `-p` with no separating space) or `--password=flexipos-root-dev-only`, so `mysqladmin` authenticates with the literal password `flexipos-root-dev-only` and the healthcheck can pass once run.
- **Actual**: The fifth array element is the single string `"-p flexipos-root-dev-only"` — `-p` followed by a literal space before the password, all inside one `CMD`-exec-form argv token (Docker's `HEALTHCHECK CMD` array form execs directly, with no shell, so this whole string is passed to `mysqladmin` as one argument). `mysqladmin`'s option parser treats everything after `-p` in that single token as the inline password value, so the password it actually sends is `" flexipos-root-dev-only"` — with a leading space — not the real password. This will not match the `MYSQL_ROOT_PASSWORD` the server was initialized with, authentication fails, the healthcheck never succeeds, `mysql` is never reported `healthy`, and `app`'s `depends_on: mysql: condition: service_healthy` blocks the application container from starting indefinitely once `docker compose up` is actually run (post-G-1 / FP-218).
- **Suspected location**: `docker-compose.yml`, `services.mysql.healthcheck.test`, line 71.
- **Why this is in scope now, despite the engine hold**: this is a static, structural defect visible directly in the parsed compose configuration (no engine needed to see it) — exactly the class of check this task's AC-17/plan Step 4 asks `qa` to perform against the JSON output. It does not fail any AC listed in the spec today (AC-19 explicitly defers the runtime proof), but it will silently break the stack the first time anyone follows the documented first-run steps after G-1, so it should be fixed before this stack is relied upon.
- **Not a fresh regression caused by dev's own test run**: confirmed by reading the file and the JSON directly; no container was started to observe this.

## Regression risks / notes

- No PHP file is touched by this task (confirmed via `git show 6c0b3cb --stat`), so the pre-existing `QaModuleNameValidationTest`/`QaModuleRegistryContractTest` cluster (12 failures, state-pollution only under the full suite, 15/15 green standalone) is unrelated to this task and should not block it on its own.
- The vocabulary-sweep risk the plan called out (pasting compose JSON prose into a swept file) was not triggered; all four new files pass `QaVocabularyRenameSweepTest`, and this report itself is in `docs/qa/`, which the plan notes as exempt.
- BUG-1 is cheap to fix (`-pflexipos-root-dev-only`, no space) and does not require touching anything else; re-verification can be done the same way (reading `docker compose config --format json`) without touching the engine hold.
- Everything else in the compose file, Dockerfile, env profile and documentation is structurally sound and internally consistent; this is a single-line defect, not a systemic one.
