# T-024 decisions — CI pipeline

Decisions made while writing `docs/specs/T-024-ci-pipeline.md`, per the TEAM PROTOCOL
(no blocking questions to the human on this task). D-1 below is the human's own
ruling, carried into this file verbatim as instructed rather than presented as `ba`'s
inference; D-2 onward are `ba`'s own, each the most conventional/safest/reversible
option available.

D-1 | Does `ENGINE_HOLD` (the standing rule that nothing may connect to a real
MySQL/MariaDB engine until G-1, a verified dump of all surviving databases, put in
place after the 2026-10-14 data-loss incident) cover a CI runner's own ephemeral,
disposable database service container? | **No — explicit human ruling, not `ba`'s
own call.** The project owner has explicitly decided that `ENGINE_HOLD` does not
cover a CI runner's own ephemeral, disposable database service container: one
created fresh per CI run, destroyed after, never persisting, and never touching the
shared local/XAMPP engine or any surviving tenant data. CI pipeline work, including
real migration tests against a fresh CI-only database and against a database already
carrying prior migration state ("an existing database"), is explicitly **in scope**
and may connect to and run migrations against such a throwaway CI container. | This
is a direct ruling from the project owner on the one hard business question this task
raised (flagged for `ba` to decide and log in the backlog's T-024 row), not an
inference `ba` made itself. The hold still fully applies to anything outside CI —
nothing here touches or weakens it for local dev, the T-023 Docker dev stack, or any
other context. Reversible: n/a — this is the human's own ruling, not reopened here.

D-2 | Which CI system? The backlog's FP-218 row does not name one, and no CI config
exists anywhere in the repo today (`.github/workflows/`, `.gitlab-ci.yml` and
equivalents all checked, none found). | GitHub Actions. | The repository is
GitHub-hosted (git remotes/branch conventions in `CLAUDE.md` and the backlog assume a
GitHub-style PR flow) and GitHub Actions is the conventional default for a
GitHub-hosted repo needing no separate CI account or infrastructure. It also matches
the idiom T-023 already used for structural-only proof (`docker compose config`
needing no daemon) — GitHub Actions workflow YAML can be parsed and inspected the
same way, without an account or a real run, which this spec leans on (see the
NOT VERIFIABLE fencing below). Reversible: yes — swapping CI systems is a config file
change, not an architectural one.

D-3 | Does the CI database come from the T-023 Docker Compose dev stack
(`docker compose up` against `docker-compose.yml`), or from a CI-native mechanism? |
A CI-native mechanism: GitHub Actions' own `services:` block on the relevant jobs,
not an invocation of `docker-compose.yml`. | `docs/docker-dev-environment.md` is
explicit that the Compose stack is laptop-oriented (bind mounts into the host
repository, host-path assumptions, a loopback-only port layout designed to avoid
colliding with XAMPP on the same machine) — none of which apply to or are needed by a
CI runner, which has no competing XAMPP engine and no host repository to bind-mount
over. A GitHub Actions `services:` block is the idiomatic, documented way to get a
throwaway database container in that environment, and reusing the dev stack's
compose file would couple CI's database lifecycle to a file whose design constraints
(T-023 D-6, D-7) are about a different problem. The version choice (MySQL 8.4) is
still reused from T-023/`docker-compose.yml`, per D-11 below — only the delivery
mechanism differs. Reversible: yes.

D-4 | Does CI need a live Redis service? | No. | `docs/architecture.md` lists Redis
as the event bus transport/cache, but T-018 (event bus and outbox) built and tested
against an in-memory stand-in by its own D-1, specifically because no real Redis
exists in this project's test environment yet; T-018's final gate recorded 9
Redis-adapter skips for exactly this reason, and nothing in the shipped suite is
reported anywhere as requiring a live Redis to pass. Adding a Redis service to CI
speculatively, with no test that would exercise it, would be unused infrastructure
carrying its own maintenance cost. Reversible: yes — trivial to add a `services:`
entry later if a Redis-dependent test is ever shipped (e.g. when T-018's Redis
adapter stops being skip-only).

D-5 | What exact job/step shape satisfies FP-218's four listed checks (format,
static analysis, boundaries, tests)? One monolithic step calling `composer check`,
or named per-requirement steps? | Named per-requirement steps — `composer lint`,
`composer stan`, `composer deptrac`, then `composer test` (the full suite, not
`test:fast`; see D-6) — run in that order inside one job, after `composer install`. |
FP-218 lists these as four distinct checks, and separating them into four named
steps gives a CI UI (and a future reviewer re-reading a failed run) a step-level
label for which check failed, rather than one opaque `composer check` log to grep
through — a materially easier debugging experience for the same underlying commands
`modules/host/composer.json` already defines. The commands and their order
(`probes:clean`, `engine:clean`, `lint`, `stan`, `deptrac`, `test`) are unchanged
from what `composer check` already runs; CI steps simply expose that existing order
as separate, individually-reportable steps rather than inventing a new one.
Reversible: yes — collapsing back to one `composer check` step is a one-line
workflow edit.

D-6 | Does the CI quality-gate job run `composer test` (the full suite) or
`composer test:fast` (excludes the `toolchain` group)? | The full suite
(`composer test`, equivalently the four named steps ending in it per D-5) on every
push and PR — never `test:fast`. | `CLAUDE.md`'s own Commands table states
`test:fast` is "Never the gate" and that "task completion is always judged by
`composer check`"; CI is exactly the gate that statement is about, not the inner
development loop `test:fast` exists for. Running the full suite in CI, with
`test:fast` left as a local-only convenience, keeps that distinction intact rather
than quietly promoting the fast loop into the role CLAUDE.md reserves for the full
one. Reversible: yes.

D-7 | Trigger scope: which branches/events run the pipeline? | `push` with no branch
filter (every branch) and `pull_request` with no base-branch filter (every target),
no `paths`/`paths-ignore` filtering. | The most conventional, simplest starting shape
for a project with no CI today, and the safest: a push to any `ai/<ID>-<slug>`
branch (this project's own branch convention) gets the same gate a PR into `main`
would get, so a defect is caught at the point it is pushed rather than only at
merge time. Path filtering is deliberately omitted — narrowing the trigger is an
optimization with a real failure mode (a change that looks docs-only but affects a
generated fixture or a static-analysis registry slips through), and nothing in this
task's scope asks for that optimization. Reversible: yes — filters are additive and
easy to introduce later if the pipeline's run time becomes a problem (see FP-328's
standing gate-headroom concern, unrelated to this task but worth not compounding
blindly).

D-8 | What does "a fresh database" mean concretely, given `tenant:provision` (the
command that would create a real tenant end-to-end) does not exist yet — FP-206 is
still blocked by `ENGINE_HOLD` and has not shipped? | A tenant database created
directly against the CI MySQL service container (a plain `CREATE DATABASE`, or the
same mechanism the shipped Engine-group tests already use to provision a scratch
tenant schema) with zero prior migration rows, then migrated to head with the
already-shipped per-tenant migration runner (`php artisan tenant:migrate`, T-008). |
`docs/database.md` §14's aspirational CI job list ("`tenant:provision` end to end...
across the fixture") describes a later-phase pipeline built on commands this project
has not shipped; building this task's migration-fresh job around a command that
does not exist would make the job unbuildable today. Scoping it to the migration
runner alone — which is shipped, tested, and exactly what FP-218's own wording asks
for ("migration tests... on a fresh... database") — is the narrower, buildable
reading. `tenant:provision` end-to-end CI coverage is left for whenever FP-206 ships.
Reversible: yes — this job's scope only grows once `tenant:provision` exists.

D-9 | What does "an existing database" mean concretely — an idempotent re-run with
no pending migrations, or an upgrade from a partial migration history? | An upgrade
from a partial migration history: migrate to one version short of head
(`tenant:migrate --to=<head-1>`, a flag T-008 already ships) against a fresh tenant
database, then migrate the remainder to head with a second, separate
`tenant:migrate` invocation and no `--to` flag. | This is the literal reading of "an
existing database" in FP-218's wording — a database that already carries some prior
migration state when the pipeline touches it, as distinct from the fresh-database
job's zero-history case — and it is buildable today with a flag the migration runner
already has, unlike a release-to-release upgrade test (`docs/database.md` §14's
"migrate-from-previous-release" job), which would need a tagged prior release this
project does not have yet (it has not shipped a first release). A same-run idempotent
re-run (run `tenant:migrate` twice with nothing pending the second time) was
considered and rejected as the primary case because it tests a narrower property
(no double-apply) than "existing database" asks for; it is folded into this job as
an additional assertion instead (AC-15) rather than replacing the two-step upgrade.
Reversible: yes — a release-tagged upgrade job can be added once the project has a
prior release to upgrade from.

D-10 | Credentials for the CI-only MySQL service container: GitHub Actions
`secrets`, or inline placeholder values in the workflow file? | Inline placeholder
values, the same pattern `docker-compose.yml` already uses for its own dev-only
MySQL root password (`flexipos-root-dev-only`). | The database is destroyed with the
job and never holds anything of value; it is not the project's actual credential
surface and introducing `secrets.*` references for it would imply a security
property (a managed secret) the container does not need and cannot leak anything
meaningful from if exposed in a public log. Matches CLAUDE.md's own rule ("never
commit... real `.env` values") by construction, since these are not real values.
Reversible: yes.

D-11 | CI database engine version: reuse MySQL 8.4 (T-023's / `docker-compose.yml`'s
choice), or something else? | MySQL 8.4, the same version `docker-compose.yml`
already pins for its `mysql` service, and the version `docs/architecture.md` names
for "CI and MySQL 8.4 parity" generally. | FP-218's own task scope note in the
backlog explicitly asks this job to "reuse what T-023 already defined where
sensible... rather than reinventing it" for the version choice specifically (while
still being its own throwaway CI-native service, D-3). Matching versions also means
a migration behaviour difference caught in CI is a real MySQL-8.4-vs-something-else
question, not a false positive from testing two different engines. Reversible: yes
(a version bump is a one-line change in two places that should probably always move
together, but that coupling is outside this task's scope to enforce).

D-12 | How are this task's own acceptance criteria verified, given nobody on this
team can trigger a real GitHub Actions run? | Split into (a) static/structural
checks any of `dev`/`qa` can run locally without a GitHub Actions account — the
workflow YAML parses, and its parsed structure names the required triggers, jobs,
steps and services — and (b) a small number of criteria that describe the pipeline's
actual behaviour once run for real, each explicitly marked **NOT VERIFIABLE until a
real push/PR run** and not claimed as passing by static inspection alone. | Same
idiom T-023 used for `docker compose config` (AC-17 there) and the same fencing
pattern T-023's own AC-19 and T-022's engine-gated case used for "this needs a real
engine/service nobody here can start." Claiming a real CI run's outcome from reading
YAML would be exactly the kind of unverifiable claim CLAUDE.md's "report real command
results" rule exists to prevent. Reversible: n/a — a verification-method choice, not
a design choice; superseded automatically the first time this pipeline actually runs
on a real push or PR.

D-13 | Why does the CI-registered tenant need status `provisioning`, and not
`active` or any other enterable status? | The shipped tenant migration tree carries
contract-phase steps at versions 15 and 21 (`modules/host/database/catalogue/
tenant_schema_versions.json`, the fixed tree this spec treats as given). The
planner's contract guard
(`PlanMigrationRunHandler::assertContractStepsUnblocked`, verified by reading
`packages/kernel/src/Schema/Features/PlanMigrationRun/PlanMigrationRunHandler.php`)
refuses to plan a contract-phase migration while any tenant in the fleet — named
by this run or not — sits below the version the contract step requires minus one,
with exactly one status exempted from that blocking check:
`PlanMigrationRunHandler::BLOCKING_EXEMPT_STATUS`, which is the literal string
`'provisioning'`, on the stated reasoning that a provisioning database "holds
nothing yet" so a column it never had cannot be the thing a contract step drops.
A fresh CI tenant starts at version 0, below both contract steps; registered as
any status other than `provisioning` it would appear in its own fleet's blocking
set and refuse itself at the first contract-phase migration it reaches. Registering
it as `provisioning` also means only a run that names the tenant explicitly
(`--tenant=<id>`) will pick it up at all: `provisioning` is one of
`TenantStatusPolicy::NAMED_ONLY_STATUSES` (`packages/kernel/src/Tenancy/
TenantStatusPolicy.php`), so a bulk run (`--all`) would skip it — consistent with
this spec's AC-8/AC-12 requirement that each migration job invoke
`tenant:migrate --tenant=<id>` by name, never `--all`. | Reversible: yes — this is
a value written into one SQL fixture file (`.github/ci/register-ci-tenant.sql` per
the plan), not a schema or architectural commitment; changing it back to `active`
is a one-line edit, though doing so would re-break the fresh-database job against
today's shipped contract steps.
