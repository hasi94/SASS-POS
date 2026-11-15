<?php

use Flexipos\Kernel\Schema\Features\DetectSchemaDrift\DetectSchemaDrift;
use Flexipos\Kernel\Schema\Features\DetectSchemaDrift\DetectSchemaDriftHandler;
use Flexipos\Kernel\Schema\Features\DetectSchemaDrift\SchemaDrift;
use Flexipos\Kernel\Schema\Features\RepairSchemaVersion\RepairSchemaVersion;
use Flexipos\Kernel\Schema\Features\RepairSchemaVersion\RepairSchemaVersionHandler;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Testing\Engine\EngineConnection;
use Flexipos\Testing\Engine\EngineOnHold;
use Flexipos\Testing\Engine\ThreeTenantFixture;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Drift and repair, against three real databases
|--------------------------------------------------------------------------
|
| T-008 step 12's gated half: the reads that need the three-tenant fixture.
| **Nothing in this file is evidence for AC-54 or AC-55 while ENGINE_HOLD is
| set**; both are recorded NOT VERIFIED with the hold and gate G-1 named. The
| comparison itself, both directions, the serving refusal and the
| "re-applies nothing" half are proven on the stand-in in
| packages/kernel/tests/Schema/Features/{DetectSchemaDrift,RepairSchemaVersion}/.
|
| Nothing here is destructive: the only statements are selects against the
| tenant databases and one UPDATE of a master row. Every database is created
| and dropped by ThreeTenantFixture, by name, through EngineDatabase.
|
*/

function driftEngineFixture(): ThreeTenantFixture
{
    expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0);

    $baseline = MigrationSet::baseline(base_path((string) config('schema.tenant_migration_path')));

    $fixture = new ThreeTenantFixture(base_path((string) config('schema.tenant_migration_path')));

    $fixture->mapInto(DB::connection('master'), $baseline->highestVersion());
    $fixture->build();

    return $fixture;
}

it('refuses to read a tenant database while the hold is set', function () {
    $fixture = new ThreeTenantFixture(base_path((string) config('schema.tenant_migration_path')));

    expect(fn () => $fixture->build())->toThrow(EngineOnHold::class);
})->skip(fn (): bool => ! EngineConnection::onHold(), 'the ENGINE_HOLD suspension has been cleared');

it('reports the fixture tenant whose mapping is ahead of its database', function () {
    // Tenant A holds nothing and its mapping says so, so it is aligned at
    // zero; B and C carry the baseline. Raising A's mirror by hand is the
    // `ahead` input, and B is the control that the check is not reporting
    // everybody.
    $baseline = MigrationSet::baseline(base_path((string) config('schema.tenant_migration_path')));
    $fixture = driftEngineFixture();

    try {
        DB::connection('master')->table('tenant_databases')
            ->where('tenant_id', ThreeTenantFixture::EMPTY_TENANT)
            ->update(['schema_version' => $baseline->highestVersion()]);

        $report = app(DetectSchemaDriftHandler::class)->handle(new DetectSchemaDrift($baseline));

        $byTenant = [];

        foreach ($report as $answer) {
            $byTenant[$answer->tenantId] = $answer->direction;
        }

        expect($byTenant[ThreeTenantFixture::EMPTY_TENANT])->toBe(SchemaDrift::AHEAD)
            ->and($byTenant[ThreeTenantFixture::FAILING_TENANT])->toBe(SchemaDrift::ALIGNED);
    } finally {
        $fixture->drop();
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('corrects a fixture mapping that is behind its database and re-applies nothing', function () {
    $baseline = MigrationSet::baseline(base_path((string) config('schema.tenant_migration_path')));
    $fixture = driftEngineFixture();

    try {
        $database = $fixture->databaseNameFor(ThreeTenantFixture::FAILING_TENANT);

        $before = EngineConnection::connection($database)->table('migrations')->count();

        // The crash between the runner's two writes, on a real database.
        DB::connection('master')->table('tenant_databases')
            ->where('tenant_id', ThreeTenantFixture::FAILING_TENANT)
            ->update(['schema_version' => 2]);

        $result = app(RepairSchemaVersionHandler::class)->handle(
            new RepairSchemaVersion($baseline, [ThreeTenantFixture::FAILING_TENANT])
        );

        expect($result->corrected)->toBe([ThreeTenantFixture::FAILING_TENANT => $baseline->highestVersion()])
            // Nothing ran: the row count is identical, which is the half a
            // "the mapping is right now" assertion cannot give.
            ->and(EngineConnection::connection($database)->table('migrations')->count())->toBe($before);
    } finally {
        $fixture->drop();
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');
