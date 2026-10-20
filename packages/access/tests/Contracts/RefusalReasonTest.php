<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Contracts;

use Flexipos\Contracts\Access\AccessDecision;
use Flexipos\Contracts\Access\RefusalReason;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 1 - the published refusal vocabulary, and why this tree is
| namespaced
|--------------------------------------------------------------------------
|
| **This is the first genuinely Deptrac-collected test tree in the
| repository, and the namespace above is the whole reason.** Deptrac matches a
| layer by namespace, not by directory, so a registered `tests` path whose
| files all declare no namespace contributes no collectable token and reports
| zero violations because nothing in it was collected. The three trees that
| are in that state are recorded in qaGlobalNamespaceTestTrees() against
| FP-303, a list that is self-liquidating by design; this tree adds no fourth
| entry.
|
| One consequence a later reader should not have to rediscover: a Pest file
| declares no class, so Deptrac still collects nothing *from this file* - what
| the namespace buys is that any class declared in this tree (a Support
| helper, a fixture) lands inside Package_Access and is boundary-checked. The
| guard that would otherwise fail - QaToolchainRegistryCoverageTest, made
| honest by T-009's W-2 - is satisfied by the declaration itself.
|
| D-26's set is **closed** and it is asserted as a set, in both directions: a
| reason added without a decision fails here, and a reason removed fails here
| too. The three finer causes are asserted to be absent, because the whole
| disclosure decision is that they never reach a caller.
|
| No database, no application state: these are pure values.
|
*/

uses(TestCase::class);

it('publishes exactly the fifteen reasons of D-26, as a closed set', function () {
    $values = array_map(
        static fn (RefusalReason $reason): string => $reason->value,
        RefusalReason::cases()
    );

    sort($values);

    expect($values)->toBe([
        'actor_required',
        'branch_required',
        'employee_unavailable',
        'has_scoped_grants',
        'last_owner',
        'no_escalation',
        'not_attached',
        'not_permitted',
        'permissions_changed',
        'role_in_use',
        'scope_not_applicable',
        'self_change',
        'system_role',
        'unknown_permission',
        'validation',
    ])->and($values)->toHaveCount(15);
});

it('keeps the three finer causes out of the caller-facing vocabulary', function () {
    $reasons = array_map(
        static fn (RefusalReason $reason): string => $reason->value,
        RefusalReason::cases()
    );

    $leaked = array_values(array_intersect(AccessDecision::DETAILS, $reasons));

    expect($leaked)->toBe([])
        ->and(AccessDecision::DETAILS)->toBe(['not_granted', 'explicit_deny', 'out_of_scope']);
});
