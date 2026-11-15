<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\RenameRole;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\RenameRole\RenameRole;
use Flexipos\Access\Features\RenameRole\RenameRoleHandler;
use Flexipos\Access\Support\SystemRole;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 11 - renaming a role (AC-21, AC-52's "not bumped" list)
|--------------------------------------------------------------------------
|
| Two facts that look like one. A rename changes what a role is CALLED and
| nothing about what it CONFERS, so it must not bump a holder's
| perm_version: a bump is a signal to every live token that its claims are
| stale, and spending it on a cosmetic change would retire tokens for
| nothing. The holder in this file exists only to make that assertion
| possible.
|
| The system-role refusal is asserted against `is_system` on the row, not
| against the key `owner`: a shop that named its own role `owner` must not
| thereby make it immune, and a shop that renames the real one must not be
| able to escape the guard by doing so.
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

function renameRole(RenameRole $command): void
{
    /** @var RenameRoleHandler $handler */
    $handler = app(RenameRoleHandler::class);

    $handler->handle($command);
}

it('renames a custom role and bumps nobody (AC-21 control, AC-52)', function () {
    $roleId = AccessSeed::role('shift_lead');
    $employeeId = AccessSeed::employee('holder');
    AccessSeed::assign($employeeId, $roleId);

    $before = AccessSeed::permVersion($employeeId);

    renameRole(new RenameRole($roleId, 'Floor supervisor', 'Runs the floor.'));

    $row = AccessSeed::connection()->table('roles')->where('id', $roleId)->first();

    expect($row->name)->toBe('Floor supervisor')
        ->and($row->description)->toBe('Runs the floor.')
        ->and($row->key)->toBe('shift_lead')
        ->and(AccessSeed::permVersion($employeeId))->toBe($before);
});

it('refuses to rename a system role and leaves it exactly as it was (AC-21)', function () {
    $roleId = AccessSeed::role(SystemRole::OWNER_KEY, [], ['is_system' => true, 'name' => SystemRole::OWNER_NAME]);

    try {
        renameRole(new RenameRole($roleId, 'Overlord'));

        expect(false)->toBeTrue('a system role must not be renameable');
    } catch (AccessRefused $refused) {
        expect($refused->reason)->toBe(RefusalReason::SystemRole);
    }

    expect(AccessSeed::connection()->table('roles')->where('id', $roleId)->value('name'))
        ->toBe(SystemRole::OWNER_NAME);
});

it('refuses a blank name, and a role that does not exist, with validation (AC-19)', function () {
    $roleId = AccessSeed::role('shift_lead');

    foreach ([
        [new RenameRole($roleId, '   '), 'a blank name'],
        [new RenameRole((string) Str::ulid(), 'Anything'), 'a role that does not exist'],
    ] as [$command, $why]) {
        try {
            renameRole($command);

            expect(false)->toBeTrue("[{$why}] was accepted");
        } catch (AccessRefused $refused) {
            expect($refused->reason)->toBe(RefusalReason::Validation, $why);
        }
    }

    expect(AccessSeed::connection()->table('roles')->where('id', $roleId)->value('name'))->toBe('shift_lead');
});

it('refuses a soft-deleted role, which is not the same refusal as a system role (AC-22)', function () {
    $roleId = AccessSeed::role('gone', [], ['deleted_at' => now()->format('Y-m-d H:i:s.v')]);

    try {
        renameRole(new RenameRole($roleId, 'Back again'));

        expect(false)->toBeTrue('a soft-deleted role must not be renameable');
    } catch (AccessRefused $refused) {
        expect($refused->reason)->toBe(RefusalReason::Validation);
    }
});
