<?php

use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Flexipos\Kernel\Tenancy\Exceptions\TenantDatabaseNotActive;
use Flexipos\Kernel\Tenancy\Exceptions\TenantDatabaseNotMapped;
use Flexipos\Kernel\Tenancy\Exceptions\TenantSchemaBehind;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/*
 * T-008 step 7: the serving schema-version gate, and the one rule the migration
 * purpose waives.
 *
 * AC-19 and AC-20 are [E] criteria. This file is not their evidence - the engine
 * half is the same gate in front of a MariaDB connection, NOT VERIFIED while
 * ENGINE_HOLD is set and waiting on gate G-1 (AC-63, D-35). What is answered
 * here is every branch of the gate itself, and three inputs matter more than
 * the rest:
 *
 * - a mapping **exactly at** the required version, because a gate written `>`
 *   instead of `>=` is invisible at every other input;
 * - a mapping **above** it, which is served with exactly one warning for the
 *   entry and not one per query;
 * - the same behind tenant entered for the **migration** purpose, which
 *   succeeds. That is AC-19's exemption asserted rather than described, and the
 *   exemption covers the version gate only: the status gate still refuses a
 *   suspended tenant to the runner, which is the last case here.
 *
 * The fixture is T-003's: two real SQLite files with a marker table, no engine
 * and no master schema. The mapping source is the configuration stand-in, and
 * since step 7 it has a matching pair - tenancyFixtureMigrationTargets() - so
 * the runner's purpose has somewhere to read from.
 */

require_once __DIR__.'/../Support/tenancy-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    tenancyBootFixture();
});

afterEach(function () {
    tenancyForgetFixture();
});

afterAll(function () {
    tenancyDeleteFixtureRoot();
});

/**
 * Set the required version and rebuild both mapping sources with the given
 * tenant at the given schema version.
 */
function versionGateMapping(int $required, int $tenantAVersion, ?int $tenantBVersion = null): void
{
    config()->set('tenancy.required_schema_version', $required);

    $mapping = tenancyFixtureMapping();
    $mapping['tenant-a']['schema_version'] = $tenantAVersion;
    $mapping['tenant-b']['schema_version'] = $tenantBVersion ?? $required;

    config()->set('tenancy.databases', $mapping);

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetInstance(TenantMigrationTargetRepository::class);
    app()->forgetScopedInstances();
}

/**
 * Everything logged while the callback ran.
 *
 * @return list<string>
 */
function versionGateLog(Closure $callback): array
{
    $records = [];

    Event::listen(MessageLogged::class, function (MessageLogged $logged) use (&$records): void {
        $records[] = $logged->level.' '.$logged->message.' '.(string) json_encode($logged->context);
    });

    $callback();

    return $records;
}

/*
|--------------------------------------------------------------------------
| Below the required version: refused, before any connection
|--------------------------------------------------------------------------
*/

it('refuses a behind tenant for serving before any connection is attempted', function () {
    versionGateMapping(required: 6, tenantAVersion: 5);

    $refusal = null;

    try {
        tenancy()->enter('tenant-a');
    } catch (TenantSchemaBehind $thrown) {
        $refusal = $thrown;
    }

    expect($refusal)->toBeInstanceOf(TenantSchemaBehind::class)
        ->and((string) $refusal?->getMessage())->toContain('tenant-a')
        ->and((string) $refusal?->getMessage())->toContain('5')
        ->and((string) $refusal?->getMessage())->toContain('6')
        // The gate runs before the credential reference is dereferenced, so
        // there is nothing in the message to redact - and nothing in it.
        ->and((string) $refusal?->getMessage())->not->toContain(tenancyFixturePassword());

    // No connection was opened and no state was touched: the stanza is still
    // the at-rest placeholder and the manager holds no tenant connection. This
    // is the assertion that distinguishes "refused at the gate" from "refused
    // by the driver after trying".
    expect(tenancyTenantStanza())->toBe(tenancyPlaceholderStanza())
        ->and(array_keys(DB::getConnections()))->not->toContain('tenant')
        ->and(tenancy()->current()->hasTenant())->toBeFalse();

    // And a different tenant at the required version is served in the same
    // process afterwards: the refusal is about one tenant, not about the
    // process (AC-19's last clause).
    tenancy()->enter('tenant-b');

    expect(tenancyMarker())->toBe('tenant-b');

    tenancy()->leave();
});

/*
|--------------------------------------------------------------------------
| Exactly at the required version: served. The input that makes >= bind.
|--------------------------------------------------------------------------
*/

it('serves a tenant exactly at the required version, with no warning', function () {
    versionGateMapping(required: 6, tenantAVersion: 6);

    $records = versionGateLog(function (): void {
        tenancy()->enter('tenant-a');
    });

    expect(tenancyMarker())->toBe('tenant-a')
        // A gate written `>` instead of `>=` refuses here and nowhere else,
        // which is why this input is mandatory rather than nice to have.
        ->and($records)->toBe([]);

    tenancy()->leave();
});

it('serves every tenant when the floor is explicitly zero', function () {
    // An explicit zero is no floor at all, rather than a floor of zero that a
    // 0-version tenant happens to pass. Since step 7a that is a *recorded*
    // opt-out and not a default: an absent APP_REQUIRED_SCHEMA_VERSION is a
    // misconfiguration the gate refuses to serve on, which is
    // TenancyVersionFloorStatesTest's subject. This case must not be read as
    // covering it.
    versionGateMapping(required: 0, tenantAVersion: 0);

    $records = versionGateLog(function (): void {
        tenancy()->enter('tenant-a');
    });

    expect(tenancyMarker())->toBe('tenant-a')
        ->and($records)->toBe([]);

    tenancy()->leave();
});

/*
|--------------------------------------------------------------------------
| Above it: served, with exactly one warning for the entry
|--------------------------------------------------------------------------
*/

it('serves a tenant ahead of the code and warns exactly once per entry', function () {
    versionGateMapping(required: 6, tenantAVersion: 7);

    $records = versionGateLog(function (): void {
        tenancy()->enter('tenant-a');

        // Four queries inside the one scope. "One warning for that entry, not
        // one per query" is the whole of AC-20, and a gate implemented in a
        // query listener would produce five lines here.
        foreach (range(1, 4) as $ignored) {
            DB::connection('tenant')->select('select value from marker');
        }
    });

    $warnings = array_values(array_filter(
        $records,
        static fn (string $record): bool => str_starts_with($record, 'warning')
    ));

    expect(tenancyMarker())->toBe('tenant-a')
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('ahead of this code')
        ->and($warnings[0])->toContain('tenant-a')
        ->and($warnings[0])->not->toContain(tenancyFixturePassword());

    // A re-entrant enter() for the same tenant nests and does not warn again.
    $second = versionGateLog(function (): void {
        tenancy()->enter('tenant-a');
        tenancy()->leave();
    });

    expect($second)->toBe([]);

    tenancy()->leave();
});

/*
|--------------------------------------------------------------------------
| The exemption, asserted: the runner enters a tenant the gate refuses
|--------------------------------------------------------------------------
*/

it('enters a tenant at the required version minus one for the migration purpose', function () {
    versionGateMapping(required: 6, tenantAVersion: 5);

    // The serving purpose refuses this exact tenant - asserted above and again
    // here, so the two halves of the exemption are one claim.
    expect(fn () => tenancy()->enter('tenant-a'))->toThrow(TenantSchemaBehind::class);

    // The migration purpose enters it. A tenant below the required version is
    // the only kind the runner ever migrates, so a gate that refused it would
    // refuse the runner its only job.
    tenancy()->enter('tenant-a', TenantConnectionPurpose::Migrating);

    expect(tenancyMarker())->toBe('tenant-a');

    // And it connected as the *migrate* user, from the runner's own mapping
    // source: the username in the stanza is the one only that source can
    // produce, which is how AC-18's two halves are told apart.
    $stanza = tenancyTenantStanza();

    expect($stanza['username'])->toBe('flx_tenant_a_migrate')
        ->and($stanza['username'])->not->toBe(tenancyFixtureMapping()['tenant-a']['username']);

    tenancy()->leave();

    // The serving entry for the same tenant carries the app username, so the
    // migrate pair is reachable only through the purpose.
    versionGateMapping(required: 6, tenantAVersion: 6);

    tenancy()->enter('tenant-a');

    expect(tenancyTenantStanza()['username'])->toBe('flx_ta_app');

    tenancy()->leave();
});

it('still refuses a suspended tenant to the runner, because only the version gate is waived', function () {
    versionGateMapping(required: 6, tenantAVersion: 5);

    $targets = tenancyFixtureMigrationTargets();
    $targets['tenant-a']['status'] = 'suspended';

    config()->set('tenancy.migration_targets', $targets);
    app()->forgetInstance(TenantMigrationTargetRepository::class);
    app()->forgetScopedInstances();

    // "Migration purpose" is not a general bypass: the status rules bind the
    // runner exactly as they bind a request (AC-51). Without this the exemption
    // would read as a licence rather than as one named waiver.
    expect(fn () => tenancy()->enter('tenant-a', TenantConnectionPurpose::Migrating))
        ->toThrow(TenantDatabaseNotActive::class);

    expect(tenancyTenantStanza())->toBe(tenancyPlaceholderStanza())
        ->and(tenancy()->current()->hasTenant())->toBeFalse();
});

it('refuses a tenant the runner has no migration target for', function () {
    versionGateMapping(required: 6, tenantAVersion: 5);

    config()->set('tenancy.migration_targets', []);
    app()->forgetInstance(TenantMigrationTargetRepository::class);
    app()->forgetScopedInstances();

    // Null from the runner's source is a refusal, never "fall back to the
    // serving mapping" - which would migrate a tenant as the app user.
    expect(fn () => tenancy()->enter('tenant-a', TenantConnectionPurpose::Migrating))
        ->toThrow(TenantDatabaseNotMapped::class);
});
