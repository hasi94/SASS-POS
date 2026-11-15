<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\CreateRole;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\CreateRole\CreateRole;
use Flexipos\Access\Features\CreateRole\CreateRoleHandler;
use Flexipos\Access\Features\DeleteRole\DeleteRole;
use Flexipos\Access\Features\DeleteRole\DeleteRoleHandler;
use Flexipos\Access\Support\RoleKey;
use Flexipos\Access\Support\RoleView;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 11 - creating a role (AC-19)
|--------------------------------------------------------------------------
|
| The valid request is in the same file as every refusal, which is AC-19's
| own control: a handler that refused everything would pass a file holding
| only refusals.
|
| AC-19 also asks for one audit row. The audit writer is step 16 and does not
| exist yet; it is not stubbed here, because a stub that wrote nothing and a
| writer nobody called are the same green. Step 16 adds the row and its case.
|
| The key reservation across soft deletion is asserted here rather than in
| the deletion slice, because it is a property of CREATE: the refusal is this
| handler's, and a test of the deleter could only observe it by calling this
| one anyway.
|
| The malformed requests are a list this file prints rather than a Pest
| dataset, so the refusals can be counted and the list asserted non-empty -
| a loop that silently iterated nothing would otherwise report the same
| green as seven refusals.
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

function createRole(CreateRole $command): RoleView
{
    /** @var CreateRoleHandler $handler */
    $handler = app(CreateRoleHandler::class);

    return $handler->handle($command);
}

it('creates a custom role with no permissions (AC-19)', function () {
    $view = createRole(new CreateRole('shift_lead', 'Shift lead', 'Runs a shift.'));

    $row = AccessSeed::connection()->table('roles')->where('key', 'shift_lead')->first();

    expect($row)->not->toBeNull()
        ->and((bool) $row->is_system)->toBeFalse()
        ->and($row->deleted_at)->toBeNull()
        ->and($row->name)->toBe('Shift lead')
        ->and($row->description)->toBe('Runs a shift.')
        ->and($view->key)->toBe('shift_lead')
        ->and($view->isSystem)->toBeFalse()
        ->and($view->permissionKeys)->toBe([])
        ->and(AccessSeed::connection()->table('role_permissions')->where('role_id', $row->id)->count())->toBe(0);
});

it('refuses every malformed request with validation and writes nothing (AC-19)', function () {
    $cases = [
        ['', 'Shift lead', 'a missing key'],
        ['   ', 'Shift lead', 'a blank key'],
        ['shift_lead', '', 'a missing name'],
        ['shift_lead', '  ', 'a blank name'],
        ['Shift Lead', 'Shift lead', 'capitals and a space are not the role-key format'],
        ['shift.lead', 'Shift lead', 'a dot is a permission key, not a role key'],
        ['9lead', 'Shift lead', 'a key must start with a letter'],
        [str_repeat('a', RoleKey::MAX_LENGTH + 1), 'Shift lead', 'a key over the column width'],
        ['shift_lead', str_repeat('n', 101), 'a name over the column width'],
    ];

    $before = AccessSeed::connection()->table('roles')->count();

    foreach ($cases as [$key, $name, $why]) {
        try {
            createRole(new CreateRole($key, $name));

            expect(false)->toBeTrue("[{$why}] was accepted");
        } catch (AccessRefused $refused) {
            expect($refused->reason)->toBe(RefusalReason::Validation, $why);
        }
    }

    fwrite(STDOUT, PHP_EOL.'AC-19 refusals examined: '.count($cases).PHP_EOL);

    expect($cases)->not->toBeEmpty()
        ->and(AccessSeed::connection()->table('roles')->count())->toBe($before);
});

it('accepts a key at exactly the column width, which is the control for the width refusal (AC-19)', function () {
    $key = str_repeat('a', RoleKey::MAX_LENGTH);

    expect(createRole(new CreateRole($key, 'Long'))->key)->toBe($key);
});

it('refuses a key already used, including by a soft-deleted role (AC-19, AC-22)', function () {
    $view = createRole(new CreateRole('shift_lead', 'Shift lead'));

    expect(fn () => createRole(new CreateRole('shift_lead', 'Another')))
        ->toThrow(AccessRefused::class);

    /** @var DeleteRoleHandler $deleter */
    $deleter = app(DeleteRoleHandler::class);
    $deleter->handle(new DeleteRole($view->id));

    // The soft-deleted role is gone from the live set and the key is still
    // reserved: the pair is the criterion, because a key freed by deletion
    // would also make the first assertion pass.
    expect(AccessSeed::connection()->table('roles')->where('key', 'shift_lead')->whereNull('deleted_at')->count())->toBe(0);

    try {
        createRole(new CreateRole('shift_lead', 'Reused'));

        expect(false)->toBeTrue('a soft-deleted role must keep its key reserved');
    } catch (AccessRefused $refused) {
        expect($refused->reason)->toBe(RefusalReason::Validation);
    }

    expect(AccessSeed::connection()->table('roles')->where('key', 'shift_lead')->count())->toBe(1);
});
