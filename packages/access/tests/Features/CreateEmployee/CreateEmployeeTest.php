<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\CreateEmployee;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\CreateEmployee\CreateEmployee;
use Flexipos\Access\Features\CreateEmployee\CreateEmployeeHandler;
use Flexipos\Access\Features\DeleteEmployee\DeleteEmployee;
use Flexipos\Access\Features\DeleteEmployee\DeleteEmployeeHandler;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Access\Support\EmployeeView;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 12 - creating an employee (AC-24, AC-25)
|--------------------------------------------------------------------------
|
| AC-25's two controls are in this file beside the refusals they control,
| because each of them is the case a careless implementation of the
| neighbouring refusal would also reject:
|
| - a code of exactly 20 characters is accepted, which is what makes "over
|   20 is refused" a boundary rather than a guess;
| - a null `user_id` is accepted, and a SECOND employee with a null
|   `user_id` is accepted too (AC-7). A unique index over a nullable column
|   treats NULLs as distinct, and that is the legitimate use of the shape
|   docs/database.md 3.3 otherwise warns about; a handler that "helpfully"
|   refused the second one would break the normal case - a shop full of
|   cashiers with PINs and no web login.
|
| `perm_version` is asserted at its stored initial value rather than at the
| literal 1: the column's default is the authority, and a test naming the
| number would be its second home.
|
| The audit row AC-24 asks for is step 16's.
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

function createEmployee(CreateEmployee $command): EmployeeView
{
    /** @var CreateEmployeeHandler $handler */
    $handler = app(CreateEmployeeHandler::class);

    return $handler->handle($command);
}

it('creates an employee with the defaults AC-24 names', function () {
    $actorId = AccessSeed::employee('actor');

    $view = createEmployee(new CreateEmployee('C001', ' Nimal ', ' Perera ', actorId: $actorId));

    $row = AccessSeed::employeeRow($view->id);

    expect($row)->not->toBeNull()
        ->and($row['code'])->toBe('C001')
        ->and($row['first_name'])->toBe('Nimal')
        ->and($row['last_name'])->toBe('Perera')
        // The trimmed first and last names joined by exactly one space.
        ->and($row['display_name'])->toBe('Nimal Perera')
        ->and($row['status'])->toBe(EmployeeStatus::ACTIVE)
        ->and($row['pin_hash'])->toBeNull()
        ->and($row['user_id'])->toBeNull()
        ->and($row['deleted_at'])->toBeNull()
        ->and($row['created_by'])->toBe($actorId)
        ->and($row['updated_by'])->toBe($actorId)
        ->and($view->hasPin)->toBeFalse()
        ->and($view->roles)->toBe([]);
});

it('falls back to the first name alone when there is no last name, and takes a display name as given (AC-24)', function () {
    $noLast = createEmployee(new CreateEmployee('C002', 'Nimal'));
    $explicit = createEmployee(new CreateEmployee('C003', 'Nimal', 'Perera', 'Nim'));

    expect($noLast->displayName)->toBe('Nimal')
        ->and($explicit->displayName)->toBe('Nim');
});

it('refuses every malformed request with validation and writes no row (AC-25)', function () {
    $taken = createEmployee(new CreateEmployee('TAKEN', 'Taken', userId: (string) Str::ulid()));
    $takenUserId = (string) AccessSeed::employeeRow($taken->id)['user_id'];

    $deleted = createEmployee(new CreateEmployee('DELETED', 'Deleted'));
    /** @var DeleteEmployeeHandler $deleter */
    $deleter = app(DeleteEmployeeHandler::class);
    $deleter->handle(new DeleteEmployee($deleted->id));

    $cases = [
        [new CreateEmployee('', 'Nimal'), 'a missing code'],
        [new CreateEmployee('   ', 'Nimal'), 'a blank code'],
        [new CreateEmployee('C010', ''), 'a missing first name'],
        [new CreateEmployee('C010', '  '), 'a blank first name'],
        [new CreateEmployee(str_repeat('C', 21), 'Nimal'), 'a code over 20 characters'],
        [new CreateEmployee('C 010', 'Nimal'), 'whitespace inside the code'],
        [new CreateEmployee("C\t010", 'Nimal'), 'a tab inside the code'],
        [new CreateEmployee('TAKEN', 'Nimal'), 'a code already used'],
        [new CreateEmployee('DELETED', 'Nimal'), 'a code used by a soft-deleted employee'],
        [new CreateEmployee('C010', 'Nimal', userId: 'not-a-ulid'), 'a user id that is not a ULID'],
        [new CreateEmployee('C010', 'Nimal', userId: $takenUserId), 'a user id another employee holds'],
        [new CreateEmployee('C010', 'Nimal', status: 'vacationing'), 'a status nobody declared'],
        [new CreateEmployee('C010', str_repeat('n', 101)), 'a first name over the column width'],
    ];

    $before = AccessSeed::connection()->table('employees')->count();

    foreach ($cases as [$command, $why]) {
        try {
            createEmployee($command);

            expect(false)->toBeTrue("[{$why}] was accepted");
        } catch (AccessRefused $refused) {
            expect($refused->reason)->toBe(RefusalReason::Validation, $why);
        }
    }

    fwrite(STDOUT, PHP_EOL.'AC-25 refusals examined: '.count($cases).PHP_EOL);

    expect($cases)->not->toBeEmpty()
        ->and(AccessSeed::connection()->table('employees')->count())->toBe($before);
});

it('accepts a 20-character code, two null user ids, and every declared status (AC-25 controls, AC-7)', function () {
    $code = str_repeat('C', 20);

    expect(createEmployee(new CreateEmployee($code, 'Nimal'))->code)->toBe($code);

    // Two employees with no web login: the normal case, and the control that
    // keeps the user_id refusal from being a blanket one.
    createEmployee(new CreateEmployee('N001', 'One'));
    createEmployee(new CreateEmployee('N002', 'Two'));

    expect(AccessSeed::connection()->table('employees')->whereNull('user_id')->count())->toBe(3);

    foreach (EmployeeStatus::STATUSES as $index => $status) {
        expect(createEmployee(new CreateEmployee('S'.$index, 'Status', status: $status))->status)->toBe($status);
    }
});
