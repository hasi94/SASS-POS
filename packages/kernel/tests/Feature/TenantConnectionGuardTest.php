<?php

use Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Kernel\Tenancy\TenantModel;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/*
 * AC-1, AC-2, AC-3, AC-4 - the core security guarantee of the task.
 *
 * `master` is always there; `tenant` refuses to exist until a tenant does. The
 * refusal has to happen before any network attempt, with a message that says
 * plainly what is wrong, and it has to hold for the query builder and for
 * Eloquent alike.
 */

uses(TestCase::class);

/**
 * An Eloquent model on the shared tenant connection, exactly as every FP-204+
 * business model will be.
 */
final class TenancyGuardProbeModel extends TenantModel
{
    public $timestamps = false;

    protected $table = 'marker';
}

it('ships a master connection built from the environment', function () {
    $master = (array) config('database.connections.master');

    // AC-1: present, and configured from DB_MASTER_* - phpunit.xml points the
    // driver and database at sqlite, which is how a value coming from the
    // environment rather than from a literal is observable here.
    expect($master)->not->toBe([])
        ->and($master['driver'])->toBe('sqlite')
        ->and($master['database'])->toBe(':memory:')
        ->and($master)->toHaveKey('host')
        ->and($master)->toHaveKey('username')
        // No 'url' key: ConfigurationUrlParser must never be handed the empty
        // DB_URL the suite sets.
        ->and($master)->not->toHaveKey('url');
});

it('queries the master connection with no tenant resolved at all', function () {
    // AC-2.
    expect(app(Tenancy::class)->current()->hasTenant())->toBeFalse()
        ->and(DB::connection('master')->scalar('select 1 + 1'))->toEqual(2);
});

it('declares no usable tenant database at rest', function () {
    // AC-3: a sentinel driver, an empty database name and a prefix, and nothing
    // else. No host, no username, no password - ever, while no tenant is
    // resolved.
    $tenant = (array) config('database.connections.tenant');

    expect(array_keys($tenant))->toBe(['driver', 'database', 'prefix'])
        ->and($tenant['driver'])->toBe('flexipos-no-tenant')
        ->and($tenant['database'])->toBe('')
        ->and($tenant)->not->toHaveKey('host')
        ->and($tenant)->not->toHaveKey('port')
        ->and($tenant)->not->toHaveKey('username')
        ->and($tenant)->not->toHaveKey('password');
});

it('refuses a query on the tenant connection before opening anything', function () {
    $established = [];

    Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use (&$established): void {
        $established[] = (string) $event->connectionName;
    });

    expect(fn (): mixed => DB::connection('tenant')->scalar('select 1'))
        ->toThrow(NoTenantResolved::class, 'No tenant has been resolved for this process');

    // AC-4: the refusal happens while *building* the connection, so no
    // Connection object was cached and no ConnectionEstablished event fired -
    // which is to say no driver was ever asked to open anything.
    expect(array_keys(DB::getConnections()))->not->toContain('tenant')
        ->and($established)->not->toContain('tenant');
});

it('names the remedy in the refusal rather than failing like a dead database', function () {
    $message = null;

    try {
        DB::connection('tenant')->scalar('select 1');
    } catch (NoTenantResolved $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toBeString()
        ->and((string) $message)->toContain('[tenant] database connection')
        ->and((string) $message)->toContain('Tenancy::run()')
        // Not a driver-level failure dressed up as a tenancy one.
        ->and((string) $message)->not->toContain('SQLSTATE')
        ->and((string) $message)->not->toContain('Connection refused')
        ->and((string) $message)->not->toContain('Unsupported driver');
});

it('refuses the same way through an Eloquent model on the tenant connection', function () {
    // AC-4's other half: the boundary is the connection, so a model gets the
    // same refusal as a raw query, with no cooperation from the model.
    expect((new TenancyGuardProbeModel)->getConnectionName())->toBe('tenant');

    expect(fn (): int => TenancyGuardProbeModel::query()->count())
        ->toThrow(NoTenantResolved::class);
});

it('refuses a tenant transaction as loudly as a tenant query', function () {
    expect(fn (): mixed => DB::connection('tenant')->transaction(fn (): int => 1))
        ->toThrow(NoTenantResolved::class);
});
