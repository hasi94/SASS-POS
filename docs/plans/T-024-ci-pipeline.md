# Plan: T-024 CI pipeline (FP-218)

Spec: `docs/specs/T-024-ci-pipeline.md` (AC-1 to AC-21). Decisions: `docs/decisions/T-024-ci-pipeline.md` (D-1 to D-12). This plan follows every decision as written; nothing below reopens one.

## Approach summary

- One GitHub Actions workflow, `.github/workflows/ci.yml`, with three independent jobs and no `needs:` between them. Each one reports its own pass/fail on a PR (user story 3).
  - `quality` has no `services:`. It runs `composer install`, then four named steps: `composer lint`, `composer stan`, `composer deptrac`, `composer test`.
  - `migrate-fresh` has its own `mysql:8.4` service. It migrates one tenant from an empty database to head.
  - `migrate-existing` has its own separate `mysql:8.4` service. It migrates to head-1, then to head, then runs a third time with nothing pending.
- **`tenant:migrate` needs more than "a tenant database", and the spec does not say so.** The runner reads everything from the master connection. It needs:
  - the master schema migrated;
  - the catalogue published in `schema_versions`, or it refuses with AC-32;
  - one `database_servers`, `tenants` and `tenant_databases` row for the tenant;
  - `--tenant=<id>` or `--all`, or it refuses.

  So each migration job puts the master database (`flexipos_master`) and the tenant database (`flx_t_ci`) in **the same job-scoped service container**. Then it runs `artisan migrate` (master), then `schema:publish`, then a committed SQL file that creates the tenant database and registers it, then `tenant:migrate --tenant=<id>`.
- The tenant is registered with `tenant_databases.status = 'provisioning'`. This is required, not cosmetic. The shipped tree has contract steps at versions 15 and 21. The planner's contract guard (`PlanMigrationRunHandler::assertContractStepsUnblocked`) refuses a contract step while any non-`provisioning` tenant is below V-1, and our own tenant starts at 0. As `active`, it would block itself and the fresh run would be refused. A named run accepts `provisioning` (`TenantStatusPolicy::NAMED_ONLY_STATUSES`). A non-offline success never changes the status, so the second and third runs accept it too.
- Head and head-1 are **derived at run time** from `modules/host/database/catalogue/tenant_schema_versions.json`, never hard-coded. `schema:publish` has already failed the job if that JSON disagrees with the tree. Today head is 31.
- The service's port is the **ephemeral mapped port**, `${{ job.services.mysql.ports['3306'] }}`, exported to `$GITHUB_ENV`. The host is `127.0.0.1`. This means no literal engine port appears outside a `services:` block (AC-17), and it cannot collide with the runner image's own stopped MySQL.
- The actual runtime outcomes are asserted **inside the jobs**, by a small committed shell script that reads `tenant_databases.schema_version` and the tenant's `migrations` row count after each invocation. AC-9 and AC-13 to AC-15 then mean something on the first real run, even though nobody here can trigger one (D-12).
- Static verification is a Pest test, `modules/host/tests/Feature/CiWorkflowShapeTest.php`. It parses the workflow with `symfony/yaml`, which is already in `vendor` and already used the same way by `QaToolchainRegistryCoverageTest`. It asserts every statically checkable AC. The gate itself then keeps the workflow honest, including inside CI. This is the FP-218 home that T-023's own D-4 deferred the compose-parsing test to.

## Alternatives rejected

- **Fixed `3306:3306` port mapping:** this is the idiomatic form. But it would put the literal address `127.0.0.1:3306` (the XAMPP engine's address in `phpunit.xml`) into job env, which AC-17 forbids on a literal reading. It can also collide with the runner image's MySQL.
- **SQLite file for the master, MySQL only for the tenant:** this skips the risk of master migrations failing on MySQL 8.4 (see Risks). But it is not the production shape. It is kept as a documented fallback, not the plan.
- **Seeding the master with a PHP script or `artisan tinker`:** a PHP file under the repo falls inside lint, stan, deptrac and the containment scans. A plain SQL file under `.github/ci/` is outside the PHP gate and readable by anyone.
- **`docker exec` into the service for the `mysql` client:** the runner image already ships a MySQL client. `docker exec` stays as the fallback if that ever changes.
- **One `composer check` step:** D-5 rules this out.
- **Running `probes:clean` / `engine:clean` in CI:** a fresh checkout has no probes. `engine:clean` honours `ENGINE_HOLD` and opens nothing. AC-3 neither requires nor forbids them, and D-5's four steps are the shape.
- **`actionlint` / `yamllint`:** neither is installed here, and adding either is a new tool download. Pest + `symfony/yaml` covers AC-1. Adding `actionlint` as a CI step later is a cheap follow-up.
- **Reusing T-023's `infra/docker/app/Dockerfile` as a job container:** D-3 keeps CI off the laptop stack. `setup-php` is the idiomatic hosted-runner path.

## Files to change / create

- `.github/workflows/ci.yml` (new). The workflow, with short header comments covering:
  - D-1, the only reason a database service exists at all;
  - why each migration job registers a `provisioning` tenant;
  - why the port is ephemeral.
- `.github/ci/register-ci-tenant.sql` (new). Contents:
  - `CREATE DATABASE flx_t_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` (plain, **not** `IF NOT EXISTS`, so a non-fresh database fails loudly);
  - one `database_servers` row with `host '127.0.0.1'`, `port @ci_port`, `engine 'mysql'`, `tls_mode 'disabled'`, `status 'active'`;
  - one `tenants` row with `status 'provisioning'`;
  - one `tenant_databases` row with `status 'provisioning'`, `schema_version 0`, `size_bytes 0`, and `migrate_credential_ref` / `app_credential_ref` set to `env://CI_TENANT_PASSWORD`.

  It contains no `DROP`. The column lists come from the master migrations `000105`, `000112` and `000113`; the fixture in `packages/kernel/tests/Support/master-mapping-fixture.php` is a ready template for the required columns. Both migration jobs use it unchanged: their containers are separate, so the same ids are fine.
- `.github/ci/assert-tenant-state.sh` (new). Usage: `assert-tenant-state.sh <expected_version> [<expected_jobs_in_latest_run>]`. It:
  - reads `flexipos_master.tenant_databases.schema_version` for `$CI_TENANT_ID`;
  - reads `SELECT COUNT(*) FROM flx_t_ci.migrations`;
  - optionally reads the latest `tenant_migration_runs.total`;
  - prints all three, and exits 1 with a named message on any mismatch.

  It is called as `bash .github/ci/…`, so no executable bit has to survive a Windows commit.
- `modules/host/tests/Feature/CiWorkflowShapeTest.php` (new). The static AC checks (see Test strategy). It spawns no child process, so it stays out of the `toolchain` group.
- **Not touched:**
  - `docker-compose.yml` and `docs/docker-dev-environment.md` (spec, Out of scope). FP-395's `start_period` fix therefore stays open against the compose file; this plan applies the same lesson to the CI service only.
  - `CLAUDE.md`, `composer.json`, `phpunit.xml`, `.env.example`, and any PHP outside the one test.

## Data / API changes

No schema, endpoint, migration or runtime-code change. New configuration only.

### `ci.yml` shape (`dev` chooses the exact labels; the ACs pin structure, not names)

- `name: CI`. Triggers: `on: { push: , pull_request: }`, both bare, with no `branches`, `tags`, `paths` or `paths-ignore` (D-7, AC-2, AC-20).
- Top level:
  - `permissions: { contents: read }`
  - `defaults.run.working-directory: modules/host`
  - `defaults.run.shell: bash`

  `shell: bash` matters: GitHub then runs `bash --noprofile --norc -eo pipefail`, so a piped command cannot mask a failure. The runner's implicit default has no `pipefail`.
- Every job:
  - `runs-on: ubuntu-24.04` (pinned, not `-latest`);
  - `actions/checkout@v4`;
  - `shivammathur/setup-php@v2` with these settings:
    - `php-version: '8.2'`, the 8.2 line of CLAUDE.md's 8.2.12. The patch level floats; see Risks.
    - `extensions: mbstring, intl, bcmath, gd, zip, pdo_mysql, pdo_sqlite, fileinfo, openssl`. These are CLAUDE.md's XAMPP list, plus `pdo_sqlite`, which the suite runs on. phpredis is deliberately absent, as it is in XAMPP (D-4).
    - `coverage: none`
    - `tools: composer:v2`
    - `ini-values: memory_limit=512M`, which matches XAMPP's value, measured here.

  Then `composer install --no-interaction --no-progress --prefer-dist`. No `continue-on-error` anywhere, and no step-level `if:` (AC-18, AC-19).
- **`quality`:**
  - `timeout-minutes: 180`. This has to sit above `composer.json`'s own 7800 s gate limit, so the gate's own `FLEXIPOS-GATE-TIMEOUT` message is what a slow run reports, not a silent runner kill.
  - Steps: install → `composer lint` → `composer stan` → `composer deptrac` → `composer test`.
  - No `services:` and no database env.
- **`migrate-fresh` and `migrate-existing`:** `timeout-minutes: 30`.
  - `services.mysql`:
    - `image: mysql:8.4`
    - `env`: `MYSQL_ROOT_PASSWORD: ci-root-dev-only`, `MYSQL_DATABASE: flexipos_master`
    - `ports: ['3306']`, the ephemeral host port
    - **no `volumes:`**
    - `options: >-` with `--health-cmd="mysqladmin ping -h 127.0.0.1 --password=ci-root-dev-only --silent"`, `--health-interval=5s`, `--health-timeout=5s`, `--health-retries=20` and `--health-start-period=60s`.

    These notes on the health check are for `dev`:
    - **GitHub Actions has no `healthcheck:` key.** Its idiom is Docker `create` flags in `options:`, and the runner waits for `healthy` before the first step.
    - The `--password=` form keeps the password one argv token, the T-023 BUG-1 lesson. The placeholder contains no spaces, either.
    - `-h 127.0.0.1` forces TCP, so the init-time server (networking off) cannot report healthy early.
    - `mysqladmin ping` exits 0 even on "Access denied" (T-023 review). So the credentials are proven separately, by the first runner-side step below.
  - Job `env` (D-10: inline dev-only placeholders, no `secrets.`):

    | Group | Values |
    |---|---|
    | Application | `APP_ENV: testing` (allowed by `EnvSecretResolver` and `EventBusDriver`), `MODULES: demo` |
    | Master connection | `DB_CONNECTION: master`, `DB_MASTER_DRIVER: mysql`, `DB_MASTER_HOST: 127.0.0.1`, `DB_MASTER_DATABASE: flexipos_master`, `DB_MASTER_USERNAME: root`, `DB_MASTER_PASSWORD: ci-root-dev-only` |
    | Process-local stand-ins | `CACHE_STORE: array` (the mapping cache must not outlive one artisan process), `SESSION_DRIVER: array`, `QUEUE_CONNECTION: sync`, `EVENTBUS_DRIVER: memory` |
    | Logging | `LOG_CHANNEL: stderr` |
    | CI tenant | `CI_TENANT_ID: <a fixed 26-char id>`, `CI_TENANT_PASSWORD: ci-root-dev-only` |

    `DB_MASTER_PORT` is **not** set here. Job-level `env` cannot read the `job` context, so step 1 writes it.
  - Shared step prefix:
    1. Export `DB_MASTER_PORT=${{ job.services.mysql.ports['3306'] }}` to `$GITHUB_ENV`.
    2. Verify the credentials and the engine with `mysql -h 127.0.0.1 -P "$DB_MASTER_PORT" -uroot --password="$DB_MASTER_PASSWORD" -N -e 'SELECT VERSION()'`. Fail unless the result starts with `8.4`.
    3. `php artisan migrate --database=master --force`
    4. `php artisan schema:publish`
    5. Read the catalogue head with a `php -r` one-liner over the JSON. Export `CATALOGUE_HEAD` and `CATALOGUE_HEAD_MINUS_1`, and fail if head < 2.
    6. Register the tenant: `mysql … -e "SET @ci_port = $DB_MASTER_PORT; SOURCE ../../.github/ci/register-ci-tenant.sql"`. Paths are relative to `modules/host`.
  - **`migrate-fresh` then runs** (AC-8, AC-9, AC-21):
    - `php artisan tenant:migrate --tenant="$CI_TENANT_ID"`, with no `--to`
    - `bash ../../.github/ci/assert-tenant-state.sh "$CATALOGUE_HEAD"`
  - **`migrate-existing` then runs**, as six separate steps (AC-12 to AC-15):
    1. `php artisan tenant:migrate --tenant="$CI_TENANT_ID" --to="$CATALOGUE_HEAD_MINUS_1"`
    2. assert `$CATALOGUE_HEAD_MINUS_1`
    3. `php artisan tenant:migrate --tenant="$CI_TENANT_ID"`, with no `--to`
    4. assert `$CATALOGUE_HEAD`
    5. `php artisan tenant:migrate --tenant="$CI_TENANT_ID"`, the no-op
    6. assert `$CATALOGUE_HEAD 0`. Zero jobs were planned, because a tenant at the target is not selected, and the version and row count are unchanged.

### Exit-code facts the design relies on (read from source, not assumed)

- `TenantMigrateCommand` returns the run's exit code. A failed job makes the run `completed_with_failures`, which exits 1, and a refusal exits 1.
- A run with nothing to do is `completed`, which exits 0.
- One gap: a **lock-skipped** job also ends `completed` and exits 0. The assert script closes it, because the version would not have moved.

## Implementation steps

- [ ] **Step 1: Write the shape test, red.** Write `CiWorkflowShapeTest.php` with every assertion listed under Test strategy. Run `composer test:fast -- --filter=CiWorkflowShape`, or the Pest file directly, and confirm it fails because the workflow is missing. (AC-1 to AC-8, AC-11, AC-12, AC-16 to AC-18, AC-20, and AC-21's static half)
- [ ] **Step 2: Workflow skeleton + `quality` job.** Write `ci.yml` with the triggers, `permissions`, `defaults` (working directory, `shell: bash`) and the `quality` job as specified. Re-run the test: the trigger, `quality` and global assertions go green, and the migration-job assertions stay red. (AC-1 to AC-4, AC-6, AC-18 to AC-20)
- [ ] **Step 3: CI support files.** Write `.github/ci/register-ci-tenant.sql` and `.github/ci/assert-tenant-state.sh`. Check the column lists against master migrations `000105`, `000112` and `000113`: every NOT NULL column without a default must be given. Run `bash -n .github/ci/assert-tenant-state.sh` for syntax only. **Do not run either file against any engine on this machine**: `ENGINE_HOLD`, and D-1 covers CI containers only. (supports AC-8, AC-12, AC-13 to AC-15)
- [ ] **Step 4: `migrate-fresh` job.** Add the service block, the job env, the shared prefix and the fresh tail. The fresh-job assertions go green. (AC-5, AC-7, AC-8, AC-16, AC-17, AC-21)
- [ ] **Step 5: `migrate-existing` job.** Duplicate the service, env and prefix (as plain YAML, no anchors; the test parses either, but plain is easier to review), then add the six-step tail. The whole test is green. (AC-5, AC-11, AC-12, AC-15's static shape)
- [ ] **Step 6: Static spot-checks outside Pest.** Run each check below and record what each command printed in the QA hand-off:
  - `grep -n "secrets\." .github/workflows/ci.yml` → no output (AC-16);
  - `grep -n -i "docker.compose\|docker-compose" .github/workflows/ci.yml` → nothing outside comments, and preferably nothing at all (AC-6);
  - `grep -n "test:fast\|continue-on-error" .github/workflows/ci.yml` → no output (AC-3, AC-18).
- [ ] **Step 7: Full gate.** Run `cd modules/host && composer check`. The new test, the new YAML and the `.github/` tree must not trip any existing sweep: the vocabulary, documentation-accuracy and containment tests all walk the tree. Fix by rewording, never by adding an exemption.

## Test strategy

- **Unit / static, the gate itself:** `CiWorkflowShapeTest` parses `dirname(base_path(), 2).'/.github/workflows/ci.yml'` with `Symfony\Component\Yaml\Yaml::parseFile`. It was measured here that Symfony keeps an unquoted `on` as the string key `on`; it is not YAML 1.1's boolean. Assertions:

  | AC | What the test asserts |
  |---|---|
  | AC-1 | The file parses, and the result is an array with `on` and `jobs`. |
  | AC-2, AC-20 | `on` has keys `push` and `pull_request`, and neither carries `branches`, `branches-ignore`, `tags`, `tags-ignore`, `paths` or `paths-ignore`. |
  | AC-3 | Exactly one job whose `run` lines contain `composer lint`. In that job, `composer install` comes before `lint`, then `stan`, then `deptrac`, then `test`, each in a distinct step, by step index. The string `test:fast` appears nowhere in the file. |
  | AC-4 | That job has no `services` key. |
  | AC-5 | At least two other jobs contain `php artisan tenant:migrate`. |
  | AC-6 | No `run` value matches `docker compose`, `docker-compose` or `docker-compose.yml`. |
  | AC-7, AC-11 | Each migration job has `services.mysql.image === 'mysql:8.4'` and no `volumes` key under the service. The two jobs are distinct keys, which makes them independent containers by construction. |
  | AC-8, AC-21 | Fresh job order: install, then `register-ci-tenant.sql`, then a `tenant:migrate` with no `--to`. No `--to` appears anywhere in that job. The SQL file contains `CREATE DATABASE` without `IF NOT EXISTS`, and no `DROP`. |
  | AC-12, AC-15 shape | Existing job order: install, then register, then `tenant:migrate … --to=`, then `tenant:migrate` with no `--to`, then a third `tenant:migrate` with no `--to`. Each invocation is its own step. |
  | AC-16 | The raw file text contains no `secrets.`. Every `MYSQL_*PASSWORD` / `DB_*PASSWORD` value is a literal containing `dev-only`. |
  | AC-17 | Every env key ending `_HOST` equals `127.0.0.1`. Every `_PORT` value written anywhere references `job.services.mysql.ports`. `quality` has no `DB_*` env. |
  | AC-18, AC-19 static half | No `continue-on-error` key at any depth. No step in `quality` has an `if:`. `defaults.run.shell === 'bash'`. |

- **Integration / e2e:** none is runnable here. The migration jobs' runtime half is asserted by `assert-tenant-state.sh` on the first real GitHub run. Until then AC-9, AC-10, AC-13, AC-14, AC-15 and AC-19's skip reporting stay **NOT VERIFIABLE UNTIL A REAL RUN** (D-12). QA must not mark them passed from static reading.
- AC-10 (destruction) has no assertion in the workflow. It follows from service containers being job-scoped and having no volumes, which AC-7 checks statically. A real run's "Stop containers" post-step is the runtime evidence.

## Risks & rollback

- **R-1, top risk: the suite has never run on Linux.** Every gate result so far is from Windows/XAMPP. Path separators, case-sensitive filesystems, path-package symlinks instead of junctions, and `.env`-absent boot could all make `quality` red on its first real run for reasons unrelated to this task. It cannot be checked statically. The first real run's failures should be triaged as new backlog items, not as T-024 defects.
- **R-2: the master migrations have never run on MySQL 8.4.** The suite runs them only on SQLite. A real incompatibility would turn both migration jobs red at step 3 of the prefix. That is a genuine finding, which is what CI is for. If it blocks for long, the fallback is an SQLite-file master (`DB_MASTER_DRIVER=sqlite`, `DB_MASTER_DATABASE=$RUNNER_TEMP/master.sqlite`), which keeps the tenant on MySQL. It is a one-job-env edit.
- **R-3: `composer test` takes about 1232 s warm here,** and a hosted runner is likely slower. `timeout-minutes: 180` sits above the 7800 s gate limit, so the gate reports its own timeout first.
- **R-4: floating versions.** PHP 8.2's patch level and the `mysql:8.4` patch level float, like FP-396's concern. Pin them if reproducibility bites.
- **R-5: GitHub runner image drift.** The `mysql` client is preinstalled on `ubuntu-24.04` today. If it is ever dropped, the fallback is `docker exec ${{ job.services.mysql.id }} mysql …`, which is not `docker compose`, so AC-6 holds.
- **R-6: `APP_KEY` is unset.** No console path read here needs the encrypter. If a boot path does, add one step that exports a random `base64:` key to `$GITHUB_ENV`.
- **R-7: tree sweeps.** The memory note "gate sweeps the working tree" applies: run `composer check` (step 7) before handing off.
- **Rollback:** delete `.github/` and the one test file. Nothing else in the tree changes.

## Questions for the human

None. The points below are spec corrections for `ba`. I have no cross-session messaging tool, so they go to the lead; none blocks implementation:

1. **AC-8 / AC-12 are underspecified.** The step "creates a tenant database … then invokes `tenant:migrate`" also needs the master migrated, `schema:publish`, a registered `database_servers` / `tenants` / `tenant_databases` row, and `--tenant=<id>`; otherwise the command refuses. Suggested wording: "creates and registers a tenant database (master schema migrated, catalogue published, mapping row written)".
2. **The registered status must be `provisioning`.** Without it, the contract guard refuses the fresh run: a tenant at 0 blocks itself at contract version 15. `ba` may want this logged as a decision, since it is a business-rule interaction.
3. **AC-17 cannot hold if read literally.** A job must name a host and port outside `services:` to reach its own service. Suggested wording: "…no host other than `127.0.0.1` and no port other than the job's own mapped service port". The plan meets the stricter intent by using only `job.services.mysql.ports`.
4. **AC-9 / AC-13 conflate two stores.** The "migration-tracking table" is the tenant's Laravel `migrations` table, which holds names and batches but no versions. The version lives in the master's `tenant_databases.schema_version`. The assert script checks both. Suggested wording: "schema_version equals head, and the tenant's `migrations` table holds head rows".
5. **AC-15's "reports zero migrations applied" has no output line to match.** The command prints no "0 applied" line; with nothing pending it prints only the run's final status. The observable is a run with `total = 0` and unchanged version and row count, which the script asserts.
6. **Wrong cross-reference in D-9.** D-9's last sentence says the idempotency check is "folded into this job … (AC-9)". It is AC-15.
