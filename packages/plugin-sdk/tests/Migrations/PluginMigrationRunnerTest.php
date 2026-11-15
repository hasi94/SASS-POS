<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Migrations;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\PluginSdk\Exceptions\PluginMigrationRefused;
use Flexipos\PluginSdk\Migrations\PluginMigrationRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

require_once __DIR__.'/../Support/plugin-fixture.php';

/*
|--------------------------------------------------------------------------
| T-019 step 8 - PluginMigrationRunner (AC-38, AC-39)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    pluginEnterTenantForMigration('tenant-a');
});

afterEach(function () {
    tenancy()->leave();
    AccessTenantFixture::forget();
});

it('runs both fixture migrations in file order, recording batch 1 and creating the tables (AC-38)', function () {
    $count = app(PluginMigrationRunner::class)->run('fixture-echo', __DIR__.'/../Fixtures/FixtureEcho/migrations');

    expect($count)->toBe(2);

    $connection = DB::connection(AccessTenantFixture::connectionName());

    $rows = $connection->table('plugin_migrations')->where('plugin_name', 'fixture-echo')->orderBy('migration')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('batch')->unique()->all())->toBe([1]);

    expect($connection->getSchemaBuilder()->hasTable('plg_fixture_echo_after_effects'))->toBeTrue()
        ->and($connection->getSchemaBuilder()->hasTable('plg_fixture_echo_notes'))->toBeTrue();
});

it('is a no-op on a second run, touching no DDL (AC-39)', function () {
    app(PluginMigrationRunner::class)->run('fixture-echo', __DIR__.'/../Fixtures/FixtureEcho/migrations');

    $connection = DB::connection(AccessTenantFixture::connectionName());
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $count = app(PluginMigrationRunner::class)->run('fixture-echo', __DIR__.'/../Fixtures/FixtureEcho/migrations');

    expect($count)->toBe(2)
        ->and($connection->table('plugin_migrations')->where('plugin_name', 'fixture-echo')->count())->toBe(2);

    foreach ($connection->getQueryLog() as $entry) {
        expect($entry['query'])->not->toContain('create table');
    }

    $connection->disableQueryLog();
});

it('refuses the whole set when one file is bad, and nothing runs', function () {
    $tmp = sys_get_temp_dir().'/plugin-sdk-runner-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($tmp);
    File::copy(__DIR__.'/../Fixtures/FixtureEcho/migrations/0001_create_plg_fixture_echo_after_effects_table.php', $tmp.'/0001_create_plg_fixture_echo_after_effects_table.php');
    File::put($tmp.'/0002_bad.php', "<?php\n/* @phase expand */\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration { public function up(): void { Schema::create('loyalty_bad', function (\$t) { \$t->string('x'); }); } };");

    try {
        expect(fn () => app(PluginMigrationRunner::class)->run('fixture-echo', $tmp))
            ->toThrow(PluginMigrationRefused::class);

        $connection = DB::connection(AccessTenantFixture::connectionName());

        expect($connection->table('plugin_migrations')->where('plugin_name', 'fixture-echo')->count())->toBe(0)
            ->and($connection->getSchemaBuilder()->hasTable('plg_fixture_echo_after_effects'))->toBeFalse();
    } finally {
        File::deleteDirectory($tmp);
    }
});
