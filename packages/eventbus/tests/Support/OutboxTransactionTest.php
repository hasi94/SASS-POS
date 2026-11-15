<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Support;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Events\NewEvent;
use Flexipos\Contracts\Events\Outbox;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 5 - the outbox row shares the caller's own transaction (AC-6)
|--------------------------------------------------------------------------
*/

require_once __DIR__.'/eventbus-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    eventbusEnterTenant('tenant-a');
    eventbusCreateMarkerTable();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

/**
 * A test-local rollback signal (W-7, review round 1). qa's mutation testing
 * found that catching every `RuntimeException` let a SQLite "database is
 * locked" `QueryException` - which also extends `RuntimeException` - pass
 * this test for the wrong reason, with only a separate control test
 * actually going red. Production code never throws this type, so catching
 * it exactly is the whole guard: an unrelated lock error now surfaces
 * instead of being swallowed.
 */
final class ForcedRollback extends RuntimeException {}

it('rolls back the outbox row together with the business change that caused it', function () {
    $connection = DB::connection(AccessTenantFixture::connectionName());

    try {
        $connection->transaction(function () use ($connection) {
            $connection->table('eventbus_marker')->insert(['value' => 'written']);

            $connectionsBeforeWrite = array_keys(DB::getConnections());

            app(Outbox::class)->record(new NewEvent('fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FAV'));

            // OutboxWriter opens no transaction of its own (AC-6): still
            // depth 1, this closure's own transaction(). It also resolves
            // no connection beyond whatever was already resolved before
            // the write - the row landed on the caller's own connection.
            expect($connection->transactionLevel())->toBe(1);
            expect(array_keys(DB::getConnections()))->toBe($connectionsBeforeWrite);

            throw new ForcedRollback('forced rollback');
        });
    } catch (ForcedRollback) {
        // expected
    }

    expect($connection->table('outbox_events')->count())->toBe(0)
        ->and($connection->table('eventbus_marker')->count())->toBe(0);
});

it('control: the committed twin leaves both the marker row and the outbox row', function () {
    $connection = DB::connection(AccessTenantFixture::connectionName());

    $connection->transaction(function () use ($connection) {
        $connection->table('eventbus_marker')->insert(['value' => 'written']);

        app(Outbox::class)->record(new NewEvent('fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FAV'));
    });

    expect($connection->table('outbox_events')->count())->toBe(1)
        ->and($connection->table('eventbus_marker')->count())->toBe(1);
});
