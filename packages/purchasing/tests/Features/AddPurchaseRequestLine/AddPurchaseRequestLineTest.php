<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\AddPurchaseRequestLine;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Contracts\Catalog\UnitRefusal;
use Flexipos\Contracts\Catalog\UnitResolver;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Flexipos\Purchasing\Documents\RequestStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\AddPurchaseRequestLine\AddPurchaseRequestLine;
use Flexipos\Purchasing\Features\AddPurchaseRequestLine\AddPurchaseRequestLineHandler;
use Flexipos\Purchasing\Features\AddPurchaseRequestLine\AddPurchaseRequestLineResult;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 12 - AddPurchaseRequestLine (AC-22, AC-23, AC-25, AC-36, AC-39)
|--------------------------------------------------------------------------
|
| The stored quad is exactly what the real UnitResolver returns; every product,
| unit, quantity and supplier refusal leaves the whole state as it was; the 201st
| line, a locked request and a replay have their own rows.
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

/**
 * @param  array<string, mixed>  $fields
 */
function puAddRequestLine(array $fields, ?string $actorId): AddPurchaseRequestLineResult
{
    /** @var AddPurchaseRequestLineHandler $handler */
    $handler = app(AddPurchaseRequestLineHandler::class);

    return $handler->handle(new AddPurchaseRequestLine($fields, $actorId));
}

/**
 * A draft request on the desk, and the fields of a line for it.
 *
 * @param  array<string, string>  $desk
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function puAddLineFields(array $desk, string $requestId, array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::ulid(),
        'request_id' => $requestId,
        'product_id' => $desk['product'],
        'unit_id' => $desk['unit'],
        'quantity' => '5',
    ], $overrides);
}

/**
 * @param  array<string, string>  $desk
 * @param  array<string, mixed>  $overrides
 */
function puDraftRequest(array $desk, array $overrides = []): string
{
    return PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], $overrides);
}

/**
 * Refuse the add with this reason, and prove nothing changed anywhere.
 *
 * @param  array<string, mixed>  $fields
 */
function puAddLineRefused(PurchasingRefusal $reason, array $fields, ?string $actorId, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puAddRequestLine($fields, $actorId), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-22: stores the unit quad exactly as UnitResolver::snapshot returns it, the cost and the supplier, with one audit row against the header', function () {
    $desk = PurchasingWorld::desk();
    $request = puDraftRequest($desk);
    $fields = puAddLineFields($desk, $request, ['estimated_cost' => '2.5', 'supplier_id' => $desk['supplier']]);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puAddRequestLine(array_merge($fields, ['id' => strtolower($fields['id']), 'request_id' => strtolower($request)]), strtolower($desk['buyer']));

    $snapshot = app(UnitResolver::class)->snapshot($desk['product'], $desk['unit'], '5');

    expect($result->replayed)->toBeFalse()
        ->and(PurchasingSeed::row('purchase_request_items', $fields['id']))->toMatchArray([
            'request_id' => $request,
            'line_no' => 1,
            'product_id' => $desk['product'],
            'unit_id' => $snapshot->unitId,
            'supplier_id' => $desk['supplier'],
            'purchase_order_item_id' => null,
        ])
        ->and($result->line)->toMatchArray([
            'quantity' => $snapshot->quantity,
            'unit_factor' => $snapshot->unitFactor,
            'base_quantity' => $snapshot->baseQuantity,
            'estimated_cost' => '2.5000',
            'line_no' => 1,
        ])
        ->and(PurchasingSeed::row('purchase_requests', $request))->toMatchArray([
            'updated_at' => '2026-11-17 10:00:00.000',
            'updated_by' => $desk['buyer'],
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $desk['buyer'],
        'branch_id' => $desk['branch'],
        'module' => PurchasingAuditActions::MODULE,
        'action' => PurchasingAuditActions::REQUEST_LINE_ADDED,
        'subject_type' => 'purchase_request',
        'subject_id' => $request,
        'before' => null,
    ])
        ->and($audit['after']['id'])->toBe($fields['id'])
        ->and($audit['after']['base_quantity'])->toBe($snapshot->baseQuantity);
});

it('AC-22: a larger unit is frozen as its quantity, its factor and the base quantity, and the cost is optional', function () {
    $desk = PurchasingWorld::desk();
    $units = PurchasingSeed::unitPair('EAD', 'DZD', '12');
    $product = PurchasingSeed::product('DOZEN-1', $units['base']);
    $request = puDraftRequest($desk);

    $result = puAddRequestLine(puAddLineFields($desk, $request, ['product_id' => $product, 'unit_id' => $units['large'], 'quantity' => '2']), $desk['buyer']);

    expect($result->line)->toMatchArray([
        'unit_id' => $units['large'],
        'quantity' => '2.0000',
        'unit_factor' => '12.000000',
        'base_quantity' => '24.0000',
        'estimated_cost' => null,
        'supplier_id' => null,
    ]);
});

it('AC-25: the same product may appear on two lines, which are numbered in order, and a removed number is not reused', function () {
    $desk = PurchasingWorld::desk();
    $request = puDraftRequest($desk);

    $first = puAddRequestLine(puAddLineFields($desk, $request), $desk['buyer']);
    $second = puAddRequestLine(puAddLineFields($desk, $request, ['quantity' => '1']), $desk['buyer']);

    PurchasingSeed::connection()->table('purchase_request_items')->where('id', $first->lineId)->delete();

    $third = puAddRequestLine(puAddLineFields($desk, $request), $desk['buyer']);

    expect([$first->line['line_no'], $second->line['line_no'], $third->line['line_no']])->toBe([1, 2, 3]);
});

it('AC-23: refuses a quantity that is zero, negative, a float, text, null, missing or with five decimals as validation, and an enormous one as out_of_range', function () {
    $desk = PurchasingWorld::desk();
    $request = puDraftRequest($desk);

    foreach (['0', '0.0000', 0, '-1', -2, 1.5, 'abc', null, '1.00001', '1e3', '', [], true] as $bad) {
        puAddLineRefused(PurchasingRefusal::Validation, puAddLineFields($desk, $request, ['quantity' => $bad]), $desk['buyer'], var_export($bad, true));
    }

    $missing = puAddLineFields($desk, $request);
    unset($missing['quantity']);

    puAddLineRefused(PurchasingRefusal::Validation, $missing, $desk['buyer'], 'missing');
    puAddLineRefused(PurchasingRefusal::OutOfRange, puAddLineFields($desk, $request, ['quantity' => '100000000000000']), $desk['buyer'], '15 digits');
});

it('AC-23: refuses an estimated cost that is negative, a float or text as validation, and accepts zero', function () {
    $desk = PurchasingWorld::desk();
    $request = puDraftRequest($desk);

    foreach (['-1', 2.5, 'abc', '1.00001', []] as $bad) {
        puAddLineRefused(PurchasingRefusal::Validation, puAddLineFields($desk, $request, ['estimated_cost' => $bad]), $desk['buyer'], var_export($bad, true));
    }

    puAddLineRefused(PurchasingRefusal::OutOfRange, puAddLineFields($desk, $request, ['estimated_cost' => '100000000000000']), $desk['buyer'], '15 digits');

    expect(puAddRequestLine(puAddLineFields($desk, $request, ['estimated_cost' => '0']), $desk['buyer'])->line['estimated_cost'])->toBe('0.0000');
});

it('AC-23: an unknown unit and a unit of another dimension are unit_unresolvable, carrying the resolver s reason', function (string $case, UnitRefusal $cause) {
    $desk = PurchasingWorld::desk();
    $request = puDraftRequest($desk);

    $unit = $case === 'unknown unit' ? (string) Str::ulid() : PricingSeed::unit('PUKG', 'mass');

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::UnitUnresolvable, fn () => puAddRequestLine(puAddLineFields($desk, $request, ['unit_id' => $unit]), $desk['buyer']));

    expect($refused->cause)->toBe($cause)
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'unknown unit' => ['unknown unit', UnitRefusal::UnknownUnit],
    'another dimension' => ['another dimension', UnitRefusal::CrossDimension],
]);

it('AC-23 and D-39: an unknown, inactive or soft-deleted product is unknown_product, and one that cannot be bought is product_not_purchasable', function (array $overrides, PurchasingRefusal $reason) {
    $desk = PurchasingWorld::desk();
    $request = puDraftRequest($desk);
    $product = PurchasingSeed::product('REF-'.substr((string) Str::ulid(), -8), $desk['unit'], $overrides);

    puAddLineRefused($reason, puAddLineFields($desk, $request, ['product_id' => $product]), $desk['buyer']);
})->with([
    'inactive' => [['is_active' => false], PurchasingRefusal::UnknownProduct],
    'soft-deleted' => [['deleted_at' => PurchasingSeed::NOW], PurchasingRefusal::UnknownProduct],
    'not purchasable' => [['is_purchasable' => false], PurchasingRefusal::ProductNotPurchasable],
    'not stocked' => [['track_stock' => false], PurchasingRefusal::ProductNotPurchasable],
    'a variant parent' => [['type' => 'variant_parent'], PurchasingRefusal::ProductNotPurchasable],
    'a bundle' => [['type' => 'bundle'], PurchasingRefusal::ProductNotPurchasable],
    'the non-stock type' => [['type' => 'service', 'track_stock' => true], PurchasingRefusal::ProductNotPurchasable],
]);

it('AC-23: an unknown product id is unknown_product', function () {
    $desk = PurchasingWorld::desk();

    puAddLineRefused(PurchasingRefusal::UnknownProduct, puAddLineFields($desk, puDraftRequest($desk), ['product_id' => (string) Str::ulid()]), $desk['buyer']);
});

it('AC-23 and AC-13: a supplier that is unknown, soft-deleted or inactive is refused, and an active one is stored', function () {
    $desk = PurchasingWorld::desk();
    $request = puDraftRequest($desk);
    $inactive = PurchasingSeed::supplier('OFF', ['is_active' => false]);
    $deleted = PurchasingSeed::supplier('GONE', ['deleted_at' => PurchasingSeed::NOW]);

    puAddLineRefused(PurchasingRefusal::UnknownSupplier, puAddLineFields($desk, $request, ['supplier_id' => (string) Str::ulid()]), $desk['buyer'], 'unknown');
    puAddLineRefused(PurchasingRefusal::UnknownSupplier, puAddLineFields($desk, $request, ['supplier_id' => $deleted]), $desk['buyer'], 'deleted');
    puAddLineRefused(PurchasingRefusal::SupplierInactive, puAddLineFields($desk, $request, ['supplier_id' => $inactive]), $desk['buyer'], 'inactive');
    puAddLineRefused(PurchasingRefusal::Validation, puAddLineFields($desk, $request, ['supplier_id' => 'nope']), $desk['buyer'], 'malformed');

    expect(puAddRequestLine(puAddLineFields($desk, $request, ['supplier_id' => $desk['supplier']]), $desk['buyer'])->line['supplier_id'])->toBe($desk['supplier']);
});

it('AC-25: refuses a line on any request that is not a draft as locked, and writes nothing', function (string $status) {
    $desk = PurchasingWorld::desk();
    $request = puDraftRequest($desk, ['status' => $status]);

    puAddLineRefused(PurchasingRefusal::Locked, puAddLineFields($desk, $request), $desk['buyer'], $status);
})->with(array_combine(
    array_map(static fn (RequestStatus $status): string => $status->value, array_values(array_filter(RequestStatus::cases(), static fn (RequestStatus $status): bool => ! $status->isEditable()))),
    array_map(static fn (RequestStatus $status): array => [$status->value], array_values(array_filter(RequestStatus::cases(), static fn (RequestStatus $status): bool => ! $status->isEditable()))),
));

it('AC-25: the 200th line is accepted and the 201st is too_many_lines', function () {
    $desk = PurchasingWorld::desk();
    $request = puDraftRequest($desk);

    $rows = [];

    for ($i = 1; $i <= 199; $i++) {
        $rows[] = [
            'id' => (string) Str::ulid(),
            'request_id' => $request,
            'line_no' => $i,
            'product_id' => $desk['product'],
            'unit_id' => $desk['unit'],
            'quantity' => '1.0000',
            'unit_factor' => '1.000000',
            'base_quantity' => '1.0000',
        ];
    }

    PurchasingSeed::connection()->table('purchase_request_items')->insert($rows);

    $last = puAddRequestLine(puAddLineFields($desk, $request), $desk['buyer']);

    expect($last->line['line_no'])->toBe(200);

    puAddLineRefused(PurchasingRefusal::TooManyLines, puAddLineFields($desk, $request), $desk['buyer'], '201st');
});

it('AC-25: an unknown request is not_found and a malformed id is validation', function () {
    $desk = PurchasingWorld::desk();

    puAddLineRefused(PurchasingRefusal::NotFound, puAddLineFields($desk, (string) Str::ulid()), $desk['buyer']);
    puAddLineRefused(PurchasingRefusal::Validation, puAddLineFields($desk, 'nope'), $desk['buyer']);
    puAddLineRefused(PurchasingRefusal::Validation, puAddLineFields($desk, puDraftRequest($desk), ['id' => 'nope']), $desk['buyer']);
});

it('AC-36: an identical replay of the line id returns the original and writes nothing, and a replay of a rounded quantity is still a replay', function () {
    $desk = PurchasingWorld::desk();
    $request = puDraftRequest($desk);
    $coarse = PricingSeed::unit('PUCOARSE', 'count', ['precision' => 1]);
    $product = PurchasingSeed::product('COARSE-1', $coarse);

    $plain = puAddLineFields($desk, $request, ['estimated_cost' => '1.5']);
    $rounded = puAddLineFields($desk, $request, ['product_id' => $product, 'unit_id' => $coarse, 'quantity' => '2.55']);

    $first = puAddRequestLine($plain, $desk['buyer']);
    $second = puAddRequestLine($rounded, $desk['buyer']);

    $before = PurchasingRefusals::state();

    $again = puAddRequestLine($plain, $desk['buyer']);
    $roundedAgain = puAddRequestLine($rounded, $desk['buyer']);

    expect($second->line['quantity'])->not->toBe('2.5500')
        ->and($again->replayed)->toBeTrue()
        ->and($again->line)->toBe($first->line)
        ->and($roundedAgain->replayed)->toBeTrue()
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-36: the same line id with a different payload, or on another request, is replay_conflict naming the id', function (array $change) {
    $desk = PurchasingWorld::desk();
    $request = puDraftRequest($desk);
    $other = puDraftRequest($desk);
    $fields = puAddLineFields($desk, $request, ['estimated_cost' => '1.5']);

    puAddRequestLine($fields, $desk['buyer']);

    if (isset($change['other_request'])) {
        $change = ['request_id' => $other];
    }

    if (isset($change['other_product'])) {
        $change = ['product_id' => PurchasingSeed::product('ELSE-1', $desk['unit'])];
    }

    if (isset($change['other_supplier'])) {
        $change = ['supplier_id' => $desk['supplier']];
    }

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::ReplayConflict, fn () => puAddRequestLine(array_merge($fields, $change), $desk['buyer']));

    expect($refused->getMessage())->toContain($fields['id'])
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'another quantity' => [['quantity' => '6']],
    'another cost' => [['estimated_cost' => '9']],
    'no cost' => [['estimated_cost' => null]],
    'a supplier' => [['other_supplier' => true]],
    'another product' => [['other_product' => true]],
    'another request' => [['other_request' => true]],
]);

it('AC-21 and AC-38: a grant scoped to another branch, the view key alone and a missing actor are refused, with the resolver s reason', function () {
    $desk = PurchasingWorld::desk();
    $elsewhere = PurchasingSeed::branch();
    $request = puDraftRequest($desk);

    $scoped = AccessSeed::actorHolding('scoped', [PurchasingPermissions::REQUESTS_MANAGE], $elsewhere);
    $viewer = AccessSeed::actorHolding('viewer', [PurchasingPermissions::REQUESTS_VIEW], $desk['branch']);

    $before = PurchasingRefusals::state();

    $refusedScoped = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puAddRequestLine(puAddLineFields($desk, $request), $scoped));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puAddRequestLine(puAddLineFields($desk, $request), $viewer));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puAddRequestLine(puAddLineFields($desk, $request), null));

    expect($refusedScoped->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);

    $companyWide = AccessSeed::actorHolding('director', [PurchasingPermissions::REQUESTS_MANAGE]);

    expect(puAddRequestLine(puAddLineFields($desk, $request), $companyWide)->line['line_no'])->toBe(1);
});

it('AC-24: a stored line keeps its factor and base quantity when the unit ladder later moves', function () {
    $desk = PurchasingWorld::desk();
    $units = PurchasingSeed::unitPair('EAL', 'DZL', '12');
    $product = PurchasingSeed::product('LADDER-1', $units['base']);
    $request = puDraftRequest($desk);

    $line = puAddRequestLine(puAddLineFields($desk, $request, ['product_id' => $product, 'unit_id' => $units['large'], 'quantity' => '1']), $desk['buyer']);

    PurchasingSeed::connection()->table('units')->where('id', $units['large'])->update(['factor' => '24']);

    expect(PurchasingSeed::row('purchase_request_items', $line->lineId))->toMatchArray([
        'quantity' => '1.0000',
        'unit_factor' => '12.000000',
        'base_quantity' => '12.0000',
    ]);
});
