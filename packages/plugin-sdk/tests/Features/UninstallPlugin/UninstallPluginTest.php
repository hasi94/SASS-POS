<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Features\UninstallPlugin;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Flexipos\PluginSdk\Features\DisablePlugin\DisablePlugin;
use Flexipos\PluginSdk\Features\DisablePlugin\DisablePluginHandler;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePlugin;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePluginHandler;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Features\UninstallPlugin\UninstallPlugin;
use Flexipos\PluginSdk\Features\UninstallPlugin\UninstallPluginHandler;
use Flexipos\PluginSdk\Support\PluginDataExporter;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

require_once __DIR__.'/../../Support/plugin-fixture.php';

/*
|--------------------------------------------------------------------------
| T-019 step 9 - UninstallPluginHandler (AC-20)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

final class RecordingExporter implements PluginDataExporter
{
    /** @var list<array{tenant: string, plugin: string, table: string, rowCount: int}> */
    public array $calls = [];

    public function export(string $tenantId, string $plugin, string $table, iterable $rows): void
    {
        $count = 0;

        foreach ($rows as $ignored) {
            $count++;
        }

        $this->calls[] = ['tenant' => $tenantId, 'plugin' => $plugin, 'table' => $table, 'rowCount' => $count];
    }
}

final class ThrowingExporter implements PluginDataExporter
{
    public function export(string $tenantId, string $plugin, string $table, iterable $rows): void
    {
        throw new RuntimeException('export failed');
    }
}

beforeEach(function () {
    PluginSeed::migrate();
    AccessTenantFixture::boot();
    app()->register(new FixtureHookPointProvider(app()), true);
});

afterEach(function () {
    AccessTenantFixture::forget();
});

it('refuses uninstalling an enabled plugin, and drops nothing', function () {
    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../../Fixtures/FixtureEcho'));
    $tenant = pluginRegisterFixtureTenant('tenant-a');
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));

    try {
        app(UninstallPluginHandler::class)->handle(new UninstallPlugin('fixture-echo', $tenant));
        throw new RuntimeException('Expected PluginRefused.');
    } catch (PluginRefused $refused) {
        expect($refused->reason)->toBe('disableFirst');
    }

    AccessTenantFixture::enter('tenant-a');
    expect(DB::connection(AccessTenantFixture::connectionName())->getSchemaBuilder()->hasTable('plg_fixture_echo_after_effects'))->toBeTrue();
});

it('exports every owned table before dropping, and removes the migration records and master row (AC-20)', function () {
    $recorder = new RecordingExporter;
    app()->instance(PluginDataExporter::class, $recorder);

    $registered = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../../Fixtures/FixtureEcho'));
    $tenant = pluginRegisterFixtureTenant('tenant-a');
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));
    app(DisablePluginHandler::class)->handle(new DisablePlugin('fixture-echo', $tenant));

    $result = app(UninstallPluginHandler::class)->handle(new UninstallPlugin('fixture-echo', $tenant));

    expect($result->droppedTables)->toContain('plg_fixture_echo_after_effects', 'plg_fixture_echo_notes')
        ->and(array_column($recorder->calls, 'table'))->toContain('plg_fixture_echo_after_effects', 'plg_fixture_echo_notes');

    AccessTenantFixture::enter('tenant-a');
    $connection = DB::connection(AccessTenantFixture::connectionName());

    expect($connection->getSchemaBuilder()->hasTable('plg_fixture_echo_after_effects'))->toBeFalse()
        ->and($connection->getSchemaBuilder()->hasTable('plg_fixture_echo_notes'))->toBeFalse()
        ->and($connection->table('plugin_migrations')->where('plugin_name', 'fixture-echo')->count())->toBe(0);

    expect(PluginSeed::tenantPluginRow($tenant, $registered->pluginId))->toBeNull();
});

it('drops nothing when the exporter throws', function () {
    app()->instance(PluginDataExporter::class, new ThrowingExporter);

    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../../Fixtures/FixtureEcho'));
    $tenant = pluginRegisterFixtureTenant('tenant-a');
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));
    app(DisablePluginHandler::class)->handle(new DisablePlugin('fixture-echo', $tenant));

    try {
        app(UninstallPluginHandler::class)->handle(new UninstallPlugin('fixture-echo', $tenant));
        throw new RuntimeException('Expected the exporter`s own exception.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('export failed');
    }

    AccessTenantFixture::enter('tenant-a');
    expect(DB::connection(AccessTenantFixture::connectionName())->getSchemaBuilder()->hasTable('plg_fixture_echo_after_effects'))->toBeTrue();
});

it('leaves a prefix-collision plugin`s tables alone', function () {
    $tmp = sys_get_temp_dir().'/plugin-sdk-uninstall-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($tmp.'/migrations');
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/FixtureEcho/plugin.json'), true);
    $manifest['name'] = 'fixture-echo-two';
    $manifest['hooks'] = [];
    File::put($tmp.'/plugin.json', json_encode($manifest));
    File::put($tmp.'/migrations/0001_create_plg_fixture_echo_two_notes_table.php', "<?php\n/* @phase expand */\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration { public function up(): void { Schema::create('plg_fixture_echo_two_notes', function (\$t) { \$t->string('id', 26)->primary(); }); } };");

    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../../Fixtures/FixtureEcho'));
    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion($tmp));

    $tenant = pluginRegisterFixtureTenant('tenant-a');
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo-two', $tenant));
    app(DisablePluginHandler::class)->handle(new DisablePlugin('fixture-echo', $tenant));

    app(UninstallPluginHandler::class)->handle(new UninstallPlugin('fixture-echo', $tenant));

    AccessTenantFixture::enter('tenant-a');
    expect(DB::connection(AccessTenantFixture::connectionName())->getSchemaBuilder()->hasTable('plg_fixture_echo_two_notes'))->toBeTrue();

    File::deleteDirectory($tmp);
});
