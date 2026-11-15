<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Support;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Organization\BranchSeed;
use Flexipos\Contracts\Organization\DefaultBranch;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Support\OrganizationRefusal;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 step 6 - DatabaseDefaultBranch (AC-1, AC-26, B-12)
|--------------------------------------------------------------------------
|
| validate() needs no connection: every case in that block runs with no
| tenant entered, to prove the claim rather than merely state it.
|
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function defaultBranch(): DefaultBranch
{
    /** @var DefaultBranch $service */
    $service = app(DefaultBranch::class);

    return $service;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function validSeed(array $overrides = []): BranchSeed
{
    $defaults = [
        'tenantName' => 'Pharmacy One',
        'timezone' => 'Asia/Colombo',
        'currency' => 'LKR',
        'businessType' => 'retail',
        'code' => null,
        'name' => null,
    ];

    $values = array_merge($defaults, $overrides);

    return new BranchSeed(
        $values['tenantName'],
        $values['timezone'],
        $values['currency'],
        $values['businessType'],
        $values['code'],
        $values['name'],
    );
}

it('validates without opening a connection (no tenant entered)', function () {
    AccessTenantFixture::forget();

    defaultBranch()->validate(validSeed());

    expect(true)->toBeTrue();
});

it('refuses a 101-character name, needs no connection either (B-12)', function () {
    AccessTenantFixture::forget();

    $longName = str_repeat('A', 101);

    OrganizationSeed::refusal(
        OrganizationRefusal::Validation,
        fn () => defaultBranch()->validate(validSeed(['name' => $longName]))
    );
});

it('accepts a 100-character name', function () {
    AccessTenantFixture::forget();

    defaultBranch()->validate(validSeed(['name' => str_repeat('A', 100)]));

    expect(true)->toBeTrue();
});

it('refuses a non-IANA timezone', function () {
    AccessTenantFixture::forget();

    OrganizationSeed::refusal(
        OrganizationRefusal::Validation,
        fn () => defaultBranch()->validate(validSeed(['timezone' => 'Nowhere/Place']))
    );
});

it('refuses an unknown business type', function () {
    AccessTenantFixture::forget();

    OrganizationSeed::refusal(
        OrganizationRefusal::Validation,
        fn () => defaultBranch()->validate(validSeed(['businessType' => 'bogus']))
    );
});

it('refuses a bad code', function () {
    AccessTenantFixture::forget();

    OrganizationSeed::refusal(
        OrganizationRefusal::Validation,
        fn () => defaultBranch()->validate(validSeed(['code' => 'way too long for a code']))
    );
});

it('creates the branch with the MAIN default and the tenant name, when ensure() is called with no code/name', function () {
    $branchId = defaultBranch()->ensure(validSeed());

    $row = AccessSeed::connection()->table('branches')->where('id', $branchId)->first();

    expect($row->code)->toBe('MAIN')
        ->and($row->name)->toBe('Pharmacy One');
});

it('returns the same id on a second call (idempotent, a resumed seed_core)', function () {
    $first = defaultBranch()->ensure(validSeed());
    $second = defaultBranch()->ensure(validSeed());

    expect($second)->toBe($first)
        ->and(AccessSeed::connection()->table('branches')->count())->toBe(1);
});

it('reports exists() true for a live branch and false for an unknown id', function () {
    $branchId = defaultBranch()->ensure(validSeed());

    expect(defaultBranch()->exists($branchId))->toBeTrue()
        ->and(defaultBranch()->exists((string) Str::ulid()))->toBeFalse();
});

it('reports exists() false for a soft-deleted branch', function () {
    $branchId = defaultBranch()->ensure(validSeed());

    AccessSeed::connection()->table('branches')
        ->where('id', $branchId)
        ->update(['deleted_at' => '2026-11-07 10:00:00.000']);

    expect(defaultBranch()->exists($branchId))->toBeFalse();
});
