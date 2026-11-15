#!/usr/bin/env bash
set -euo pipefail

# T-024 (FP-218): the runtime half of AC-9, AC-13, AC-14 and AC-15. Called as
# `bash .github/ci/assert-tenant-state.sh ...`, so no executable bit has to
# survive a Windows commit.
#
# Nobody on this team can trigger a real GitHub Actions run, so these checks
# cannot be proven today; they are what makes the acceptance criteria mean
# something on the first real run (D-12). Each migration job calls this once
# after every `tenant:migrate` invocation.
#
# Usage: assert-tenant-state.sh <expected_version> [<expected_total_in_latest_run>]
#
#   <expected_version>              the version the master's
#                                   `tenant_databases.schema_version` row for
#                                   this tenant (AC-9, AC-13) and the row
#                                   count of the tenant's own `migrations`
#                                   table (the tenant's own migration-tracking
#                                   table, same ACs) must both equal - every
#                                   migration up to and including that version
#                                   is one row.
#   <expected_total_in_latest_run>  optional. When given, the latest
#                                   `tenant_migration_runs.total` row must
#                                   equal it. AC-15's "zero jobs were planned"
#                                   check passes `0` here.
#
# Expects DB_MASTER_PORT, DB_MASTER_PASSWORD and CI_TENANT_ID already set in
# the calling job's environment.

if [ "$#" -lt 1 ]; then
    echo "usage: assert-tenant-state.sh <expected_version> [<expected_total_in_latest_run>]" >&2
    exit 1
fi

expected_version="$1"
expected_total="${2:-}"

mysql_master() {
    mysql -h 127.0.0.1 -P "$DB_MASTER_PORT" -uroot --password="$DB_MASTER_PASSWORD" -N -B -e "$1" flexipos_master
}

mysql_tenant() {
    mysql -h 127.0.0.1 -P "$DB_MASTER_PORT" -uroot --password="$DB_MASTER_PASSWORD" -N -B -e "$1" flx_t_ci
}

actual_version=$(mysql_master "SELECT schema_version FROM tenant_databases WHERE tenant_id = '${CI_TENANT_ID}'")
actual_migration_rows=$(mysql_tenant "SELECT COUNT(*) FROM migrations")
latest_total=$(mysql_master "SELECT total FROM tenant_migration_runs ORDER BY created_at DESC LIMIT 1")

echo "tenant_databases.schema_version    = ${actual_version} (expected ${expected_version})"
echo "flx_t_ci.migrations row count      = ${actual_migration_rows} (expected ${expected_version})"
echo "latest tenant_migration_runs.total = ${latest_total}"

status=0

if [ "${actual_version}" != "${expected_version}" ]; then
    echo "FAIL: master schema_version is ${actual_version}, expected ${expected_version}" >&2
    status=1
fi

if [ "${actual_migration_rows}" != "${expected_version}" ]; then
    echo "FAIL: flx_t_ci.migrations holds ${actual_migration_rows} rows, expected ${expected_version}" >&2
    status=1
fi

if [ -n "${expected_total}" ] && [ "${latest_total}" != "${expected_total}" ]; then
    echo "FAIL: latest run's total is ${latest_total}, expected ${expected_total}" >&2
    status=1
fi

exit "${status}"
