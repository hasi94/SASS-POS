<?php

use Flexipos\Kernel\Schema\Exceptions\TenantLockUnsupported;
use Flexipos\Kernel\Schema\Support\PerTenantLock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The lock name, and what the lock does on a driver that has no locks
|--------------------------------------------------------------------------
|
| T-008 step 10. Two things are provable here and the third is not:
|
| 1. the lock name is a pure function, and it is the one thing two sessions
|    have to agree on;
| 2. a connection that cannot grant the lock produces a named error rather than
|    a cheerful "granted". That direction is the criterion: a helper that
|    returned true on SQLite would be a guard stubbed permissively by the
|    driver, and the first thing it would permit is two runners inside one
|    tenant.
|
| What is **not** provable here is whether a second session is actually
| refused - AC-49 and AC-65 - because that needs two connections to a real
| engine and a lock is re-entrant within one session. Those criteria are
| **NOT VERIFIED**: ENGINE_HOLD is set and gate G-1 is unmet. The gated test
| lives in modules/host/tests/Engine/PerTenantLockTest.php and declares its
| skip; nothing in this file is named as if it covered them.
|
*/

uses(TestCase::class);

it('builds one lock name per tenant, inside the engine 64-character limit', function () {
    $a = PerTenantLock::nameFor('01J0000000000000000000000A');
    $b = PerTenantLock::nameFor('01J0000000000000000000000B');

    expect($a)->toBe(PerTenantLock::PREFIX.'01J0000000000000000000000A')
        ->and($a)->not->toBe($b)
        // MariaDB truncates a lock name at 64 characters, and two names
        // truncated to the same string are one lock: the prefix plus a
        // 26-character ULID has to fit with room to spare.
        ->and(strlen($a))->toBeLessThanOrEqual(64);
});

it('refuses to report a lock on a driver that has no GET_LOCK, naming the driver', function () {
    // The suite stand-in. Asserted from configuration first, because the whole
    // point of the case is which driver answered.
    expect(config('database.connections.master.driver'))->toBe('sqlite');

    $connection = DB::connection('master');

    expect(fn () => PerTenantLock::acquire($connection, '01J0000000000000000000000A'))
        ->toThrow(TenantLockUnsupported::class)
        ->and(fn () => PerTenantLock::release($connection, '01J0000000000000000000000A'))
        ->toThrow(TenantLockUnsupported::class);

    // The driver and the lock name are both in the message: a reader who meets
    // this error has to be told which connection was asked and for what.
    try {
        PerTenantLock::acquire($connection, '01J0000000000000000000000A');
    } catch (TenantLockUnsupported $refusal) {
        expect($refusal->getMessage())->toContain('[sqlite]')
            ->and($refusal->getMessage())->toContain(PerTenantLock::nameFor('01J0000000000000000000000A'));
    }
});
