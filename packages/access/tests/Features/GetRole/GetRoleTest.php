<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\GetRole;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\GetRole\GetRole;
use Flexipos\Access\Features\GetRole\GetRoleHandler;
use Flexipos\Access\Support\RoleView;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 11 - reading one role (AC-23)
|--------------------------------------------------------------------------
|
| The detail query returns the same five facts the list does, which is why
| both produce the same RoleView: two shapes for one answer is how a field
| comes to be present in one and missing from the other.
|
| A soft-deleted role is refused rather than returned with a flag. AC-23
| says the queries exclude soft-deleted roles, and "excluded" for a detail
| query is a refusal.
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

function getRole(string $roleId): RoleView
{
    /** @var GetRoleHandler $handler */
    $handler = app(GetRoleHandler::class);

    return $handler->handle(new GetRole($roleId));
}

it('returns the role with its permission keys in key order (AC-23)', function () {
    AccessSeed::permission('fixture.z');
    AccessSeed::permission('fixture.a');

    $roleId = AccessSeed::role('shift_lead', ['fixture.z', 'fixture.a'], [
        'name' => 'Shift lead',
        'description' => 'Runs a shift.',
    ]);

    $view = getRole($roleId);

    expect($view->id)->toBe($roleId)
        ->and($view->key)->toBe('shift_lead')
        ->and($view->name)->toBe('Shift lead')
        ->and($view->description)->toBe('Runs a shift.')
        ->and($view->isSystem)->toBeFalse()
        ->and($view->permissionKeys)->toBe(['fixture.a', 'fixture.z']);
});

it('refuses a role that is soft-deleted or that never existed (AC-23)', function () {
    $deletedId = AccessSeed::role('gone', [], ['deleted_at' => now()->format('Y-m-d H:i:s.v')]);

    foreach ([$deletedId => 'a soft-deleted role', (string) Str::ulid() => 'a role that never existed'] as $roleId => $why) {
        try {
            getRole((string) $roleId);

            expect(false)->toBeTrue("[{$why}] was returned");
        } catch (AccessRefused $refused) {
            expect($refused->reason)->toBe(RefusalReason::Validation, $why);
        }
    }
});
