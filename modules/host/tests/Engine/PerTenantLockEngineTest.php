<?php

use Flexipos\Kernel\Schema\Support\PerTenantLock;
use Flexipos\Testing\Engine\EngineConnection;
use Flexipos\Testing\Engine\EngineOnHold;
use Flexipos\Testing\Engine\ThreeTenantFixture;

/*
|--------------------------------------------------------------------------
| The per-tenant lock, on the only engine that has one
|--------------------------------------------------------------------------
|
| T-008 step 10, the gated half. GET_LOCK exists on MariaDB and not on the
| suite stand-in, so **AC-49 and AC-65 are NOT VERIFIED** while ENGINE_HOLD is
| set and gate G-1 is unmet. Nothing in this file has ever run against the
| engine, and no test here is named as if it had.
|
| The two-session case AC-49 is really about is not here either, and that is
| deliberate rather than an omission: a MariaDB advisory lock is re-entrant
| within one session, so a single-connection test of "the second holder is
| refused" would assert the opposite of the criterion and pass. It needs two
| connections to a live engine, which is the first thing to write once the hold
| clears.
|
| What runs under the hold is the inverted case, for the reason plan section
| 11.6 gives: in either state exactly one of the two cases below asserts
| something, so this is never a file in which nothing is asserted.
|
*/

it('cannot reach the engine to take a lock while the hold is on', function () {
    // Fail-closed in code: the connection throws before any statement, so a
    // lock cannot be taken by accident while the hold stands.
    expect(fn () => EngineConnection::connection(
        (new ThreeTenantFixture(base_path((string) config('schema.tenant_migration_path'))))
            ->databaseNameFor(ThreeTenantFixture::FAILING_TENANT)
    ))->toThrow(EngineOnHold::class);
})->skip(fn (): bool => ! EngineConnection::onHold(), 'the ENGINE_HOLD suspension has been cleared');

it('takes and releases the per-tenant lock on one session without waiting', function () {
    $fixture = new ThreeTenantFixture(base_path((string) config('schema.tenant_migration_path')));
    $tenant = ThreeTenantFixture::FAILING_TENANT;

    try {
        $fixture->build();

        $connection = EngineConnection::connection($fixture->databaseNameFor($tenant));

        // This is the engine capability, not AC-49: within one session the
        // lock is re-entrant, so what is measured here is that GET_LOCK with a
        // zero timeout **returns immediately** rather than waiting. The
        // criterion about a *second session* needs a second connection and is
        // the first thing to add when the hold clears.
        expect(PerTenantLock::acquire($connection, $tenant))->toBeTrue()
            ->and(PerTenantLock::release($connection, $tenant))->toBeTrue();
    } finally {
        $fixture->drop();
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');
