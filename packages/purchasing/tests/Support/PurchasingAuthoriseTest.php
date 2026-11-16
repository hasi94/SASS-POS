<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Support;

use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 6 - Authorise (AC-18, AC-85 (a) to (c), AC-86)
|--------------------------------------------------------------------------
|
| The real resolver over the real keys (registered by the real registry sync),
| so a scope or a reason is answered by the resolver and not by this test. AC-85
| (d), "the branch asked is the stored branch", is a property of each slice and
| is proved there: Authorise asks exactly the branch it is handed.
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    /** @var SyncPermissionRegistryHandler $sync */
    $sync = app(SyncPermissionRegistryHandler::class);
    $sync->handle(new SyncPermissionRegistry);
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function puResolver(): PermissionResolver
{
    /** @var PermissionResolver $resolver */
    $resolver = app(PermissionResolver::class);

    return $resolver;
}

/**
 * The reason a refusal carries, or null when the call was allowed.
 */
function puRefusalCause(?string $actor, string $key, ?string $branch): ?string
{
    try {
        Authorise::require(puResolver(), $actor, $key, $branch);
    } catch (PurchasingRefused $refused) {
        expect($refused->code())->toBe($refused->reason->value);

        return $refused->reason->value.'/'.($refused->cause instanceof RefusalReason ? $refused->cause->value : 'none');
    }

    return null;
}

it('accepts a grant scoped to branch A at A and refuses it at B with the resolver s reason as cause (AC-85 a)', function () {
    $branchA = AccessSeed::branch();
    $branchB = AccessSeed::branch();
    $employee = AccessSeed::actorHolding('buyer', [PurchasingPermissions::ORDERS_VIEW], $branchA);

    expect(puRefusalCause($employee, PurchasingPermissions::ORDERS_VIEW, $branchA))->toBeNull()
        ->and(puRefusalCause($employee, PurchasingPermissions::ORDERS_VIEW, $branchB))->toBe('not_authorised/not_permitted');
});

it('accepts a company-wide grant at any branch (AC-85 b)', function () {
    $branchA = AccessSeed::branch();
    $branchB = AccessSeed::branch();
    $employee = AccessSeed::actorHolding('director', [PurchasingPermissions::ORDERS_VIEW]);

    expect(puRefusalCause($employee, PurchasingPermissions::ORDERS_VIEW, $branchA))->toBeNull()
        ->and(puRefusalCause($employee, PurchasingPermissions::ORDERS_VIEW, $branchB))->toBeNull();
});

it('refuses a branch-scoped key asked with no branch as branch_required, even for a company-wide holder (AC-85 c)', function () {
    $employee = AccessSeed::actorHolding('director', [PurchasingPermissions::ORDERS_VIEW]);

    expect(puRefusalCause($employee, PurchasingPermissions::ORDERS_VIEW, null))->toBe('not_authorised/branch_required');
});

it('asks a supplier key with no branch and never refuses it branch_required (AC-18)', function () {
    $holder = AccessSeed::actorHolding('supplier_clerk', [PurchasingPermissions::SUPPLIERS_MANAGE]);
    $other = AccessSeed::actorHolding('no_supplier_rights', [PurchasingPermissions::ORDERS_VIEW]);

    expect(puRefusalCause($holder, PurchasingPermissions::SUPPLIERS_MANAGE, null))->toBeNull()
        ->and(puRefusalCause($other, PurchasingPermissions::SUPPLIERS_MANAGE, null))->toBe('not_authorised/not_permitted');
});

it('refuses an employee who cannot act with employee_unavailable, whatever they hold (AC-86)', function (string $who) {
    $branch = AccessSeed::branch();
    $roleKeys = [PurchasingPermissions::ORDERS_VIEW, PurchasingPermissions::SUPPLIERS_VIEW];
    $role = AccessSeed::role('everything', $roleKeys);

    $employee = match ($who) {
        'suspended' => AccessSeed::employee('e_susp', ['status' => EmployeeStatus::SUSPENDED]),
        'on leave' => AccessSeed::employee('e_leave', ['status' => EmployeeStatus::ON_LEAVE]),
        'terminated' => AccessSeed::employee('e_term', ['status' => EmployeeStatus::TERMINATED]),
        'deleted' => AccessSeed::employee('e_del', ['deleted_at' => now()->format('Y-m-d H:i:s.v')]),
        default => (string) Str::ulid(),
    };

    if ($who !== 'missing') {
        AccessSeed::assign($employee, $role);
    }

    expect(puRefusalCause($employee, PurchasingPermissions::ORDERS_VIEW, $branch))->toBe('not_authorised/employee_unavailable')
        ->and(puRefusalCause($employee, PurchasingPermissions::SUPPLIERS_VIEW, null))->toBe('not_authorised/employee_unavailable');
})->with(['suspended', 'on leave', 'terminated', 'deleted', 'missing']);

it('returns the folded actor id it checked, so a slice uses the value it asked about', function () {
    $employee = AccessSeed::actorHolding('director', [PurchasingPermissions::SUPPLIERS_VIEW]);

    expect(Authorise::require(puResolver(), strtolower($employee), PurchasingPermissions::SUPPLIERS_VIEW, null))->toBe($employee);
});

/**
 * The refusal a call throws; the test fails when it throws nothing.
 */
function puRefusalOf(callable $call): PurchasingRefused
{
    try {
        $call();
    } catch (PurchasingRefused $refused) {
        return $refused;
    }

    throw new \LogicException('The call was expected to refuse and did not.');
}

it('refuses a missing or malformed actor and a malformed branch as validation, before the resolver is asked', function (?string $actor, ?string $branch) {
    $refused = puRefusalOf(fn () => Authorise::require(puResolver(), $actor, PurchasingPermissions::ORDERS_VIEW, $branch));

    expect($refused->code())->toBe('validation')->and($refused->cause)->toBeNull();
})->with([
    'no actor' => [null, null],
    'a malformed actor' => ['not-a-ulid', null],
    'a malformed branch' => ['01HZZZZZZZZZZZZZZZZZZZZZZZ', 'branch'],
]);

it('accepts the first of several keys that allows, and refuses with the last key s real reason when none does', function () {
    $branch = AccessSeed::branch();
    $viewer = AccessSeed::actorHolding('viewer', [PurchasingPermissions::SUPPLIERS_VIEW]);
    $manager = AccessSeed::actorHolding('manager', [PurchasingPermissions::SUPPLIERS_MANAGE]);
    $nobody = AccessSeed::actorHolding('nobody', [PurchasingPermissions::ORDERS_VIEW], $branch);

    $keys = [PurchasingPermissions::SUPPLIERS_VIEW, PurchasingPermissions::SUPPLIERS_MANAGE];

    expect(Authorise::requireAny(puResolver(), $viewer, $keys, null))->toBe(['actor' => $viewer, 'key' => 'purchasing.suppliers.view'])
        ->and(Authorise::requireAny(puResolver(), $manager, $keys, null))->toBe(['actor' => $manager, 'key' => 'purchasing.suppliers.manage']);

    $refused = puRefusalOf(fn () => Authorise::requireAny(puResolver(), $nobody, $keys, null));

    expect($refused->code())->toBe('not_authorised')
        ->and($refused->cause)->toBe(RefusalReason::NotPermitted);
});

it('does not swallow a validation refusal while trying the next key', function () {
    $refused = puRefusalOf(fn () => Authorise::requireAny(puResolver(), null, [PurchasingPermissions::SUPPLIERS_VIEW, PurchasingPermissions::SUPPLIERS_MANAGE], null));

    expect($refused->code())->toBe('validation');
});
