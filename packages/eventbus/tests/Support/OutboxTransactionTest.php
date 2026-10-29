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

it('rolls back the outbox row together with the business change that caused it', function () {
    $connection = DB::connection(AccessTenantFixture::connectionName());

    try {
        $connection->transaction(function () use ($connection) {
            $connection->table('eventbus_marker')->insert(['value' => 'written']);

            app(Outbox::class)->record(new NewEvent('fixture.pinged', 'fixture', '01ARZ3NDEKTSV4RRFFQ69G5FAV'));

            throw new RuntimeException('forced rollback');
        });
    } catch (RuntimeException) {
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
