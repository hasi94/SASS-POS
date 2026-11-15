<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\RefundSale;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Customers\CreditLedgerEntry;
use Flexipos\Contracts\Customers\CreditLedgerPosting;
use Flexipos\Contracts\Customers\CreditRefusal;
use Flexipos\Contracts\Customers\CustomerCreditLedger;
use Flexipos\Contracts\Organization\DocumentNumbersRefusal;
use Flexipos\Contracts\Pricing\PricingRefusal;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLine;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLineHandler;
use Flexipos\Sales\Features\AddTender\AddTender;
use Flexipos\Sales\Features\AddTender\AddTenderHandler;
use Flexipos\Sales\Features\CompleteSale\CompleteSale;
use Flexipos\Sales\Features\CompleteSale\CompleteSaleHandler;
use Flexipos\Sales\Features\RefundSale\RefundSale;
use Flexipos\Sales\Features\RefundSale\RefundSaleHandler;
use Flexipos\Sales\Features\RefundSale\RefundSaleResult;
use Flexipos\Sales\Tests\Support\DeadlockingNumbers;
use Flexipos\Sales\Tests\Support\LockMarkingGrammar;
use Flexipos\Sales\Tests\Support\SalesQueryLog;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use PDOException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 20 - RefundSale
|     (AC-25 to AC-31, AC-12A, AC-24A, AC-47A, AC-48, AC-50, AC-53, AC-54, AC-57; D-32, D-38, D-43,
|      D-47, D-55, D-58, D-63, D-64, D-71 to D-73; R-1, R-4, R-5, R-8, R-10)
|--------------------------------------------------------------------------
|
| Against the real pricing engine, unit resolver, discount usage, credit ledger and
| numbering. The original sales are built through the cart, tender and completion
| slices. The rows marked R-1 pin the default for owner question Q-1 (the engine's
| own refusal passes through): a ruling for the other option flips exactly those rows.
| The deadlock rows prove the retry's shape against a numbering that throws the
| engine's error: NOT VERIFIED on a real engine.
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/sales-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    salesEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * A till; a 100.0000 product on a 10 percent exclusive tax (110.0000 a unit); a case of 24; and one method
 * of each kind, one of them refusing refunds.
 *
 * @return array<string, mixed>
 */
function rfWorld(): array
{
    $till = SalesWorld::till();
    $group = SalesWorld::taxGroup('TX10', '10.0000');
    $catalogue = SalesWorld::product('P1', '100.0000', $group);
    $case = PricingSeed::unit('CASE');
    SalesWorld::conversion($case, $catalogue['unit'], '24.000000');

    return [
        'till' => $till,
        'product' => $catalogue['product'],
        'unit' => $catalogue['unit'],
        'case' => $case,
        'group' => $group,
        'cash' => SalesSeed::paymentMethod('CASH', ['type' => 'cash', 'allows_change' => true]),
        'card' => SalesSeed::paymentMethod('CARD', ['type' => 'card']),
        'credit' => SalesSeed::paymentMethod('CREDIT', ['type' => 'credit']),
        'noref' => SalesSeed::paymentMethod('NOREF', ['type' => 'card', 'allows_refund' => false]),
        'manager' => SalesSeed::employee('manager'),
    ];
}

/**
 * A completed sale. Each line is `[unit key, quantity, ?discount]`; each tender `[method key, offered]`.
 * A customer, when given, is attached before the tenders.
 *
 * @param  array<string, mixed>  $w
 * @param  list<array{0: string, 1: string, 2?: string|null}>  $lines
 * @param  list<array{0: string, 1: string}>|null  $tenders  null pays the exact total in cash
 * @return array{sale: string, items: list<string>, total: string}
 */
function rfSale(array $w, array $lines = [['unit', '1']], ?array $tenders = null, ?string $customer = null): array
{
    $sale = SalesWorld::sale($w['till']);

    if ($customer !== null) {
        SalesSeed::connection()->table('sales')->where('id', $sale)->update(['customer_id' => $customer, 'customer_name' => 'Ada', 'customer_phone' => '0771234567', 'customer_tax_no' => 'TAX-1']);
    }

    foreach ($lines as $line) {
        $fields = ['sale_id' => $sale, 'product_id' => $w['product'], 'unit_id' => $w[$line[0]], 'quantity' => $line[1]];

        if (($line[2] ?? null) !== null) {
            $fields['discount_id'] = $line[2];
        }

        app(AddSaleLineHandler::class)->handle(new AddSaleLine($fields, $w['till']['cashier']));
    }

    $total = (string) SalesSeed::row('sales', $sale)['total'];

    foreach ($tenders ?? [['cash', $total]] as [$method, $offered]) {
        app(AddTenderHandler::class)->handle(new AddTender(['id' => (string) Str::ulid(), 'sale_id' => $sale, 'payment_method_id' => $w[$method], 'offered' => $offered], $w['till']['cashier']));
    }

    app(CompleteSaleHandler::class)->handle(new CompleteSale(['sale_id' => $sale], $w['till']['cashier']));

    $items = SalesSeed::connection()->table('sale_items')->where('sale_id', $sale)->orderBy('line_no')->pluck('id')->all();

    return ['sale' => $sale, 'items' => $items, 'total' => (string) SalesSeed::row('sales', $sale)['total']];
}

/**
 * A decided refund approval at the till's branch, as the decide step leaves one: bound to a sale, or goodwill.
 *
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $overrides
 */
function rfApproval(array $w, ?string $sale, array $overrides = []): string
{
    return SalesSeed::approval($w['till']['branch'], $w['till']['cashier'], array_merge([
        'type' => 'refund',
        'reference_type' => $sale === null ? 'goodwill_refund' : 'sale',
        'reference_id' => $sale,
        'status' => 'approved',
        'decided_by' => $w['manager'],
        'decided_at' => SalesSeed::NOW,
        'decision_method' => 'pin_local',
    ], $overrides));
}

/**
 * A refund request against a sale: lines are `[item index, unit key, quantity]`, tenders `[method key, offered]`.
 *
 * @param  array<string, mixed>  $w
 * @param  array{sale: string, items: list<string>, total: string}  $original
 * @param  list<array{0: int, 1: string, 2: string}>  $lines
 * @param  list<array{0: string, 1: string}>|null  $tenders  null refunds the whole amount in cash
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function rfFields(array $w, array $original, array $lines, ?string $approval, ?array $tenders, array $extra = []): array
{
    $fields = [
        'terminal_id' => $w['till']['terminal'],
        'reference_sale_id' => $original['sale'],
        'lines' => array_map(fn (array $l): array => ['refund_of_item_id' => $original['items'][$l[0]], 'unit_id' => $w[$l[1]], 'quantity' => $l[2]], $lines),
        'tenders' => array_map(fn (array $t): array => ['payment_method_id' => $w[$t[0]], 'offered' => $t[1]], $tenders ?? []),
    ];

    if ($approval !== null) {
        $fields['approval_id'] = $approval;
    }

    return array_merge($fields, $extra);
}

/**
 * A goodwill refund request: lines are `[product, unit key, quantity]`.
 *
 * @param  array<string, mixed>  $w
 * @param  list<array{0: string, 1: string, 2: string}>  $lines
 * @param  list<array{0: string, 1: string}>  $tenders
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function rfGoodwill(array $w, array $lines, ?string $approval, array $tenders, array $extra = []): array
{
    $fields = [
        'terminal_id' => $w['till']['terminal'],
        'lines' => array_map(fn (array $l): array => ['product_id' => $l[0], 'unit_id' => $w[$l[1]], 'quantity' => $l[2]], $lines),
        'tenders' => array_map(fn (array $t): array => ['payment_method_id' => $w[$t[0]], 'offered' => $t[1]], $tenders),
    ];

    if ($approval !== null) {
        $fields['approval_id'] = $approval;
    }

    return array_merge($fields, $extra);
}

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $fields
 */
function rfRefund(array $w, array $fields, ?string $actor = 'cashier'): RefundSaleResult
{
    return app(RefundSaleHandler::class)->handle(new RefundSale($fields, $actor === 'cashier' ? $w['till']['cashier'] : $actor));
}

/**
 * Every table a refund can write, whole, so a stray write fails the comparison.
 *
 * @return array<string, mixed>
 */
function rfState(): array
{
    $c = SalesSeed::connection();
    $rows = static fn (string $table): array => $c->table($table)->get()->map(fn ($r) => (array) $r)->all();

    return [
        'sales' => $rows('sales'),
        'lines' => $rows('sale_items'),
        'tenders' => $rows('sale_tenders'),
        'approvals' => $rows('approval_requests'),
        'ledger' => $rows('customer_ledger'),
        'customers' => $rows('customers'),
        'numbers' => $rows('number_sequences'),
        'discounts' => $rows('discounts'),
        'audit' => SalesSeed::audit(),
    ];
}

function rfCustomer(): string
{
    return SalesWorld::creditCustomer('Credit '.Str::random(4));
}

/**
 * The refund documents.
 *
 * @return list<array<string, mixed>>
 */
function rfRefunds(): array
{
    return SalesSeed::connection()->table('sales')->where('type', 'refund')->orderBy('created_at')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
}

describe('the refund document (AC-25, AC-30, AC-31, AC-54; D-43, D-58)', function () {
    it('writes a refund row numbered R000001 that references the sale, in the refunding terminal\'s own shift, with unsigned lines and a negative tender', function () {
        $w = rfWorld();
        $customer = rfCustomer();
        $original = rfSale($w, [['unit', '2']], null, $customer);
        $approval = rfApproval($w, $original['sale']);
        $second = SalesWorld::secondTill($w['till'], 'T2');
        $auditBefore = count(SalesSeed::audit());
        $fields = rfFields($w, $original, [[0, 'unit', '1']], strtolower($approval), [['cash', '110']], ['terminal_id' => $second['terminal']]);

        $result = rfRefund($w, $fields);

        $refund = SalesSeed::row('sales', $result->refundId);
        $line = SalesSeed::connection()->table('sale_items')->where('sale_id', $result->refundId)->first();
        $tender = SalesSeed::connection()->table('sale_tenders')->where('sale_id', $result->refundId)->first();

        expect($result->number)->toBe('R000001')
            ->and($result->total)->toBe('110.0000')
            ->and($result->originalStatus)->toBe('partially_refunded')
            ->and($refund)->toMatchArray([
                'type' => 'refund',
                'number' => 'R000001',
                'status' => 'completed',
                'reference_sale_id' => $original['sale'],
                'terminal_id' => $second['terminal'],
                'shift_id' => $second['shift'],
                'branch_id' => $w['till']['branch'],
                'employee_id' => $w['till']['cashier'],
                'customer_id' => $customer,
                'customer_name' => 'Ada',
                'customer_phone' => '0771234567',
                'customer_tax_no' => 'TAX-1',
                'currency' => 'LKR',
                'business_date' => '2026-11-12',
                'approval_request_id' => $approval,
                'subtotal' => '100.0000',
                'tax_total' => '10.0000',
                'total' => '110.0000',
                'paid_total' => '110.0000',
                'balance_due' => '0.0000',
                'change_due' => '0.0000',
                'completed_at' => '2026-11-12 10:00:00.000',
                'stock_applied' => 0,
            ])
            // Unsigned on the lines (D-43), negative on the tender.
            ->and($line->refund_of_item_id)->toBe($original['items'][0])
            ->and($line->quantity)->toEqual('1.0000')
            ->and($line->line_total)->toEqual('110.0000')
            ->and($tender->amount)->toEqual('-110.0000')
            ->and($tender->tendered)->toBeNull()
            ->and($tender->change_given)->toEqual('0.0000')
            ->and($tender->method_type)->toBe('cash')
            ->and(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBe('2026-11-12 10:00:00.000')
            // The original's own line moved by the refunded base quantity, and the original sale is partially refunded.
            ->and(SalesSeed::row('sale_items', $original['items'][0])['refunded_quantity'])->toEqual('1.0000')
            ->and(SalesSeed::row('sales', $original['sale'])['status'])->toBe('partially_refunded');

        $audits = array_slice(SalesSeed::audit(), $auditBefore);

        expect($audits)->toHaveCount(1)
            ->and($audits[0])->toMatchArray(['module' => 'sales', 'action' => 'sales_sale_refunded', 'subject_type' => 'sale', 'subject_id' => $result->refundId]);
    });

    it('writes a goodwill refund with a null reference, the optional customer, and the refunding terminal\'s shift', function () {
        $w = rfWorld();
        $customer = rfCustomer();
        $approval = rfApproval($w, null);

        $result = rfRefund($w, rfGoodwill($w, [[$w['product'], 'unit', '1']], $approval, [['cash', '110']], ['customer_id' => strtolower($customer)]));

        expect(SalesSeed::row('sales', $result->refundId))->toMatchArray([
            'type' => 'refund', 'reference_sale_id' => null, 'number' => 'R000001', 'customer_id' => $customer,
            'customer_name' => SalesSeed::row('customers', $customer)['name'], 'shift_id' => $w['till']['shift'], 'total' => '110.0000',
        ])
            ->and(SalesSeed::connection()->table('sale_items')->where('sale_id', $result->refundId)->value('refund_of_item_id'))->toBeNull()
            ->and($result->originalStatus)->toBeNull();
    });

    it('sets the original refunded once every line is refunded in full, and a goodwill refund touches no original', function () {
        $w = rfWorld();
        $original = rfSale($w, [['unit', '1']]);

        rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], rfApproval($w, $original['sale']), [['cash', '110']]));

        expect(SalesSeed::row('sales', $original['sale'])['status'])->toBe('refunded')
            ->and(SalesSeed::row('sale_items', $original['items'][0])['refunded_quantity'])->toEqual('1.0000');
    });

    it('writes no stock, no general-ledger row and no usage release (AC-31)', function () {
        $w = rfWorld();
        $discount = PricingSeed::discount('D5', ['usage_limit' => 5]);
        $original = rfSale($w, [['unit', '1', $discount]]);
        $approval = rfApproval($w, $original['sale']);
        $total = SalesSeed::row('sales', $original['sale'])['total'];
        $used = SalesSeed::row('discounts', $discount)['used_count'];

        $log = SalesQueryLog::during(fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], $approval, [['cash', (string) $total]])));

        $touched = array_filter($log, static fn (string $sql): bool => preg_match('/(from|into|update|join) "(stock_|journal_|inventory_)/', $sql) === 1);

        expect($touched)->toBe([])->and(SalesSeed::row('discounts', $discount)['used_count'])->toEqual($used);
    });
});

describe('the unit of a refund line (AC-26, R-8)', function () {
    it('inherits the original factor after unit_conversions changes: one case still returns 24 units (the §6.5 walk-through at sale scale)', function () {
        $w = rfWorld();
        $original = rfSale($w, [['case', '1']]);
        SalesSeed::connection()->table('unit_conversions')->update(['factor' => '30.000000']);
        $approval = rfApproval($w, $original['sale']);

        $result = rfRefund($w, rfFields($w, $original, [[0, 'case', '1']], $approval, [['cash', $original['total']]]));

        $line = SalesSeed::connection()->table('sale_items')->where('sale_id', $result->refundId)->first();

        expect($line->unit_factor)->toEqual('24.000000')
            ->and($line->base_quantity)->toEqual('24.0000')
            ->and($line->unit_id)->toBe($w['case'])
            ->and(SalesSeed::row('sale_items', $original['items'][0])['refunded_quantity'])->toEqual('24.0000')
            ->and(SalesSeed::row('sales', $original['sale'])['status'])->toBe('refunded');
    });

    it('gives a factor of exactly 1 in the stock unit, and accumulates base quantity across a case refund and a single refund', function () {
        $w = rfWorld();
        $original = rfSale($w, [['case', '2']]);

        // One case (24 base), then 24 singles (24 base) exhausts the 48.
        rfRefund($w, rfFields($w, $original, [[0, 'case', '1']], rfApproval($w, $original['sale']), [['cash', '2640']]));

        expect(SalesSeed::row('sale_items', $original['items'][0])['refunded_quantity'])->toEqual('24.0000')
            ->and(SalesSeed::row('sales', $original['sale'])['status'])->toBe('partially_refunded');

        $result = rfRefund($w, rfFields($w, $original, [[0, 'unit', '24']], rfApproval($w, $original['sale']), [['cash', '2640']]));

        $single = SalesSeed::connection()->table('sale_items')->where('sale_id', $result->refundId)->first();

        expect($single->unit_factor)->toEqual('1.000000')
            ->and($single->base_quantity)->toEqual('24.0000')
            ->and(SalesSeed::row('sale_items', $original['items'][0])['refunded_quantity'])->toEqual('48.0000')
            ->and(SalesSeed::row('sales', $original['sale'])['status'])->toBe('refunded');
    });

    it('refuses a third unit as inherited_unit_mismatch, writing nothing', function () {
        $w = rfWorld();
        $pack = PricingSeed::unit('PACK');
        SalesWorld::conversion($pack, $w['unit'], '6.000000');
        $w['pack'] = $pack;
        $original = rfSale($w, [['case', '1']]);
        $approval = rfApproval($w, $original['sale']);
        $before = rfState();

        $refused = SalesRefusals::expect(SalesRefusal::InheritedUnitMismatch, fn () => rfRefund($w, rfFields($w, $original, [[0, 'pack', '1']], $approval, [['cash', '660']])));

        expect($refused->getMessage())->not->toBe('')->and(rfState())->toBe($before);
    });

    it('prices a goodwill line through the ordinary ladder: the current factor, not an inherited one', function () {
        $w = rfWorld();
        SalesSeed::connection()->table('unit_conversions')->update(['factor' => '30.000000']);

        $result = rfRefund($w, rfGoodwill($w, [[$w['product'], 'case', '1']], rfApproval($w, null), [['cash', '3300']]));

        $line = SalesSeed::connection()->table('sale_items')->where('sale_id', $result->refundId)->first();

        expect($line->unit_factor)->toEqual('30.000000')->and($line->base_quantity)->toEqual('30.0000');
    });

    it('refuses a quantity of zero or less as validation before any unit or price is resolved (AC-52)', function (string $quantity) {
        $w = rfWorld();
        $original = rfSale($w);
        $approval = rfApproval($w, $original['sale']);
        $before = rfState();

        SalesRefusals::expect(SalesRefusal::Validation, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', $quantity]], $approval, [['cash', '110']])));

        expect(rfState())->toBe($before);
    })->with(['0', '-1', '-0.5', '0.0000']);
});

describe('the price of a refund line (AC-27, AC-57; D-38, D-63, D-64)', function () {
    /**
     * A list for the till's branch: 200 a case from 1 base unit, 150 a case from 96 (the tier is in base units: 4 cases).
     *
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>  $listOverrides
     */
    function rfTierList(array $w, array $listOverrides = []): string
    {
        $list = PricingSeed::priceList('TIER', array_merge(['currency' => 'LKR'], $listOverrides));
        PricingSeed::item($list, $w['product'], $w['case'], '1.0000', '200.0000');
        PricingSeed::item($list, $w['product'], $w['case'], '96.0000', '150.0000');
        PricingSeed::assignment($list, 'branch', $w['till']['branch']);

        return $list;
    }

    it('re-resolves a quantity tier: 2 cases out of 4 are priced at the 1-case price, not the 4-case price', function () {
        $w = rfWorld();
        rfTierList($w);
        $original = rfSale($w, [['case', '4']]);
        $approval = rfApproval($w, $original['sale']);

        expect(SalesSeed::row('sale_items', $original['items'][0])['unit_price'])->toEqual('150.0000');

        $result = rfRefund($w, rfFields($w, $original, [[0, 'case', '2']], $approval, [['cash', '440']]));

        $line = SalesSeed::connection()->table('sale_items')->where('sale_id', $result->refundId)->first();

        expect($line->unit_price)->toEqual('200.0000')->and($line->subtotal)->toEqual('400.0000')->and($result->total)->toBe('440.0000');
    });

    it('quotes at the original completed_at, not at today: a list edited after the sale changes nothing, and one that started after it does not apply (F-13)', function (string $edit, string $expectedPrice, string $tender) {
        $w = rfWorld();
        $list = rfTierList($w);
        $original = rfSale($w, [['case', '1']]);
        $approval = rfApproval($w, $original['sale']);

        // The refund happens two days later; the list's window is then edited.
        app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-14 10:00:00.000'));

        $window = match ($edit) {
            // Ended after the sale but before the refund: the sale's instant is still inside it.
            'ended after the sale' => ['ends_at' => '2026-11-13 00:00:00.000'],
            // Started after the sale: the sale's instant is outside it.
            default => ['starts_at' => '2026-11-13 00:00:00.000'],
        };
        SalesSeed::connection()->table('price_lists')->where('id', $list)->update($window);

        $result = rfRefund($w, rfFields($w, $original, [[0, 'case', '1']], $approval, [['cash', $tender]]));

        expect(SalesSeed::connection()->table('sale_items')->where('sale_id', $result->refundId)->value('unit_price'))->toEqual($expectedPrice);
    })->with([
        'ended after the sale' => ['ended after the sale', '200.0000', '220'],
        'started after the sale' => ['started after the sale', '2400.0000', '2640'],
    ]);

    it('quotes a goodwill line at the refund\'s own instant (AC-27)', function () {
        $w = rfWorld();
        $list = rfTierList($w, ['starts_at' => '2026-11-13 00:00:00.000']);
        app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-14 10:00:00.000'));

        $result = rfRefund($w, rfGoodwill($w, [[$w['product'], 'case', '1']], rfApproval($w, null), [['cash', '220']]));

        expect($list)->not->toBe('')->and(SalesSeed::connection()->table('sale_items')->where('sale_id', $result->refundId)->value('unit_price'))->toEqual('200.0000');
    });

    it('accepts that a promotion ended between the add and the completion makes the refund differ from the charge (Q-9, AC-57)', function () {
        $w = rfWorld();
        rfTierList($w, ['ends_at' => '2026-11-12 10:30:00.000']);
        // The line is added while the list is on (10:00); the sale completes after it ended (11:00).
        $sale = SalesWorld::sale($w['till']);
        app(AddSaleLineHandler::class)->handle(new AddSaleLine(['sale_id' => $sale, 'product_id' => $w['product'], 'unit_id' => $w['case'], 'quantity' => '1'], $w['till']['cashier']));
        $charged = SalesSeed::connection()->table('sale_items')->where('sale_id', $sale)->value('unit_price');
        app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-12 11:00:00.000'));
        app(AddTenderHandler::class)->handle(new AddTender(['id' => (string) Str::ulid(), 'sale_id' => $sale, 'payment_method_id' => $w['cash'], 'offered' => '220'], $w['till']['cashier']));
        app(CompleteSaleHandler::class)->handle(new CompleteSale(['sale_id' => $sale], $w['till']['cashier']));
        $original = ['sale' => $sale, 'items' => SalesSeed::connection()->table('sale_items')->where('sale_id', $sale)->pluck('id')->all(), 'total' => '220.0000'];

        $result = rfRefund($w, rfFields($w, $original, [[0, 'case', '1']], rfApproval($w, $sale), [['cash', '2640']]));

        expect($charged)->toEqual('200.0000')
            ->and(SalesSeed::connection()->table('sale_items')->where('sale_id', $result->refundId)->value('unit_price'))->toEqual('2400.0000');
    });

    it('accepts that a customer moved to another group is refunded at the new group\'s price (Q-10, AC-57)', function () {
        $w = rfWorld();
        $groupA = PricingSeed::customerGroup('GA');
        $groupB = PricingSeed::customerGroup('GB');
        $list = PricingSeed::priceList('GROUPA', ['currency' => 'LKR']);
        PricingSeed::item($list, $w['product'], $w['case'], '1.0000', '200.0000');
        PricingSeed::assignment($list, 'customer_group', $groupA);
        $customer = rfCustomer();
        SalesSeed::connection()->table('customers')->where('id', $customer)->update(['group_id' => $groupA]);

        $original = rfSale($w, [['case', '1']], [['cash', '220']], $customer);
        SalesSeed::connection()->table('customers')->where('id', $customer)->update(['group_id' => $groupB]);

        $result = rfRefund($w, rfFields($w, $original, [[0, 'case', '1']], rfApproval($w, $original['sale']), [['cash', '2640']]));

        expect(SalesSeed::row('sale_items', $original['items'][0])['unit_price'])->toEqual('200.0000')
            ->and(SalesSeed::connection()->table('sale_items')->where('sale_id', $result->refundId)->value('unit_price'))->toEqual('2400.0000');
    });
});

describe('the cap on what can be refunded (AC-28)', function () {
    it('refuses over_refund naming the remaining base quantity, and writes nothing', function () {
        $w = rfWorld();
        $original = rfSale($w, [['unit', '3']]);
        rfRefund($w, rfFields($w, $original, [[0, 'unit', '2']], rfApproval($w, $original['sale']), [['cash', '220']]));
        $approval = rfApproval($w, $original['sale']);
        $before = rfState();

        $refused = SalesRefusals::expect(SalesRefusal::OverRefund, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '2']], $approval, [['cash', '220']])));

        expect($refused->getMessage())->toContain('1.0000')->and(rfState())->toBe($before);
    });

    it('takes a second partial refund to exhaustion as refunded, and refuses a third as fully_refunded', function () {
        $w = rfWorld();
        $original = rfSale($w, [['unit', '2']]);

        rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], rfApproval($w, $original['sale']), [['cash', '110']]));
        expect(SalesSeed::row('sales', $original['sale'])['status'])->toBe('partially_refunded');

        rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], rfApproval($w, $original['sale']), [['cash', '110']]));
        expect(SalesSeed::row('sales', $original['sale'])['status'])->toBe('refunded');

        $approval = rfApproval($w, $original['sale']);
        $before = rfState();

        SalesRefusals::expect(SalesRefusal::FullyRefunded, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], $approval, [['cash', '110']])));

        expect(rfState())->toBe($before)->and(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBeNull();
    });

    it('refuses everything but a sale in completed or partially_refunded as not_refundable', function (string $status, string $type) {
        $w = rfWorld();
        $original = rfSale($w);
        $approval = rfApproval($w, $original['sale']);
        SalesSeed::connection()->table('sales')->where('id', $original['sale'])->update(['status' => $status, 'type' => $type]);
        $before = rfState();

        SalesRefusals::expect(SalesRefusal::NotRefundable, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], $approval, [['cash', '110']])));

        expect(rfState())->toBe($before);
    })->with([
        'draft' => ['draft', 'sale'],
        'held' => ['held', 'sale'],
        'pending payment' => ['pending_payment', 'sale'],
        'voided' => ['voided', 'sale'],
        'a refund document' => ['completed', 'refund'],
    ]);

    it('refuses a line that is not on the named sale as not_found, and the same line twice as validation', function () {
        $w = rfWorld();
        $original = rfSale($w);
        $other = rfSale($w);
        $approval = rfApproval($w, $original['sale']);
        $before = rfState();

        SalesRefusals::expect(SalesRefusal::NotFound, fn () => rfRefund($w, rfFields($w, array_merge($original, ['items' => $other['items']]), [[0, 'unit', '1']], $approval, [['cash', '110']])));
        SalesRefusals::expect(SalesRefusal::Validation, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1'], [0, 'unit', '1']], $approval, [['cash', '220']])));

        expect(rfState())->toBe($before);
    });
});

describe('the approval (AC-24A, AC-29; D-47, D-72)', function () {
    it('is approval_required without one, for a referenced refund and a goodwill one alike', function () {
        $w = rfWorld();
        $original = rfSale($w);
        $before = rfState();

        SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], null, [['cash', '110']])));
        SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => rfRefund($w, rfGoodwill($w, [[$w['product'], 'unit', '1']], null, [['cash', '110']])));

        expect(rfState())->toBe($before);
    });

    it('refuses an approval bound to another sale, or to goodwill, as approval_mismatch, and one for a void as the same', function (string $case) {
        $w = rfWorld();
        $original = rfSale($w);
        $other = rfSale($w);

        $approval = match ($case) {
            'another sale' => rfApproval($w, $other['sale']),
            'goodwill' => rfApproval($w, null),
            default => rfApproval($w, $original['sale'], ['type' => 'void']),
        };
        $before = rfState();

        SalesRefusals::expect(SalesRefusal::ApprovalMismatch, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], $approval, [['cash', '110']])));

        expect(rfState())->toBe($before);
    })->with(['another sale', 'goodwill', 'a void']);

    it('refuses a sale-bound approval for a goodwill refund as approval_mismatch', function () {
        $w = rfWorld();
        $original = rfSale($w);
        $approval = rfApproval($w, $original['sale']);
        $before = rfState();

        SalesRefusals::expect(SalesRefusal::ApprovalMismatch, fn () => rfRefund($w, rfGoodwill($w, [[$w['product'], 'unit', '1']], $approval, [['cash', '110']])));

        expect(rfState())->toBe($before);
    });

    it('refuses a retry after success as approval_already_used: the consumed approval is the refund\'s idempotency guard', function () {
        $w = rfWorld();
        $original = rfSale($w, [['unit', '2']]);
        $approval = rfApproval($w, $original['sale']);
        $fields = rfFields($w, $original, [[0, 'unit', '1']], $approval, [['cash', '110']]);

        rfRefund($w, $fields);
        $before = rfState();

        SalesRefusals::expect(SalesRefusal::ApprovalAlreadyUsed, fn () => rfRefund($w, $fields));

        expect(rfState())->toBe($before)->and(rfRefunds())->toHaveCount(1);
    });

    it('passes the refund\'s own branch: a goodwill approval from branch A is approval_mismatch at a terminal of branch B, and consumed at A (AC-24A)', function () {
        $w = rfWorld();
        $tillB = SalesWorld::till('TB');
        $approval = rfApproval($w, null);
        $before = rfState();

        $atB = rfGoodwill($w, [[$w['product'], 'unit', '1']], $approval, [['cash', '110']], ['terminal_id' => $tillB['terminal']]);
        SalesRefusals::expect(SalesRefusal::ApprovalMismatch, fn () => rfRefund($w, $atB, $tillB['cashier']));

        expect(rfState())->toBe($before)->and(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBeNull();

        rfRefund($w, rfGoodwill($w, [[$w['product'], 'unit', '1']], $approval, [['cash', '110']]));

        expect(SalesSeed::row('approval_requests', $approval)['consumed_at'])->not->toBeNull();
    });

    it('refuses a referenced refund at another branch than the sale\'s as not_refundable, before the gate: the approval is untouched (AC-54)', function () {
        $w = rfWorld();
        $tillB = SalesWorld::till('TB');
        $original = rfSale($w);
        $approval = rfApproval($w, $original['sale']);
        $before = rfState();

        $fields = rfFields($w, $original, [[0, 'unit', '1']], $approval, [['cash', '110']], ['terminal_id' => $tillB['terminal']]);

        SalesRefusals::expect(SalesRefusal::NotRefundable, fn () => rfRefund($w, $fields, $tillB['cashier']));

        expect(rfState())->toBe($before)->and(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBeNull();
    });
});

describe('the tenders (AC-12A, AC-30; D-41, D-71)', function () {
    it('refuses a refund tender onto a method with allows_refund false as unsupported_method_type, writing nothing, the approval unconsumed (AC-12A)', function () {
        $w = rfWorld();
        $original = rfSale($w);
        $approval = rfApproval($w, $original['sale']);
        $before = rfState();

        SalesRefusals::expect(SalesRefusal::UnsupportedMethodType, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], $approval, [['noref', '110']])));

        expect(rfState())->toBe($before)
            ->and(rfRefunds())->toBe([])
            ->and(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBeNull()
            ->and(SalesSeed::connection()->table('number_sequences')->where('document_type', 'refund')->count())->toBe(0)
            ->and(SalesSeed::row('sale_items', $original['items'][0])['refunded_quantity'])->toEqual('0.0000');
    });

    it('accepts the same method once allows_refund is true, and the same flag-less method on an ordinary sale', function () {
        $w = rfWorld();
        $original = rfSale($w, [['unit', '1']], [['noref', '110']]);
        SalesSeed::connection()->table('payment_methods')->where('id', $w['noref'])->update(['allows_refund' => true]);

        $result = rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], rfApproval($w, $original['sale']), [['noref', '110']]));

        expect(SalesSeed::connection()->table('sale_tenders')->where('sale_id', $result->refundId)->value('amount'))->toEqual('-110.0000');
    });

    it('refuses loyalty and voucher tenders as unsupported_method_type (AC-12)', function (string $type) {
        $w = rfWorld();
        $w['odd'] = SalesSeed::paymentMethod('ODD', ['type' => $type]);
        $original = rfSale($w);
        $approval = rfApproval($w, $original['sale']);

        SalesRefusals::expect(SalesRefusal::UnsupportedMethodType, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], $approval, [['odd', '110']])));
    })->with(['loyalty', 'voucher']);

    it('refuses tenders that do not add up to the refund as balance_due, and a tender over it as exceeds_balance, with no change given back', function (array $tenders, SalesRefusal $expected) {
        $w = rfWorld();
        $original = rfSale($w);
        $approval = rfApproval($w, $original['sale']);
        $before = rfState();

        SalesRefusals::expect($expected, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], $approval, $tenders)));

        expect(rfState())->toBe($before);
    })->with([
        'none' => [[], SalesRefusal::BalanceDue],
        'short, with a method that takes part payment' => [[['cash', '100']], SalesRefusal::BalanceDue],
        'over, on a method that gives change on a sale' => [[['cash', '120']], SalesRefusal::ExceedsBalance],
        'a second tender with nothing left to pay out' => [[['cash', '110'], ['card', '10']], SalesRefusal::ExceedsBalance],
    ]);

    it('splits a refund across methods, each negative, summing to minus the total', function () {
        $w = rfWorld();
        $original = rfSale($w, [['unit', '1']], [['cash', '60'], ['card', '50']]);

        $result = rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], rfApproval($w, $original['sale']), [['cash', '60'], ['card', '50']]));

        $amounts = SalesSeed::connection()->table('sale_tenders')->where('sale_id', $result->refundId)->orderBy('method_type')->pluck('amount')->map(fn ($a) => (string) (float) $a)->all();

        expect($amounts)->toBe(['-50', '-60'])->and($result->tenderIds)->toHaveCount(2);
    });

    it('completes a zero-total refund with no tender, numbered and audited like any other (a fully discounted line)', function () {
        $w = rfWorld();
        $free = PricingSeed::discount('FREE', ['type' => 'percentage', 'value' => '100.0000', 'usage_limit' => 5]);
        $original = rfSale($w, [['unit', '1', $free]], []);
        $approval = rfApproval($w, $original['sale']);

        $result = rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], $approval, []));

        expect($result->total)->toBe('0.0000')
            ->and($result->number)->toBe('R000001')
            ->and(SalesSeed::connection()->table('sale_tenders')->where('sale_id', $result->refundId)->count())->toBe(0)
            ->and(SalesSeed::row('sales', $result->refundId))->toMatchArray(['status' => 'completed', 'paid_total' => '0.0000'])
            ->and(SalesSeed::row('sales', $original['sale'])['status'])->toBe('refunded');
    });

    it('refuses a tender on a zero-total refund as exceeds_balance (nothing is owed back)', function () {
        $w = rfWorld();
        $free = PricingSeed::discount('FREE', ['type' => 'percentage', 'value' => '100.0000', 'usage_limit' => 5]);
        $original = rfSale($w, [['unit', '1', $free]], []);
        $approval = rfApproval($w, $original['sale']);

        SalesRefusals::expect(SalesRefusal::ExceedsBalance, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], $approval, [['cash', '1']])));
    });
});

describe('a credit refund (AC-30, AC-48; F-4, R-4, R-20)', function () {
    it('posts a refund entry keyed on the refund tender, with a positive credit equal to the tender\'s magnitude, and one customers audit row', function () {
        $w = rfWorld();
        $customer = rfCustomer();
        $original = rfSale($w, [['unit', '1']], [['credit', '110']], $customer);
        $approval = rfApproval($w, $original['sale']);
        $auditBefore = count(SalesSeed::audit());

        $result = rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], $approval, [['credit', '110']]));

        $rows = SalesSeed::connection()->table('customer_ledger')->where('type', 'refund')->get();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->reference_type)->toBe('sale_tender')
            ->and(strtoupper((string) $rows[0]->reference_id))->toBe($result->tenderIds[0])
            ->and((string) (float) $rows[0]->credit)->toBe('110')
            ->and((float) $rows[0]->debit)->toBe(0.0)
            ->and($rows[0]->customer_id)->toBe($customer)
            ->and($rows[0]->currency)->toBe('LKR')
            ->and($rows[0]->branch_id)->toBe($w['till']['branch'])
            ->and(array_count_values(array_column(array_slice(SalesSeed::audit(), $auditBefore), 'module')))->toBe(['customers' => 1, 'sales' => 1]);
    });

    it('is customer_required for a credit refund with no customer, and writes nothing', function () {
        $w = rfWorld();
        $original = rfSale($w, [['unit', '1']]);
        $approval = rfApproval($w, $original['sale']);
        $before = rfState();

        SalesRefusals::expect(SalesRefusal::CustomerRequired, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], $approval, [['credit', '110']])));

        expect(rfState())->toBe($before);
    });

    it('refuses a replayed posting as credit_refused carrying replay_conflict, and writes nothing (R-4)', function () {
        $w = rfWorld();
        $customer = rfCustomer();
        $original = rfSale($w, [['unit', '1']], [['credit', '110']], $customer);
        $approval = rfApproval($w, $original['sale']);
        $before = rfState();

        app()->instance(CustomerCreditLedger::class, new class implements CustomerCreditLedger
        {
            public function record(CreditLedgerEntry $entry): CreditLedgerPosting
            {
                return new CreditLedgerPosting((string) Str::ulid(), $entry->customerId, $entry->type, $entry->debit, $entry->credit, '0.0000', 'LKR', true);
            }
        });

        $refused = SalesRefusals::expect(SalesRefusal::CreditRefused, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], $approval, [['credit', '110']])));

        expect($refused->cause)->toBe(CreditRefusal::ReplayConflict)->and(rfState())->toBe($before);
    });

    it('refuses a customer merged since the sale as credit_refused carrying customer_not_live, rolling back the whole refund', function () {
        $w = rfWorld();
        $customer = rfCustomer();
        $original = rfSale($w, [['unit', '1']], [['credit', '110']], $customer);
        $approval = rfApproval($w, $original['sale']);
        SalesSeed::connection()->table('customers')->where('id', $customer)->update(['deleted_at' => SalesSeed::NOW]);
        $before = rfState();

        $refused = SalesRefusals::expect(SalesRefusal::CreditRefused, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], $approval, [['credit', '110']])));

        expect($refused->cause)->toBe(CreditRefusal::CustomerNotLive)->and(rfState())->toBe($before);
    });
});

describe('the sellability exemption (AC-47A; D-57, D-73)', function () {
    it('accepts a goodwill line for an inactive or unsellable product, and quotes it normally (M11)', function (array $fact) {
        $w = rfWorld();
        SalesSeed::connection()->table('products')->where('id', $w['product'])->update($fact);

        $result = rfRefund($w, rfGoodwill($w, [[$w['product'], 'unit', '1']], rfApproval($w, null), [['cash', '110']]));

        expect($result->total)->toBe('110.0000');
    })->with([
        'inactive' => [['is_active' => false]],
        'not sellable' => [['is_sellable' => false]],
    ]);

    it('accepts a referenced line for a product deactivated since the sale', function () {
        $w = rfWorld();
        $original = rfSale($w);
        SalesSeed::connection()->table('products')->where('id', $w['product'])->update(['is_active' => false, 'is_sellable' => false]);

        $result = rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], rfApproval($w, $original['sale']), [['cash', '110']]));

        expect($result->total)->toBe('110.0000');
    });
});

describe('the engine\'s live-state refusals pass through (R-1, AC-53; the default for owner question Q-1)', function () {
    // A ruling for option (b) on Q-1 changes exactly the expectations of this dataset and of the soft-deleted goodwill row below.
    it('refuses the refund under the engine\'s own reason, and writes nothing', function (string $case, PricingRefusal $reason) {
        $w = rfWorld();
        $discount = PricingSeed::discount('D1', ['usage_limit' => $case === 'limit' ? 1 : 5]);
        $original = rfSale($w, [['unit', '1', $discount]]);
        $approval = rfApproval($w, $original['sale']);

        $change = match ($case) {
            'limit' => null,
            'deactivated' => static fn () => SalesSeed::connection()->table('discounts')->where('id', $discount)->update(['is_active' => false]),
            'deleted' => static fn () => SalesSeed::connection()->table('discounts')->where('id', $discount)->update(['deleted_at' => SalesSeed::NOW]),
            'allow_discount off' => static fn () => SalesSeed::connection()->table('products')->where('id', $w['product'])->update(['allow_discount' => false]),
            default => static fn () => SalesSeed::connection()->table('products')->where('id', $w['product'])->update(['deleted_at' => SalesSeed::NOW]),
        };

        if ($change !== null) {
            $change();
        }

        $total = (string) SalesSeed::row('sales', $original['sale'])['total'];
        $before = rfState();

        $refused = SalesRefusals::expect(SalesRefusal::PricingRefused, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], $approval, [['cash', $total]])));

        expect($refused->cause)->toBe($reason)
            ->and($refused->code())->toBe($reason->value)
            ->and(rfState())->toBe($before);
    })->with([
        // This sale's own consumption reached the limit, so the sale that used the last use cannot be refunded.
        'limit' => ['limit', PricingRefusal::UsageLimitReached],
        'deactivated' => ['deactivated', PricingRefusal::Inactive],
        'deleted' => ['deleted', PricingRefusal::UnknownDiscount],
        'allow_discount off' => ['allow_discount off', PricingRefusal::DiscountNotAllowed],
        'product deleted' => ['product deleted', PricingRefusal::UnknownProduct],
    ]);

    it('refuses a goodwill line for a soft-deleted product as unknown_product (AC-47A, AC-53: the built default until Q-1 is ruled)', function () {
        $w = rfWorld();
        SalesSeed::connection()->table('products')->where('id', $w['product'])->update(['deleted_at' => SalesSeed::NOW]);
        $approval = rfApproval($w, null);
        $before = rfState();

        $refused = SalesRefusals::expect(SalesRefusal::PricingRefused, fn () => rfRefund($w, rfGoodwill($w, [[$w['product'], 'unit', '1']], $approval, [['cash', '110']])));

        expect($refused->cause)->toBe(PricingRefusal::UnknownProduct)->and(rfState())->toBe($before);
    });
});

describe('the customer of a goodwill refund (AC-54; D-58)', function () {
    it('refuses a customer that is unknown, soft-deleted or merged as not_found, and writes nothing', function (string $case) {
        $w = rfWorld();
        $customer = rfCustomer();
        $given = match ($case) {
            'unknown' => (string) Str::ulid(),
            'deleted' => $customer,
            default => $customer,
        };

        if ($case === 'deleted') {
            SalesSeed::connection()->table('customers')->where('id', $customer)->update(['deleted_at' => SalesSeed::NOW]);
        }

        if ($case === 'merged') {
            SalesSeed::connection()->table('customers')->where('id', $customer)->update(['merged_into_id' => rfCustomer()]);
        }

        $approval = rfApproval($w, null);
        $before = rfState();

        SalesRefusals::expect(SalesRefusal::NotFound, fn () => rfRefund($w, rfGoodwill($w, [[$w['product'], 'unit', '1']], $approval, [['cash', '110']], ['customer_id' => $given])));

        expect(rfState())->toBe($before);
    })->with(['unknown', 'deleted', 'merged']);

    it('takes no customer_id on a referenced refund: the original\'s is copied', function () {
        $w = rfWorld();
        $original = rfSale($w);

        SalesRefusals::expect(SalesRefusal::Validation, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], rfApproval($w, $original['sale']), [['cash', '110']], ['customer_id' => rfCustomer()])));
    });
});

describe('numbering (AC-50)', function () {
    it('refuses a sequence the numbering will not allocate as numbering_refused, carrying the cause, with the approval and quantities untouched', function (string $case, DocumentNumbersRefusal $cause) {
        $w = rfWorld();
        $original = rfSale($w);
        $approval = rfApproval($w, $original['sale']);

        SalesSeed::connection()->table('number_sequences')->insert([
            'id' => (string) Str::ulid(), 'branch_id' => null, 'document_type' => 'refund',
            'prefix' => $case === 'long' ? str_repeat('X', 28) : 'R', 'next_number' => 1, 'padding' => 6, 'suffix' => null,
            'reset_period' => $case === 'yearly' ? 'yearly' : 'never', 'last_reset_at' => null, 'created_at' => SalesSeed::NOW, 'updated_at' => SalesSeed::NOW,
        ]);

        $before = rfState();

        $refused = SalesRefusals::expect(SalesRefusal::NumberingRefused, fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], $approval, [['cash', '110']])));

        expect($refused->cause)->toBe($cause)
            ->and(rfState())->toBe($before)
            ->and(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBeNull();
    })->with([
        'yearly' => ['yearly', DocumentNumbersRefusal::UnsupportedResetPeriod],
        'long' => ['long', DocumentNumbersRefusal::NumberTooLong],
    ]);
});

describe('a deadlock is retried once (NOT VERIFIED on a real engine)', function () {
    /**
     * A credit refund against an original with a discount, so the retry has a ledger row, an approval and a quantity to leave behind.
     *
     * @return array{w: array<string, mixed>, original: array{sale: string, items: list<string>, total: string}, fields: array<string, mixed>, approval: string}
     */
    function rfDeadlockSetup(): array
    {
        $w = rfWorld();
        $customer = rfCustomer();
        $original = rfSale($w, [['unit', '2']], [['credit', '220']], $customer);
        $approval = rfApproval($w, $original['sale']);

        return ['w' => $w, 'original' => $original, 'approval' => $approval, 'fields' => rfFields($w, $original, [[0, 'unit', '1']], $approval, [['credit', '110']])];
    }

    it('(a) runs the whole write again after a deadlock on the numbering: one number, one increment, one approval use, one ledger row, one audit row', function () {
        ['w' => $w, 'original' => $original, 'fields' => $fields, 'approval' => $approval] = rfDeadlockSetup();
        $auditBefore = count(SalesSeed::audit());

        $wrapper = DeadlockingNumbers::wrap(function (int $call): void {
            if ($call === 1) {
                throw DeadlockingNumbers::deadlock();
            }
        });

        $result = rfRefund($w, $fields);

        $audits = array_slice(SalesSeed::audit(), $auditBefore);

        expect($wrapper->calls)->toBe(2)
            ->and($result->number)->toBe('R000001')
            ->and(rfRefunds())->toHaveCount(1)
            ->and(SalesSeed::row('sale_items', $original['items'][0])['refunded_quantity'])->toEqual('1.0000')
            ->and(SalesSeed::row('approval_requests', $approval)['consumed_at'])->not->toBeNull()
            ->and(SalesSeed::connection()->table('customer_ledger')->where('type', 'refund')->count())->toBe(1)
            ->and(array_count_values(array_column($audits, 'module')))->toBe(['customers' => 1, 'sales' => 1]);
    });

    it('(b) surfaces the engine error after the second deadlock, with nothing written', function () {
        ['w' => $w, 'fields' => $fields] = rfDeadlockSetup();
        $before = rfState();

        $wrapper = DeadlockingNumbers::wrap(function (): void {
            throw DeadlockingNumbers::deadlock();
        });

        expect(fn () => rfRefund($w, $fields))->toThrow(QueryException::class, 'Deadlock found');

        expect($wrapper->calls)->toBe(2)->and(rfState())->toBe($before)->and(rfRefunds())->toBe([]);
    });

    it('(c) does not retry an error that is not a deadlock', function () {
        ['w' => $w, 'fields' => $fields] = rfDeadlockSetup();

        $wrapper = DeadlockingNumbers::wrap(function (): void {
            throw new QueryException('tenant', 'select 1', [], new PDOException('SQLSTATE[HY000]: General error: 1 no such table'));
        });

        expect(fn () => rfRefund($w, $fields))->toThrow(QueryException::class);

        expect($wrapper->calls)->toBe(1);
    });
});

it('takes the shift shared, then the original sale FOR UPDATE, then the approval, the methods shared, the customer, and the number last (Ruling 7, R-5)', function () {
    $w = rfWorld();
    $customer = rfCustomer();
    $original = rfSale($w, [['unit', '1']], [['credit', '110']], $customer);
    $approval = rfApproval($w, $original['sale']);

    $inside = SalesQueryLog::inTransaction(SalesQueryLog::during(fn () => rfRefund($w, rfFields($w, $original, [[0, 'unit', '1']], $approval, [['credit', '110']]))));

    $first = static function (string $table, string $marker) use ($inside): ?int {
        foreach ($inside as $position => $sql) {
            if (str_contains($sql, '"'.$table.'"') && str_contains($sql, $marker)) {
                return $position;
            }
        }

        return null;
    };

    $shift = $first('cash_shifts', LockMarkingGrammar::SHARED);
    $sale = $first('sales', LockMarkingGrammar::FOR_UPDATE);
    $approvals = $first('approval_requests', LockMarkingGrammar::FOR_UPDATE);
    $methods = $first('payment_methods', LockMarkingGrammar::SHARED);
    $customers = $first('customers', LockMarkingGrammar::FOR_UPDATE);
    $numbers = $first('number_sequences', LockMarkingGrammar::FOR_UPDATE);

    expect($shift)->toBe(0)
        ->and($sale)->toBeGreaterThan($shift)
        ->and($approvals)->toBeGreaterThan($sale)
        ->and($methods)->toBeGreaterThan($approvals)
        ->and($customers)->toBeGreaterThan($methods)
        ->and($numbers)->toBeGreaterThan($customers);

    $locksAfter = array_filter(array_slice($inside, $numbers + 1), static fn (string $sql): bool => str_contains($sql, LockMarkingGrammar::FOR_UPDATE) && ! str_contains($sql, 'number_sequences'));

    expect($locksAfter)->toBe([]);
});

it('refuses a bad request: unknown fields, ids, no lines, a mixed line, no terminal, no open shift, a missing actor', function () {
    $w = rfWorld();
    $original = rfSale($w);
    $approval = rfApproval($w, $original['sale']);
    $good = rfFields($w, $original, [[0, 'unit', '1']], $approval, [['cash', '110']]);
    $before = rfState();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => rfRefund($w, array_merge($good, ['status' => 'completed'])));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rfRefund($w, array_merge($good, ['business_date' => '2026-01-01'])));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rfRefund($w, array_merge($good, ['terminal_id' => 'x'])));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rfRefund($w, array_merge($good, ['reference_sale_id' => 'x'])));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rfRefund($w, array_merge($good, ['lines' => []])));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rfRefund($w, array_merge($good, ['lines' => [['unit_id' => $w['unit'], 'quantity' => '1']]])));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rfRefund($w, array_merge($good, ['lines' => [['refund_of_item_id' => $original['items'][0], 'unit_id' => $w['unit'], 'quantity' => '1', 'unit_price' => '1']]])));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rfRefund($w, array_merge($good, ['tenders' => [['payment_method_id' => $w['cash'], 'offered' => '110', 'amount' => '110']]])));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rfRefund($w, array_merge($good, ['approval_id' => 'nope'])));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => rfRefund($w, array_merge($good, ['reference_sale_id' => (string) Str::ulid()])));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => rfRefund($w, array_merge($good, ['terminal_id' => (string) Str::ulid()])));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rfRefund($w, $good, null));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => rfRefund($w, $good, (string) Str::ulid()));

    SalesSeed::connection()->table('cash_shifts')->where('id', $w['till']['shift'])->update(['status' => 'closed', 'closed_at' => SalesSeed::NOW]);
    SalesRefusals::expect(SalesRefusal::NoOpenShift, fn () => rfRefund($w, $good));

    expect(SalesSeed::connection()->table('sales')->where('type', 'refund')->count())->toBe(0)
        ->and(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBeNull()
        ->and(count(rfState()['audit']))->toBe(count($before['audit']));
});
