<?php

use Flexipos\Auth\Tenancy\TenantSelectionPolicy;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-009 step 6: the single authority for `tenants.status`
|--------------------------------------------------------------------------
|
| AC-12's "the selectable set is read from one place and moving it moves the
| result", and the seam AC-40(b) and AC-37 will read.
|
| **What this file proves today, and what it cannot yet.** The three shipped
| dependents - the tenant list of AC-12, the selection refusal of AC-40(b) and
| the tenant-scoped refresh re-check of AC-37 - are written in plan steps 8, 10
| and 11. They do not exist while step 6 runs, so the complete moved-value
| proof (move the set, watch all three follow) cannot close here; it closes in
| each dependent's own step, and until then AC-47's residual (b) stays open.
| That is reported to the architect rather than papered over with a proof whose
| dependent is a fixture: a test-local consumer following a moved value says
| nothing about `LoginHandler`.
|
| What does close here are the three things the dependents will rest on:
|
| 1. the answers themselves, over the nine statuses the spec names, plus
|    deny-by-default for a status nobody shipped;
| 2. that the set moves only through a signature - a constant, a private
|    constructor, one named seam - and through no configuration channel,
|    asserted by type so that adding a ConfigRepository to this class goes red;
| 3. that the container is the hand-off: a policy bound as the one the process
|    has reaches a consumer built afterwards. **With its negative control**: a
|    second policy object constructed and left unused changes nothing, which is
|    what separates "the consumer read the bound policy" from "the test moved
|    something nobody reads".
|
| No database and no engine: a policy is a list and a predicate.
|
*/

uses(TestCase::class);

/**
 * A status no production code has ever heard of.
 *
 * Deliberately not one of the nine, so a dependent that happened to agree
 * cannot agree with this one by accident.
 */
function tenantSelectionScratchStatus(): string
{
    return 'held-for-proof';
}

/**
 * Bind a policy over a different selectable set as the only one the process
 * has, the way `policyMove()` does for the kernel's migratable list.
 *
 * `instance()` rather than a mutated singleton closure, so anything built
 * after this call receives this object.
 *
 * @param  list<string>  $statuses
 */
function tenantSelectionMove(array $statuses): TenantSelectionPolicy
{
    $policy = TenantSelectionPolicy::withSelectableStatuses($statuses);

    app()->instance(TenantSelectionPolicy::class, $policy);

    return $policy;
}

/*
|--------------------------------------------------------------------------
| The answers
|--------------------------------------------------------------------------
*/

it('permits exactly trial, active and past_due among the nine statuses the spec names', function (string $status, bool $selectable) {
    expect(TenantSelectionPolicy::shipped()->permits($status))->toBe($selectable);
})->with([
    // AC-12's own list, in the spec's order, each with the answer it asks for.
    ['trial', true],
    ['active', true],
    ['past_due', true],
    ['pending', false],
    ['provisioning', false],
    ['suspended', false],
    ['read_only', false],
    ['closed', false],
    ['archived', false],
]);

it('denies a status nobody shipped, however near it looks', function () {
    $policy = TenantSelectionPolicy::shipped();

    // Deny by default is the safe direction for an access decision: a value a
    // later migration adds, a case variant, a padded copy and the empty string
    // are all refusals rather than accidental grants.
    expect($policy->permits(tenantSelectionScratchStatus()))->toBeFalse()
        ->and($policy->permits('ACTIVE'))->toBeFalse()
        ->and($policy->permits(' active'))->toBeFalse()
        ->and($policy->permits(''))->toBeFalse()
        // And the subject is live: the same object does grant the shipped one,
        // so the four refusals above are not a policy that refuses everything.
        ->and($policy->permits('active'))->toBeTrue();
});

it('quotes its own set, so a refusal can name the authority instead of restating it', function () {
    expect(TenantSelectionPolicy::shipped()->selectable())->toBe(TenantSelectionPolicy::SELECTABLE)
        ->and(TenantSelectionPolicy::SELECTABLE)->toBe(['trial', 'active', 'past_due']);
});

/*
|--------------------------------------------------------------------------
| The value moves, and it moves through a signature
|--------------------------------------------------------------------------
*/

it('answers from the set it was built over and not from the constant', function () {
    // The shipped control first: without it, the moved policy's refusal of
    // `active` below would also be what a policy that refuses everything does.
    expect(TenantSelectionPolicy::shipped()->permits('active'))->toBeTrue()
        ->and(TenantSelectionPolicy::shipped()->permits('closed'))->toBeFalse();

    $moved = TenantSelectionPolicy::withSelectableStatuses(['closed', tenantSelectionScratchStatus()]);

    expect($moved->permits('closed'))->toBeTrue()
        ->and($moved->permits(tenantSelectionScratchStatus()))->toBeTrue()
        // The three shipped statuses are refused by the moved policy: a
        // `permits()` that consulted the constant instead of the property
        // would still grant these, which is this assertion's whole purpose.
        ->and($moved->permits('active'))->toBeFalse()
        ->and($moved->permits('trial'))->toBeFalse()
        ->and($moved->permits('past_due'))->toBeFalse()
        ->and($moved->selectable())->toBe(['closed', tenantSelectionScratchStatus()])
        // Moving one object does not move the authority for everyone else.
        ->and(TenantSelectionPolicy::shipped()->permits('closed'))->toBeFalse();
});

it('holds the selectable set in one property and builds only through its two named makers', function () {
    $class = new ReflectionClass(TenantSelectionPolicy::class);

    // One property: there is no second list a serving-style answer could be
    // read from, and nothing to keep in step with anything.
    expect(array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        $class->getProperties()
    ))->toBe(['selectableStatuses'])
        // A private constructor, so `new TenantSelectionPolicy([...])` from a
        // handler is not a thing that compiles: the two makers are the whole
        // surface, and one of them is marked internal to tests.
        ->and($class->getConstructor()?->isPrivate())->toBeTrue()
        ->and($class->isFinal())->toBeTrue();
});

it('accepts no object through which configuration could reach it', function () {
    // The guard-rail that pays for injectability, by type rather than by name
    // (the shape TenantStatusPolicyTest established): the day somebody adds a
    // ConfigRepository, a Container or an Application to this class, this goes
    // red and "no operator can widen an access decision" is void.
    $class = new ReflectionClass(TenantSelectionPolicy::class);

    $methods = $class->getMethods(ReflectionMethod::IS_PUBLIC);
    $methods[] = $class->getConstructor();

    $types = [];

    foreach (array_filter($methods) as $method) {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            $types[] = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;
        }
    }

    // The scan asserts its own subject is non-empty: a class with no parameters
    // at all would pass this by having nothing to look at.
    expect($types)->not->toBe([]);

    foreach ($types as $type) {
        expect(class_exists($type))->toBeFalse(
            'TenantSelectionPolicy accepts ['.$type.'], which can carry configuration into the policy.'
        );
    }
});

/*
|--------------------------------------------------------------------------
| The container is the hand-off, and the unused policy is the control
|--------------------------------------------------------------------------
|
| The consumer below stands in for the three dependents until they exist. It
| proves the *binding*, which is the half of the moved-value proof that belongs
| to step 6: a policy bound here reaches something built afterwards through its
| constructor. It does not prove anything about LoginHandler, SelectTenant or
| RefreshToken, and the header says so.
|
*/

it('hands the shipped policy to anything the container builds', function () {
    expect(app(TenantSelectionPolicy::class)->selectable())->toBe(TenantSelectionPolicy::SELECTABLE)
        ->and(app(TenantSelectionPolicyConsumer::class)->maySelect('active'))->toBeTrue()
        ->and(app(TenantSelectionPolicyConsumer::class)->maySelect('closed'))->toBeFalse();
});

it('reaches a consumer built after the move, and ignores a policy nobody was given', function () {
    // Before: the consumer answers from the shipped set.
    expect(app(TenantSelectionPolicyConsumer::class)->maySelect('closed'))->toBeFalse();

    tenantSelectionMove(['closed']);

    $afterMove = app(TenantSelectionPolicyConsumer::class);

    expect($afterMove->maySelect('closed'))->toBeTrue()
        ->and($afterMove->maySelect('active'))->toBeFalse();

    // The negative control, and it is the point of this test. A second policy
    // object is constructed over a third set and handed to nobody. If the
    // consumer's answer changed here, the test above would be measuring the
    // act of constructing a policy rather than the act of reading the one the
    // process was given - and a dependent holding a private copy would sail
    // through both.
    $unused = TenantSelectionPolicy::withSelectableStatuses(['active', tenantSelectionScratchStatus()]);

    expect($unused->permits('active'))->toBeTrue()
        ->and(app(TenantSelectionPolicyConsumer::class)->maySelect('active'))->toBeFalse()
        ->and(app(TenantSelectionPolicyConsumer::class)->maySelect(tenantSelectionScratchStatus()))->toBeFalse()
        ->and(app(TenantSelectionPolicyConsumer::class)->maySelect('closed'))->toBeTrue();
});

/**
 * A consumer in the shape every dependent will have: it takes the policy in
 * its constructor and asks it, and it holds no list of its own.
 *
 * Test-only, and it stands in for nothing it will be cited as proof of - the
 * dependents of steps 8, 10 and 11 are proved in their own files.
 */
final class TenantSelectionPolicyConsumer
{
    public function __construct(
        private readonly TenantSelectionPolicy $policy,
    ) {}

    public function maySelect(string $status): bool
    {
        return $this->policy->permits($status);
    }
}
