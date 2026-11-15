<?php

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/*
|--------------------------------------------------------------------------
| File-scope helpers shared by this package's tests (T-020 plan section 3)
|--------------------------------------------------------------------------
|
| `require_once` rather than relying on another test file having been
| collected first (the FP-164 shape every other package fixture avoids the
| same way).
*/

/**
 * Enter the fixture tenant - `boot()` once per test in `beforeEach`, and
 * only `enter()` afterwards (the memory note every package here follows).
 */
function workflowEnterTenant(string $tenant = 'tenant-a'): void
{
    AccessTenantFixture::enter($tenant);
}

/**
 * A fixed clock, bound over the container so every write in a test is
 * stamped with a known instant.
 */
function workflowClockAt(string $at): RunnerClock
{
    $clock = RunnerClock::fixedAt($at);

    app()->instance(RunnerClock::class, $clock);

    return $clock;
}

/**
 * Seed a two-step approver chain: a requester R, role one held by E1 and
 * E2, role two held by E3 and E4.
 *
 * @return array{requester: string, roleOne: string, roleTwo: string, e1: string, e2: string, e3: string, e4: string}
 */
function workflowSeedChain(): array
{
    $requester = AccessSeed::employee('requester');
    $roleOne = AccessSeed::role('wf_role_one');
    $roleTwo = AccessSeed::role('wf_role_two');

    $e1 = AccessSeed::employee('e1');
    $e2 = AccessSeed::employee('e2');
    $e3 = AccessSeed::employee('e3');
    $e4 = AccessSeed::employee('e4');

    AccessSeed::assign($e1, $roleOne);
    AccessSeed::assign($e2, $roleOne);
    AccessSeed::assign($e3, $roleTwo);
    AccessSeed::assign($e4, $roleTwo);

    return [
        'requester' => $requester,
        'roleOne' => $roleOne,
        'roleTwo' => $roleTwo,
        'e1' => $e1,
        'e2' => $e2,
        'e3' => $e3,
        'e4' => $e4,
    ];
}
