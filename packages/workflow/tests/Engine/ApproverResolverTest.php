<?php

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RoleHolders;
use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;
use Flexipos\Workflow\Definitions\ApproverType;
use Flexipos\Workflow\Engine\ApproverResolver;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/../Support/workflow-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    workflowEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

function wfResolver(): ApproverResolver
{
    return new ApproverResolver(app(RoleHolders::class));
}

it('resolves role, employee, any_of and all_of as Ruling 6b says', function () {
    $role = AccessSeed::role('wf_resolve_role');
    $e1 = AccessSeed::employee('wf-r1');
    $e2 = AccessSeed::employee('wf-r2');
    AccessSeed::assign($e1, $role);

    $byRole = wfResolver()->resolve(ApproverType::Role, ['roles' => [$role]], null, 'nobody');
    expect(array_column($byRole, 'employeeId'))->toBe([$e1]);

    $byEmployee = wfResolver()->resolve(ApproverType::Employee, ['employees' => [$e2]], null, 'nobody');
    expect(array_column($byEmployee, 'employeeId'))->toBe([$e2]);

    $anyOf = wfResolver()->resolve(ApproverType::AnyOf, ['roles' => [$role], 'employees' => [$e2]], null, 'nobody');
    expect(array_column($anyOf, 'employeeId'))->toEqualCanonicalizing([$e1, $e2]);

    $allOf = wfResolver()->resolve(ApproverType::AllOf, ['roles' => [$role], 'employees' => [$e2]], null, 'nobody');
    expect(array_column($allOf, 'employeeId'))->toEqualCanonicalizing([$e1, $e2]);
});

it('excludes the requester from the resolved set', function () {
    $role = AccessSeed::role('wf_self_role');
    $requester = AccessSeed::employee('wf-requester');
    $other = AccessSeed::employee('wf-other');
    AccessSeed::assign($requester, $role);
    AccessSeed::assign($other, $role);

    $resolved = wfResolver()->resolve(ApproverType::Role, ['roles' => [$role]], null, $requester);

    expect(array_column($resolved, 'employeeId'))->toBe([$other]);
});

it('gives an empty set for a role nobody holds', function () {
    $role = AccessSeed::role('wf_empty_role');

    expect(wfResolver()->resolve(ApproverType::Role, ['roles' => [$role]], null, 'nobody'))->toBe([]);
});

it('throws approverTypeUnavailable for a hand-inserted unresolvable type', function () {
    try {
        wfResolver()->resolve(ApproverType::BranchManager, [], null, 'nobody');
        $this->fail('Expected WorkflowEvaluationFailed.');
    } catch (WorkflowEvaluationFailed $e) {
        expect($e->cause)->toBe('approverTypeUnavailable');
    }
});
