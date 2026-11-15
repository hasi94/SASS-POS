<?php

use Flexipos\Access\Tests\Support\AccessTenantFixture;

use function Pest\Laravel\json;

/*
|--------------------------------------------------------------------------
| T-014 step 9 - the location routes over HTTP (AC-21 to AC-25, AC-63)
|--------------------------------------------------------------------------
|
| The slices are proved in the package. What this file proves is the wiring
| and the shapes: a warehouse created over the wire, the filters read from the
| query string, `location_in_use` as a 403 with that body, `unknown_branch` as
| a 422 `validation` with its reason, and the permission key each route needs.
*/

require_once __DIR__.'/../Support/organization-http-fixture.php';

beforeEach(function () {
    orgHttpBoot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

it('creates, lists, reads, updates and deactivates a warehouse as the owner (AC-21 to AC-25)', function () {
    $world = orgHttpWorld();
    $token = orgHttpToken($world, $world['owner']);

    $branch = (string) orgHttpCall('POST', '/api/v1/organization/branches', $token, [
        'code' => 'main', 'name' => 'Main', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
    ])->json('branch_id');

    $created = orgHttpCall('POST', '/api/v1/organization/locations', $token, ['code' => 'wh1', 'name' => 'Back', 'branch_id' => $branch]);

    expect($created->getStatusCode())->toBe(201)
        ->and($created->json('code'))->toBe('WH1');

    $location = (string) $created->json('location_id');

    // The filters come from the query string: this branch has its store and
    // the warehouse, and a type filter narrows to one.
    $byBranch = orgHttpCall('GET', '/api/v1/organization/locations?branch_id='.$branch, $token);
    $byType = orgHttpCall('GET', '/api/v1/organization/locations?branch_id='.$branch.'&type=warehouse', $token);

    expect(array_column($byBranch->json('locations'), 'code'))->toBe(['MAIN', 'WH1'])
        ->and(array_column($byType->json('locations'), 'code'))->toBe(['WH1']);

    expect(orgHttpCall('GET', '/api/v1/organization/locations?type=freezer', $token)->getStatusCode())->toBe(422)
        ->and(orgHttpCall('GET', '/api/v1/organization/locations/'.strtolower($location), $token)->json('id'))->toBe($location)
        ->and(orgHttpCall('PATCH', '/api/v1/organization/locations/'.$location, $token, ['name' => 'Rear'])->getStatusCode())->toBe(200);

    $off = orgHttpCall('PUT', '/api/v1/organization/locations/'.$location.'/status', $token, ['is_active' => false]);

    expect($off->getStatusCode())->toBe(200)
        ->and($off->json('is_active'))->toBeFalse();
});

it('answers location_in_use with a 403 body and unknown_branch as a 422 validation with its reason (AC-24, AC-63)', function () {
    $world = orgHttpWorld();
    $token = orgHttpToken($world, $world['owner']);

    $created = orgHttpCall('POST', '/api/v1/organization/branches', $token, [
        'code' => 'main', 'name' => 'Main', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
    ]);

    $store = (string) $created->json('location_id');

    $inUse = orgHttpCall('PUT', '/api/v1/organization/locations/'.$store.'/status', $token, ['is_active' => false]);

    expect($inUse->getStatusCode())->toBe(403)
        ->and($inUse->json())->toBe(['error' => 'location_in_use']);

    $unknown = orgHttpCall('POST', '/api/v1/organization/locations', $token, [
        'code' => 'wh9', 'name' => 'Lost', 'branch_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    ]);

    expect($unknown->getStatusCode())->toBe(422)
        ->and($unknown->json())->toBe(['error' => 'validation', 'reason' => 'unknown_branch']);

    // Only a warehouse can be created here.
    $typed = orgHttpCall('POST', '/api/v1/organization/locations', $token, ['code' => 'tr1', 'name' => 'T', 'type' => 'transit']);

    expect($typed->getStatusCode())->toBe(422);
});

it('lets a viewer read locations and not manage them (AC-18)', function () {
    $world = orgHttpWorld();
    $viewer = orgHttpEmployeeHolding('loc_viewer', ['locations.view']);
    $token = orgHttpToken($world, $viewer);

    expect(orgHttpCall('GET', '/api/v1/organization/locations', $token)->getStatusCode())->toBe(200);

    $refused = orgHttpCall('POST', '/api/v1/organization/locations', $token, ['code' => 'wh1', 'name' => 'Nope']);

    expect($refused->getStatusCode())->toBe(403)
        ->and($refused->json())->toBe(['error' => 'not_permitted'])
        ->and(json('GET', '/api/v1/organization/locations')->getStatusCode())->toBe(401);
});
