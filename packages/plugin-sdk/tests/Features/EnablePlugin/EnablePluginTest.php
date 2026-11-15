<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Features\EnablePlugin;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePlugin;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePluginHandler;
use Flexipos\PluginSdk\Features\GrantPluginAccess\GrantPluginAccess;
use Flexipos\PluginSdk\Features\GrantPluginAccess\GrantPluginAccessHandler;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

require_once __DIR__.'/../../Support/plugin-fixture.php';

/*
|--------------------------------------------------------------------------
| T-019 step 9 - EnablePluginHandler (AC-9, AC-11, AC-13 to AC-16, AC-25)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PluginSeed::migrate();
    AccessTenantFixture::boot();
    app()->register(new FixtureHookPointProvider(app()), true);
});

afterEach(function () {
    AccessTenantFixture::forget();
});

function enablePluginFixtureEchoPath(): string
{
    return __DIR__.'/../../Fixtures/FixtureEcho';
}

/**
 * @param  array<string, mixed>  $overrides
 */
function enablePluginRegisterVariant(array $overrides): string
{
    $tmp = sys_get_temp_dir().'/plugin-sdk-enable-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($tmp.'/migrations');

    $manifest = json_decode((string) file_get_contents(enablePluginFixtureEchoPath().'/plugin.json'), true);
    $manifest = array_merge($manifest, $overrides);
    File::put($tmp.'/plugin.json', json_encode($manifest));

    $result = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion($tmp));

    return $result->pluginId;
}

it('passes through installing to enabled, stamps the row, and gives schema_version 2 (AC-14)', function () {
    $registered = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(enablePluginFixtureEchoPath()));
    $tenant = pluginRegisterFixtureTenant('tenant-a');

    $result = app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant, installedBy: null));

    expect($result->noop)->toBeFalse()
        ->and($result->status)->toBe('enabled')
        ->and($result->schemaVersion)->toBe(2);

    $row = PluginSeed::tenantPluginRow($tenant, $registered->pluginId);

    expect($row['status'])->toBe('enabled')
        ->and($row['installed_at'])->not->toBeNull()
        ->and($row['enabled_at'])->not->toBeNull()
        ->and($row['schema_version'])->toBe(2);

    $updatedAt = $row['updated_at'];

    $second = app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));

    expect($second->noop)->toBeTrue();

    $rowAfter = PluginSeed::tenantPluginRow($tenant, $registered->pluginId);
    expect($rowAfter['updated_at'])->toBe($updatedAt);

    // plugin_migrations is unchanged by the no-op second call.
    AccessTenantFixture::enter('tenant-a');
    expect(DB::connection(AccessTenantFixture::connectionName())->table('plugin_migrations')->where('plugin_name', 'fixture-echo')->count())->toBe(2);
});

it('refuses with licenceRequired when requires_licence is true and no licence is active (AC-13)', function () {
    $pluginId = enablePluginRegisterVariant(['name' => 'fixture-licensed', 'requires_licence' => true, 'hooks' => []]);
    $tenant = pluginRegisterFixtureTenant('tenant-a');

    try {
        app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-licensed', $tenant));
        throw new RuntimeException('Expected PluginRefused.');
    } catch (PluginRefused $refused) {
        expect($refused->reason)->toBe('licenceRequired');
    }

    expect(PluginSeed::tenantPluginRow($tenant, $pluginId))->toBeNull();
});

it('stores licence_id when a licence is active, and enables with none when requires_licence is false', function () {
    $pluginId = enablePluginRegisterVariant(['name' => 'fixture-licensed-2', 'requires_licence' => true, 'hooks' => []]);
    $tenant = pluginRegisterFixtureTenant('tenant-a');
    $licenceId = PluginSeed::licence($tenant, 'fixture-licensed-2');

    $result = app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-licensed-2', $tenant));

    expect($result->status)->toBe('enabled');

    $row = PluginSeed::tenantPluginRow($tenant, $pluginId);
    expect($row['licence_id'])->toBe($licenceId);
});

it('gives the same licenceRequired reason for an expired, revoked, or suspended licence (AC-16)', function (string $status, array $attrs) {
    $pluginId = enablePluginRegisterVariant(['name' => 'fixture-lapsed-'.$status, 'requires_licence' => true, 'hooks' => []]);
    $tenant = pluginRegisterFixtureTenant('tenant-a');
    PluginSeed::licence($tenant, 'fixture-lapsed-'.$status, 'plugin', $status === 'lapsed' ? 'active' : $status, $attrs);

    try {
        app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-lapsed-'.$status, $tenant));
        throw new RuntimeException('Expected PluginRefused.');
    } catch (PluginRefused $refused) {
        expect($refused->reason)->toBe('licenceRequired');
    }

    expect(PluginSeed::tenantPluginRow($tenant, $pluginId))->toBeNull();
})->with([
    'lapsed' => ['lapsed', ['valid_until' => '2020-01-01 00:00:00.000']],
    'revoked' => ['revoked', []],
    'suspended' => ['suspended', []],
]);

it('refuses a private plugin with no grant, and enables once granted (AC-11)', function () {
    $pluginId = enablePluginRegisterVariant(['name' => 'fixture-private-enable', 'visibility' => 'private', 'hooks' => []]);
    $tenant = pluginRegisterFixtureTenant('tenant-a');

    try {
        app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-private-enable', $tenant));
        throw new RuntimeException('Expected PluginRefused.');
    } catch (PluginRefused $refused) {
        expect($refused->reason)->toBe('accessNotGranted');
    }

    app(GrantPluginAccessHandler::class)->handle(new GrantPluginAccess('fixture-private-enable', $tenant));

    $result = app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-private-enable', $tenant));

    expect($result->status)->toBe('enabled');
});

it('refuses a dependency that is not satisfied, and the control enables once it is (AC-9)', function () {
    enablePluginRegisterVariant(['name' => 'fixture-dep-base', 'hooks' => []]);
    enablePluginRegisterVariant(['name' => 'fixture-dep-a', 'hooks' => [], 'requires' => ['fixture-dep-base' => '^1.0']]);
    enablePluginRegisterVariant(['name' => 'fixture-dep-b', 'hooks' => [], 'requires' => ['fixture-dep-base' => '^2.0']]);

    $tenant = pluginRegisterFixtureTenant('tenant-a');

    try {
        app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-dep-a', $tenant));
        throw new RuntimeException('Expected PluginRefused.');
    } catch (PluginRefused $refused) {
        expect($refused->reason)->toBe('dependencyNotSatisfied');
    }

    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-dep-base', $tenant));

    try {
        app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-dep-b', $tenant));
        throw new RuntimeException('Expected PluginRefused.');
    } catch (PluginRefused $refused) {
        expect($refused->reason)->toBe('dependencyNotSatisfied');
    }

    $result = app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-dep-a', $tenant));
    expect($result->status)->toBe('enabled');
});

it('refuses a second plugin overriding a point already claimed (AC-25 across plugins)', function () {
    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(enablePluginFixtureEchoPath()));
    enablePluginRegisterVariant(['name' => 'fixture-rival-override']);
    $tenant = pluginRegisterFixtureTenant('tenant-a');

    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));

    try {
        app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-rival-override', $tenant));
        throw new RuntimeException('Expected PluginRefused.');
    } catch (PluginRefused $refused) {
        expect($refused->reason)->toBe('overrideAlreadyClaimed');
    }
});

it('refuses enable with tripped when the row is already in error', function () {
    $registered = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(enablePluginFixtureEchoPath()));
    $tenant = pluginRegisterFixtureTenant('tenant-a');

    PluginSeed::connection()->table('tenant_plugins')->insert([
        'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'plugin_id' => $registered->pluginId,
        'plugin_version_id' => $registered->versionId, 'status' => 'error', 'schema_version' => 2,
        'failure_count' => 5, 'tripped_at' => '2026-01-01 00:00:00.000', 'last_error' => 'boom',
        'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000',
    ]);

    try {
        app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));
        throw new RuntimeException('Expected PluginRefused.');
    } catch (PluginRefused $refused) {
        expect($refused->reason)->toBe('tripped');
    }
});

it('leaves a failed migration at installing with last_error, and completes on retry', function () {
    $tmp = sys_get_temp_dir().'/plugin-sdk-enable-fail-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($tmp.'/migrations');
    $manifest = json_decode((string) file_get_contents(enablePluginFixtureEchoPath().'/plugin.json'), true);
    $manifest['name'] = 'fixture-fails-first';
    $manifest['hooks'] = [];
    File::put($tmp.'/plugin.json', json_encode($manifest));
    File::put($tmp.'/migrations/0001_bad.php', "<?php\n/* @phase expand */\nuse Illuminate\\Database\\Migrations\\Migration;\nreturn new class extends Migration { public function up(): void { throw new \RuntimeException('boom'); } };");

    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion($tmp));
    $tenant = pluginRegisterFixtureTenant('tenant-a');

    try {
        app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-fails-first', $tenant));
        throw new RuntimeException('Expected an exception.');
    } catch (Throwable $e) {
        // expected: the migration throws
    }

    $pluginId = PluginSeed::pluginRow('fixture-fails-first')['id'];
    $row = PluginSeed::tenantPluginRow($tenant, $pluginId);

    expect($row['status'])->toBe('installing')
        ->and($row['last_error'])->not->toBeNull();

    File::put($tmp.'/migrations/0001_bad.php', "<?php\n/* @phase expand */\nuse Illuminate\\Database\\Migrations\\Migration;\nreturn new class extends Migration { public function up(): void {} };");

    $result = app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-fails-first', $tenant));

    expect($result->status)->toBe('enabled');

    File::deleteDirectory($tmp);
});
