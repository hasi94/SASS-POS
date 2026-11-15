<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Support;

use Flexipos\Platform\Support\CommercialStatus;

/*
|--------------------------------------------------------------------------
| T-016 step 3 - CommercialStatus::target(), Ruling 4's table
|--------------------------------------------------------------------------
*/

it('maps every subscription status to its tenant status, first row wins', function (
    string $subscriptionStatus,
    bool $graceExpired,
    string $expectedStatus,
    string $expectedTrigger,
) {
    $target = CommercialStatus::target('trial', null, $subscriptionStatus, $graceExpired, 'none');

    expect($target)->toBe(['status' => $expectedStatus, 'trigger' => $expectedTrigger]);
})->with([
    ['cancelled', false, 'suspended', 'subscription_lapsed'],
    ['expired', false, 'suspended', 'subscription_lapsed'],
    ['past_due', true, 'suspended', 'past_due_grace_expired'],
    ['paused', false, 'read_only', 'subscription_paused'],
    ['past_due', false, 'past_due', 'subscription_past_due'],
    ['active', false, 'active', 'subscription_active'],
    ['trial', false, 'trial', 'subscription_trial'],
]);

it('suspends for a lapsed licence even with an active subscription', function () {
    expect(CommercialStatus::target('active', null, 'active', false, 'lapsed'))
        ->toBe(['status' => 'suspended', 'trigger' => 'licence_lapsed']);
});

it('leaves a protected status alone, closed and archived and pending and provisioning', function (string $protected) {
    expect(CommercialStatus::target($protected, null, 'cancelled', false, 'lapsed'))
        ->toBe(['status' => $protected, 'trigger' => null]);
})->with(['closed', 'archived', 'pending', 'provisioning']);

it('leaves a manually suspended tenant alone when the reason is not its own sentinel', function () {
    expect(CommercialStatus::target('suspended', 'operator_decision', 'active', false, 'none'))
        ->toBe(['status' => 'suspended', 'trigger' => null]);
});

it('lifts a suspension it imposed itself once the cause reverses', function () {
    expect(CommercialStatus::target('suspended', 'subscription_lapsed', 'active', false, 'none'))
        ->toBe(['status' => 'active', 'trigger' => 'subscription_active']);
});

it('leaves tenants.status unchanged when there is no subscription row at all', function () {
    expect(CommercialStatus::target('active', null, null, false, 'none'))
        ->toBe(['status' => 'active', 'trigger' => null]);
});
