<?php

use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Flexipos\Kernel\Tenancy\Exceptions\RequiredSchemaVersionNotConfigured;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * T-008 step 7a: the version floor has three states, and absence is not zero.
 *
 * Step 7 shipped a floor that returned early on `$required <= 0`, and
 * config/tenancy.php cast an absent APP_REQUIRED_SCHEMA_VERSION to 0. So a
 * deployment that never named the variable served every tenant with no version
 * gate at all - including a tenant whose mapping says schema version 0, which
 * is a database that may hold nothing but `migrations`. That is risk R-14 and
 * ruling section 13.1: absence is a misconfiguration, not a choice.
 *
 * Three states, and each one is a case here:
 *
 * - **a number** - the floor, proven in TenancyServingVersionGateTest;
 * - **an explicit `0`** - the recorded "no floor; `status` is the only guard"
 *   opt-out, which stays served. Asserted, because a later implementation that
 *   made the floor mandatory would remove a state a deployment may legitimately
 *   want (the first tenant-less installs, and FP-206's provisioning window);
 * - **absent** - refused for serving, with a named exception that names the
 *   variable, and rendered 503 **without** Retry-After so a misconfiguration is
 *   distinguishable at the wire from a tenant that is merely behind. The
 *   rendering half is modules/host/tests/Feature/TenantSchemaBehindRenderingTest.php.
 *
 * The refusal is at the gate and not at boot, which is the part worth reading:
 * a boot-time refusal would also stop `php artisan tenant:migrate` - the one
 * command that fixes the condition - and every queue worker with it. So the
 * migrating purpose stays exempt, and the last case here asserts that.
 *
 * The absent state is produced the way a deployment produces it: the variable is
 * removed from all three places Laravel's Env repository reads ($_SERVER, $_ENV
 * and the process environment), config/tenancy.php is evaluated, and the value
 * the *file* yields is the input to the gate. A literal null handed to
 * config() would prove the gate and say nothing about the file, and phpunit.xml's
 * own <env> writes only two of the three.
 *
 * No engine, no master schema: the fixture is T-003's two SQLite files.
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
 * config/tenancy.php evaluated with APP_REQUIRED_SCHEMA_VERSION in the given
 * state: null removes it from all three places, a string sets it in all three.
 *
 * @return array<string, mixed>
 */
function floorStateShippedConfig(?string $value): array
{
    $ambient = $_SERVER['APP_REQUIRED_SCHEMA_VERSION']
        ?? $_ENV['APP_REQUIRED_SCHEMA_VERSION']
        ?? (is_string($raw = getenv('APP_REQUIRED_SCHEMA_VERSION')) ? $raw : null);

    tenancyWriteEnvironment('APP_REQUIRED_SCHEMA_VERSION', $value);

    try {
        /** @var array<string, mixed> $shipped */
        $shipped = require base_path('config/tenancy.php');
    } finally {
        tenancyWriteEnvironment('APP_REQUIRED_SCHEMA_VERSION', is_string($ambient) ? $ambient : null);
    }

    return $shipped;
}

/**
 * Point the suite's floor at whatever config/tenancy.php yields for the given
 * environment state, and put tenant-a at schema version 0.
 */
function floorStateMapping(?string $variable): void
{
    config()->set(
        'tenancy.required_schema_version',
        floorStateShippedConfig($variable)['required_schema_version']
    );

    $mapping = tenancyFixtureMapping();
    $mapping['tenant-a']['schema_version'] = 0;

    config()->set('tenancy.databases', $mapping);

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetInstance(TenantMigrationTargetRepository::class);
    app()->forgetScopedInstances();
}

/*
|--------------------------------------------------------------------------
| The file: absent is null, and only an explicit value is a number
|--------------------------------------------------------------------------
*/

it('yields a null floor when the variable is absent and a number when it is set', function () {
    expect(floorStateShippedConfig(null)['required_schema_version'])->toBeNull()
        // An explicit zero is a value someone wrote, so it survives as 0 and
        // not as null: the two states are told apart by the file, which is what
        // makes the gate able to tell them apart at all.
        ->and(floorStateShippedConfig('0')['required_schema_version'])->toBe(0)
        ->and(floorStateShippedConfig('6')['required_schema_version'])->toBe(6)
        // An empty variable is nobody's choice either: `APP_REQUIRED_SCHEMA_VERSION=`
        // in an .env file is the shape a half-finished deployment actually has.
        ->and(floorStateShippedConfig('')['required_schema_version'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Absent: refused for serving, before any connection
|--------------------------------------------------------------------------
*/

it('refuses to serve a tenant at schema version 0 when the floor is not configured', function () {
    floorStateMapping(null);

    $refusal = null;

    try {
        tenancy()->enter('tenant-a');
    } catch (RequiredSchemaVersionNotConfigured $thrown) {
        $refusal = $thrown;
    }

    // The class *and* a fact only this branch knows: the name of the variable
    // nobody set. Pinning the class alone would not distinguish this refusal
    // from the behind-tenant one, and the message is what an operator reads.
    expect($refusal)->toBeInstanceOf(RequiredSchemaVersionNotConfigured::class)
        ->and((string) $refusal?->getMessage())->toContain('APP_REQUIRED_SCHEMA_VERSION')
        ->and((string) $refusal?->getMessage())->toContain('tenant-a')
        ->and((string) $refusal?->getMessage())->not->toContain(tenancyFixturePassword());

    // Nothing was opened: the floor is consulted before the credential
    // reference is dereferenced, so the refusal costs no connection.
    expect(tenancyTenantStanza())->toBe(tenancyPlaceholderStanza())
        ->and(array_keys(DB::getConnections()))->not->toContain('tenant')
        ->and(tenancy()->current()->hasTenant())->toBeFalse();
});

it('refuses a tenant that is well past any plausible floor, because the floor is the missing fact', function () {
    floorStateMapping(null);

    // The control for "it refuses because the version is 0": a tenant at
    // version 99 is refused too. The refusal is about the configuration, not
    // about the tenant, so no mapping value can satisfy it.
    $mapping = tenancyFixtureMapping();
    $mapping['tenant-a']['schema_version'] = 99;

    config()->set('tenancy.databases', $mapping);
    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetScopedInstances();

    expect(fn () => tenancy()->enter('tenant-a'))
        ->toThrow(RequiredSchemaVersionNotConfigured::class);
});

/*
|--------------------------------------------------------------------------
| An explicit zero: the recorded opt-out, still served
|--------------------------------------------------------------------------
*/

it('serves a tenant at schema version 0 when the floor is explicitly zero', function () {
    floorStateMapping('0');

    // Written down by someone: "no floor; tenant_databases.status is the only
    // guard". If this case ever goes red the fix has quietly become "the floor
    // is mandatory", which is a different decision from the one ruled.
    tenancy()->enter('tenant-a');

    expect(tenancyMarker())->toBe('tenant-a');

    tenancy()->leave();
});

/*
|--------------------------------------------------------------------------
| The migrating purpose is unaffected by either state
|--------------------------------------------------------------------------
*/

it('migrates a tenant whether the floor is absent or zero, because the runner is the remedy', function () {
    foreach ([null, '0'] as $state) {
        floorStateMapping($state);

        // A gate that disables `tenant:migrate` is fail-stuck, not fail-closed:
        // the runner is the only thing that moves a tenant off version 0, and
        // it is also the only thing that runs while nobody has set the floor.
        tenancy()->enter('tenant-a', TenantConnectionPurpose::Migrating);

        expect(tenancyMarker())->toBe('tenant-a')
            ->and(tenancyTenantStanza()['username'])->toBe('flx_tenant_a_migrate');

        tenancy()->leave();
    }
});
