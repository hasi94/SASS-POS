<?php

use Flexipos\Kernel\Schema\Features\ReportSchemaStatus\ReportSchemaStatus;
use Flexipos\Kernel\Schema\Features\ReportSchemaStatus\ReportSchemaStatusHandler;
use Flexipos\Kernel\Schema\Features\ReportSchemaStatus\SchemaStatusReport;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The behind report
|--------------------------------------------------------------------------
|
| T-008 step 12, AC-57. Master-only: this slice opens no tenant connection at
| all, which is why it is the one part of step 12 that is fully provable while
| ENGINE_HOLD is set. AC-57 is still recorded NOT VERIFIED, because the
| criterion is marked [E].
|
| Red by omitting the mechanism. The dataset is the criterion's own: tenants
| at several versions, on two servers, one `archived` at an old version, and
| one exactly at the required version as the control.
|
*/

require_once __DIR__.'/../../../Support/migration-run-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    masterMappingMigrate();
});

function statusReport(int $required): SchemaStatusReport
{
    return app(ReportSchemaStatusHandler::class)->handle(new ReportSchemaStatus($required));
}

/**
 * The criterion's dataset, built once.
 */
function statusFixture(): void
{
    $east = masterMappingServer('server-east');
    $west = masterMappingServer('server-west');

    runFixtureTenant('tenant-oldest', $east, 'active', 2, 1024);
    runFixtureTenant('tenant-middle', $east, 'active', 4, 2048);
    runFixtureTenant('tenant-readonly', $west, 'read_only', 4, 3072);
    // Exactly at the floor: the control, and the input a `<=` comparison
    // would get wrong.
    runFixtureTenant('tenant-current', $west, 'active', 6, 4096);
    // Gone, not behind: a report that listed it would send somebody to
    // migrate a database nobody serves.
    runFixtureTenant('tenant-archived', $east, 'archived', 1, 512);
}

it('counts per version and per server, names the oldest laggard and leaves out the archived one', function () {
    statusFixture();

    $report = statusReport(6);

    expect($report->byVersion)->toBe([2 => 1, 4 => 2, 6 => 1])
        ->and($report->byServer)->toBe([
            masterMappingId('server-east') => [2 => 1, 4 => 1],
            masterMappingId('server-west') => [4 => 1, 6 => 1],
        ]);

    $behind = array_map(static fn (array $row): string => $row['tenant_id'], $report->behind);

    expect($behind)->toBe([
        masterMappingId('tenant-oldest'),
        masterMappingId('tenant-middle'),
        masterMappingId('tenant-readonly'),
    ])
        ->and($report->oldestLaggard)->toBe(masterMappingId('tenant-oldest'));

    // The archived tenant appears nowhere: not in the counts, not per server,
    // not in the list.
    foreach ($report->byVersion as $version => $count) {
        expect($version)->not->toBe(1);
    }

    expect($behind)->not->toContain(masterMappingId('tenant-archived'));
});

it('does not list a tenant exactly at the required version', function () {
    // Stated as its own case because it is the boundary a `<=` gets wrong,
    // and the failure mode is a report that asks an operator to migrate the
    // whole platform every time the floor moves.
    statusFixture();

    $behind = array_map(static fn (array $row): string => $row['tenant_id'], statusReport(6)->behind);

    expect($behind)->not->toContain(masterMappingId('tenant-current'));

    // And one version higher, the same tenant *is* behind - which is what
    // proves the comparison is against the argument and not against a
    // literal.
    $higher = array_map(static fn (array $row): string => $row['tenant_id'], statusReport(7)->behind);

    expect($higher)->toContain(masterMappingId('tenant-current'));
});

it('reports nobody behind as null rather than as an empty name', function () {
    $server = masterMappingServer('server-east');

    runFixtureTenant('tenant-current', $server, 'active', 6, 1024);

    $report = statusReport(6);

    expect($report->behind)->toBe([])
        ->and($report->oldestLaggard)->toBeNull();
});

it('refuses to report when no floor is configured at all', function () {
    // The same three states as the serving gate (ruling 13.1): an explicit 0
    // is the documented "no floor", and absent is a misconfiguration. A
    // report that folded them together would describe every tenant as behind
    // version zero and say nothing.
    statusFixture();

    config()->set('tenancy.required_schema_version', null);

    expect(Artisan::call('tenant:schema-status'))->toBe(1)
        ->and(Artisan::output())->toContain('APP_REQUIRED_SCHEMA_VERSION');

    config()->set('tenancy.required_schema_version', 0);

    expect(Artisan::call('tenant:schema-status'))->toBe(0);
});

it('prints the report and the laggard', function () {
    statusFixture();

    config()->set('tenancy.required_schema_version', 6);

    expect(Artisan::call('tenant:schema-status'))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('Required schema version: 6')
        ->and($output)->toContain(masterMappingId('tenant-oldest'))
        ->and($output)->not->toContain(masterMappingId('tenant-archived'));
});
