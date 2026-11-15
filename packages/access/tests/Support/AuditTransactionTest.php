<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Features\AssignRole\AssignRole;
use Flexipos\Access\Features\AssignRole\AssignRoleHandler;
use Illuminate\Database\Events\QueryExecuted;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 16 - the change, the bump and the audit row are one (AC-71)
|--------------------------------------------------------------------------
|
| THE PROBE OBSERVES THE PROPERTY, NOT A CONSEQUENCE, and that is the
| shape-5 lesson from T-009 step 7 written out. The tempting test is "make
| something throw after the write and show the row is gone" - and it
| SURVIVES the very wrapper the criterion is about, because a handler with
| no transaction at all would still lose the row if the throw happened
| before the insert, and would keep it if afterwards, depending only on
| where the throw was put. What AC-71 is about is that the three writes
| are inside ONE transaction, so that is what is read: the connection's
| own `transactionLevel()`, during each statement, through a query
| listener.
|
| THE PROBE HAS ITS OWN LIVENESS CONTROL, because a probe that is reading
| nothing reports exactly what a probe reading a clean run reports. Two
| things are asserted about the probe itself: that it saw the three
| statements by name, and that the identical probe reports level 0 for a
| statement executed outside any transaction. Without the second, a probe
| that returned a non-zero constant would pass.
|
| The forced failure is produced by the listener itself, which throws when
| it sees the audit insert - so the change, the bump AND the audit row have
| all executed when it fires. That is "after its audit and bump were
| prepared", exactly; a throw placed earlier would prove less.
|
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * One actor who may assign, one subject, one role.
 *
 * @return array<string, string>
 */
function auditTransactionWorld(): array
{
    $ids = [
        'key' => AccessSeed::permission('fixture.a'),
    ];

    $ids['role'] = AccessSeed::role('shift_lead', [$ids['key']]);
    $ids['subject'] = AccessSeed::employee('subject');
    $ids['actor'] = AccessSeed::actorHolding('actor', [$ids['key']]);

    return $ids;
}

/**
 * @param  array<string, string>  $ids
 */
function assignForAudit(array $ids): void
{
    /** @var AssignRoleHandler $handler */
    $handler = app(AssignRoleHandler::class);

    $handler->handle(new AssignRole($ids['subject'], $ids['role'], null, $ids['actor']));
}

it('executes the change, the bump and the audit row inside one transaction (AC-71)', function () {
    $ids = auditTransactionWorld();
    $connection = AccessSeed::connection();

    /** @var list<array{sql: string, level: int}> $observed */
    $observed = [];

    $connection->listen(function (QueryExecuted $query) use ($connection, &$observed): void {
        $observed[] = ['sql' => $query->sql, 'level' => $connection->transactionLevel()];
    });

    assignForAudit($ids);

    $levelFor = static function (string $needle) use ($observed): ?int {
        foreach ($observed as $statement) {
            if (str_contains($statement['sql'], $needle)) {
                return $statement['level'];
            }
        }

        return null;
    };

    // The three writes AC-71 names, each read by the statement that made
    // it rather than by its effect.
    expect($levelFor('insert into "employee_roles"'))->not->toBeNull()
        ->and($levelFor('insert into "employee_roles"'))->toBeGreaterThan(0)
        ->and($levelFor('update "employees" set "perm_version"'))->not->toBeNull()
        ->and($levelFor('update "employees" set "perm_version"'))->toBeGreaterThan(0)
        ->and($levelFor('insert into "audit_logs"'))->not->toBeNull()
        ->and($levelFor('insert into "audit_logs"'))->toBeGreaterThan(0);

    // The probe saw something. A probe reading nothing reports the same
    // thing as a probe reading a clean run, and the three assertions above
    // would all be vacuous against an empty list if they were written as
    // a loop.
    expect(count($observed))->toBeGreaterThanOrEqual(3);
});

it('reports level zero for a statement outside a transaction, so a non-zero reading means something', function () {
    auditTransactionWorld();
    $connection = AccessSeed::connection();

    /** @var list<array{sql: string, level: int}> $observed */
    $observed = [];

    $connection->listen(function (QueryExecuted $query) use ($connection, &$observed): void {
        $observed[] = ['sql' => $query->sql, 'level' => $connection->transactionLevel()];
    });

    // The identical probe, on a statement nobody wrapped.
    $connection->table('employees')->count();

    expect($observed)->not->toBe([])
        ->and($observed[0]['level'])->toBe(0);
});

it('leaves neither the change, the bump nor the audit row when the operation fails mid-way (AC-71)', function () {
    $ids = auditTransactionWorld();
    $connection = AccessSeed::connection();

    $versionBefore = AccessSeed::permVersion($ids['subject']);

    $armed = true;

    $connection->listen(function (QueryExecuted $query) use (&$armed): void {
        // Fires once the audit insert has executed, so the change and the
        // bump are already written and uncommitted. Disarmed immediately so
        // the assertions below can read the database.
        if ($armed && str_contains($query->sql, 'insert into "audit_logs"')) {
            $armed = false;

            throw new RuntimeException('Forced failure after the audit row was written.');
        }
    });

    try {
        assignForAudit($ids);

        expect(false)->toBeTrue('the forced failure must propagate');
    } catch (RuntimeException $failure) {
        expect($failure->getMessage())->toContain('Forced failure');
    }

    $armed = false;

    expect($connection->table('employee_roles')->where('employee_id', $ids['subject'])->count())->toBe(0)
        ->and(AccessSeed::permVersion($ids['subject']))->toBe($versionBefore)
        ->and($connection->table('audit_logs')->count())->toBe(0);
});

it('persists all three when the same operation is not interfered with (AC-71 control)', function () {
    $ids = auditTransactionWorld();
    $connection = AccessSeed::connection();

    $versionBefore = AccessSeed::permVersion($ids['subject']);

    assignForAudit($ids);

    expect($connection->table('employee_roles')->where('employee_id', $ids['subject'])->count())->toBe(1)
        ->and(AccessSeed::permVersion($ids['subject']))->toBe($versionBefore + 1)
        ->and($connection->table('audit_logs')->count())->toBe(1);
});
