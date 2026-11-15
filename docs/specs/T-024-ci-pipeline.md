# T-024: CI pipeline

## Problem / goal

Every quality check this project relies on — format (`composer lint`), static
analysis (`composer stan`), module/package boundaries (`composer deptrac`), the full
test suite (`composer test`), and the per-tenant migration runner — exists today only
as a command someone runs by hand on their own machine (`CLAUDE.md`'s Commands
table). Nothing runs these automatically when code is pushed or a pull request is
opened, so a defect that would have been caught by one of these checks can land on a
branch, or reach a PR, with nobody having run the check at all. FP-218 asks for a CI
pipeline that runs format, static analysis, boundary, and test checks, plus
migration tests against both a fresh database and one that already carries prior
migration state, automatically on every push and pull request.

## Users affected

- `dev`, `qa`, `reviewer` and the lead — everyone on this team who pushes code or
  reviews a pull request gains an automatic, independent confirmation that the
  checks they already run locally (or are supposed to) actually pass, without
  relying on self-reported "I ran composer check" claims.
- Future human contributors to this repository, who get the same automatic
  confirmation on a PR without needing this project's local toolchain paths
  memorized from `CLAUDE.md`.

## Current behaviour

No CI configuration exists anywhere in the repository today — `.github/workflows/`,
`.gitlab-ci.yml` and equivalent locations were checked and are empty/absent. Every
check (`composer lint`, `composer stan`, `composer deptrac`, `composer test`,
`composer check`) is a local command, run manually, documented only in `CLAUDE.md`.
The per-tenant migration runner (`php artisan tenant:migrate`, shipped in T-008) and
its fixtures (`ThreeTenantFixture`, the T-011 fixture baseline) are exercised today
only by the Pest suite's own stand-in connection, never against a real MySQL engine,
because of `ENGINE_HOLD`. T-023 built a Docker Compose dev stack (MySQL 8.4, Redis 7,
Mailpit) but deliberately proved it only structurally (`docker compose config`, no
daemon) and explicitly named this task, FP-218, as "where this stack is exercised end
to end once the hold lifts" — a framing this spec narrows per D-1 and D-3: this task
does not run the T-023 Compose stack at all; it uses its own CI-native database
service instead (see decisions).

## User stories

- As a developer on this team, I want every push and pull request to automatically
  run format, static-analysis, boundary and test checks, so that a defect those
  checks would catch is visible before it reaches review rather than depending on
  someone remembering to run `composer check` locally.
- As a developer on this team, I want the pipeline to also run the per-tenant
  migration runner against a real, disposable database — once from empty and once
  against a database that already has some migrations applied — so that a migration
  defect invisible to the stand-in connection (e.g. an engine-specific SQL difference,
  or a migration that only works when run first) is caught automatically rather than
  waiting for the engine hold to lift and someone to notice by hand.
- As a reviewer, I want a pull request to show pass/fail status for each check
  independently (format, static analysis, boundaries, tests, each migration case),
  so that I can see at a glance which specific check failed rather than one opaque
  "build failed" signal.

## Acceptance criteria

All acceptance criteria below are statically verifiable — by `dev` or `qa` parsing
and inspecting the committed workflow file's structure, the same idiom T-023 used for
`docker compose config` — **except** those explicitly marked **[NOT VERIFIABLE UNTIL
A REAL RUN]**, which describe the pipeline's actual runtime outcome once GitHub
Actions executes it on a real push or pull request; nobody on this team can trigger
that today, so those criteria cannot be claimed as passing from static inspection
alone (D-12).

### Group A — Pipeline existence, trigger and job inventory

- AC-1: Given the repository, When `.github/workflows/<name>.yml` (exact filename
  left to `dev`) is parsed as YAML, Then it parses without error.
- AC-2: Given the parsed workflow, When inspecting its `on:` block, Then it declares
  both `push` and `pull_request` triggers, neither restricted by a `branches:`,
  `paths:` or `paths-ignore:` filter (D-7).
- AC-3: Given the parsed workflow, When inspecting its jobs, Then at least one job's
  steps include, in order, `composer install` followed by four distinct steps
  running `composer lint`, `composer stan`, `composer deptrac` and `composer test`
  (D-5, D-6) — never `composer test:fast` in place of the last one.
- AC-4: Given the parsed workflow, When inspecting the job identified in AC-3, Then
  it declares no `services:` block (no live MySQL or Redis attached to the
  format/static-analysis/boundary/test job — D-4, and keeping ENGINE_HOLD's spirit
  for everything outside the two migration jobs below).
- AC-5: Given the parsed workflow, When inspecting its jobs, Then at least two
  further, distinct jobs exist whose steps invoke the per-tenant migration runner
  (`php artisan tenant:migrate`) — one covering the fresh-database case (Group B)
  and one covering the existing-database case (Group C).
- AC-6: Given any job in the workflow, When inspecting its steps, Then no step
  invokes `docker compose` or references `docker-compose.yml` (D-3 — the CI database
  is its own GitHub Actions `services:` block, never the T-023 dev stack).

### Group B — Migration test, fresh database

- AC-7: Given the fresh-database migration job, When inspecting its `services:`
  block, Then it declares a `mysql` service using image `mysql:8.4` (D-11, matching
  `docker-compose.yml`'s version choice) with no `volumes:` entry mapping to
  anything outside the container itself (so nothing persists past the job, keeping
  this container the "ephemeral, disposable" kind D-1's ruling describes).
- AC-8: Given the fresh-database migration job, When inspecting its steps, Then
  after `composer install` it creates and registers a tenant database in that
  service container — the master schema migrated, the migration catalogue
  published (`schema:publish`), a `database_servers` row, a `tenants` row, and a
  `tenant_databases` mapping row all written for it, with zero prior rows in its
  own migration-tracking table — then invokes `php artisan tenant:migrate
  --tenant=<id>` against that database with no `--to` flag (i.e. to head), and the
  job is configured to fail (non-zero exit propagated to the job result) if that
  command exits non-zero.
- AC-9: **[NOT VERIFIABLE UNTIL A REAL RUN]** Given the fresh-database migration job
  actually executes on a real push or pull request, When `tenant:migrate` runs
  against the brand-new, empty tenant database, Then it completes with exit code 0,
  the master's `tenant_databases.schema_version` for that tenant ends at the latest
  shipped migration version, and the tenant's own migration-tracking table records
  every migration name up to that version, with no error logged for any individual
  migration file.
- AC-10: **[NOT VERIFIABLE UNTIL A REAL RUN]** Given the fresh-database migration
  job's service container, When the job finishes (success or failure), Then the
  container and any data written to it are destroyed with the job and nothing
  persists to a subsequent run.

### Group C — Migration test, existing database

- AC-11: Given the existing-database migration job, When inspecting its `services:`
  block, Then it declares a `mysql` service matching AC-7's shape (its own
  independent service container, not shared with or reused from the fresh-database
  job — D-9's two-step design needs its own tenant database to carry a partial
  migration history before the second step runs).
- AC-12: Given the existing-database migration job, When inspecting its steps, Then
  after `composer install` and creating and registering a tenant database (master
  schema migrated, catalogue published, a `database_servers` row, a `tenants` row
  and a `tenant_databases` mapping row all written for it), it first invokes
  `php artisan tenant:migrate --tenant=<id> --to=<head-1>` (one version short of
  the latest shipped migration, D-9) against that database, then invokes a second,
  separate `php artisan tenant:migrate --tenant=<id>` with no `--to` flag against
  the same database, and the job is configured to fail if either invocation exits
  non-zero.
- AC-13: **[NOT VERIFIABLE UNTIL A REAL RUN]** Given the existing-database migration
  job actually executes on a real push or pull request, When the first
  `tenant:migrate --to=<head-1>` invocation completes, Then the master's
  `tenant_databases.schema_version` for that tenant reads `head-1` and the
  tenant's own migration-tracking table records every migration name up to and
  including version `head-1`, and the database is left in that partial state for
  the second invocation to act on.
- AC-14: **[NOT VERIFIABLE UNTIL A REAL RUN]** Given the existing-database migration
  job's database already carries migrations up to `head-1` (AC-13), When the second
  `tenant:migrate` invocation (no `--to`) runs against it, Then it completes with
  exit code 0, applies only the remaining migration(s) needed to reach head, and
  does not re-apply, duplicate, or error on any migration already recorded as run.
- AC-15: **[NOT VERIFIABLE UNTIL A REAL RUN]** Given the existing-database migration
  job has already reached head (AC-14), When `tenant:migrate` is invoked a third
  time against the same database with nothing pending, Then it completes with exit
  code 0, reports zero pending/planned migration jobs for that run, and neither
  the tenant's own migration-tracking table nor the master's recorded
  `tenant_databases.schema_version` changes as a result (idempotency check folded
  into this job per D-9).

### Group D — Isolation, credentials and safety

- AC-16: Given any job's `services:` block in the workflow, When inspecting its
  environment variables, Then database credentials are inline placeholder values
  (e.g. a literal dev-only password string), never a reference to
  `secrets.<name>` or any value that could plausibly be a real production
  credential (D-10).
- AC-17: Given the whole workflow file, When inspecting every value written as a
  database host or port, Then no host other than `127.0.0.1` and no port other
  than the job's own mapped service port (e.g. a reference to
  `job.services.mysql.ports`) appears anywhere — the constraint is that nothing in
  this workflow ever points at the shared/real engine's conventional address (the
  one `ENGINE_HOLD` protects, or any tenant database outside the two throwaway CI
  service containers), not that no address may be named at all; a job naming
  `127.0.0.1` and its own ephemeral mapped port to reach its own service container
  satisfies this AC.
- AC-18: Given the workflow file, When inspecting every job for a `continue-on-error`
  flag, Then none is set — a failed step stops its job (default GitHub Actions
  behaviour), so a lint-only failure, for example, does not let a later step mask it
  by still reporting job success.

### Group E — Negative and edge cases

- AC-19: Given a pull request that introduces only a formatting violation (would
  fail `composer lint`) with no static-analysis, boundary, or test defect, When the
  workflow runs, Then (by AC-3's step ordering and AC-18's fail-fast default) the
  lint step fails and the stan/deptrac/test steps in the same job do not run or are
  marked skipped. **[NOT VERIFIABLE UNTIL A REAL RUN]** for the actual skip reporting;
  the ordering and fail-fast configuration themselves are statically verifiable now.
- AC-20: Given a push that touches only files under `docs/` (for example, a spec),
  When the workflow triggers (AC-2's no-path-filter design), Then every job still
  runs — this project has no CI-exempt change category, so a documentation-only push
  still gets the full gate.
- AC-21: Given the fresh-database job's tenant database starts with zero rows in its
  migration-tracking table (AC-8), and given a hypothetical migration file that is
  only valid when some earlier migration has already run (an ordering defect), When
  `tenant:migrate` runs against that empty database, Then such a defect is surfaced
  as a non-zero exit in this job — this is the scenario this job exists to catch,
  distinct from the existing-database job's partial-history scenario. (Design
  statement, not a claim this exact defect exists in the shipped tree today.)

## Out of scope

- Running the T-023 Docker Compose dev stack (`docker-compose.yml`) itself in CI, or
  any change to that file or `docs/docker-dev-environment.md` (D-3).
- `tenant:provision` end-to-end CI coverage, a release-to-release upgrade job
  (`docs/database.md` §14's aspirational "migrate-from-previous-release" job), a
  schema-drift diff against a committed dump, and a deliberately-broken-tenant
  failure-isolation case — all described in `docs/database.md` §14 as a later-phase
  CI shape, but none buildable today because the commands or artefacts they need
  (`tenant:provision`, a tagged prior release, a committed schema dump) do not exist
  yet (D-8, D-9). Left for whenever those land.
- A live Redis service in CI (D-4).
- Deployment, release tagging, or any step beyond running checks and reporting
  pass/fail (`docs/releases/` and devops-engineer concerns, not this task).
- Caching strategy for Composer/npm dependencies between CI runs, matrix testing
  across multiple PHP versions, and any other CI performance optimization — left to
  `dev`/`architect` as implementation detail, not a business rule this spec needs to
  pin.
- Branch protection rules requiring this workflow to pass before merge (a GitHub
  repository-settings change, not a file this task's role can edit or verify, and
  not named in FP-218).
- Frontend (`frontend/`) and print-agent (`print-agent/`) test pipelines — FP-218's
  wording and the Commands table section this task reads from are both
  backend/`modules/host`-scoped; a frontend/print-agent CI job is a separate backlog
  concern if and when one is wanted.

## Non-functional requirements

- The pipeline must be able to run unattended on GitHub's hosted runners with no
  manual setup step beyond what the workflow file itself declares (no requirement to
  pre-provision a persistent database, secret, or external service).
- Nothing in the workflow may write to, or depend on, any database outside its own
  job-scoped `services:` containers (AC-17) — this is the condition D-1's exemption
  was granted on, and the spec treats it as a hard constraint, not a preference.

## Open questions

None — the one genuinely open business question this task raised (whether
`ENGINE_HOLD` covers a CI-only disposable database) was answered directly by the
project owner (D-1); every other open point was a `ba` decision, logged in
`docs/decisions/T-024-ci-pipeline.md` (D-2 through D-13).

## Assumptions

- The workflow operates against `modules/host` as its working directory for all
  Composer/Artisan commands, matching every command in `CLAUDE.md`'s Commands table.
- "The full test suite" (AC-3, D-6) means `composer test` as currently defined in
  `modules/host/composer.json` (which already runs `artisan config:clear` then the
  Pest suite via `tools/gate-step.php`); this task does not change what that script
  does, only runs it automatically.
- `php artisan tenant:migrate` (T-008) and its `--to=` flag are the correct,
  already-shipped mechanism for both migration jobs; this task does not add, modify,
  or extend that command.
- The exact workflow filename, job identifiers, and step `name:` labels are left to
  `dev`/`architect` to choose; every AC above checks structure and content (triggers,
  services, command invocations, ordering) rather than pinning an exact job or step
  name, so implementation freedom there does not risk failing an AC.
