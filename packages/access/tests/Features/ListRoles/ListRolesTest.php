<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\ListRoles;

use Flexipos\Access\Features\ListRoles\ListRoles;
use Flexipos\Access\Features\ListRoles\ListRolesHandler;
use Flexipos\Access\Support\RoleView;
use Flexipos\Access\Support\SystemRole;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 11 - listing roles (AC-23)
|--------------------------------------------------------------------------
|
| The fixture is seeded in an order that is NOT the expected order, so "the
| list is ordered by key" is a fact about the query rather than about the
| order rows happened to be inserted in. A fixture seeded alphabetically
| would pass against a handler with no `order by` at all.
|
| `roles.view` is not checked here and must not be: the permission a route
| requires is the route's declaration, carried by the middleware, and
| AC-63's sweep over the route table is where it is proved. A handler that
| re-checked it would be a second authority for one fact (FP-184).
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
 * @return list<RoleView>
 */
function listRoles(): array
{
    /** @var ListRolesHandler $handler */
    $handler = app(ListRolesHandler::class);

    return $handler->handle(new ListRoles);
}

it('returns every live role by key, with its permission keys (AC-23)', function () {
    AccessSeed::permission('fixture.b');
    AccessSeed::permission('fixture.a');

    AccessSeed::role('zebra', ['fixture.b', 'fixture.a'], ['name' => 'Zebra', 'description' => 'Last.']);
    AccessSeed::role(SystemRole::OWNER_KEY, ['fixture.a'], ['is_system' => true, 'name' => SystemRole::OWNER_NAME]);
    AccessSeed::role('apple', [], ['name' => 'Apple']);
    AccessSeed::role('deleted_one', ['fixture.a'], ['deleted_at' => now()->format('Y-m-d H:i:s.v')]);

    $roles = listRoles();

    expect(array_map(static fn (RoleView $view): string => $view->key, $roles))
        ->toBe(['apple', 'owner', 'zebra']);

    $byKey = [];

    foreach ($roles as $role) {
        $byKey[$role->key] = $role;
    }

    expect($byKey['zebra']->name)->toBe('Zebra')
        ->and($byKey['zebra']->description)->toBe('Last.')
        ->and($byKey['zebra']->isSystem)->toBeFalse()
        ->and($byKey['zebra']->permissionKeys)->toBe(['fixture.a', 'fixture.b'])
        ->and($byKey['owner']->isSystem)->toBeTrue()
        ->and($byKey['apple']->permissionKeys)->toBe([])
        ->and($byKey['apple']->description)->toBeNull();
});

it('returns an empty list for a tenant with no roles, and does not fail on one (AC-23)', function () {
    expect(listRoles())->toBe([]);
});
