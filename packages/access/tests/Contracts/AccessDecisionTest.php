<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Contracts;

use Flexipos\Contracts\Access\AccessDecision;
use Flexipos\Contracts\Access\RefusalReason;
use InvalidArgumentException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 1, D-26 - a refusal is never constructible without a cause
|--------------------------------------------------------------------------
|
| The property under test is structural: there is no way to build this value
| with allowed = false and no reason, because the constructor is private and
| the two named constructors are the only entrances. That is what lets every
| later criterion assert the *cause* rather than "something refused" - the
| sibling-guard mask the plan's section 6.3 calls the big one for this task.
|
| The detail rule is asserted in both directions, which is the point: a detail
| outside the closed three is refused, and a detail beside a reason other than
| not_permitted is refused as well. Without the second half, the three finer
| causes could be attached to `branch_required` and the log would record a
| cause the resolver cannot have meant.
|
*/

uses(TestCase::class);

it('allows with no reason and no detail', function () {
    $decision = AccessDecision::allow();

    expect($decision->allowed)->toBeTrue()
        ->and($decision->reason)->toBeNull()
        ->and($decision->detail)->toBeNull();
});

it('carries the caller-facing reason and the finer cause separately', function () {
    $decision = AccessDecision::refuse(RefusalReason::NotPermitted, AccessDecision::DETAIL_EXPLICIT_DENY);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reason)->toBe(RefusalReason::NotPermitted)
        ->and($decision->detail)->toBe('explicit_deny');
});

it('refuses a detail that is not one of the closed three', function () {
    expect(static fn () => AccessDecision::refuse(RefusalReason::NotPermitted, 'because_i_said_so'))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a detail beside a reason that is not not_permitted', function () {
    expect(static fn () => AccessDecision::refuse(RefusalReason::BranchRequired, AccessDecision::DETAIL_OUT_OF_SCOPE))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts every one of the closed three beside not_permitted', function (string $detail) {
    expect(AccessDecision::refuse(RefusalReason::NotPermitted, $detail)->detail)->toBe($detail);
})->with(array_map(
    static fn (string $detail): array => [$detail],
    AccessDecision::DETAILS
));

it('refuses with every reason, detail omitted', function (string $value) {
    $decision = AccessDecision::refuse(RefusalReason::from($value));

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reason?->value)->toBe($value)
        ->and($decision->detail)->toBeNull();
})->with(array_map(
    static fn (RefusalReason $reason): array => [$reason->value],
    RefusalReason::cases()
));
