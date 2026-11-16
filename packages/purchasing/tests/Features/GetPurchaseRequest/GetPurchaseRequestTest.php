<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\GetPurchaseRequest;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\GetPurchaseRequest\GetPurchaseRequest;
use Flexipos\Purchasing\Features\GetPurchaseRequest\GetPurchaseRequestHandler;
use Flexipos\Purchasing\Features\GetPurchaseRequest\GetPurchaseRequestResult;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 14 - GetPurchaseRequest (AC-21, AC-34, AC-38, AC-84, D-36)
|--------------------------------------------------------------------------
|
| Header and lines as stored, to a holder of the view key at the request's own
| branch, and to nobody else.
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/purchasing-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    puEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function puGetRequest(string $requestId, ?string $actorId): GetPurchaseRequestResult
{
    /** @var GetPurchaseRequestHandler $handler */
    $handler = app(GetPurchaseRequestHandler::class);

    return $handler->handle(new GetPurchaseRequest($requestId, $actorId));
}

it('AC-34: returns the header and the lines by line number, as stored, with every decimal a string', function () {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], [
        'required_date' => '2026-12-01',
        'notes' => 'Restock',
    ]);
    $second = PurchasingSeed::requestItem($request, $desk['product'], $desk['unit'], 2, ['quantity' => '2.0000', 'base_quantity' => '2.0000']);
    $first = PurchasingSeed::requestItem($request, $desk['product'], $desk['unit'], 1, [
        'quantity' => '5.0000',
        'unit_factor' => '12.000000',
        'base_quantity' => '60.0000',
        'estimated_cost' => '2.5000',
        'supplier_id' => $desk['supplier'],
    ]);

    $result = puGetRequest(strtolower($request), strtolower($desk['buyer']));

    expect($result->request)->toBe([
        'id' => $request,
        'created_at' => PurchasingSeed::NOW,
        'updated_at' => PurchasingSeed::NOW,
        'created_by' => $desk['buyer'],
        'updated_by' => $desk['buyer'],
        'number' => PurchasingSeed::row('purchase_requests', $request)['number'],
        'branch_id' => $desk['branch'],
        'location_id' => $desk['location'],
        'requested_by' => $desk['buyer'],
        'required_date' => '2026-12-01',
        'status' => 'draft',
        'workflow_instance_id' => null,
        'notes' => 'Restock',
    ])
        ->and(array_column($result->lines, 'id'))->toBe([$first, $second])
        ->and($result->lines[0])->toBe([
            'id' => $first,
            'request_id' => $request,
            'line_no' => 1,
            'product_id' => $desk['product'],
            'unit_id' => $desk['unit'],
            'quantity' => '5.0000',
            'unit_factor' => '12.000000',
            'base_quantity' => '60.0000',
            'estimated_cost' => '2.5000',
            'supplier_id' => $desk['supplier'],
            'purchase_order_item_id' => null,
        ])
        ->and($result->lines[1]['estimated_cost'])->toBeNull();
});

it('AC-34: a request with no lines has an empty list, and the workflow instance id is returned when there is one', function () {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);

    expect(puGetRequest($request, $desk['buyer'])->lines)->toBe([]);
});

it('AC-24: the lines are read as stored, whatever the unit ladder has become since', function () {
    $desk = PurchasingWorld::desk();
    $units = PurchasingSeed::unitPair('EAG', 'DZG', '12');
    $product = PurchasingSeed::product('GET-LADDER', $units['base']);
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    PurchasingSeed::requestItem($request, $product, $units['large'], 1, ['quantity' => '1.0000', 'unit_factor' => '12.000000', 'base_quantity' => '12.0000']);

    PurchasingSeed::connection()->table('units')->where('id', $units['large'])->update(['factor' => '24']);

    expect(puGetRequest($request, $desk['buyer'])->lines[0])->toMatchArray([
        'unit_factor' => '12.000000',
        'base_quantity' => '12.0000',
    ]);
});

it('AC-21 and AC-34: the view key is asked at the request s stored branch: a company-wide grant and a grant at that branch read, anything else is refused with the resolver s reason', function () {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);

    $companyWide = AccessSeed::actorHolding('director', [PurchasingPermissions::REQUESTS_VIEW]);
    $atBranch = AccessSeed::actorHolding('at_branch', [PurchasingPermissions::REQUESTS_VIEW], $desk['branch']);
    $elsewhere = AccessSeed::actorHolding('elsewhere', [PurchasingPermissions::REQUESTS_VIEW], PurchasingSeed::branch());
    $managerOnly = AccessSeed::actorHolding('manager_only', [PurchasingPermissions::REQUESTS_MANAGE], $desk['branch']);
    $orderKey = AccessSeed::actorHolding('orders', [PurchasingPermissions::ORDERS_VIEW], $desk['branch']);

    expect(puGetRequest($request, $companyWide)->request['id'])->toBe($request)
        ->and(puGetRequest($request, $atBranch)->request['id'])->toBe($request);

    foreach (['elsewhere' => $elsewhere, 'manager only' => $managerOnly, 'an order key' => $orderKey] as $who => $actor) {
        $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puGetRequest($request, $actor), $who);

        expect($refused->cause)->toBe(RefusalReason::NotPermitted);
    }
});

it('AC-34 and AC-86: a missing actor is validation and an unavailable employee is refused with the resolver s reason', function () {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);

    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puGetRequest($request, null));

    AccessSeed::connection()->table('employees')->where('id', $desk['buyer'])->update(['status' => 'suspended']);

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puGetRequest($request, $desk['buyer']));

    expect($refused->cause)->toBe(RefusalReason::EmployeeUnavailable);
});

it('AC-34: an unknown id is not_found and a malformed one is validation', function () {
    $desk = PurchasingWorld::desk();

    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => puGetRequest((string) Str::ulid(), $desk['buyer']));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puGetRequest('nope', $desk['buyer']));
});
