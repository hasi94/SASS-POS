-- T-024 (FP-218): registers one throwaway tenant in a CI-only MySQL service
-- container. D-1 is the only reason this file is ever run against a real
-- engine at all: the project owner's own ruling that ENGINE_HOLD does not
-- cover a CI runner's own ephemeral, disposable database container
-- (docs/decisions/T-024-ci-pipeline.md D-1). Nothing here is ever run
-- against the shared/local engine, or against any surviving tenant data.
--
-- No DROP anywhere. CREATE DATABASE is deliberately not IF NOT EXISTS: this
-- file runs once per job, against a database the job's own service
-- container just started, so a second run finding the database already
-- there is a workflow bug, not something to paper over.
--
-- The tenant is registered `provisioning`, not `active` (D-13). The shipped
-- tenant migration tree carries contract-phase steps at catalogue versions
-- 15 and 21 (modules/host/database/catalogue/tenant_schema_versions.json).
-- PlanMigrationRunHandler::assertContractStepsUnblocked() refuses to plan a
-- contract-phase migration while any tenant in the fleet - named by this run
-- or not - sits below that step's version minus one, with exactly one status
-- exempted: PlanMigrationRunHandler::BLOCKING_EXEMPT_STATUS, the literal
-- string 'provisioning'. This tenant starts at version 0, below both
-- contract steps; registered as anything else it would block itself at the
-- first contract-phase migration it reaches. `provisioning` is also one of
-- TenantStatusPolicy::NAMED_ONLY_STATUSES, so only a run that names this
-- tenant explicitly (--tenant=<id>) ever picks it up - exactly what both
-- migration jobs in .github/workflows/ci.yml do; neither ever passes --all.
--
-- The caller sets @ci_port before sourcing this file, e.g.:
--   mysql ... -e "SET @ci_port = $DB_MASTER_PORT; SOURCE .github/ci/register-ci-tenant.sql"
--
-- Column lists come from modules/host/database/migrations/
-- 2026_10_14_000105_create_tenants_table.php, 2026_10_14_000112_create_
-- database_servers_table.php and 2026_10_14_000113_create_tenant_databases_
-- table.php - every NOT NULL column without a default is given here.
-- `tenants.sequence` is the one exception: it is AUTO_INCREMENT on the real
-- engine and is left for the engine to assign.
--
-- There is only one user on this throwaway engine (root), so the app and
-- migrate usernames are both `root`; `app_credential_ref` and
-- `migrate_credential_ref` both point at the one password a SecretResolver
-- dereferences at connect time, the same `CI_TENANT_PASSWORD` the workflow's
-- own job env sets to the server's root password.

CREATE DATABASE flx_t_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE flexipos_master;

INSERT INTO database_servers (
    id, code, name, host, port, engine, tls_mode,
    provisioner_credential_ref, tier, max_tenants, status,
    created_at, updated_at
) VALUES (
    'CIMIGRATIONSERVERXXXXXXXXX', 'ci-server', 'CI MySQL service container',
    '127.0.0.1', @ci_port, 'mysql', 'disabled',
    'env://CI_TENANT_PASSWORD', 'shared', 1, 'active',
    UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
);

INSERT INTO tenants (
    id, code, slug, name, status, country, timezone, currency,
    business_type, created_at, updated_at
) VALUES (
    'CIMIGRATIONTENANTXXXXXXXXX', 'CI-MIGRATION', 'ci-migration',
    'CI migration tenant', 'provisioning', 'LK', 'Asia/Colombo', 'LKR',
    'retail', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
);

INSERT INTO tenant_databases (
    tenant_id, server_id, database_identifier, database_name,
    app_username, app_credential_ref, migrate_username, migrate_credential_ref,
    charset, collation, status, schema_version, size_bytes, profile,
    created_at, updated_at
) VALUES (
    'CIMIGRATIONTENANTXXXXXXXXX', 'CIMIGRATIONSERVERXXXXXXXXX',
    'CI-MIGRATION', 'flx_t_ci',
    'root', 'env://CI_TENANT_PASSWORD', 'root', 'env://CI_TENANT_PASSWORD',
    'utf8mb4', 'utf8mb4_unicode_ci', 'provisioning', 0, 0, 'standard',
    UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
);
