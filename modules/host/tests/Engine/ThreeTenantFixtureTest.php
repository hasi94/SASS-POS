<?php

use Flexipos\Testing\Engine\EngineConnection;
use Flexipos\Testing\Engine\EngineOnHold;
use Flexipos\Testing\Engine\ThreeTenantFixture;

/*
|--------------------------------------------------------------------------
| The three-tenant fixture's engine half
|--------------------------------------------------------------------------
|
| T-008 step 8. Building the fixture creates three databases and applies the
| baseline to two of them, so it is engine work and it waits behind gate G-1.
| **Nothing in this file is evidence for any [E] criterion while ENGINE_HOLD is
| set**; the criteria the fixture serves are recorded NOT VERIFIED with the hold
| and the gate named.
|
| What runs under the hold is the inverted test, and it is not a formality: in
| either state exactly one of the two cases below asserts something, so a reader
| six months from now cannot find a file in which nothing is asserted at all.
| The hold is fail-closed in code - a connection attempt *throws* - and only the
| tests that declare the skip are absent.
|
| The fixture's mapping half needs no engine and is proven in
| tests/Feature/ThreeTenantFixtureMappingTest.php.
|
*/

it('refuses to build while the engine is on hold', function () {
    $fixture = new ThreeTenantFixture(base_path((string) config('schema.tenant_migration_path')));

    // Both directions: the hold stops the fixture before it creates anything
    // and before it drops anything. A fixture that only checked on the way in
    // would still issue a drop in a teardown after a failure.
    expect(fn () => $fixture->build())->toThrow(EngineOnHold::class)
        ->and(fn () => $fixture->drop())->toThrow(EngineOnHold::class);
})->skip(fn (): bool => ! EngineConnection::onHold(), 'the ENGINE_HOLD suspension has been cleared');

it('builds three databases and applies the baseline to two of them', function () {
    $fixture = new ThreeTenantFixture(base_path((string) config('schema.tenant_migration_path')));

    try {
        $fixture->build();

        $empty = $fixture->databaseNameFor(ThreeTenantFixture::EMPTY_TENANT);
        $failing = $fixture->databaseNameFor(ThreeTenantFixture::FAILING_TENANT);

        // Tenant A is empty - not "has no tables we recognise", but holds none
        // of the baseline's - while the tenant FX-2 fails on starts from the
        // same baseline as B, so its later failure is about FX-2 and not about
        // where it started.
        expect(EngineConnection::connection($empty)->getSchemaBuilder()->hasTable('settings'))->toBeFalse()
            ->and(EngineConnection::connection($failing)->getSchemaBuilder()->hasTable('settings'))->toBeTrue();

        foreach (tenantBaselineTables() as $table) {
            expect(EngineConnection::connection($failing)->getSchemaBuilder()->hasTable($table))->toBeTrue();
        }
    } finally {
        // By name, one at a time, through EngineDatabase - the only form of
        // cleanup this repository has. There is no sweep anywhere.
        $fixture->drop();
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');
