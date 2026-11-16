<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\UpdatePurchaseRequestLine;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Contracts\Catalog\UnitRefusal;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Flexipos\Purchasing\Documents\RequestStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\UpdatePurchaseRequestLine\UpdatePurchaseRequestLine;
use Flexipos\Purchasing\Features\UpdatePurchaseRequestLine\UpdatePurchaseRequestLineHandler;
use Flexipos\Purchasing\Features\UpdatePurchaseRequestLine\UpdatePurchaseRequestLineResult;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 12 - UpdatePurchaseRequestLine (AC-22 to AC-25, AC-39, D-14)
|--------------------------------------------------------------------------
|
| A unit or quantity change resolves the quad again; a cost or supplier change
| leaves the stored quad alone; a call that changes nothing writes nothing.
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
function puUpdateRequestLine(array $fields, ?string $actorId): UpdatePurchaseRequestLineResult
{
    /** @var UpdatePurchaseRequestLineHandler $handler */
    $handler = app(UpdatePurchaseRequestLineHandler::class);

    return $handler->handle(new UpdatePurchaseRequestLine($fields, $actorId));
}

/**
 * A draft request holding one line of 5 each at 2.5.
 *
 * @param  array<string, string>  $desk
 * @return array{request: string, line: string}
 */
function puRequestWithLine(array $desk, string $status = 'draft'): array
{
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['status' => $status]);
    $line = PurchasingSeed::requestItem($request, $desk['product'], $desk['unit'], 1, [
        'quantity' => '5.0000',
        'unit_factor' => '1.000000',
        'base_quantity' => '5.0000',
        'estimated_cost' => '2.5000',
    ]);

    return ['request' => $request, 'line' => $line];
}

/**
 * @param  array<string, mixed>  $fields
 */
function puUpdateLineRefused(PurchasingRefusal $reason, array $fields, ?string $actorId, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puUpdateRequestLine($fields, $actorId), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-22: a new quantity resolves the quad again, and one audit row holds the line before and after', function () {
    $desk = PurchasingWorld::desk();
    $made = puRequestWithLine($desk);

    $result = puUpdateRequestLine(['request_id' => strtolower($made['request']), 'line_id' => strtolower($made['line']), 'quantity' => '8'], $desk['buyer']);

    expect($result->changedColumns)->toBe(['quantity', 'base_quantity'])
        ->and(PurchasingSeed::row('purchase_request_items', $made['line']))->toMatchArray([
            'quantity' => '8.0000',
            'base_quantity' => '8.0000',
            'unit_id' => $desk['unit'],
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $desk['buyer'],
        'branch_id' => $desk['branch'],
        'action' => PurchasingAuditActions::REQUEST_LINE_UPDATED,
        'subject_type' => 'purchase_request',
        'subject_id' => $made['request'],
    ])
        ->and($audit['before']['quantity'])->toBe('5.0000')
        ->and($audit['after']['quantity'])->toBe('8.0000')
        ->and($audit['after']['id'])->toBe($made['line']);
});

it('AC-22 and D-14: a new unit freezes the new factor and base quantity', function () {
    $desk = PurchasingWorld::desk();
    $units = PurchasingSeed::unitPair('EAU', 'DZU', '12');
    $product = PurchasingSeed::product('UNITS-1', $units['base']);
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    $line = PurchasingSeed::requestItem($request, $product, $units['base'], 1, ['quantity' => '24.0000', 'base_quantity' => '24.0000']);

    $result = puUpdateRequestLine(['request_id' => $request, 'line_id' => $line, 'unit_id' => $units['large'], 'quantity' => '2'], $desk['buyer']);

    expect($result->line)->toMatchArray([
        'unit_id' => $units['large'],
        'quantity' => '2.0000',
        'unit_factor' => '12.000000',
        'base_quantity' => '24.0000',
    ]);
});

it('AC-24: a cost or supplier change leaves the stored quad alone even when the unit ladder has moved, and a quantity change resolves it fresh', function () {
    $desk = PurchasingWorld::desk();
    $units = PurchasingSeed::unitPair('EAK', 'DZK', '12');
    $product = PurchasingSeed::product('LADDER-2', $units['base']);
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    $line = PurchasingSeed::requestItem($request, $product, $units['large'], 1, [
        'quantity' => '1.0000',
        'unit_factor' => '12.000000',
        'base_quantity' => '12.0000',
    ]);

    PurchasingSeed::connection()->table('units')->where('id', $units['large'])->update(['factor' => '24']);

    puUpdateRequestLine(['request_id' => $request, 'line_id' => $line, 'estimated_cost' => '4', 'supplier_id' => $desk['supplier']], $desk['buyer']);

    expect(PurchasingSeed::row('purchase_request_items', $line))->toMatchArray([
        'unit_factor' => '12.000000',
        'base_quantity' => '12.0000',
        'estimated_cost' => '4.0000',
        'supplier_id' => $desk['supplier'],
    ]);

    puUpdateRequestLine(['request_id' => $request, 'line_id' => $line, 'quantity' => '2'], $desk['buyer']);

    expect(PurchasingSeed::row('purchase_request_items', $line))->toMatchArray([
        'unit_factor' => '24.000000',
        'base_quantity' => '48.0000',
    ]);
});

it('AC-22: null clears the estimated cost and the supplier, and a value left out is kept', function () {
    $desk = PurchasingWorld::desk();
    $made = puRequestWithLine($desk);

    puUpdateRequestLine(['request_id' => $made['request'], 'line_id' => $made['line'], 'supplier_id' => $desk['supplier']], $desk['buyer']);

    $cleared = puUpdateRequestLine(['request_id' => $made['request'], 'line_id' => $made['line'], 'estimated_cost' => null, 'supplier_id' => null], $desk['buyer']);

    expect($cleared->changedColumns)->toBe(['estimated_cost', 'supplier_id'])
        ->and($cleared->line['estimated_cost'])->toBeNull()
        ->and($cleared->line['supplier_id'])->toBeNull()
        ->and($cleared->line['quantity'])->toBe('5.0000');
});

it('AC-25: a call that changes nothing writes nothing, however the values are spelt', function () {
    $desk = PurchasingWorld::desk();
    $made = puRequestWithLine($desk);
    $before = PurchasingRefusals::state();

    $same = puUpdateRequestLine([
        'request_id' => $made['request'],
        'line_id' => $made['line'],
        'quantity' => '5.00',
        'unit_id' => strtolower($desk['unit']),
        'estimated_cost' => '2.5',
    ], $desk['buyer']);

    expect($same->changed())->toBeFalse()
        ->and(PurchasingRefusals::state())->toBe($before)
        ->and(puUpdateRequestLine(['request_id' => $made['request'], 'line_id' => $made['line']], $desk['buyer'])->changed())->toBeFalse()
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-23: an edit is held to the add s rules for quantity, unit, cost and supplier', function (array $change, PurchasingRefusal $reason) {
    $desk = PurchasingWorld::desk();
    $made = puRequestWithLine($desk);
    $inactive = PurchasingSeed::supplier('OFF', ['is_active' => false]);
    $deleted = PurchasingSeed::supplier('GONE', ['deleted_at' => PurchasingSeed::NOW]);

    if (($change['supplier_id'] ?? null) === '__inactive__') {
        $change['supplier_id'] = $inactive;
    }

    if (($change['supplier_id'] ?? null) === '__deleted__') {
        $change['supplier_id'] = $deleted;
    }

    puUpdateLineRefused($reason, array_merge(['request_id' => $made['request'], 'line_id' => $made['line']], $change), $desk['buyer']);
})->with([
    'a zero quantity' => [['quantity' => '0'], PurchasingRefusal::Validation],
    'a negative quantity' => [['quantity' => '-1'], PurchasingRefusal::Validation],
    'a float quantity' => [['quantity' => 1.5], PurchasingRefusal::Validation],
    'a null quantity' => [['quantity' => null], PurchasingRefusal::Validation],
    'five decimals' => [['quantity' => '1.00001'], PurchasingRefusal::Validation],
    'an enormous quantity' => [['quantity' => '100000000000000'], PurchasingRefusal::OutOfRange],
    'a negative cost' => [['estimated_cost' => '-1'], PurchasingRefusal::Validation],
    'a float cost' => [['estimated_cost' => 2.5], PurchasingRefusal::Validation],
    'an unknown unit' => [['unit_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0'], PurchasingRefusal::UnitUnresolvable],
    'a malformed unit' => [['unit_id' => 'nope'], PurchasingRefusal::Validation],
    'an unknown supplier' => [['supplier_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0'], PurchasingRefusal::UnknownSupplier],
    'a deleted supplier' => [['supplier_id' => '__deleted__'], PurchasingRefusal::UnknownSupplier],
    'an inactive supplier' => [['supplier_id' => '__inactive__'], PurchasingRefusal::SupplierInactive],
    'a product change' => [['product_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0'], PurchasingRefusal::Validation],
    'a line number change' => [['line_no' => 7], PurchasingRefusal::Validation],
]);

it('AC-23: a unit of another dimension is unit_unresolvable, carrying the resolver s reason', function () {
    $desk = PurchasingWorld::desk();
    $made = puRequestWithLine($desk);
    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::UnitUnresolvable, fn () => puUpdateRequestLine(['request_id' => $made['request'], 'line_id' => $made['line'], 'unit_id' => PricingSeed::unit('PUKG2', 'mass')], $desk['buyer']));

    expect($refused->cause)->toBe(UnitRefusal::CrossDimension)
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-23: a product that has since become unavailable refuses a quantity change but not a cost change, and a supplier already on the line may be kept while inactive', function () {
    $desk = PurchasingWorld::desk();
    $made = puRequestWithLine($desk);

    puUpdateRequestLine(['request_id' => $made['request'], 'line_id' => $made['line'], 'supplier_id' => $desk['supplier']], $desk['buyer']);

    PurchasingSeed::connection()->table('products')->where('id', $desk['product'])->update(['is_active' => false]);
    PurchasingSeed::connection()->table('suppliers')->where('id', $desk['supplier'])->update(['is_active' => false]);

    puUpdateLineRefused(PurchasingRefusal::UnknownProduct, ['request_id' => $made['request'], 'line_id' => $made['line'], 'quantity' => '9'], $desk['buyer']);

    $result = puUpdateRequestLine(['request_id' => $made['request'], 'line_id' => $made['line'], 'estimated_cost' => '3', 'supplier_id' => $desk['supplier']], $desk['buyer']);

    expect($result->changedColumns)->toBe(['estimated_cost']);
});

it('AC-25: refuses an edit of a line of a request that is not a draft as locked, and writes nothing', function (string $status) {
    $desk = PurchasingWorld::desk();
    $made = puRequestWithLine($desk, $status);

    puUpdateLineRefused(PurchasingRefusal::Locked, ['request_id' => $made['request'], 'line_id' => $made['line'], 'quantity' => '9'], $desk['buyer'], $status);
})->with(array_combine(
    array_map(static fn (RequestStatus $status): string => $status->value, array_values(array_filter(RequestStatus::cases(), static fn (RequestStatus $status): bool => ! $status->isEditable()))),
    array_map(static fn (RequestStatus $status): array => [$status->value], array_values(array_filter(RequestStatus::cases(), static fn (RequestStatus $status): bool => ! $status->isEditable()))),
));

it('AC-25: a line of another request, an unknown line and a malformed id are not_found or validation', function () {
    $desk = PurchasingWorld::desk();
    $made = puRequestWithLine($desk);
    $other = puRequestWithLine($desk);

    puUpdateLineRefused(PurchasingRefusal::NotFound, ['request_id' => $made['request'], 'line_id' => $other['line'], 'quantity' => '9'], $desk['buyer'], 'another request s line');
    puUpdateLineRefused(PurchasingRefusal::NotFound, ['request_id' => $made['request'], 'line_id' => (string) Str::ulid(), 'quantity' => '9'], $desk['buyer'], 'unknown line');
    puUpdateLineRefused(PurchasingRefusal::NotFound, ['request_id' => (string) Str::ulid(), 'line_id' => $made['line'], 'quantity' => '9'], $desk['buyer'], 'unknown request');
    puUpdateLineRefused(PurchasingRefusal::Validation, ['request_id' => $made['request'], 'line_id' => 'nope', 'quantity' => '9'], $desk['buyer'], 'bad line id');
    puUpdateLineRefused(PurchasingRefusal::Validation, ['request_id' => $made['request'], 'quantity' => '9'], $desk['buyer'], 'no line id');
});

it('AC-21 and AC-38: the permission is asked at the stored branch, and the view key alone is refused', function () {
    $desk = PurchasingWorld::desk();
    $made = puRequestWithLine($desk);
    $scoped = AccessSeed::actorHolding('scoped', [PurchasingPermissions::REQUESTS_MANAGE], PurchasingSeed::branch());
    $viewer = AccessSeed::actorHolding('viewer', [PurchasingPermissions::REQUESTS_VIEW], $desk['branch']);
    $fields = ['request_id' => $made['request'], 'line_id' => $made['line'], 'quantity' => '9'];

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puUpdateRequestLine($fields, $scoped));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puUpdateRequestLine($fields, $viewer));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puUpdateRequestLine($fields, null));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);
});
