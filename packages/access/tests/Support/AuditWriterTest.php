<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Features\AssignRole\AssignRole;
use Flexipos\Access\Features\AssignRole\AssignRoleHandler;
use Flexipos\Access\Features\AttachBranch\AttachBranch;
use Flexipos\Access\Features\AttachBranch\AttachBranchHandler;
use Flexipos\Access\Features\BootstrapOwner\BootstrapOwnerHandler;
use Flexipos\Access\Features\ChangeEmployeeStatus\ChangeEmployeeStatus;
use Flexipos\Access\Features\ChangeEmployeeStatus\ChangeEmployeeStatusHandler;
use Flexipos\Access\Features\ClearEmployeePermission\ClearEmployeePermission;
use Flexipos\Access\Features\ClearEmployeePermission\ClearEmployeePermissionHandler;
use Flexipos\Access\Features\CreateEmployee\CreateEmployee;
use Flexipos\Access\Features\CreateEmployee\CreateEmployeeHandler;
use Flexipos\Access\Features\CreateRole\CreateRole;
use Flexipos\Access\Features\CreateRole\CreateRoleHandler;
use Flexipos\Access\Features\DeleteEmployee\DeleteEmployee;
use Flexipos\Access\Features\DeleteEmployee\DeleteEmployeeHandler;
use Flexipos\Access\Features\DeleteRole\DeleteRole;
use Flexipos\Access\Features\DeleteRole\DeleteRoleHandler;
use Flexipos\Access\Features\DetachBranch\DetachBranch;
use Flexipos\Access\Features\DetachBranch\DetachBranchHandler;
use Flexipos\Access\Features\RemoveRoleAssignment\RemoveRoleAssignment;
use Flexipos\Access\Features\RemoveRoleAssignment\RemoveRoleAssignmentHandler;
use Flexipos\Access\Features\RenameRole\RenameRole;
use Flexipos\Access\Features\RenameRole\RenameRoleHandler;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermission;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermissionHandler;
use Flexipos\Access\Features\SetPin\SetPin;
use Flexipos\Access\Features\SetPin\SetPinHandler;
use Flexipos\Access\Features\SetRolePermissions\SetRolePermissions;
use Flexipos\Access\Features\SetRolePermissions\SetRolePermissionsHandler;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Features\UpdateEmployee\UpdateEmployee;
use Flexipos\Access\Features\UpdateEmployee\UpdateEmployeeHandler;
use Flexipos\Access\Support\AccessLogWriter;
use Flexipos\Access\Support\AuditWriter;
use Flexipos\Access\Support\ClientIp;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Contracts\Access\RefusalReason;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 16 - one audit row per successful operation (AC-70)
|--------------------------------------------------------------------------
|
| The sweep is the instrument and it enumerates rather than lists: it runs
| every writing operation of sections C to E once, counts the audit rows
| before and after each, and asserts the delta is exactly one. "At least
| one" would pass a handler that wrote twice, which is the shape a retry
| or a second writer produces and the shape an auditor cannot tell from
| two real operations.
|
| Its domain is PUBLISHED and derived: the last case reads which handlers
| actually call the writer and compares that against the operations run
| here, so a fifteenth operation arriving with no audit row - or with one
| and no case - is red rather than silent.
|
| `actor_name` being a snapshot is asserted by changing it afterwards, and
| the claim that case supports is bounded: against a stored column it
| CANNOT fail, because a column holds a value. What it pins is the shape -
| that the name is written at write time rather than derived on read - so
| that a later refactor to a join or a view turns it red. It is a
| regression guard, not evidence that today's writer is right, and the
| assertion that the name was captured at all is the other half of it.
|
| A refusal writes NO row, asserted for three different refusal reasons,
| because one guard throwing before the writer proves nothing about a
| guard that throws after it.
|
*/

uses(TestCase::class);

/**
 * The address every row below is expected to carry.
 *
 * Bound through {@see ClientIp::fixed()} because a package test has no
 * request, so the shipped resolver would answer null - and null is exactly
 * what the defect wrote. An expected value the test chose is the only way
 * the assertion can tell "the writer stored the client's address" from
 * "there was no client".
 */
const AUDIT_FIXED_IP = '203.0.113.7';

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(ClientIp::class, ClientIp::fixed(AUDIT_FIXED_IP));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @return list<array<string, mixed>>
 */
function auditRows(): array
{
    return AccessSeed::connection()->table('audit_logs')
        ->orderBy('id')
        ->get()
        ->map(static fn ($row): array => (array) $row)
        ->all();
}

function auditRowCount(): int
{
    return AccessSeed::connection()->table('audit_logs')->count();
}

/**
 * Every column `audit_logs` holds, so that an expected row is a whole row.
 *
 * Read from the table rather than listed here: a column added by a later
 * migration arrives in this list on the day it is created, and the
 * comparisons below then report it as unaccounted for instead of ignoring
 * it. A hand-written list is the thing that made AC-70's `ip` invisible.
 *
 * @return list<string>
 */
function auditColumns(): array
{
    $columns = AccessSeed::connection()->getSchemaBuilder()->getColumnListing('audit_logs');

    sort($columns);

    return $columns;
}

/**
 * Compare one written row against the whole row that was expected.
 *
 * **The assertion shape is the point, and it is AC-70's remedy.** Step 16
 * asserted this row field by field, and a field-by-field assertion is
 * silent about every field it omits - which is how `ip` shipped
 * hard-coded to null through fourteen handlers and a green sweep. A
 * criterion that enumerates the fields of a row is asserted against the
 * whole row: every column is named, `id` and `created_at` are the two
 * whose values the test cannot know and are asserted for shape instead,
 * and anything else absent from `$expected` is reported.
 *
 * Extracted so it can be RUN TWICE - once against the row the writer
 * produced, and once against a row with a field knocked out, which must
 * throw.
 *
 * @param  array<string, mixed>  $row
 * @param  array<string, mixed>  $expected
 */
function assertWholeAuditRow(array $row, array $expected): void
{
    $columns = auditColumns();

    // `id` is the table's and `created_at` is the clock's; both are
    // asserted for shape, and naming them here is what keeps them from
    // being quietly absent from the comparison.
    expect(array_keys($expected + ['id' => null, 'created_at' => null]))
        ->toEqualCanonicalizing($columns, 'every column of audit_logs must be accounted for')
        ->and($row['id'])->not->toBeNull()
        ->and((string) $row['created_at'])->not->toBe('');

    $actual = $row;

    unset($actual['id'], $actual['created_at']);

    ksort($actual);
    ksort($expected);

    expect($actual)->toBe($expected);
}

/**
 * The actor, the subject, a role and a branch.
 *
 * @return array<string, string>
 */
function auditWorld(): array
{
    $ids = [
        'key' => AccessSeed::permission('fixture.a'),
        'branch' => AccessSeed::branch(),
    ];

    $ids['role'] = AccessSeed::role('shift_lead', [$ids['key']]);
    $ids['subject'] = AccessSeed::employee('subject');
    $ids['actor'] = AccessSeed::actorHolding('actor', [$ids['key']]);

    // T-026 D-23: grantOwner() needs the system `owner` role and an
    // employee to grant it to. The role comes from the sync below, which
    // writes no audit row of its own; the employee is seeded directly
    // (AccessSeed's own convention, not through CreateEmployeeHandler),
    // precisely so this setup does not add a row ahead of the one every
    // *other* case in this file expects to find at index 0.
    app(SyncPermissionRegistryHandler::class)->handle(new SyncPermissionRegistry);
    $ids['ownerEmployee'] = AccessSeed::employee('OWNER');

    return $ids;
}

/**
 * Every operation of sections C to E that writes, in an order each one's
 * preconditions allow, keyed by the handler that carries the write.
 *
 * @param  array<string, string>  $ids
 * @return array<class-string, callable(): void>
 */
function auditOperations(array $ids): array
{
    $created = [];

    return [
        CreateRoleHandler::class => static function () use ($ids, &$created): void {
            /** @var CreateRoleHandler $handler */
            $handler = app(CreateRoleHandler::class);
            $created['role'] = $handler->handle(new CreateRole('tidy', 'Tidy', null, $ids['actor']))->id;
        },
        RenameRoleHandler::class => static function () use ($ids, &$created): void {
            /** @var RenameRoleHandler $handler */
            $handler = app(RenameRoleHandler::class);
            $handler->handle(new RenameRole($created['role'], 'Tidier', 'A description.', $ids['actor']));
        },
        SetRolePermissionsHandler::class => static function () use ($ids, &$created): void {
            /** @var SetRolePermissionsHandler $handler */
            $handler = app(SetRolePermissionsHandler::class);
            $handler->handle(new SetRolePermissions($created['role'], [$ids['key']], $ids['actor']));
        },
        CreateEmployeeHandler::class => static function () use ($ids, &$created): void {
            /** @var CreateEmployeeHandler $handler */
            $handler = app(CreateEmployeeHandler::class);
            $created['employee'] = $handler->handle(new CreateEmployee(
                code: 'new-emp',
                firstName: 'New',
                actorId: $ids['actor'],
            ))->id;
        },
        UpdateEmployeeHandler::class => static function () use ($ids, &$created): void {
            /** @var UpdateEmployeeHandler $handler */
            $handler = app(UpdateEmployeeHandler::class);
            $handler->handle(new UpdateEmployee($created['employee'], ['first_name' => 'Renamed'], $ids['actor']));
        },
        AttachBranchHandler::class => static function () use ($ids): void {
            /** @var AttachBranchHandler $handler */
            $handler = app(AttachBranchHandler::class);
            $handler->handle(new AttachBranch($ids['subject'], $ids['branch'], false, $ids['actor']));
        },
        AssignRoleHandler::class => static function () use ($ids): void {
            /** @var AssignRoleHandler $handler */
            $handler = app(AssignRoleHandler::class);
            $handler->handle(new AssignRole($ids['subject'], $ids['role'], $ids['branch'], $ids['actor']));
        },
        SetEmployeePermissionHandler::class => static function () use ($ids): void {
            /** @var SetEmployeePermissionHandler $handler */
            $handler = app(SetEmployeePermissionHandler::class);
            $handler->handle(new SetEmployeePermission($ids['subject'], $ids['key'], true, null, $ids['actor']));
        },
        ClearEmployeePermissionHandler::class => static function () use ($ids): void {
            /** @var ClearEmployeePermissionHandler $handler */
            $handler = app(ClearEmployeePermissionHandler::class);
            $handler->handle(new ClearEmployeePermission($ids['subject'], $ids['key'], null, $ids['actor']));
        },
        RemoveRoleAssignmentHandler::class => static function () use ($ids): void {
            /** @var RemoveRoleAssignmentHandler $handler */
            $handler = app(RemoveRoleAssignmentHandler::class);
            $handler->handle(new RemoveRoleAssignment($ids['subject'], $ids['role'], $ids['branch'], $ids['actor']));
        },
        DetachBranchHandler::class => static function () use ($ids): void {
            /** @var DetachBranchHandler $handler */
            $handler = app(DetachBranchHandler::class);
            $handler->handle(new DetachBranch($ids['subject'], $ids['branch'], $ids['actor']));
        },
        SetPinHandler::class => static function () use ($ids): void {
            // Step 17, and AC-70's `pin_set` clause: it was absent from this
            // sweep only because the slice did not exist. The row records the
            // fact and the instant; SetPinTest asserts that it holds neither
            // the PIN nor the hash.
            /** @var SetPinHandler $handler */
            $handler = app(SetPinHandler::class);
            $handler->handle(new SetPin($ids['subject'], '7391', $ids['actor']));
        },
        ChangeEmployeeStatusHandler::class => static function () use ($ids): void {
            /** @var ChangeEmployeeStatusHandler $handler */
            $handler = app(ChangeEmployeeStatusHandler::class);
            $handler->handle(new ChangeEmployeeStatus($ids['subject'], EmployeeStatus::ON_LEAVE, $ids['actor']));
        },
        DeleteEmployeeHandler::class => static function () use ($ids): void {
            /** @var DeleteEmployeeHandler $handler */
            $handler = app(DeleteEmployeeHandler::class);
            $handler->handle(new DeleteEmployee($ids['subject'], $ids['actor']));
        },
        DeleteRoleHandler::class => static function () use ($ids, &$created): void {
            /** @var DeleteRoleHandler $handler */
            $handler = app(DeleteRoleHandler::class);
            $handler->handle(new DeleteRole($created['role'], $ids['actor']));
        },
        // T-026 D-23: the one operation with no actor at all, by design -
        // see the special case below for its `actor_id`/`actor_name`.
        BootstrapOwnerHandler::class => static function () use ($ids): void {
            /** @var BootstrapOwnerHandler $handler */
            $handler = app(BootstrapOwnerHandler::class);
            $handler->grantOwner($ids['ownerEmployee']);
        },
    ];
}

it('writes exactly one audit row for every writing operation of sections C to E (AC-70)', function () {
    $ids = auditWorld();
    $operations = auditOperations($ids);

    expect($operations)->not->toBe([]);

    $actions = [];

    foreach ($operations as $handler => $run) {
        $before = auditRowCount();

        $run();

        $rows = auditRows();

        expect(count($rows) - $before)->toBe(1, "[{$handler}] must write exactly one audit row");

        $row = $rows[count($rows) - 1];

        // Every column is accounted for, so a field the writer stopped
        // filling is reported here rather than passed over.
        expect(array_keys($row))->toEqualCanonicalizing(
            auditColumns(),
            "[{$handler}] wrote a row that does not cover audit_logs"
        );

        // The fields that are the same for every operation except one, with
        // their expected values - `ip` among them, which is the field a
        // field-by-field assertion omitted and a whole-row one cannot.
        //
        // T-026 D-23: BootstrapOwnerHandler::grantOwner() is the one
        // operation with no actor at all, by design - the chicken-and-egg
        // first-owner grant - so its expected `actor_id`/`actor_name` are
        // both null rather than the shared actor every other operation uses.
        $isActorless = $handler === BootstrapOwnerHandler::class;

        expect([
            'module' => $row['module'],
            'actor_type' => $row['actor_type'],
            'actor_id' => $row['actor_id'],
            'actor_name' => $row['actor_name'],
            'ip' => AccessLogWriter::unpackIp($row['ip'] === null ? null : (string) $row['ip']),
            'user_agent' => $row['user_agent'],
            'request_id' => $row['request_id'],
        ])->toBe([
            'module' => AuditWriter::MODULE,
            'actor_type' => AuditWriter::ACTOR_TYPE,
            'actor_id' => $isActorless ? null : $ids['actor'],
            'actor_name' => $isActorless ? null : 'actor',
            'ip' => AUDIT_FIXED_IP,
            'user_agent' => null,
            'request_id' => null,
        ], "[{$handler}] constant audit fields");

        expect((string) $row['action'])->not->toBe('')
            ->and($row['subject_id'])->not->toBeNull()
            ->and($row['created_at'])->not->toBeNull();

        $actions[$handler] = (string) $row['action'];
    }

    // Printed, so the report names what was exercised rather than a count.
    expect(array_values($actions))->toBe(array_values(array_unique($actions)), 'actions: '.implode(', ', $actions))
        ->and(count($actions))->toBe(count($operations));
});

it('records the actors name as a snapshot that a later rename does not move (AC-70)', function () {
    $ids = auditWorld();

    /** @var CreateRoleHandler $handler */
    $handler = app(CreateRoleHandler::class);
    $handler->handle(new CreateRole('tidy', 'Tidy', null, $ids['actor']));

    $before = auditRows()[0];

    expect($before['actor_name'])->toBe('actor');

    AccessSeed::connection()->table('employees')
        ->where('id', $ids['actor'])
        ->update(['display_name' => 'Somebody Else']);

    expect(auditRows()[0]['actor_name'])->toBe('actor');
});

it('derives changed_fields from what actually moved, not from what was named (AC-70)', function () {
    $ids = auditWorld();

    $employeeId = AccessSeed::employee('target', ['first_name' => 'Same', 'last_name' => 'Old']);

    /** @var UpdateEmployeeHandler $handler */
    $handler = app(UpdateEmployeeHandler::class);

    // Two fields named, one of them identical to what is stored.
    $handler->handle(new UpdateEmployee(
        $employeeId,
        ['first_name' => 'Same', 'last_name' => 'New'],
        $ids['actor']
    ));

    $row = auditRows()[0];

    expect(json_decode((string) $row['changed_fields'], true))->toBe(['last_name'])
        ->and(json_decode((string) $row['before'], true))->toBe(['first_name' => 'Same', 'last_name' => 'Old'])
        ->and(json_decode((string) $row['after'], true))->toBe(['first_name' => 'Same', 'last_name' => 'New']);
});

it('writes no audit row for a refused operation, whichever guard refused it (AC-70)', function () {
    $ids = auditWorld();

    AccessSeed::attach($ids['subject'], $ids['branch']);

    // validation - a role key already used
    AccessRefusals::expect(RefusalReason::Validation, function () use ($ids) {
        app(CreateRoleHandler::class)->handle(new CreateRole('shift_lead', 'Clash', null, $ids['actor']));
    });

    // not_attached - a branch the subject is not attached to
    AccessRefusals::expect(RefusalReason::NotAttached, function () use ($ids) {
        app(AssignRoleHandler::class)->handle(
            new AssignRole($ids['subject'], $ids['role'], AccessSeed::branch(), $ids['actor'])
        );
    });

    // self_change - the actor on their own record
    AccessRefusals::expect(RefusalReason::SelfChange, function () use ($ids) {
        app(AttachBranchHandler::class)->handle(
            new AttachBranch($ids['actor'], AccessSeed::branch(), false, $ids['actor'])
        );
    });

    expect(auditRowCount())->toBe(0);
});

it('covers exactly the handlers that call the writer, read from their source (D-27)', function () {
    $featureRoot = dirname(__DIR__, 2).'/src/Features';
    $writers = [];

    /** @var iterable<\SplFileInfo> $files */
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($featureRoot));

    foreach ($files as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        if (! str_contains((string) file_get_contents($file->getPathname()), '$this->audit->write(')) {
            continue;
        }

        $slice = basename(dirname($file->getPathname()));
        $writers[] = 'Flexipos\\Access\\Features\\'.$slice.'\\'.$file->getBasename('.php');
    }

    sort($writers);

    $covered = array_keys(auditOperations(['key' => '', 'branch' => '', 'role' => '', 'subject' => '', 'actor' => '']));
    sort($covered);

    expect($writers)->not->toBe([])
        ->and($writers)->toBe($covered, 'handlers calling the audit writer: '.implode(', ', $writers));
});

it('writes the whole audit row, every column of it, for one operation (AC-70)', function () {
    $ids = auditWorld();

    /** @var CreateRoleHandler $handler */
    $handler = app(CreateRoleHandler::class);
    $roleId = $handler->handle(new CreateRole('tidy', 'Tidy', null, $ids['actor']))->id;

    $row = auditRows()[0];

    assertWholeAuditRow($row, [
        'actor_type' => AuditWriter::ACTOR_TYPE,
        'actor_id' => $ids['actor'],
        'actor_name' => 'actor',
        'branch_id' => null,
        'module' => AuditWriter::MODULE,
        'action' => (string) $row['action'],
        'subject_type' => (string) $row['subject_type'],
        'subject_id' => $roleId,
        'before' => null,
        'after' => (string) $row['after'],
        'changed_fields' => (string) $row['changed_fields'],
        'ip' => AccessLogWriter::packIp(AUDIT_FIXED_IP),
        'user_agent' => null,
        'request_id' => null,
    ]);

    // The four fields the comparison above took from the row itself are
    // pinned here, so the whole-row assertion is not partly circular.
    expect((string) $row['action'])->toBe('role_created')
        ->and((string) $row['subject_type'])->toBe('role')
        ->and(json_decode((string) $row['after'], true))->toHaveKey('key')
        ->and(json_decode((string) $row['changed_fields'], true))->toContain('key');
});

it('reddens when a field is absent, where the field-by-field shape passed (AC-70, mutation control)', function () {
    $ids = auditWorld();

    /** @var CreateRoleHandler $handler */
    $handler = app(CreateRoleHandler::class);
    $handler->handle(new CreateRole('tidy', 'Tidy', null, $ids['actor']));

    $row = auditRows()[0];

    $expected = [
        'actor_type' => AuditWriter::ACTOR_TYPE,
        'actor_id' => $ids['actor'],
        'actor_name' => 'actor',
        'branch_id' => null,
        'module' => AuditWriter::MODULE,
        'action' => (string) $row['action'],
        'subject_type' => (string) $row['subject_type'],
        'subject_id' => (string) $row['subject_id'],
        'before' => null,
        'after' => (string) $row['after'],
        'changed_fields' => (string) $row['changed_fields'],
        'ip' => AccessLogWriter::packIp(AUDIT_FIXED_IP),
        'user_agent' => null,
        'request_id' => null,
    ];

    // The shipped row passes, which is the liveness half: an assertion
    // that throws on everything proves nothing about the one below.
    assertWholeAuditRow($row, $expected);

    // The defect, reproduced in its exact shape: a writer that stopped
    // filling `ip`. This is what shipped at step 16.
    $mutated = $row;
    $mutated['ip'] = null;

    expect(fn () => assertWholeAuditRow($mutated, $expected))->toThrow(ExpectationFailedException::class);

    // And the shape that let it ship: the step-16 assertion named five
    // fields and `ip` was not among them, so the mutated row passes it.
    expect($mutated['module'])->toBe(AuditWriter::MODULE)
        ->and($mutated['actor_type'])->toBe(AuditWriter::ACTOR_TYPE)
        ->and($mutated['actor_id'])->toBe($ids['actor'])
        ->and((string) $mutated['action'])->not->toBe('')
        ->and($mutated['subject_id'])->not->toBeNull();
});

it('records no address when there is no client, and the two cases differ (AC-70)', function () {
    $ids = auditWorld();

    app()->instance(ClientIp::class, ClientIp::none());

    /** @var CreateRoleHandler $handler */
    $handler = app(CreateRoleHandler::class);
    $handler->handle(new CreateRole('tidy', 'Tidy', null, $ids['actor']));

    expect(auditRows()[0]['ip'])->toBeNull();

    // The discriminating half: the identical operation under a client
    // stores the address, so "null" above is the absence of a client and
    // not the absence of the field.
    // Resolved again, because the writer took its `ClientIp` when the
    // handler above was built: the address is a collaborator, not a
    // global read, which is the property this re-resolution shows.
    app()->instance(ClientIp::class, ClientIp::fixed(AUDIT_FIXED_IP));

    /** @var CreateRoleHandler $second */
    $second = app(CreateRoleHandler::class);
    $second->handle(new CreateRole('tidier', 'Tidier', null, $ids['actor']));

    $rows = auditRows();

    expect(AccessLogWriter::unpackIp((string) $rows[1]['ip']))->toBe(AUDIT_FIXED_IP);
});

it('stores an IPv6 address and reads it back unchanged (AC-70)', function () {
    $ids = auditWorld();

    app()->instance(ClientIp::class, ClientIp::fixed('2001:db8::7'));

    /** @var CreateRoleHandler $handler */
    $handler = app(CreateRoleHandler::class);
    $handler->handle(new CreateRole('tidy', 'Tidy', null, $ids['actor']));

    expect(AccessLogWriter::unpackIp((string) auditRows()[0]['ip']))->toBe('2001:db8::7');
});
