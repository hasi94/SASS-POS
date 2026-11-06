# T-023: Docker development environment

## Problem / goal

FlexiPOS currently has one real local-dev path: native XAMPP (PHP 8.2.12 on
`E:\xampp\php\php.exe`, MariaDB 10.4.32 on `E:\xampp\mysql\bin\`), documented in
`CLAUDE.md`. `docs/architecture.md` and `docs/deployment-vps.md` already describe a
Docker-based stack for CI and for the VPS (MySQL 8.4, Redis 7, the host application
image), but nothing today lets a developer who prefers (or whose machine needs)
Docker run the application locally without XAMPP. FP-217 asks for that second path —
application, MySQL, Redis, a mail catcher, as a Docker Compose stack — with the XAMPP
path kept and documented as the alternative, not replaced.

This is infrastructure-as-code only: compose/Dockerfile/env/doc authoring. It is not
an invitation to boot a real database engine as part of proving this task done — see
the engine-hold constraint below.

## Users affected

- Developers on this team who set up a new machine, or who prefer containers to a
  native XAMPP install (e.g. non-Windows machines, or Windows machines where XAMPP is
  undesirable).
- `dev`/`qa`/`reviewer` on later tasks (notably FP-218, the CI pipeline, which depends
  on this row and is where these compose files are actually exercised end-to-end).

## Current behaviour

- `CLAUDE.md`'s "Local environment" table documents only the native XAMPP toolchain
  (PHP, Composer, MariaDB, Node, Angular CLI, .NET SDK) with absolute Windows paths,
  and lists Docker Desktop as installed but "currently stopped" — present on the
  machine but not wired to any local-dev workflow.
- No `docker-compose.yml`, `compose.yaml` or project-owned `Dockerfile` exists
  anywhere in the repository today. The only Docker-related files found are inside
  third-party `vendor/` packages (`qossmic/deptrac`'s and
  `ta-tikoma/phpunit-architecture-test`'s own fixture compose files, and
  `laravel/sail`'s bundled runtime `Dockerfile`s and `mailpit.stub`) — none of these
  are project infrastructure and none are reused as-is.
- `docs/deployment-vps.md` already specifies a VPS Docker Compose topology
  (`pos-gateway`, `pos-web`, `pos-api`, `pos-worker`, `pos-scheduler`, `pos-mysql`
  on `mysql:8.4`, two Redis containers on `redis:7-alpine`) and `docs/architecture.md`
  states the stack includes "MySQL 8.4 in Docker and CI, MariaDB 10.4 in XAMPP mode" —
  this difference is a deliberate, already-settled project decision, not something
  this task reconciles or "fixes".
- `modules/host/.env.example` is written for the XAMPP/SQLite-style local default
  (`DB_CONNECTION=sqlite`, `REDIS_HOST=127.0.0.1`, `MAIL_MAILER=log`) with no
  Docker-service-name profile.
- `ENGINE_HOLD` is on project-wide: nothing may connect to a real MySQL/MariaDB
  engine until G-1 (a verified dump of all 43 surviving databases on the shared
  development MySQL instance) happens. This predates and outlives this task.

## User stories

- As a developer setting up this project for the first time, I want a Docker Compose
  stack that brings up the application, MySQL, Redis and a mail catcher with one
  command, so that I don't have to install XAMPP to start contributing.
- As a developer who already uses XAMPP, I want the existing native path to keep
  working exactly as documented today, so that this task does not disrupt my current
  setup.
- As a developer choosing between the two paths, I want one clear document that lays
  out both options side by side (what each gives you, what it costs, how to switch),
  so that I can pick the right one for my machine without reverse-engineering compose
  files.
- As `dev`/`qa` on this and later tasks, I want the Docker Compose stack's structure
  (service names, images, ports, env wiring) to be provable by static checks, so that
  I can verify this task is done without breaching the engine hold.

## Acceptance criteria

### Compose stack definition

- AC-1: Given the repository root, When `docker-compose.yml` (or `compose.yaml`) is
  parsed as YAML, Then it parses without error and declares exactly four services:
  one application service (PHP 8.2+ FPM or equivalent, running the host
  application), one MySQL service, one Redis service, one mail-catcher service
  (Mailpit, matching the stub already present in this repo's `laravel/sail`
  dependency and the convention it ships).
- AC-2: Given the MySQL service definition, When its `image` key is read, Then it
  pins MySQL **8.4** (a specific tag, not `latest`), matching
  `docs/architecture.md`'s and `docs/deployment-vps.md`'s stated stack — this is a
  deliberate version difference from XAMPP's MariaDB 10.4.32, not something this task
  reconciles.
- AC-3: Given the Redis service definition, When its `image` key is read, Then it
  pins Redis **7** (a specific tag, not `latest`).
- AC-4: Given the mail-catcher service definition, When its `image` key is read,
  Then it is Mailpit, with its web UI port and SMTP port both published to the host
  on loopback-friendly default ports that do not collide with the other services or
  with the ports XAMPP's own stack conventionally uses.
- AC-5: Given the application service definition, When it is read, Then it either
  builds from a project-owned `Dockerfile` (present in the repository, not reused
  from `vendor/`) or is documented explaining why a build is not needed; it mounts
  the repository (or `modules/host`) as a volume so local edits are reflected without
  a rebuild.
- AC-6: Given the compose file, When service dependencies are read, Then the
  application service declares `depends_on` for the MySQL, Redis and mail-catcher
  services.
- AC-7: Given the compose file, When any service's host-port bindings are read, Then
  none binds to `0.0.0.0` by default for the database or cache services (loopback
  only), consistent with the binding discipline already established in
  `docs/deployment-vps.md` rule 2 for the VPS profile — this is a local-dev stack and
  the same discipline applies at dev scale.
- AC-8: Given the compose file, When named volumes are read, Then MySQL data and
  Redis data each have a dedicated named volume so `docker compose down` (without
  `-v`) preserves data across restarts.

### Environment wiring

- AC-9: Given `.env.example` (or a new `.env.docker.example`, per the decision log),
  When its Docker-profile entries are read, Then they use the compose service names
  as hostnames (e.g. the MySQL host value is the MySQL service name, not
  `127.0.0.1`), are clearly separated from and do not overwrite the existing
  XAMPP-oriented values, and include matching credentials/database name consistent
  with the compose file's MySQL service environment variables.
- AC-10: Given the Docker-profile env file, When its Redis and mail entries are read,
  Then the Redis host/port match the compose Redis service, and
  `MAIL_HOST`/`MAIL_PORT` match the compose Mailpit service's SMTP port.
- AC-11: Given the repository, When searched for committed secrets, Then the Docker
  profile's example env file contains only placeholder/default-dev credentials (no
  real secret, no value copied from a real `.env`), consistent with `CLAUDE.md`'s
  standing rule never to commit real `.env` values.

### Documentation

- AC-12: Given `docs/` after this task, When searched, Then exactly one document
  (either a new `docs/docker-dev-environment.md` or a clearly-labelled new section in
  an existing doc, per the decision log) describes the Docker path end to end:
  prerequisites (Docker Desktop version), the exact commands to bring the stack up,
  how to run `artisan`/`composer` commands inside the app container, how to tear it
  down, and how it differs from the XAMPP path (notably the MySQL 8.4 vs MariaDB
  10.4.32 version difference, called out explicitly as intentional).
- AC-13: Given the same document, When the XAMPP path is described, Then it matches
  `CLAUDE.md`'s existing "Local environment" table and commands without
  contradiction (same tool versions, same absolute paths), presented as today's
  default, with Docker presented as the alternative — matching FP-217's own wording,
  not the reverse.
- AC-14: Given the same document, When a developer follows it to decide which path
  to use, Then it states plainly that both paths are supported, neither is being
  deprecated by this task, and gives at least one concrete reason to prefer each
  (e.g. XAMPP: already the proven default, faster on this team's current Windows
  machines; Docker: closer to CI/VPS parity, works on non-Windows machines).
- AC-15: Given this task's documentation changes, When `CLAUDE.md` itself is
  inspected, Then it is **not edited** by this task — any proposed addition to its
  "Local environment"/"Commands" sections is instead written up as a decision for
  the human's eventual sign-off (matching the T-005/T-006/T-007 precedent of a
  proposed `CLAUDE.md` edit held for the human, not applied), and the spec/plan say
  so explicitly.

### Structural proof (this task's "done", without booting a real engine)

- AC-16: Given FP-218 (the CI pipeline) depends on this row, When this task's scope
  is read, Then it is limited to authoring and structurally validating the compose
  file, Dockerfile(s), env wiring and docs — it does **not** include wiring these
  files into `composer check`, CI, or any other automated gate; that is FP-218's job.
- AC-17: Given the compose file, When a structural check parses it (e.g. `docker
  compose config` in validate-only mode, or an equivalent YAML/schema check that does
  not start containers), Then it exits successfully and the parsed service list
  matches AC-1's four services — this check does not require Docker Desktop to be
  running a daemon that connects to any engine, only Compose's own file validation.
  **NOT VERIFIABLE by an automated gate test in this task** (no such test is added
  to the Pest suite); it is a manual/CLI check dev performs once and records the
  output of.
- AC-18: Given the application Dockerfile, When it is built locally (`docker build`,
  no `up`, no engine connection), Then the build succeeds. This is an **optional,
  manual-only** verification step — it requires Docker Desktop running on the
  operator's machine, is not required for this task's gate, and is not something
  `qa` fails the task over if Docker Desktop is unavailable in the verification
  environment.
- AC-19: Given the engine hold, When any AC in this list is evaluated, Then no AC
  requires `docker compose up` against the MySQL or Redis services, no AC requires a
  running migration against the compose MySQL container, and no AC requires an
  actual SMTP/HTTP round trip through Mailpit — any such end-to-end proof is
  explicitly **NOT VERIFIABLE until G-1**, matching this backlog's existing pattern
  for engine-gated criteria (e.g. FP-363, FP-383's cluster), and is left for FP-218
  or a later task once the hold lifts.

## Out of scope

- Actually running `docker compose up` against the MySQL/Redis services and proving
  the application works end to end against the containerized database — blocked by
  `ENGINE_HOLD` until G-1; left for a follow-up once the hold lifts.
- Wiring these Docker files into `composer check`, GitHub Actions, or any CI
  pipeline — that is FP-218.
- Editing `CLAUDE.md` itself — proposed as a decision for the human, not applied by
  this task (see AC-15).
- MySQL/MariaDB version reconciliation between XAMPP (MariaDB 10.4.32) and Docker/CI
  (MySQL 8.4) — already a settled, deliberate project decision per `CLAUDE.md` and
  `docs/architecture.md`; not reopened here.
- Production/VPS compose topology (gateway, worker, scheduler, multi-network
  segmentation) — that is `docs/deployment-vps.md`'s existing scope (FP-221), not
  this local-dev task's.
- Frontend (`frontend/`) and Print Agent (`print-agent/`) containerization — FP-219
  and FP-220, already claimed by other worktrees per the backlog's T-023 status note.
- Any change to the application's runtime code.

## Non-functional requirements

- Secrets hygiene: the Docker-profile example env file contains only placeholder
  values, per `CLAUDE.md`'s standing rule and AC-11.
- Reversibility: nothing in this task modifies code paths used by the XAMPP path;
  a developer who never touches Docker sees no behavioural change.

## Open questions

None blocking. All open questions below were resolved by `ba` per the TEAM PROTOCOL
and logged in `docs/decisions/T-023-docker-dev-environment.md`.

## Assumptions

- "Mail catcher" means Mailpit, matching the convention already present via this
  repo's `laravel/sail` dependency (`stubs/mailpit.stub`) and current Laravel
  ecosystem default; MailHog (Mailpit's predecessor) is not used.
- "Application" service means the PHP/Laravel host application
  (`modules/host`), consistent with `CLAUDE.md`'s module-boosted-monolith
  description; it does not include a separate container per module, matching the
  "all modules share one application, one database connection" rule already stated
  in `CLAUDE.md`.
- The Docker stack targets developer workstations (any OS with Docker Desktop), not
  the VPS profile in `docs/deployment-vps.md`, which remains the authoritative
  source for the production topology.
- FP-217's "XAMPP path documented as the alternative" is read as: XAMPP stays the
  default (matching `CLAUDE.md`'s current framing and the backlog's own wording),
  Docker is the new, additional, equally-supported path — not a replacement.
