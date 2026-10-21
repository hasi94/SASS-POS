<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Features\DeleteEmployee\DeleteEmployee;
use Flexipos\Access\Features\DeleteEmployee\DeleteEmployeeHandler;
use Flexipos\Access\Features\SetRolePermissions\SetRolePermissions;
use Flexipos\Access\Features\SetRolePermissions\SetRolePermissionsHandler;
use Flexipos\Contracts\Access\PermissionResolver;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 QA - the two operations AccessBumpSweepTest cannot see (AC-52, AC-53)
|--------------------------------------------------------------------------
|
| AC-53's first mutation control: "deleting the bump from one operation turns
| the sweep red naming that operation". QA ran that mutation on every bump in
| the package. Seven turned the sweep red. Two did not:
|
| - `DeleteEmployee`: the sweep deletes an employee it has just CREATED, who
|   holds nothing, so no cell moves and no bump is owed;
| - `SetRolePermissions`: the sweep sets the permissions of a role it has just
|   CREATED, which nobody holds, so the fan-out has nobody to reach.
|
| Both are caught by their own slice tests, so AC-52 is proven; what is not
| proven is the sweep's claim to be the net under every operation. This file
| runs the same property - "an answer moved, so the version moved" - on the
| two operations with employees who actually hold something, including the
| holder-at-a-branch that "every employee holding that role at any scope"
| names, and a non-holder who must NOT be touched (the pair that makes the
| fan-out attributable to the role rather than to the operation having run).
|
| Mutants that must turn this file red, from a byte-identical green control:
| the bump removed from `DeleteEmployeeHandler`; the fan-out replaced by a
| constant in `SetRolePermissionsHandler`; the fan-out narrowed to null-scope
| holders.
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
 * The resolver's answers for the given employees, keys and scopes.
 *
 * @param  list<string>  $employees
 * @param  list<string>  $keys
 * @param  list<?string>  $scopes
 * @return array<string, bool>
 */
function qaBumpGapCells(array $employees, array $keys, array $scopes): array
{
    /** @var PermissionResolver $resolver */
    $resolver = app(PermissionResolver::class);

    $cells = [];

    foreach ($employees as $employee) {
        foreach ($keys as $key) {
            foreach ($scopes as $scope) {
                $cells[substr($employee, -6).'/'.$key.'@'.($scope ?? '*')] = $resolver->decide($employee, $key, $scope)->allowed;
            }
        }
    }

    return $cells;
}

it('bumps the version of an employee who is deleted while holding rights (AC-52, AC-28)', function () {
    $key = AccessSeed::permission('fixture.company');
    $actor = AccessSeed::actorHolding('actor', [$key]);
    $subject = AccessSeed::employee('subject');
    $bystander = AccessSeed::employee('bystander');

    AccessSeed::assign($subject, AccessSeed::role('holder', [$key]));
    AccessSeed::assign($bystander, AccessSeed::role('holder_two', [$key]));

    $before = qaBumpGapCells([$subject, $bystander], [$key], [null]);
    $versionBefore = AccessSeed::permVersion($subject);
    $bystanderBefore = AccessSeed::permVersion($bystander);

    // Liveness: both hold the key, so a flip is a flip of something.
    expect($before)->each->toBeTrue();

    app(DeleteEmployeeHandler::class)->handle(new DeleteEmployee($subject, $actor));

    $after = qaBumpGapCells([$subject, $bystander], [$key], [null]);

    // The answers moved for the deleted employee and for nobody else...
    expect($after[substr($subject, -6).'/'.$key.'@*'])->toBeFalse()
        ->and($after[substr($bystander, -6).'/'.$key.'@*'])->toBeTrue()
        // ...so the version of exactly that employee must have moved.
        ->and(AccessSeed::permVersion($subject))->toBeGreaterThan($versionBefore)
        ->and(AccessSeed::permVersion($bystander))->toBe($bystanderBefore);
});

it('bumps every holder of a role at any scope, and no one else, when its permissions change (AC-20, AC-52)', function () {
    $first = AccessSeed::permission('fixture.first');
    $second = AccessSeed::permission('fixture.second');
    $scoped = AccessSeed::permission('fixture.scoped', true);
    $branch = AccessSeed::branch();

    $actor = AccessSeed::actorHolding('actor', [$first, $second, $scoped]);

    $role = AccessSeed::role('shift_lead', [$first]);

    $atNull = AccessSeed::employee('at-null');
    $atBranch = AccessSeed::employee('at-branch');
    $nonHolder = AccessSeed::employee('non-holder');

    AccessSeed::attach($atBranch, $branch);
    AccessSeed::assign($atNull, $role);
    AccessSeed::assign($atBranch, $role, $branch);

    $employees = [$atNull, $atBranch, $nonHolder];
    $keys = [$first, $second, $scoped];
    $scopes = [null, $branch];

    $cellsBefore = qaBumpGapCells($employees, $keys, $scopes);
    $versions = array_map(static fn (string $id): int => AccessSeed::permVersion($id), array_combine($employees, $employees));

    // Replace {first} with {second, scoped}: the holder at the null scope
    // loses `first` and gains the other two, the holder at the branch loses
    // `first` (company-wide, so it never applied there) and gains `scoped`
    // at that branch only.
    $result = app(SetRolePermissionsHandler::class)
        ->handle(new SetRolePermissions($role, [$second, $scoped], $actor));

    $cellsAfter = qaBumpGapCells($employees, $keys, $scopes);

    $moved = [];

    foreach ($cellsBefore as $cell => $was) {
        if ($cellsAfter[$cell] !== $was) {
            $moved[] = $cell;
        }
    }

    // The property under test is only meaningful if answers really moved for
    // both holders; asserted, not assumed.
    $movedEmployees = array_unique(array_map(static fn (string $cell): string => explode('/', $cell)[0], $moved));

    expect($moved)->not->toBe([])
        ->and($movedEmployees)->toContain(substr($atNull, -6))
        ->and($movedEmployees)->toContain(substr($atBranch, -6))
        ->and($movedEmployees)->not->toContain(substr($nonHolder, -6));

    // Every employee whose answers moved was bumped, at either scope...
    expect(AccessSeed::permVersion($atNull))->toBeGreaterThan($versions[$atNull])
        ->and(AccessSeed::permVersion($atBranch))->toBeGreaterThan($versions[$atBranch])
        // ...the report agrees with the rows...
        ->and($result->bumped)->toBe(2)
        // ...and the non-holder, who holds nothing through this role, was not.
        ->and(AccessSeed::permVersion($nonHolder))->toBe($versions[$nonHolder]);
});
