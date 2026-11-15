<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\ListEmployees;

use Flexipos\Access\Features\ListEmployees\ListEmployees;
use Flexipos\Access\Features\ListEmployees\ListEmployeesHandler;
use Flexipos\Access\Support\AssignedRole;
use Flexipos\Access\Support\EmployeeView;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use ReflectionClass;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 12 - listing employees (AC-29)
|--------------------------------------------------------------------------
|
| Three things, and the third is the one that would otherwise be left to a
| reviewer's eye:
|
| - the order is by `code`, proved against a fixture seeded in the WRONG
|   order, so a handler with no `order by` fails;
| - the page size is CLAMPED, not refused, and the clamp is read from
|   configuration. That is proved by moving the configured figures and
|   watching the answers follow - a handler holding the literals 50 and 100
|   passes every fixed-number assertion and fails this one;
| - `pin_hash`, `pin_failed_attempts` and `perm_version` never reach a
|   reader. That is asserted over the VIEW'S OWN property list, by
|   reflection, rather than over the three names: a fourth secret added to
|   the employees table and rendered by accident would be invisible to a
|   test that only looked for the three (D-27, shape 7).
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
 * @return list<EmployeeView>
 */
function listEmployees(?int $pageSize = null): array
{
    /** @var ListEmployeesHandler $handler */
    $handler = app(ListEmployeesHandler::class);

    return $handler->handle(new ListEmployees($pageSize));
}

it('orders by code, excludes the deleted, and shows each role with its scope (AC-29)', function () {
    AccessSeed::permission('fixture.a');

    $branchId = AccessSeed::branch();
    $roleOne = AccessSeed::role('shift_lead', ['fixture.a']);
    $roleTwo = AccessSeed::role('cashier', ['fixture.a']);

    // Seeded in an order that is not the expected order.
    $zebra = AccessSeed::employee('Z001', ['pin_hash' => 'argon-hash-goes-here']);
    AccessSeed::employee('M001', ['deleted_at' => now()->format('Y-m-d H:i:s.v')]);
    $apple = AccessSeed::employee('A001');

    AccessSeed::assign($zebra, $roleTwo, $branchId);
    AccessSeed::assign($zebra, $roleOne);

    $views = listEmployees();

    expect(array_map(static fn (EmployeeView $view): string => $view->code, $views))->toBe(['A001', 'Z001']);

    $byCode = [];

    foreach ($views as $view) {
        $byCode[$view->code] = $view;
    }

    expect($byCode['A001']->hasPin)->toBeFalse()
        ->and($byCode['A001']->roles)->toBe([])
        ->and($byCode['A001']->id)->toBe($apple)
        ->and($byCode['Z001']->hasPin)->toBeTrue()
        ->and(array_map(static fn (AssignedRole $role): string => $role->key, $byCode['Z001']->roles))
        ->toBe(['cashier', 'shift_lead'])
        ->and($byCode['Z001']->roles[0]->branchId)->toBe($branchId)
        ->and($byCode['Z001']->roles[1]->branchId)->toBeNull();
});

it('clamps a page size above the maximum instead of refusing it, and reads both figures from configuration (AC-29)', function () {
    foreach (range(1, 5) as $index) {
        AccessSeed::employee('C00'.$index);
    }

    config()->set('access.employees.page_size', 2);
    config()->set('access.employees.max_page_size', 3);

    expect(listEmployees())->toHaveCount(2)
        ->and(listEmployees(3))->toHaveCount(3)
        // Clamped, not refused: the request for four returns the maximum.
        ->and(listEmployees(4))->toHaveCount(3)
        ->and(listEmployees(400))->toHaveCount(3)
        // A nonsensical size falls back to the configured default rather
        // than returning nothing.
        ->and(listEmployees(0))->toHaveCount(2)
        ->and(listEmployees(-1))->toHaveCount(2);

    // Moved again, so that the counts above cannot be the literals 2 and 3
    // written into the handler.
    config()->set('access.employees.page_size', 4);
    config()->set('access.employees.max_page_size', 5);

    expect(listEmployees())->toHaveCount(4)
        ->and(listEmployees(99))->toHaveCount(5);
});

it('renders no secret, and the list of what it renders is the view own (AC-29, AC-65)', function () {
    AccessSeed::employee('C001', ['pin_hash' => 'argon-hash', 'pin_failed_attempts' => 3]);

    $rendered = array_map(
        static fn (\ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(EmployeeView::class))->getProperties()
    );

    sort($rendered);

    fwrite(STDOUT, PHP_EOL.'AC-29 fields a reader sees: '.implode(', ', $rendered).PHP_EOL);

    expect($rendered)->toBe(['code', 'displayName', 'firstName', 'hasPin', 'id', 'lastName', 'roles', 'status'])
        ->and($rendered)->not->toBeEmpty();

    // And the same thing said as a serialised blob, because a property list
    // says nothing about what a renderer might add on the way out.
    $encoded = (string) json_encode(listEmployees());

    expect($encoded)->not->toContain('argon-hash')
        ->and($encoded)->not->toContain('pin_hash')
        ->and($encoded)->not->toContain('pin_failed_attempts')
        ->and($encoded)->not->toContain('perm_version')
        ->and($encoded)->toContain('C001');
});
