<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\ConvertRequestToOrder;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Catalog\ResolvedFactor;
use Flexipos\Contracts\Catalog\UnitRefusal;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Catalog\UnitResolver;
use Flexipos\Contracts\Catalog\UnitSnapshot;
use Flexipos\Contracts\Organization\DocumentNumbers;
use Flexipos\Contracts\Pricing\PricedPurchaseLine;
use Flexipos\Contracts\Pricing\PricingRefusal;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Pricing\PurchaseLinePricing;
use Flexipos\Contracts\Pricing\PurchaseLineQuote;
use Flexipos\Contracts\Pricing\TaxGroupDirectory;
use Flexipos\Contracts\Pricing\TaxGroupState;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\ConvertRequestToOrder\ConvertRequestToOrder;
use Flexipos\Purchasing\Features\ConvertRequestToOrder\ConvertRequestToOrderHandler;
use Flexipos\Purchasing\Features\ConvertRequestToOrder\ConvertRequestToOrderResult;
use Flexipos\Purchasing\Features\RemovePurchaseOrderLine\RemovePurchaseOrderLine;
use Flexipos\Purchasing\Features\RemovePurchaseOrderLine\RemovePurchaseOrderLineHandler;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\DeadlockingNumbers;
use Flexipos\Purchasing\Tests\Support\PurchasingQueryLog;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 17 - ConvertRequestToOrder (AC-30 to AC-33, AC-90, AC-94, D-48)
|--------------------------------------------------------------------------
|
| One transaction: the order, its lines at the request's stored factors, the
| pointers, the request `converted`, two audit rows and the last number taken. A
| refusal at any line writes nothing and consumes no number (the whole of the
| purchasing tables, the audit trail, the outbox, `number_sequences` and the
| workflow instances are compared).
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
function puConvert(array $fields, ?string $actorId): ConvertRequestToOrderResult
{
    /** @var ConvertRequestToOrderHandler $handler */
    $handler = app(ConvertRequestToOrderHandler::class);

    return $handler->handle(new ConvertRequestToOrder($fields, $actorId));
}

/**
 * An approved request of a dozen-of-twelve product in the dozen, one line per
 * entry of `[quantity, base quantity, estimated cost]`, the product under a 10
 * percent exclusive purchase group.
 *
 * @param  list<array{0: string, 1: string, 2: ?string}>  $lines
 * @param  string  $tag  tells two worlds in one test apart, because unit, group and product codes are unique
 * @return array{desk: array<string, string>, request: string, group: string, product: string, each: string, dozen: string, lines: list<string>}
 */
function puConvWorld(array $lines = [['10.0000', '120.0000', '5.5000']], string $status = 'approved', string $tag = 'c1'): array
{
    $desk = PurchasingWorld::desk($tag);
    $units = PurchasingSeed::unitPair('CVEA'.$tag, 'CVDZ'.$tag, '12');
    $group = PurchasingSeed::taxGroup('CV'.$tag);
    $product = PurchasingSeed::product('CV-'.$tag, $units['base'], ['purchase_tax_group_id' => $group]);
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['status' => $status, 'required_date' => '2026-12-10']);
    $ids = [];

    foreach ($lines as $index => [$quantity, $base, $cost]) {
        $ids[] = PurchasingSeed::requestItem($request, $product, $units['large'], $index + 1, [
            'quantity' => $quantity,
            'unit_factor' => '12.000000',
            'base_quantity' => $base,
            'estimated_cost' => $cost,
        ]);
    }

    return ['desk' => $desk, 'request' => $request, 'group' => $group, 'product' => $product, 'each' => $units['base'], 'dozen' => $units['large'], 'lines' => $ids];
}

/**
 * Refuse the conversion with this reason, and prove nothing changed anywhere, the
 * number sequences included.
 *
 * @param  array<string, mixed>  $fields
 */
function puConvertRefused(PurchasingRefusal $reason, array $fields, ?string $actorId, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puConvert($fields, $actorId), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-30: one transaction makes the draft order, one line per request line, the pointers and the converted request, with the next PO number and two audit rows', function () {
    $w = puConvWorld([['10.0000', '120.0000', '5.5000'], ['3.0000', '36.0000', null]]);
    $supplier = PurchasingSeed::supplier('CVS', ['name' => 'Convert Co', 'tax_no' => 'TIN-7', 'payment_terms_days' => 21, 'currency' => 'USD']);
    $before = PurchasingSeed::row('purchase_requests', $w['request']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puConvert(['request_id' => strtolower($w['request']), 'supplier_id' => strtolower($supplier)], strtolower($w['desk']['buyer']));

    expect($result->replayed)->toBeFalse()
        ->and($result->number)->toBe('PO000001')
        ->and($result->order)->toMatchArray([
            'id' => $result->orderId,
            'number' => 'PO000001',
            'status' => 'draft',
            'supplier_id' => $supplier,
            'supplier_name' => 'Convert Co',
            'supplier_tax_no' => 'TIN-7',
            'payment_terms_days' => 21,
            'branch_id' => $w['desk']['branch'],
            'location_id' => $w['desk']['location'],
            'request_id' => $w['request'],
            'order_date' => '2026-11-17',
            'expected_date' => '2026-12-10',
            'currency' => 'USD',
            'exchange_rate' => '1.00000000',
            'shipping' => '0.0000',
            'subtotal' => '55.0000',
            'discount_total' => '0.0000',
            'tax_total' => '5.5000',
            'total' => '60.5000',
            'received_value' => '0.0000',
            'created_by' => $w['desk']['buyer'],
            'workflow_instance_id' => null,
        ])
        ->and($result->lines)->toHaveCount(2)
        ->and($result->lines[0])->toMatchArray([
            'line_no' => 1,
            'product_id' => $w['product'],
            'description' => 'Product CV-c1',
            'unit_id' => $w['dozen'],
            'quantity' => '10.0000',
            'unit_factor' => '12.000000',
            'base_quantity' => '120.0000',
            'unit_cost' => '5.5000',
            'subtotal' => '55.0000',
            'discount_percent' => '0.0000',
            'discount_amount' => '0.0000',
            'tax_group_id' => $w['group'],
            'tax_amount' => '5.5000',
            'line_total' => '60.5000',
            'received_quantity' => '0.0000',
            'received_base_quantity' => '0.0000',
            'expected_date' => null,
        ])
        ->and($result->lines[1])->toMatchArray(['line_no' => 2, 'unit_cost' => '0.0000', 'subtotal' => '0.0000', 'line_total' => '0.0000', 'base_quantity' => '36.0000'])
        ->and($result->request)->toMatchArray(['status' => 'converted', 'updated_by' => $w['desk']['buyer'], 'updated_at' => '2026-11-17 10:00:00.000'])
        ->and(PurchasingSeed::row('purchase_requests', $w['request'])['status'])->toBe('converted')
        ->and($before['status'])->toBe('approved');

    foreach ($w['lines'] as $index => $requestLine) {
        expect(PurchasingSeed::row('purchase_request_items', $requestLine)['purchase_order_item_id'])->toBe($result->lines[$index]['id']);
    }

    $audit = PurchasingSeed::audit();

    expect($audit)->toHaveCount(2)
        ->and($audit[0])->toMatchArray([
            'action' => PurchasingAuditActions::REQUEST_CONVERTED,
            'subject_type' => 'purchase_request',
            'subject_id' => $w['request'],
            'branch_id' => $w['desk']['branch'],
            'actor_id' => $w['desk']['buyer'],
            'module' => PurchasingAuditActions::MODULE,
        ])
        ->and($audit[1])->toMatchArray([
            'action' => PurchasingAuditActions::ORDER_CREATED,
            'subject_type' => 'purchase_order',
            'subject_id' => $result->orderId,
            'branch_id' => $w['desk']['branch'],
            'before' => null,
        ]);
});

it('AC-30: a line that names the supplier and a line that names none are both accepted, and the currency falls back from the supplier to the branch', function () {
    $w = puConvWorld([['1.0000', '12.0000', '1.0000'], ['2.0000', '24.0000', '1.0000']]);
    PurchasingSeed::connection()->table('purchase_request_items')->where('id', $w['lines'][0])->update(['supplier_id' => $w['desk']['supplier']]);

    $result = puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']);

    expect($result->lines)->toHaveCount(2)
        ->and($result->order['currency'])->toBe('LKR');
});

it('AC-31: the stored factor survives a ladder that has since moved, and every line is re-quoted by the engine at the new order s date', function () {
    $w = puConvWorld();

    PurchasingSeed::connection()->table('units')->where('id', $w['dozen'])->update(['factor' => '24']);

    $result = puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']);

    expect($result->lines[0])->toMatchArray(['unit_factor' => '12.000000', 'base_quantity' => '120.0000', 'quantity' => '10.0000', 'line_total' => '60.5000']);
});

it('AC-31: a request line with no estimate becomes a line at 0.0000, and a product with no purchase group takes the shop default group', function () {
    $w = puConvWorld([['4.0000', '48.0000', null]]);
    $default = (string) PurchasingSeed::connection()->table('tax_groups')->where('is_default', true)->value('id');
    PurchasingSeed::connection()->table('products')->where('id', $w['product'])->update(['purchase_tax_group_id' => null]);

    $result = puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']);

    expect($result->lines[0])->toMatchArray(['unit_cost' => '0.0000', 'tax_group_id' => $default, 'line_total' => '0.0000']);
});

it('AC-32: a line naming another supplier is supplier_mismatch, and nothing is written', function () {
    $w = puConvWorld();
    PurchasingSeed::connection()->table('purchase_request_items')->where('id', $w['lines'][0])->update(['supplier_id' => PurchasingSeed::supplier('OTH')]);

    puConvertRefused(PurchasingRefusal::SupplierMismatch, ['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']);
});

it('AC-32: an unknown, deleted or inactive supplier is refused, and nothing is written', function (string $why, PurchasingRefusal $reason) {
    $w = puConvWorld();

    $supplier = match ($why) {
        'unknown' => (string) Str::ulid(),
        'deleted' => PurchasingSeed::supplier('DEL', ['deleted_at' => PurchasingSeed::NOW]),
        default => PurchasingSeed::supplier('OFF', ['is_active' => false]),
    };

    puConvertRefused($reason, ['request_id' => $w['request'], 'supplier_id' => $supplier], $w['desk']['buyer'], $why);
})->with([
    'unknown' => ['unknown', PurchasingRefusal::UnknownSupplier],
    'deleted' => ['deleted', PurchasingRefusal::UnknownSupplier],
    'inactive' => ['inactive', PurchasingRefusal::SupplierInactive],
]);

it('AC-32 and AC-37: a request that is not approved is invalid_transition naming the status, and nothing is written', function (string $status) {
    $w = puConvWorld([['1.0000', '12.0000', '1.0000']], $status);

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::InvalidTransition, fn () => puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']));

    expect($refused->getMessage())->toContain($status)
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'draft' => ['draft'],
    'pending_approval' => ['pending_approval'],
    'rejected' => ['rejected'],
    'cancelled' => ['cancelled'],
]);

it('AC-32: an approved request with no lines is no_lines, and nothing is written', function () {
    $w = puConvWorld([]);

    puConvertRefused(PurchasingRefusal::NoLines, ['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']);
});

it('AC-32: a resolver refusal at any line aborts the whole conversion, inherited_unit_mismatch and unit_unresolvable each carrying the resolver s reason, and nothing is written', function (UnitRefusal $cause, PurchasingRefusal $reason) {
    $w = puConvWorld([['1.0000', '12.0000', '1.0000'], ['2.0000', '24.0000', '1.0000']]);
    $real = app(UnitResolver::class);

    app()->instance(UnitResolver::class, new class($real, $cause) implements UnitResolver
    {
        private int $calls = 0;

        public function __construct(private readonly UnitResolver $real, private readonly UnitRefusal $cause) {}

        public function resolve(string $productId, string $fromUnitId): ResolvedFactor
        {
            return $this->real->resolve($productId, $fromUnitId);
        }

        public function snapshot(string $productId, string $unitId, int|string $quantity): UnitSnapshot
        {
            return $this->real->snapshot($productId, $unitId, $quantity);
        }

        public function snapshotInherited(string $productId, string $referencedUnitId, string $referencedUnitFactor, string $unitId, int|string $quantity): UnitSnapshot
        {
            // The second line is the one that fails: the first was quoted and must leave no trace.
            if (++$this->calls === 2) {
                throw match ($this->cause) {
                    UnitRefusal::InheritedUnitMismatch => UnitResolutionRefused::inheritedUnitMismatch('not this line s unit'),
                    default => UnitResolutionRefused::unknownUnit('no such unit'),
                };
            }

            return $this->real->snapshotInherited($productId, $referencedUnitId, $referencedUnitFactor, $unitId, $quantity);
        }
    });

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect($reason, fn () => puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']));

    expect($refused->cause)->toBe($cause)
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'inherited_unit_mismatch' => [UnitRefusal::InheritedUnitMismatch, PurchasingRefusal::InheritedUnitMismatch],
    'unit_unresolvable' => [UnitRefusal::UnknownUnit, PurchasingRefusal::UnitUnresolvable],
]);

it('AC-32: a pricing refusal is pricing_refused with the engine s reason, an overflowing line is out_of_range, and nothing is written', function () {
    $w = puConvWorld();

    app()->instance(PurchaseLinePricing::class, new class implements PurchaseLinePricing
    {
        public function quotePurchaseLine(PurchaseLineQuote $quote): PricedPurchaseLine
        {
            throw PricingRefused::noEffectiveRate('no rate on that day');
        }
    });

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::PricingRefused, fn () => puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']));

    expect($refused->cause)->toBe(PricingRefusal::NoEffectiveRate)
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-32 and AC-47: a line or a header total that cannot fit is out_of_range, and nothing is written', function () {
    $w = puConvWorld([['1.0000', '12.0000', '99999999999999.0000']]);

    puConvertRefused(PurchasingRefusal::OutOfRange, ['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer'], 'a line');

    $zero = puConvWorld([['1.0000', '12.0000', '50000000000000.0000'], ['1.0000', '12.0000', '50000000000000.0000']], 'approved', 'c2');
    PurchasingSeed::connection()->table('products')->where('id', $zero['product'])->update(['purchase_tax_group_id' => PurchasingSeed::taxGroup('CVZ', '0.0000')]);

    puConvertRefused(PurchasingRefusal::OutOfRange, ['request_id' => $zero['request'], 'supplier_id' => $zero['desk']['supplier']], $zero['desk']['buyer'], 'the header');
});

it('AC-32 and D-42: an inactive group is inactive_tax_group, an unknown one is unknown_tax_group, no default is no_default_tax_group, and nothing is written', function () {
    $w = puConvWorld();

    PurchasingSeed::connection()->table('tax_groups')->where('id', $w['group'])->update(['is_active' => false]);

    puConvertRefused(PurchasingRefusal::InactiveTaxGroup, ['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer'], 'inactive');

    PurchasingSeed::connection()->table('tax_groups')->where('id', $w['group'])->update(['is_active' => true]);

    app()->instance(TaxGroupDirectory::class, new class implements TaxGroupDirectory
    {
        public function state(string $taxGroupId, bool $lock = false): TaxGroupState
        {
            return TaxGroupState::Unknown;
        }

        public function defaultGroupId(bool $lock = false): ?string
        {
            return null;
        }
    });

    puConvertRefused(PurchasingRefusal::UnknownTaxGroup, ['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer'], 'unknown');

    $bare = puConvWorld([['1.0000', '12.0000', '1.0000']], 'approved', 'c2');
    PurchasingSeed::connection()->table('products')->where('id', $bare['product'])->update(['purchase_tax_group_id' => null]);

    puConvertRefused(PurchasingRefusal::NoDefaultTaxGroup, ['request_id' => $bare['request'], 'supplier_id' => $bare['desk']['supplier']], $bare['desk']['buyer'], 'no default');
});

it('AC-32 and D-39: a product that can no longer be bought is refused as a new line would be, and nothing is written', function (array $overrides, PurchasingRefusal $reason) {
    $w = puConvWorld();
    PurchasingSeed::connection()->table('products')->where('id', $w['product'])->update($overrides);

    puConvertRefused($reason, ['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']);
})->with([
    'inactive' => [['is_active' => false], PurchasingRefusal::UnknownProduct],
    'not purchasable' => [['is_purchasable' => false], PurchasingRefusal::ProductNotPurchasable],
]);

it('Q-B4: a supplier with no currency and a branch with a blank one is currency_required, and nothing is written', function () {
    $w = puConvWorld();
    AccessSeed::connection()->table('branches')->where('id', $w['desk']['branch'])->update(['currency' => '']);

    puConvertRefused(PurchasingRefusal::CurrencyRequired, ['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']);
});

it('AC-33: converting a converted request again for the same supplier returns the order and writes nothing, and cancelling the order leaves the request converted', function () {
    $w = puConvWorld();
    $first = puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']);
    $before = PurchasingRefusals::state();

    $again = puConvert(['request_id' => strtolower($w['request']), 'supplier_id' => strtolower($w['desk']['supplier'])], $w['desk']['buyer']);

    expect($again->replayed)->toBeTrue()
        ->and($again->orderId)->toBe($first->orderId)
        ->and($again->number)->toBe($first->number)
        ->and($again->order)->toBe($first->order)
        ->and($again->lines)->toBe($first->lines)
        ->and(PurchasingRefusals::state())->toBe($before);

    PurchasingSeed::connection()->table('purchase_orders')->where('id', $first->orderId)->update(['status' => 'cancelled']);

    $afterCancel = puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']);

    expect($afterCancel->replayed)->toBeTrue()
        ->and(PurchasingSeed::row('purchase_requests', $w['request'])['status'])->toBe('converted');
});

it('AC-33: a converted request for a different supplier is invalid_transition, and nothing is written', function () {
    $w = puConvWorld();
    puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']);

    puConvertRefused(PurchasingRefusal::InvalidTransition, ['request_id' => $w['request'], 'supplier_id' => PurchasingSeed::supplier('OTH')], $w['desk']['buyer']);
});

it('D-48: removing a converted line clears the request line s pointer and leaves the request converted', function () {
    $w = puConvWorld();
    $result = puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']);

    /** @var RemovePurchaseOrderLineHandler $remove */
    $remove = app(RemovePurchaseOrderLineHandler::class);
    $removed = $remove->handle(new RemovePurchaseOrderLine(['order_id' => $result->orderId, 'line_id' => $result->lines[0]['id']], $w['desk']['buyer']));

    expect($removed->clearedRequestLineIds)->toBe([$w['lines'][0]])
        ->and(PurchasingSeed::row('purchase_request_items', $w['lines'][0])['purchase_order_item_id'])->toBeNull()
        ->and(PurchasingSeed::row('purchase_requests', $w['request'])['status'])->toBe('converted')
        ->and($removed->order['total'])->toBe('0.0000');
});

it('AC-30 and AC-85: it needs both the request key and the order key at the request s branch, and a missing actor is validation', function (array $keys) {
    $w = puConvWorld();
    $actor = AccessSeed::actorHolding('limited', $keys, $w['desk']['branch']);

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $actor));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], null));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'the request key alone' => [[PurchasingPermissions::REQUESTS_MANAGE]],
    'the order key alone' => [[PurchasingPermissions::ORDERS_MANAGE]],
    'both view keys' => [[PurchasingPermissions::REQUESTS_VIEW, PurchasingPermissions::ORDERS_VIEW]],
    'no key' => [[]],
]);

it('AC-85: both keys scoped to another branch are refused, and both held company-wide are accepted', function () {
    $w = puConvWorld();
    $elsewhere = AccessSeed::actorHolding('elsewhere', [PurchasingPermissions::REQUESTS_MANAGE, PurchasingPermissions::ORDERS_MANAGE], PurchasingSeed::branch());
    $director = AccessSeed::actorHolding('director', [PurchasingPermissions::REQUESTS_MANAGE, PurchasingPermissions::ORDERS_MANAGE]);

    puConvertRefused(PurchasingRefusal::NotAuthorised, ['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $elsewhere);

    expect(puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $director)->order['created_by'])->toBe($director);
});

it('AC-86: a suspended employee is refused with the resolver s reason', function () {
    $w = puConvWorld();
    AccessSeed::connection()->table('employees')->where('id', $w['desk']['buyer'])->update(['status' => 'suspended']);

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']));

    expect($refused->cause)->toBe(RefusalReason::EmployeeUnavailable);
});

it('AC-91: an unknown request is not_found, a malformed or missing id is validation, and a field the operation does not take is validation', function () {
    $w = puConvWorld();

    puConvertRefused(PurchasingRefusal::NotFound, ['request_id' => (string) Str::ulid(), 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']);
    puConvertRefused(PurchasingRefusal::Validation, ['request_id' => 'nope', 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']);
    puConvertRefused(PurchasingRefusal::Validation, ['request_id' => $w['request'], 'supplier_id' => 'nope'], $w['desk']['buyer']);
    puConvertRefused(PurchasingRefusal::Validation, ['request_id' => $w['request']], $w['desk']['buyer']);
    puConvertRefused(PurchasingRefusal::Validation, ['supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']);
    puConvertRefused(PurchasingRefusal::Validation, ['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier'], 'number' => 'PO9'], $w['desk']['buyer']);
});

it('AC-52 and AC-90: a numbering refusal is numbering_refused and a failure after the number rolls everything back, the request still approved', function () {
    $w = puConvWorld();

    app()->instance(AuditTrail::class, new class implements AuditTrail
    {
        public function record(string $module, ?string $actorId, string $action, string $subjectType, string $subjectId, ?array $before, ?array $after, ?string $branchId = null): void
        {
            if ($action === PurchasingAuditActions::ORDER_CREATED) {
                throw new RuntimeException('the audit trail is down');
            }
        }
    });

    $before = PurchasingRefusals::state();

    expect(fn () => puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']))->toThrow(RuntimeException::class, 'the audit trail is down')
        ->and(PurchasingRefusals::state())->toBe($before)
        ->and(PurchasingSeed::row('purchase_requests', $w['request'])['status'])->toBe('approved');
});

it('AC-52: a unique violation on the number is numbering_refused, never an unhandled error, and writes nothing', function () {
    $w = puConvWorld();
    $taken = PurchasingSeed::order($w['desk']['supplier'], $w['desk']['branch'], $w['desk']['location']);
    $takenNumber = (string) PurchasingSeed::row('purchase_orders', $taken)['number'];

    app()->instance(DocumentNumbers::class, new class($takenNumber) implements DocumentNumbers
    {
        public function __construct(private readonly string $number) {}

        public function next(string $documentType, ?string $branchId): string
        {
            return $this->number;
        }
    });

    puConvertRefused(PurchasingRefusal::NumberingRefused, ['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']);
});

it('Ruling 7: the request header is the first row locked, the supplier is share-locked after it, and the number sequence is taken last, before the first insert', function () {
    $w = puConvWorld();

    $log = PurchasingQueryLog::duringLocked(fn () => puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']));
    $inside = PurchasingQueryLog::inTransaction($log);

    $supplier = array_values(array_filter($inside, static fn (string $sql): bool => str_contains($sql, '"suppliers"')));
    $sequence = array_values(array_filter($inside, static fn (string $sql): bool => str_contains($sql, '"number_sequences"')));
    $insert = array_values(array_filter($inside, static fn (string $sql): bool => str_starts_with($sql, 'insert into "purchase_orders"')));

    expect($inside[0])->toContain('"purchase_requests"')->and($inside[0])->toContain('/* for update */')
        ->and($supplier)->not->toBe([])
        ->and($supplier[0])->toContain('/* lock in share mode */')
        ->and($sequence)->not->toBe([])
        ->and($insert)->toHaveCount(1)
        ->and(array_search($inside[0], $inside, true))->toBeLessThan(array_search($supplier[0], $inside, true))
        ->and(array_search($supplier[0], $inside, true))->toBeLessThan(array_search($sequence[0], $inside, true))
        ->and(array_search($sequence[0], $inside, true))->toBeLessThan(array_search($insert[0], $inside, true));
});

it('Ruling 3: one deadlock on the number is retried from the top and the order is made once, a second deadlock surfaces with nothing written, and another error is not retried', function () {
    $w = puConvWorld();

    $numbers = DeadlockingNumbers::wrap(function (int $call): void {
        if ($call === 1) {
            throw DeadlockingNumbers::deadlock();
        }
    });

    $result = puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']);

    expect($numbers->calls)->toBe(2)
        ->and($result->number)->toBe('PO000001')
        ->and(PurchasingSeed::connection()->table('purchase_orders')->count())->toBe(1)
        ->and(PurchasingSeed::audit())->toHaveCount(2);

    $again = puConvWorld([['10.0000', '120.0000', '5.5000']], 'approved', 'c2');
    $always = DeadlockingNumbers::wrap(function (): void {
        throw DeadlockingNumbers::deadlock();
    });
    $before = PurchasingRefusals::state();

    expect(fn () => puConvert(['request_id' => $again['request'], 'supplier_id' => $again['desk']['supplier']], $again['desk']['buyer']))->toThrow(QueryException::class)
        ->and($always->calls)->toBe(2)
        ->and(PurchasingRefusals::state())->toBe($before);

    $broken = DeadlockingNumbers::wrap(function (): void {
        throw new RuntimeException('the sequence table is gone');
    });

    expect(fn () => puConvert(['request_id' => $again['request'], 'supplier_id' => $again['desk']['supplier']], $again['desk']['buyer']))->toThrow(RuntimeException::class, 'the sequence table is gone')
        ->and($broken->calls)->toBe(1);
});

it('AC-94: converts a 50-line request in one transaction, each product read once, and records the measured query count', function () {
    $lines = [];

    for ($i = 0; $i < 50; $i++) {
        $lines[] = ['1.0000', '12.0000', '2.0000'];
    }

    $w = puConvWorld($lines);

    $log = PurchasingQueryLog::during(fn () => puConvert(['request_id' => $w['request'], 'supplier_id' => $w['desk']['supplier']], $w['desk']['buyer']));

    $products = array_values(array_filter($log, static fn (string $sql): bool => str_contains($sql, 'from "products"')));
    $quoted = array_values(array_filter($log, static fn (string $sql): bool => str_contains($sql, 'from "products"') && str_contains($sql, '"stock_unit_id"')));

    fwrite(STDOUT, "\nAC-94 query count, ConvertRequestToOrder of 50 lines: ".count($log).' (product reads: '.count($products).', resolver product reads: '.count($quoted).")\n");

    expect(PurchasingSeed::connection()->table('purchase_order_items')->count())->toBe(50)
        ->and(count($quoted))->toBe(50)
        ->and(count($products))->toBe(51)
        ->and(count($log))->toBeLessThanOrEqual(560);
});
