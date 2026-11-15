<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\DeleteEmployee;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\CreateEmployee\CreateEmployee;
use Flexipos\Access\Features\CreateEmployee\CreateEmployeeHandler;
use Flexipos\Access\Features\DeleteEmployee\DeleteEmployee;
use Flexipos\Access\Features\DeleteEmployee\DeleteEmployeeHandler;
use Flexipos\Access\Features\ListEmployees\ListEmployees;
use Flexipos\Access\Features\ListEmployees\ListEmployeesHandler;
use Flexipos\Access\Support\EmployeeView;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 12 - soft-deleting an employee (AC-28, AC-52)
|--------------------------------------------------------------------------
|
| Four facts, and each one is asserted rather than inferred from the others:
| the row is still there with `deleted_at` set (soft deletion is the ONLY
| deletion), the version is bumped, the employee is gone from the list, and
| every resolver question about them is now `employee_unavailable`.
|
| A hard delete would satisfy "gone from the list" and "resolves nothing"
| just as well, which is why the surviving row is asserted first.
|
| The code reservation is asserted through the creator, because that is
| where the refusal lives.
|
| AC-37's last-owner safeguard refuses this operation for the only acting
| owner. It is step 14's and is deliberately not stubbed here; this file's
| employees hold no role, so no case here is one the safeguard would reach.
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

function deleteEmployee(string $employeeId): void
{
    /** @var DeleteEmployeeHandler $handler */
    $handler = app(DeleteEmployeeHandler::class);

    $handler->handle(new DeleteEmployee($employeeId));
}

it('soft-deletes, bumps, disappears from the list and resolves nothing (AC-28, AC-52)', function () {
    AccessSeed::permission('fixture.a');

    $employeeId = AccessSeed::employee('C001');
    $roleId = AccessSeed::role('shift_lead', ['fixture.a']);
    AccessSeed::assign($employeeId, $roleId);

    /** @var PermissionResolver $resolver */
    $resolver = app(PermissionResolver::class);

    expect($resolver->decide($employeeId, 'fixture.a', null)->allowed)->toBeTrue();

    $before = AccessSeed::permVersion($employeeId);

    deleteEmployee($employeeId);

    $row = AccessSeed::employeeRow($employeeId);

    /** @var ListEmployeesHandler $lister */
    $lister = app(ListEmployeesHandler::class);
    $codes = array_map(static fn (EmployeeView $view): string => $view->code, $lister->handle(new ListEmployees));

    $decision = $resolver->decide($employeeId, 'fixture.a', null);

    expect($row)->not->toBeNull()
        ->and($row['deleted_at'])->not->toBeNull()
        ->and((int) $row['perm_version'])->toBe($before + 1)
        ->and($codes)->not->toContain('C001')
        ->and($decision->allowed)->toBeFalse()
        ->and($decision->reason)->toBe(RefusalReason::EmployeeUnavailable);
});

it('keeps the code reserved after the delete (AC-28)', function () {
    /** @var CreateEmployeeHandler $creator */
    $creator = app(CreateEmployeeHandler::class);

    $view = $creator->handle(new CreateEmployee('C001', 'Nimal'));

    deleteEmployee($view->id);

    try {
        $creator->handle(new CreateEmployee('C001', 'Someone else'));

        expect(false)->toBeTrue('a soft-deleted employee must keep their code');
    } catch (AccessRefused $refused) {
        expect($refused->reason)->toBe(RefusalReason::Validation);
    }
});

it('refuses a second delete, and one for an employee that never existed (AC-28)', function () {
    $employeeId = AccessSeed::employee('C001');

    deleteEmployee($employeeId);

    foreach ([$employeeId => 'an employee already deleted', (string) Str::ulid() => 'an employee that never existed'] as $id => $why) {
        try {
            deleteEmployee((string) $id);

            expect(false)->toBeTrue("[{$why}] was accepted");
        } catch (AccessRefused $refused) {
            expect($refused->reason)->toBe(RefusalReason::Validation, $why);
        }
    }

    // The second refusal must not have bumped anybody on its way out.
    expect((int) AccessSeed::employeeRow($employeeId)['perm_version'])->toBe(2);
});
