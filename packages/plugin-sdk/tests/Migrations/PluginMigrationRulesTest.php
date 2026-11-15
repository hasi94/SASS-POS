<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Migrations;

use Flexipos\PluginSdk\Manifest\PluginName;
use Flexipos\PluginSdk\Migrations\PluginMigrationRules;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 8 - PluginMigrationRules (AC-19, plan Ruling 6)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

const PLUGIN_MIGRATION_RULES_PREFIX = 'plg_fixture_x_';

function pluginMigrationRulesSource(string $body): string
{
    return <<<PHP
    <?php
    /* @phase expand */
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\DB;
    use Flexipos\Kernel\Schema\Support\JsonValidity;

    return new class extends Migration {
        public function up(): void
        {
            {$body}
        }
    };
    PHP;
}

it('reports Schema::table on a core table', function () {
    $source = pluginMigrationRulesSource("Schema::table('employees', function (Blueprint \$t) { \$t->string('x'); });");

    $found = PluginMigrationRules::inspect($source, PLUGIN_MIGRATION_RULES_PREFIX);

    expect(array_column($found, 'rule'))->toContain('plugin-table-prefix');
});

it('reports Schema::create on a table outside the prefix', function () {
    $source = pluginMigrationRulesSource("Schema::create('loyalty_x', function (Blueprint \$t) { \$t->string('x'); });");

    $found = PluginMigrationRules::inspect($source, PLUGIN_MIGRATION_RULES_PREFIX);

    expect(array_column($found, 'rule'))->toContain('plugin-table-prefix');
});

it('reports a non-literal table name', function () {
    $source = pluginMigrationRulesSource("\$name = 'x'; Schema::create(\$name, function (Blueprint \$t) { \$t->string('x'); });");

    $found = PluginMigrationRules::inspect($source, PLUGIN_MIGRATION_RULES_PREFIX);

    expect(array_column($found, 'rule'))->toContain('plugin-table-literal');
});

it('reports a raw DB::statement call', function () {
    $source = pluginMigrationRulesSource("DB::statement('alter table employees add column x varchar(1)');");

    $found = PluginMigrationRules::inspect($source, PLUGIN_MIGRATION_RULES_PREFIX);

    expect(array_column($found, 'rule'))->toContain('plugin-no-raw-sql');
});

it('reports a native enum column', function () {
    $source = pluginMigrationRulesSource("Schema::create('".PLUGIN_MIGRATION_RULES_PREFIX."things', function (Blueprint \$t) { \$t->enum('x', ['a','b']); });");

    $found = PluginMigrationRules::inspect($source, PLUGIN_MIGRATION_RULES_PREFIX);

    expect($found)->not->toBe([]);
});

it('reports a tenant_id column', function () {
    $source = pluginMigrationRulesSource("Schema::create('".PLUGIN_MIGRATION_RULES_PREFIX."things', function (Blueprint \$t) { \$t->string('tenant_id'); });");

    $found = PluginMigrationRules::inspect($source, PLUGIN_MIGRATION_RULES_PREFIX);

    expect($found)->not->toBe([]);
});

it('reports a JsonValidity::enforce target outside the prefix', function () {
    $source = pluginMigrationRulesSource("JsonValidity::enforce('loyalty_x', 'value');");

    $found = PluginMigrationRules::inspect($source, PLUGIN_MIGRATION_RULES_PREFIX);

    expect(array_column($found, 'rule'))->toContain('plugin-json-validity-prefix');
});

it('is clean on the two real FixtureEcho migrations', function () {
    $prefix = PluginName::tablePrefix('fixture-echo');

    foreach (glob(__DIR__.'/../Fixtures/FixtureEcho/migrations/*.php') as $file) {
        $found = PluginMigrationRules::inspect((string) file_get_contents($file), $prefix);

        expect($found)->toBe([]);
    }
});
