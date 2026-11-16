<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Documents;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Purchasing\Documents\Places;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 8 - Places (AC-20, AC-41, D-40)
|--------------------------------------------------------------------------
|
| The branch through the real BranchDirectory, the location by D-40's rule, on
| the fixture tenant.
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

function puPlaces(): Places
{
    return app(Places::class);
}

/**
 * The refusal code a call ends in, or `accepted` when it returns.
 */
function puPlaceOutcome(callable $call): string
{
    try {
        $call();
    } catch (PurchasingRefused $refused) {
        return $refused->code();
    }

    return 'accepted';
}

it('accepts an active branch and returns the two facts the contract does not give', function () {
    $branch = PurchasingSeed::branch();

    $found = puPlaces()->branch(PurchasingSeed::connection(), strtolower($branch));

    expect($found->id)->toBe($branch)
        ->and($found->timezone)->toBe('Asia/Colombo')
        ->and($found->currency)->toBe('LKR');
});

it('refuses a branch that is unknown, inactive or soft-deleted as unknown_branch, and a malformed id as validation', function () {
    $inactive = PurchasingSeed::branch(false);
    $deleted = PurchasingSeed::branch();
    PurchasingSeed::connection()->table('branches')->where('id', $deleted)->update(['deleted_at' => PurchasingSeed::NOW]);

    $connection = PurchasingSeed::connection();

    expect(puPlaceOutcome(fn () => puPlaces()->branch($connection, (string) Str::ulid())))->toBe('unknown_branch')
        ->and(puPlaceOutcome(fn () => puPlaces()->branch($connection, $inactive)))->toBe('unknown_branch')
        ->and(puPlaceOutcome(fn () => puPlaces()->branch($connection, $deleted)))->toBe('unknown_branch')
        ->and(puPlaceOutcome(fn () => puPlaces()->branch($connection, 'not-a-ulid')))->toBe('validation')
        ->and(puPlaceOutcome(fn () => puPlaces()->branch($connection, null)))->toBe('validation');
});

it('accepts an active, purchasable location of the document s branch', function () {
    $branch = PurchasingSeed::branch();
    $location = PurchasingSeed::location($branch);

    $found = puPlaces()->location(PurchasingSeed::connection(), strtolower($location), $branch);

    expect($found->id)->toBe($location)->and($found->branch_id)->toBe($branch);
});

it('refuses every ineligible location as unknown_location, one row per way of failing (D-40)', function (string $way) {
    $branch = PurchasingSeed::branch();
    $otherBranch = PurchasingSeed::branch();
    $connection = PurchasingSeed::connection();

    $location = match ($way) {
        'unknown' => (string) Str::ulid(),
        'soft-deleted' => PurchasingSeed::location($branch, 'DEL', ['deleted_at' => PurchasingSeed::NOW]),
        'inactive' => PurchasingSeed::location($branch, 'OFF', ['is_active' => false]),
        'not purchasable to' => PurchasingSeed::location($branch, 'NOP', ['is_purchasable_to' => false]),
        'another branch' => PurchasingSeed::location($otherBranch, 'OTH'),
        'a central warehouse (no branch)' => PurchasingSeed::location(null, 'CEN'),
        default => throw new \LogicException("Unhandled way [{$way}]."),
    };

    expect(puPlaceOutcome(fn () => puPlaces()->location($connection, $location, $branch)))->toBe('unknown_location');
})->with(['unknown', 'soft-deleted', 'inactive', 'not purchasable to', 'another branch', 'a central warehouse (no branch)']);

it('refuses a malformed location id as validation', function () {
    $branch = PurchasingSeed::branch();

    expect(puPlaceOutcome(fn () => puPlaces()->location(PurchasingSeed::connection(), 'x', $branch)))->toBe('validation');
});
