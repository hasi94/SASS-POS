<?php

namespace Flexipos\Hr\Tests\Contracts;

use Flexipos\Contracts\Hr\DirectReports;
use Flexipos\Contracts\Hr\NoOrgChart;
use Flexipos\Contracts\Hr\OrgChart;

/*
|--------------------------------------------------------------------------
| The default org chart (T-038 plan step 4, AC-84, D-52)
|--------------------------------------------------------------------------
|
| `packages/contracts` has no tests directory, so the contract's default is
| tested by its first user, the way access tests `RefusalReason`. One case per
| question of AC-82. Pure: no database, no container.
*/

it('is an org chart', function () {
    expect(new NoOrgChart)->toBeInstanceOf(OrgChart::class);
});

it('names no manager', function () {
    expect((new NoOrgChart)->managerOf('01JABCDEFGHJKMNPQRSTVWXYZ0'))->toBeNull();
});

it('names no department head', function () {
    expect((new NoOrgChart)->departmentHeadOf('01JABCDEFGHJKMNPQRSTVWXYZ0'))->toBeNull();
});

it('names no department', function () {
    expect((new NoOrgChart)->departmentOf('01JABCDEFGHJKMNPQRSTVWXYZ0'))->toBeNull();
});

it('names no designation', function () {
    expect((new NoOrgChart)->designationOf('01JABCDEFGHJKMNPQRSTVWXYZ0'))->toBeNull();
});

it('has no direct reports and says the list is complete', function () {
    $reports = (new NoOrgChart)->directReportsOf('01JABCDEFGHJKMNPQRSTVWXYZ0');

    expect($reports)->toBeInstanceOf(DirectReports::class)
        ->and($reports->ids)->toBe([])
        ->and($reports->truncated)->toBeFalse();
});

it('knows no live department', function () {
    expect((new NoOrgChart)->isLiveDepartment('01JABCDEFGHJKMNPQRSTVWXYZ0'))->toBeFalse();
});

it('knows no live designation', function () {
    expect((new NoOrgChart)->isLiveDesignation('01JABCDEFGHJKMNPQRSTVWXYZ0'))->toBeFalse();
});
