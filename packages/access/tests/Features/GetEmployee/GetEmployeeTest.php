<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\GetEmployee;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\GetEmployee\GetEmployee;
use Flexipos\Access\Features\GetEmployee\GetEmployeeHandler;
use Flexipos\Access\Support\EmployeeView;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 12 - reading one employee (AC-29)
|--------------------------------------------------------------------------
|
| The detail query returns the same EmployeeView the list does, for the
| reason RoleView gives: two shapes for one answer is how a field comes to
| be rendered in one and not the other. `has_pin` is a boolean derived from
| `pin_hash`, which is the only thing about the PIN that ever leaves the
| database (AC-65).
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

function getEmployee(string $employeeId): EmployeeView
{
    /** @var GetEmployeeHandler $handler */
    $handler = app(GetEmployeeHandler::class);

    return $handler->handle(new GetEmployee($employeeId));
}

it('returns the employee with has_pin derived from the stored hash (AC-29)', function () {
    $withPin = AccessSeed::employee('C001', [
        'pin_hash' => 'argon-hash',
        'last_name' => 'Perera',
        'display_name' => 'Nimal P.',
    ]);
    $withoutPin = AccessSeed::employee('C002');

    $view = getEmployee($withPin);

    expect($view->id)->toBe($withPin)
        ->and($view->code)->toBe('C001')
        ->and($view->lastName)->toBe('Perera')
        ->and($view->displayName)->toBe('Nimal P.')
        ->and($view->status)->toBe('active')
        ->and($view->hasPin)->toBeTrue()
        ->and(getEmployee($withoutPin)->hasPin)->toBeFalse();
});

it('refuses a soft-deleted employee and one that never existed (AC-28, AC-29)', function () {
    $deletedId = AccessSeed::employee('C001', ['deleted_at' => now()->format('Y-m-d H:i:s.v')]);

    foreach ([$deletedId => 'a soft-deleted employee', (string) Str::ulid() => 'an employee that never existed'] as $id => $why) {
        try {
            getEmployee((string) $id);

            expect(false)->toBeTrue("[{$why}] was returned");
        } catch (AccessRefused $refused) {
            expect($refused->reason)->toBe(RefusalReason::Validation, $why);
        }
    }
});
