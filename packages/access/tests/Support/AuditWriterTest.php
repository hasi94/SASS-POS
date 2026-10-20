<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Features\AssignRole\AssignRole;
use Flexipos\Access\Features\AssignRole\AssignRoleHandler;
use Flexipos\Access\Features\AttachBranch\AttachBranch;
use Flexipos\Access\Features\AttachBranch\AttachBranchHandler;
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
use Flexipos\Access\Features\SetRolePermissions\SetRolePermissions;
use Flexipos\Access\Features\SetRolePermissions\SetRolePermissionsHandler;
use Flexipos\Access\Features\UpdateEmployee\UpdateEmployee;
use Flexipos\Access\Features\UpdateEmployee\UpdateEmployeeHandler;
use Flexipos\Access\Support\AuditWriter;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Contracts\Access\RefusalReason;
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

        expect($row['module'])->toBe(AuditWriter::MODULE)
            ->and($row['actor_type'])->toBe(AuditWriter::ACTOR_TYPE)
            ->and($row['actor_id'])->toBe($ids['actor'])
            ->and((string) $row['action'])->not->toBe('')
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
