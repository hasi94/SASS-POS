<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Qa;

use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLine;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLineHandler;
use Flexipos\Sales\Features\AddTender\AddTender;
use Flexipos\Sales\Features\AddTender\AddTenderHandler;
use Flexipos\Sales\Features\CloseCashShift\CloseCashShift;
use Flexipos\Sales\Features\CloseCashShift\CloseCashShiftHandler;
use Flexipos\Sales\Features\CompleteSale\CompleteSale;
use Flexipos\Sales\Features\CompleteSale\CompleteSaleHandler;
use Flexipos\Sales\Features\DecideApproval\DecideApproval;
use Flexipos\Sales\Features\DecideApproval\DecideApprovalHandler;
use Flexipos\Sales\Features\GetSale\GetSale;
use Flexipos\Sales\Features\GetSale\GetSaleHandler;
use Flexipos\Sales\Features\HoldSale\HoldSale;
use Flexipos\Sales\Features\HoldSale\HoldSaleHandler;
use Flexipos\Sales\Features\RefundSale\RefundSale;
use Flexipos\Sales\Features\RefundSale\RefundSaleHandler;
use Flexipos\Sales\Features\RemoveTender\RemoveTender;
use Flexipos\Sales\Features\RemoveTender\RemoveTenderHandler;
use Flexipos\Sales\Features\RequestApproval\RequestApproval;
use Flexipos\Sales\Features\RequestApproval\RequestApprovalHandler;
use Flexipos\Sales\Features\UpdateSaleLine\UpdateSaleLine;
use Flexipos\Sales\Features\UpdateSaleLine\UpdateSaleLineHandler;
use Flexipos\Sales\Support\Money;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| QA round 2 - T-030 review fix round: AC-27B, AC-13A, AC-55A
|--------------------------------------------------------------------------
|
| Each expected figure is worked by hand in the comment above its test, never
| read back from the code. Approvals go through the real RequestApproval ->
| DecideApproval chain. Every refund line is read straight from sale_items.
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/sales-fixture.php';
require_once __DIR__.'/../Support/approval-fixture.php';

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
 * @return array<string, mixed>
 */
function qrWorld(string $price, string $rate = '10.0000', bool $inclusive = false): array
{
    app(SyncPermissionRegistryHandler::class)->handle(new SyncPermissionRegistry);

    $till = SalesWorld::till();
    $group = SalesWorld::taxGroup('TXR', $rate, $inclusive);
    $catalogue = SalesWorld::product('PR', $price, $group, ['allow_price_override' => true]);

    $managers = [];

    foreach (['manager', 'manager2'] as $code) {
        $id = apvEmployeeWithPin($code);
        AccessSeed::attach($id, $till['branch']);
        AccessSeed::assign($id, AccessSeed::role('role_'.$code, [APV_KEY]), $till['branch']);
        $managers[$code] = $id;
    }

    return [
        'till' => $till,
        'product' => $catalogue['product'],
        'unit' => $catalogue['unit'],
        'manager' => $managers['manager'],
        'manager2' => $managers['manager2'],
        'cash' => SalesSeed::paymentMethod('CASH', ['type' => 'cash', 'allows_change' => true]),
        'card' => SalesSeed::paymentMethod('CARD', ['type' => 'card']),
    ];
}

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $request
 */
function qrApproval(array $w, array $request, string $decider = 'manager'): string
{
    $id = app(RequestApprovalHandler::class)->handle(new RequestApproval(array_merge(['branch_id' => $w['till']['branch']], $request), $w['till']['cashier']))->approvalId;

    app(DecideApprovalHandler::class)->handle(new DecideApproval(['approval_id' => $id, 'employee_code' => $decider, 'pin' => APV_PIN, 'decision' => 'approved']));

    return $id;
}

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $fields
 */
function qrLine(array $w, string $sale, string $quantity, array $fields = []): string
{
    return app(AddSaleLineHandler::class)->handle(new AddSaleLine(array_merge(['sale_id' => $sale, 'product_id' => $w['product'], 'unit_id' => $w['unit'], 'quantity' => $quantity], $fields), $w['till']['cashier']))->lineId;
}

/**
 * @param  array<string, mixed>  $w
 */
function qrTender(array $w, string $sale, string $method, string $offered, ?string $id = null, bool $atTerminal = false): string
{
    $id ??= (string) Str::ulid();

    app(AddTenderHandler::class)->handle(new AddTender(array_merge(['id' => $id, 'sale_id' => $sale, 'payment_method_id' => $w[$method], 'offered' => $offered], $atTerminal ? ['terminal_id' => $w['till']['terminal']] : []), $w['till']['cashier']));

    return $id;
}

/**
 * @param  array<string, mixed>  $w
 */
function qrComplete(array $w, string $sale): void
{
    app(CompleteSaleHandler::class)->handle(new CompleteSale(['sale_id' => $sale], $w['till']['cashier']));
}

/**
 * Sell and complete one product line, paid exactly in cash.
 *
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $lineFields
 * @return array{sale: string, line: string}
 */
function qrSold(array $w, string $quantity, array $lineFields = []): array
{
    $sale = SalesWorld::sale($w['till']);
    $line = qrLine($w, $sale, $quantity, $lineFields);

    qrTender($w, $sale, 'cash', (string) SalesSeed::row('sales', $sale)['total']);
    qrComplete($w, $sale);

    return ['sale' => $sale, 'line' => $line];
}

/**
 * One refund of a line through the real approval chain; returns the single refund line row.
 *
 * @param  array<string, mixed>  $w
 * @return array<string, mixed>
 */
function qrRefund(array $w, string $sale, string $line, string $quantity, string $payout): array
{
    $approval = qrApproval($w, ['type' => 'refund', 'reference_type' => 'sale', 'reference_id' => $sale]);

    $result = app(RefundSaleHandler::class)->handle(new RefundSale([
        'terminal_id' => $w['till']['terminal'],
        'reference_sale_id' => $sale,
        'approval_id' => $approval,
        'lines' => [['refund_of_item_id' => $line, 'unit_id' => $w['unit'], 'quantity' => $quantity]],
        'tenders' => [['payment_method_id' => $w['cash'], 'offered' => $payout]],
    ], $w['till']['cashier']));

    return (array) SalesSeed::connection()->table('sale_items')->where('sale_id', $result->refundId)->first();
}

/**
 * A price list that gives `$tierPrice` from `$minimum` units, for the whole branch.
 *
 * @param  array<string, mixed>  $w
 */
function qrTier(array $w, string $minimum, string $tierPrice): void
{
    $list = PricingSeed::priceList('TIERQ', ['currency' => 'LKR']);
    PricingSeed::item($list, $w['product'], $w['unit'], $minimum, $tierPrice);
    PricingSeed::assignment($list, 'branch', $w['till']['branch']);
}

// Hand-worked, AC-27B, exclusive 10 percent, list 100.0000, a tier of 80.0000 from 10 units:
//   sold 10 units: subtotal 800.0000, tax 80.0000, line_total 880.0000.
//   refund 5 (below the tier, re-quoted at 100): 500 + 50 = 550.0000.
//   refund the last 5: 880 - 550 = 330.0000 (subtotal 300, tax 30), NOT the re-quote of 550.
//   550 + 330 = 880 = the charge. A third refund is fully_refunded.
it('refunds a 10-unit tier line as 5 + 5 for 550 + 330 = 880, the last part being the remainder (hand-computed, AC-27B)', function () {
    $w = qrWorld('100.0000');
    qrTier($w, '10.0000', '80.0000');

    ['sale' => $sale, 'line' => $line] = qrSold($w, '10');

    expect(SalesSeed::row('sale_items', $line))->toMatchArray(['subtotal' => '800.0000', 'tax_amount' => '80.0000', 'line_total' => '880.0000']);

    $first = qrRefund($w, $sale, $line, '5', '550');
    $second = qrRefund($w, $sale, $line, '5', '330');

    expect($first)->toMatchArray(['subtotal' => '500.0000', 'tax_amount' => '50.0000', 'line_total' => '550.0000'])
        ->and($second)->toMatchArray(['subtotal' => '300.0000', 'tax_amount' => '30.0000', 'line_total' => '330.0000'])
        ->and(SalesSeed::row('sales', $sale)['status'])->toBe('refunded');

    $approval = qrApproval($w, ['type' => 'refund', 'reference_type' => 'sale', 'reference_id' => $sale]);

    SalesRefusals::expect(SalesRefusal::FullyRefunded, fn () => app(RefundSaleHandler::class)->handle(new RefundSale([
        'terminal_id' => $w['till']['terminal'], 'reference_sale_id' => $sale, 'approval_id' => $approval,
        'lines' => [['refund_of_item_id' => $line, 'unit_id' => $w['unit'], 'quantity' => '1']],
        'tenders' => [['payment_method_id' => $w['cash'], 'offered' => '110']],
    ], $w['till']['cashier'])));
});

// Hand-worked, AC-27B cap: the same tier line. Refund 6 (660). The remaining 4 would then re-quote at 100: 3 more is 330 and the
// money would be 990 > 880 with 1 unit still left, so it is refused over_refund and nothing is written. The last 4 are then
// 880 - 660 = 220 (a smaller figure than their re-quote of 440).
it('refuses a partial refund that would pass the charge, then lets the last 4 take exactly what is left (220)', function () {
    $w = qrWorld('100.0000');
    qrTier($w, '10.0000', '80.0000');

    ['sale' => $sale, 'line' => $line] = qrSold($w, '10');

    qrRefund($w, $sale, $line, '6', '660');

    $approval = qrApproval($w, ['type' => 'refund', 'reference_type' => 'sale', 'reference_id' => $sale]);
    $before = SalesSeed::connection()->table('sale_items')->count();

    SalesRefusals::expect(SalesRefusal::OverRefund, fn () => app(RefundSaleHandler::class)->handle(new RefundSale([
        'terminal_id' => $w['till']['terminal'], 'reference_sale_id' => $sale, 'approval_id' => $approval,
        'lines' => [['refund_of_item_id' => $line, 'unit_id' => $w['unit'], 'quantity' => '3']],
        'tenders' => [['payment_method_id' => $w['cash'], 'offered' => '330']],
    ], $w['till']['cashier'])));

    expect(SalesSeed::connection()->table('sale_items')->count())->toBe($before)
        ->and(SalesSeed::row('sale_items', $line)['refunded_quantity'])->toEqual(6)
        ->and(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBeNull();

    // The refused attempt left the approval unconsumed, so it still covers the legitimate last refund.
    $result = app(RefundSaleHandler::class)->handle(new RefundSale([
        'terminal_id' => $w['till']['terminal'], 'reference_sale_id' => $sale, 'approval_id' => $approval,
        'lines' => [['refund_of_item_id' => $line, 'unit_id' => $w['unit'], 'quantity' => '4']],
        'tenders' => [['payment_method_id' => $w['cash'], 'offered' => '220']],
    ], $w['till']['cashier']));

    expect($result->total)->toBe('220.0000')->and($result->originalStatus)->toBe('refunded');
});

// Hand-worked, AC-27B on an INCLUSIVE 10 percent group, tier 80 gross from 10 units (list 100):
//   sold 10: gross 800.0000, net 800/1.1 = 727.272727 -> 727.2727, tax 800 - 727.2727 = 72.7273, line_total 800.0000.
//   refund 5 at 100 gross: 500.0000, net 500/1.1 = 454.545454 -> 454.5455, tax 45.4545.
//   last 5: line_total 800 - 500 = 300.0000, tax 72.7273 - 45.4545 = 27.2728, net 727.2727 - 454.5455 = 272.7272; 272.7272 + 27.2728 = 300.
it('refunds an inclusive-tax tier line 5 + 5 with an exact tax split on the remainder (hand-computed)', function () {
    $w = qrWorld('100.0000', '10.0000', inclusive: true);
    qrTier($w, '10.0000', '80.0000');

    ['sale' => $sale, 'line' => $line] = qrSold($w, '10');

    $charged = SalesSeed::row('sale_items', $line);

    expect($charged)->toMatchArray(['subtotal' => '800.0000', 'tax_amount' => '72.7273', 'line_total' => '800.0000']);

    $first = qrRefund($w, $sale, $line, '5', '500');
    $second = qrRefund($w, $sale, $line, '5', '300');
    $breakdown = json_decode((string) $second['tax_breakdown'], true);

    expect($first)->toMatchArray(['line_total' => '500.0000', 'tax_amount' => '45.4545'])
        ->and($second)->toMatchArray(['line_total' => '300.0000', 'tax_amount' => '27.2728'])
        ->and($breakdown['net'])->toEqual('272.7272')
        ->and($breakdown['total'])->toEqual('27.2728')
        ->and(Money::add(Money::of($first['tax_amount']), Money::of($second['tax_amount'])))->toBe(Money::of($charged['tax_amount']));
});

// Hand-worked, AC-27B fractional override: 1.0000 unit at an approved 1.2345 on a 0 percent group: line_total 1.2345.
//   refund 0.5: 0.5 x 1.2345 = 0.61725 -> half-up 0.6173. The last 0.5 = 1.2345 - 0.6173 = 0.6172 (never 0.6173 twice = 1.2346).
// The same on an INCLUSIVE 10 percent group: net 1.2345/1.1 = 1.122272 -> 1.1223, tax 0.1122.
//   refund 0.5: 0.6173 gross, net 0.6173/1.1 = 0.561181 -> 0.5612, tax 0.0561.  Last 0.5: 0.6172, tax 0.1122 - 0.0561 = 0.0561, net 1.1223 - 0.5612 = 0.5611 (0.5611 + 0.0561 = 0.6172).
it('refunds a 1.0000 override line at 1.2345 as 0.5 + 0.5, on a zero-rate and on an inclusive group (hand-computed)', function (string $rate, bool $inclusive, array $expected) {
    $w = qrWorld('9.0000', $rate, $inclusive);
    $override = fn (string $sale): string => qrApproval($w, ['type' => 'price_override', 'reference_type' => 'sale', 'reference_id' => $sale, 'payload' => ['product_id' => $w['product'], 'unit_id' => $w['unit'], 'unit_price' => '1.2345']]);

    $sale = SalesWorld::sale($w['till']);
    $line = qrLine($w, $sale, '1', ['unit_price' => '1.2345', 'approval_id' => $override($sale)]);
    $charged = SalesSeed::row('sale_items', $line);

    expect($charged['line_total'])->toEqual($expected['charged'])->and($charged['tax_amount'])->toEqual($expected['chargedTax']);

    qrTender($w, $sale, 'cash', (string) SalesSeed::row('sales', $sale)['total']);
    qrComplete($w, $sale);

    $first = qrRefund($w, $sale, $line, '0.5', $expected['first']);
    $second = qrRefund($w, $sale, $line, '0.5', $expected['second']);

    expect($first['line_total'])->toEqual($expected['first'])
        ->and($second['line_total'])->toEqual($expected['second'])
        ->and($first['tax_amount'])->toEqual($expected['firstTax'])
        ->and($second['tax_amount'])->toEqual($expected['secondTax'])
        ->and(Money::add(Money::of($first['line_total']), Money::of($second['line_total'])))->toBe(Money::of($charged['line_total']))
        ->and(SalesSeed::row('sales', $sale)['status'])->toBe('refunded');
})->with([
    'zero rate' => ['0.0000', false, ['charged' => '1.2345', 'chargedTax' => '0.0000', 'first' => '0.6173', 'second' => '0.6172', 'firstTax' => '0.0000', 'secondTax' => '0.0000']],
    'inclusive 10 percent' => ['10.0000', true, ['charged' => '1.2345', 'chargedTax' => '0.1122', 'first' => '0.6173', 'second' => '0.6172', 'firstTax' => '0.0561', 'secondTax' => '0.0561']],
]);

// Goodwill refund and a refund after a higher price list appeared. Sold 1 at 100 + 10% = 110. A list at 200 is then assigned to the
// branch. Whatever the re-quote says, the last unit of the line pays back exactly the charge 110 and is never refused.
// A goodwill line (no original line) is quoted at now (200 + 20 = 220 each, 660 for 3) and is never capped.
it('does not refuse a legitimate refund or goodwill refund after a later price change', function () {
    $w = qrWorld('100.0000');
    ['sale' => $sale, 'line' => $line] = qrSold($w, '1');

    $list = PricingSeed::priceList('LATER', ['currency' => 'LKR']);
    PricingSeed::item($list, $w['product'], $w['unit'], '1.0000', '200.0000');
    PricingSeed::assignment($list, 'branch', $w['till']['branch']);

    $refund = qrRefund($w, $sale, $line, '1', '110');

    expect($refund['line_total'])->toEqual('110.0000');

    $goodwill = app(RefundSaleHandler::class)->handle(new RefundSale([
        'terminal_id' => $w['till']['terminal'],
        'approval_id' => qrApproval($w, ['type' => 'refund', 'reference_type' => 'goodwill_refund', 'reference_id' => null]),
        'lines' => [['product_id' => $w['product'], 'unit_id' => $w['unit'], 'quantity' => '3']],
        'tenders' => [['payment_method_id' => $w['cash'], 'offered' => '660']],
    ], $w['till']['cashier']));

    expect($goodwill->total)->toBe('660.0000');
});

// Hand-worked, AC-27B with an item discount: 3 units at 10 on 10 % exclusive with a 10 % item discount:
//   subtotal 30, discount 3, taxable 27, tax 2.70, line_total 29.70. Refund 1 at a time: 9.90, 9.90, then the last = 29.70 - 19.80 = 9.90.
it('refunds a discounted line 1 + 1 + 1 summing to the charge exactly (hand-computed)', function () {
    $w = qrWorld('10.0000');
    $discount = PricingSeed::discount('QRD', []);

    ['sale' => $sale, 'line' => $line] = qrSold($w, '3', ['discount_id' => $discount]);

    expect(SalesSeed::row('sale_items', $line))->toMatchArray(['discount_amount' => '3.0000', 'tax_amount' => '2.7000', 'line_total' => '29.7000']);

    $a = qrRefund($w, $sale, $line, '1', '9.9');
    $b = qrRefund($w, $sale, $line, '1', '9.9');
    $c = qrRefund($w, $sale, $line, '1', '9.9');

    expect(Money::sum([Money::of($a['line_total']), Money::of($b['line_total']), Money::of($c['line_total'])]))->toBe('29.7000')
        ->and(Money::sum([Money::of($a['discount_amount']), Money::of($b['discount_amount']), Money::of($c['discount_amount'])]))->toBe('3.0000')
        ->and(SalesSeed::row('sales', $sale)['status'])->toBe('refunded');
});

// Hand-worked, AC-13A: inclusive 10 percent, 24.0000 x 3 = 72.0000 total.
//   card 30            -> paid 30.0000, balance 42.0000
//   cash offered 50    -> amount 42 (change 8) -> paid 72.0000, balance 0.0000
//   remove the card 30 -> paid 42.0000, balance 30.0000, still pending_payment
//   remove the cash 42 -> back to draft, paid 0.0000, balance 72.0000
//   a second unit is added (draft is editable): total 96.0000, balance_due 96.0000 (paid 0)
//   card 30, cash 20, remove the cash, cash 66 -> paid 96, balance 0; completing: paid_total 96.0000, balance_due 0.0000, and the shift
//   (opening 100) expects 100 + 66 = 166.0000 (cash only, by amount), CARD 30.0000, CASH 66.0000: no double count of a removed tender.
it('keeps paid_total and balance_due exact through adds and removals, then completes and closes the shift without double counting (AC-13A)', function () {
    $w = qrWorld('24.0000', '10.0000', inclusive: true);
    $sale = SalesWorld::sale($w['till']);
    qrLine($w, $sale, '3');

    $card = qrTender($w, $sale, 'card', '30');

    expect(SalesSeed::row('sales', $sale))->toMatchArray(['status' => 'pending_payment', 'paid_total' => '30.0000', 'balance_due' => '42.0000']);

    $cash = qrTender($w, $sale, 'cash', '50');

    expect(SalesSeed::row('sales', $sale))->toMatchArray(['paid_total' => '72.0000', 'balance_due' => '0.0000'])
        ->and(app(GetSaleHandler::class)->handle(new GetSale($sale))->sale)->toMatchArray(['paid_total' => '72.0000', 'balance_due' => '0.0000']);

    app(RemoveTenderHandler::class)->handle(new RemoveTender(['sale_id' => $sale, 'tender_id' => $card], $w['till']['cashier']));

    expect(SalesSeed::row('sales', $sale))->toMatchArray(['status' => 'pending_payment', 'paid_total' => '42.0000', 'balance_due' => '30.0000']);

    app(RemoveTenderHandler::class)->handle(new RemoveTender(['sale_id' => $sale, 'tender_id' => $cash], $w['till']['cashier']));

    expect(SalesSeed::row('sales', $sale))->toMatchArray(['status' => 'draft', 'paid_total' => '0.0000', 'balance_due' => '72.0000']);

    qrLine($w, $sale, '1');

    expect(SalesSeed::row('sales', $sale))->toMatchArray(['total' => '96.0000', 'paid_total' => '0.0000', 'balance_due' => '96.0000']);

    qrTender($w, $sale, 'card', '30');
    $gone = qrTender($w, $sale, 'cash', '20');

    expect(SalesSeed::row('sales', $sale))->toMatchArray(['paid_total' => '50.0000', 'balance_due' => '46.0000']);

    app(RemoveTenderHandler::class)->handle(new RemoveTender(['sale_id' => $sale, 'tender_id' => $gone], $w['till']['cashier']));
    qrTender($w, $sale, 'cash', '66');

    expect(SalesSeed::row('sales', $sale))->toMatchArray(['paid_total' => '96.0000', 'balance_due' => '0.0000']);

    app(CompleteSaleHandler::class)->handle(new CompleteSale(['sale_id' => $sale], $w['till']['cashier']));

    expect(SalesSeed::row('sales', $sale))->toMatchArray(['status' => 'completed', 'paid_total' => '96.0000', 'balance_due' => '0.0000', 'change_due' => '0.0000']);

    $closed = app(CloseCashShiftHandler::class)->handle(new CloseCashShift(['shift_id' => $w['till']['shift'], 'counted_cash' => '166'], $w['till']['cashier']));

    expect($closed->expectedCash)->toBe('166.0000')
        ->and($closed->variance)->toBe('0.0000')
        ->and($closed->totalsByMethod)->toBe(['CARD' => '30.0000', 'CASH' => '66.0000'])
        ->and($closed->gross)->toBe('96.0000');
});

// AC-13A: a replayed AddTender (same id) must not move the header; a held pending sale keeps its paid figure and a further
// tender added after the recall moves it from there. Hand-worked on 45.0000: cash 20 -> 20 / 25; replay -> 20 / 25; hold, recall by a
// further card 10 -> 30 / 15.
it('does not double count a replayed tender and carries the figures across a hold and an implicit recall (AC-13A)', function () {
    $w = qrWorld('15.0000', '0.0000');
    $sale = SalesWorld::sale($w['till']);
    qrLine($w, $sale, '3');

    $id = qrTender($w, $sale, 'cash', '20');
    qrTender($w, $sale, 'cash', '20', $id);

    expect(SalesSeed::row('sales', $sale))->toMatchArray(['paid_total' => '20.0000', 'balance_due' => '25.0000'])
        ->and(SalesSeed::connection()->table('sale_tenders')->where('sale_id', $sale)->count())->toBe(1);

    app(HoldSaleHandler::class)->handle(new HoldSale(['sale_id' => $sale, 'terminal_id' => $w['till']['terminal']], $w['till']['cashier']));

    expect(SalesSeed::row('sales', $sale))->toMatchArray(['status' => 'held', 'paid_total' => '20.0000', 'balance_due' => '25.0000']);

    qrTender($w, $sale, 'card', '10', null, true);

    expect(SalesSeed::row('sales', $sale))->toMatchArray(['status' => 'pending_payment', 'paid_total' => '30.0000', 'balance_due' => '15.0000']);
});

// AC-55A, real chain: two managers each approve the SAME payload for two different lines. A re-price of either line (any order, any
// number of times) keeps that line's own approver; one approval is consumed per line, never a third.
it('keeps each line\'s own approver across re-prices when two managers approved the same payload (AC-55A, real approvals)', function () {
    $w = qrWorld('10.0000');
    $sale = SalesWorld::sale($w['till']);
    $payload = ['product_id' => $w['product'], 'unit_id' => $w['unit'], 'unit_price' => '8.0000'];

    $a1 = qrApproval($w, ['type' => 'price_override', 'reference_type' => 'sale', 'reference_id' => $sale, 'payload' => $payload], 'manager');
    $a2 = qrApproval($w, ['type' => 'price_override', 'reference_type' => 'sale', 'reference_id' => $sale, 'payload' => $payload], 'manager2');
    $first = qrLine($w, $sale, '1', ['unit_price' => '8.0000', 'approval_id' => $a1]);
    $second = qrLine($w, $sale, '1', ['unit_price' => '8.0000', 'approval_id' => $a2]);

    $update = static fn (string $line, string $quantity) => app(UpdateSaleLineHandler::class)->handle(new UpdateSaleLine(['sale_id' => $sale, 'line_id' => $line, 'quantity' => $quantity], $w['till']['cashier']));

    foreach ([[$second, '2'], [$first, '3'], [$second, '4'], [$first, '5'], [$second, '6']] as [$line, $quantity]) {
        $update($line, $quantity);
    }

    expect(SalesSeed::row('sale_items', $first)['price_overridden_by'])->toBe($w['manager'])
        ->and(SalesSeed::row('sale_items', $second)['price_overridden_by'])->toBe($w['manager2'])
        ->and(SalesSeed::connection()->table('approval_requests')->whereNotNull('consumed_at')->count())->toBe(2);
});

// Survivor closers from the mutation round (each goes red under the named mutant).

// Hand-worked. The money cap is strict: refunding 8 of 10 tier units at the re-quote of 100 + 10 percent is 880.0000, EXACTLY the
// charge of the whole line, so it is allowed (a mutant that refuses at equality, `>=`, goes red).
it('allows a partial refund that reaches the charge exactly (equality boundary, AC-27B)', function () {
    $w = qrWorld('100.0000');
    qrTier($w, '10.0000', '80.0000');

    ['sale' => $sale, 'line' => $line] = qrSold($w, '10');

    $eight = qrRefund($w, $sale, $line, '8', '880');

    expect($eight['line_total'])->toEqual('880.0000')
        ->and(SalesSeed::row('sale_items', $line)['refunded_quantity'])->toEqual(8);
});

// AC-27B edge, characterised not judged. After 8 of 10 tier units took the whole 880 (see above), the last 2 units have 0.0000 left in
// money. The spec's clause for "nothing left to refund in money (already fully refunded)" says AC-28 applies "as before", and AC-28
// only knows quantity, so the built behaviour is a 0.0000 refund line that finishes the original as refunded (AC-12B zero-total path,
// no tender). This pins what is built so a change is noticed; it is reported to the lead as a note, not a defect.
it('writes the last units of a fully-paid-back line as a zero-total refund line and finishes the original (characterisation, AC-27B edge)', function () {
    $w = qrWorld('100.0000');
    qrTier($w, '10.0000', '80.0000');

    ['sale' => $sale, 'line' => $line] = qrSold($w, '10');

    qrRefund($w, $sale, $line, '8', '880');

    $approval = qrApproval($w, ['type' => 'refund', 'reference_type' => 'sale', 'reference_id' => $sale]);

    $result = app(RefundSaleHandler::class)->handle(new RefundSale([
        'terminal_id' => $w['till']['terminal'], 'reference_sale_id' => $sale, 'approval_id' => $approval,
        'lines' => [['refund_of_item_id' => $line, 'unit_id' => $w['unit'], 'quantity' => '2']],
        'tenders' => [],
    ], $w['till']['cashier']));

    expect($result->total)->toBe('0.0000')->and($result->originalStatus)->toBe('refunded');
});

// Hand-worked. 3 units at 0.3335 on a zero-rate group with a 10 percent item discount:
//   subtotal 1.0005, discount 10 % of 1.0005 = 0.10005 -> half-up 0.1001, line_total 1.0005 - 0.1001 = 0.9004.
//   refund 1 unit: subtotal 0.3335, discount 0.03335 -> 0.0334, line_total 0.3001 (twice = 0.6002).
//   the last unit is the remainder: subtotal 1.0005 - 0.6670 = 0.3335, discount 0.1001 - 0.0668 = 0.0333 (NOT the quote 0.0334),
//   line_total 0.9004 - 0.6002 = 0.3002 = 0.3335 - 0.0333.
it('takes the discount of the last unit as the remainder, so the discounts of a full set of refunds sum to the original discount (hand-computed)', function () {
    $w = qrWorld('0.3335', '0.0000');
    $discount = PricingSeed::discount('QRD2', []);

    ['sale' => $sale, 'line' => $line] = qrSold($w, '3', ['discount_id' => $discount]);

    expect(SalesSeed::row('sale_items', $line))->toMatchArray(['subtotal' => '1.0005', 'discount_amount' => '0.1001', 'line_total' => '0.9004']);

    $a = qrRefund($w, $sale, $line, '1', '0.3001');
    $b = qrRefund($w, $sale, $line, '1', '0.3001');
    $c = qrRefund($w, $sale, $line, '1', '0.3002');

    expect($a['discount_amount'])->toEqual('0.0334')
        ->and($b['discount_amount'])->toEqual('0.0334')
        ->and($c)->toMatchArray(['subtotal' => '0.3335', 'discount_amount' => '0.0333', 'line_total' => '0.3002'])
        ->and(Money::sum([Money::of($a['discount_amount']), Money::of($b['discount_amount']), Money::of($c['discount_amount'])]))->toBe('0.1001');
});

// Hand-worked. Two lines of one sale at 100 + 10 percent: line 1 is 1 unit (110.0000), line 2 is 3 units (330.0000).
//   refund line 1 (110), then 1 unit of line 2 (110); the last 2 units of line 2 are 330 - 110 = 220.0000.
//   Earlier refunds of ANOTHER line must not count against this line (a mutant summing every refund line says 330 - 220 = 110).
it('counts only the refunds of the same original line against its remainder', function () {
    $w = qrWorld('100.0000');
    $sale = SalesWorld::sale($w['till']);
    $one = qrLine($w, $sale, '1');
    $three = qrLine($w, $sale, '3');

    qrTender($w, $sale, 'cash', (string) SalesSeed::row('sales', $sale)['total']);
    qrComplete($w, $sale);

    qrRefund($w, $sale, $one, '1', '110');
    qrRefund($w, $sale, $three, '1', '110');
    $rest = qrRefund($w, $sale, $three, '2', '220');

    expect($rest['line_total'])->toEqual('220.0000')->and(SalesSeed::row('sales', $sale)['status'])->toBe('refunded');
});

// Hand-worked. Two EXCLUSIVE taxes on one group, A 10 percent and B 5 percent, not compound. A tier of 80 from 10 units on a list of 100:
//   sold 10: subtotal 800, A = 80, B = 40, tax 120, line_total 920.
//   refund 5 (re-quoted at 100): subtotal 500, A 50, B 25, line_total 575.
//   the last 5 are the remainder: subtotal 300, A 30 (base 300), B 15 (base 300), net 300, tax 45, line_total 345 = 920 - 575.
// Each tax's base and amount must be taken off by ITS OWN tax id (a mutant that subtracts every tax's earlier amounts from each tax goes red).
it('splits the tax breakdown of the last quantity per tax, matching each tax by its own id (two taxes, hand-computed)', function () {
    app(SyncPermissionRegistryHandler::class)->handle(new SyncPermissionRegistry);

    $till = SalesWorld::till();
    $authority = PricingSeed::authority('AQR2');
    $taxA = PricingSeed::tax($authority, 'QRA');
    $taxB = PricingSeed::tax($authority, 'QRB');
    PricingSeed::rate($taxA, '10.0000', '2020-01-01');
    PricingSeed::rate($taxB, '5.0000', '2020-01-01');
    $group = PricingSeed::group('QR2', ['is_inclusive' => false]);
    PricingSeed::member($group, $taxA, 1);
    PricingSeed::member($group, $taxB, 2);
    $catalogue = SalesWorld::product('PR2', '100.0000', $group, ['allow_price_override' => true]);

    $manager = apvEmployeeWithPin('manager');
    AccessSeed::attach($manager, $till['branch']);
    AccessSeed::assign($manager, AccessSeed::role('role_manager', [APV_KEY]), $till['branch']);

    $w = ['till' => $till, 'product' => $catalogue['product'], 'unit' => $catalogue['unit'], 'manager' => $manager, 'cash' => SalesSeed::paymentMethod('CASH', ['type' => 'cash', 'allows_change' => true])];
    qrTier($w, '10.0000', '80.0000');

    ['sale' => $sale, 'line' => $line] = qrSold($w, '10');

    expect(SalesSeed::row('sale_items', $line))->toMatchArray(['subtotal' => '800.0000', 'tax_amount' => '120.0000', 'line_total' => '920.0000']);

    $first = qrRefund($w, $sale, $line, '5', '575');
    $second = qrRefund($w, $sale, $line, '5', '345');
    $breakdown = json_decode((string) $second['tax_breakdown'], true);
    $byTax = [];

    foreach ($breakdown['lines'] as $row) {
        $byTax[strtoupper((string) $row['tax_id'])] = $row;
    }

    expect($first['line_total'])->toEqual('575.0000')
        ->and($second)->toMatchArray(['subtotal' => '300.0000', 'tax_amount' => '45.0000', 'line_total' => '345.0000'])
        ->and($breakdown['net'])->toEqual('300.0000')
        ->and($breakdown['total'])->toEqual('45.0000')
        ->and($byTax[strtoupper($taxA)]['base'])->toEqual('300.0000')
        ->and($byTax[strtoupper($taxA)]['amount'])->toEqual('30.0000')
        ->and($byTax[strtoupper($taxB)]['base'])->toEqual('300.0000')
        ->and($byTax[strtoupper($taxB)]['amount'])->toEqual('15.0000');
});

// AC-55A fallback. Two lines at the same override payload, approved by manager (A) and manager2 (B). Line 1's recorded approver is then
// set to a THIRD employee (as if the record had drifted), so no approval's decider equals it: the spec says the matching falls back
// to another matching row and the line's price_overridden_by becomes THAT row's decider. Which row: the LOWEST id, never an unordered
// first row. The two approval ids are rewritten so that id order is the REVERSE of insertion order, so an unordered read is detectable.
it('falls back to the lowest-id matching approval, and names its decider, when none matches the line own approver (AC-55A)', function () {
    $w = qrWorld('10.0000');
    $sale = SalesWorld::sale($w['till']);
    $payload = ['product_id' => $w['product'], 'unit_id' => $w['unit'], 'unit_price' => '8.0000'];

    $a1 = qrApproval($w, ['type' => 'price_override', 'reference_type' => 'sale', 'reference_id' => $sale, 'payload' => $payload], 'manager');
    $a2 = qrApproval($w, ['type' => 'price_override', 'reference_type' => 'sale', 'reference_id' => $sale, 'payload' => $payload], 'manager2');
    $first = qrLine($w, $sale, '1', ['unit_price' => '8.0000', 'approval_id' => $a1]);
    qrLine($w, $sale, '1', ['unit_price' => '8.0000', 'approval_id' => $a2]);

    // Insertion order stays a1 then a2; id order becomes a2 (lowest) then a1.
    $table = SalesSeed::connection()->table('approval_requests');
    $table->where('id', $a1)->update(['id' => '7ZZZZZZZZZZZZZZZZZZZZZZZZZ']);
    $table->where('id', $a2)->update(['id' => '01AAAAAAAAAAAAAAAAAAAAAAAA']);

    $ghost = SalesSeed::employee('ghost');
    SalesSeed::connection()->table('sale_items')->where('id', $first)->update(['price_overridden_by' => $ghost]);

    app(UpdateSaleLineHandler::class)->handle(new UpdateSaleLine(['sale_id' => $sale, 'line_id' => $first, 'quantity' => '2'], $w['till']['cashier']));

    expect(SalesSeed::row('sale_items', $first)['price_overridden_by'])->toBe($w['manager2']);
});

// W-7 (reviewer round 2), recorded as observed behaviour. A FIXED discount of 5.0000 on 3 units at 10.0000, zero-rate group:
// subtotal 30, discount 5, line_total 25. A re-quote of ONE unit applies the fixed 5 to that unit again: subtotal 10, discount 5,
// line_total 5. Refund 1 + 1 (5 + 5), then the last unit is the remainder: subtotal 30 - 20 = 10, discount 5 - 10 = -5,
// line_total 25 - 10 = 15. The money sums to the charge exactly (5 + 5 + 15 = 25); the discount column of the last line is negative.
it('records what the last-quantity remainder stores on a fixed-discount line: exact money, a negative discount column (W-7)', function () {
    $w = qrWorld('10.0000', '0.0000');
    $fixed = PricingSeed::discount('QRFIX', ['type' => 'fixed', 'value' => '5.0000']);

    ['sale' => $sale, 'line' => $line] = qrSold($w, '3', ['discount_id' => $fixed]);

    expect(SalesSeed::row('sale_items', $line))->toMatchArray(['subtotal' => '30.0000', 'discount_amount' => '5.0000', 'line_total' => '25.0000']);

    $a = qrRefund($w, $sale, $line, '1', '5');
    $b = qrRefund($w, $sale, $line, '1', '5');
    $c = qrRefund($w, $sale, $line, '1', '15');

    expect($a)->toMatchArray(['subtotal' => '10.0000', 'discount_amount' => '5.0000', 'line_total' => '5.0000'])
        ->and($c)->toMatchArray(['subtotal' => '10.0000', 'discount_amount' => '-5.0000', 'line_total' => '15.0000'])
        ->and(Money::sum([Money::of($a['line_total']), Money::of($b['line_total']), Money::of($c['line_total'])]))->toBe('25.0000');
});

// W-10 (reviewer round 2), recorded as observed behaviour. A tier list prices a unit at 10.0000 (base 100.0000, 10 percent tax);
// 2 units are sold at 20.0000 + 2.0000 = 22.0000. The list's window is then moved to start after the sale, so the re-quote of ONE unit
// falls back to the base: 100 + 10 = 110.0000, dearer than the whole charge of 22. Refunding a single unit of the two (NOT the last
// quantity) is refused over_refund by the money cap, although the customer is owed 11 for it; only the whole 2 units can go back.
it('refuses one unit of a two-unit line as over_refund when the re-quote drifted above the whole charge, and takes both for 22 (W-10)', function () {
    $w = qrWorld('100.0000');
    $list = PricingSeed::priceList('DRIFT', ['currency' => 'LKR']);
    PricingSeed::item($list, $w['product'], $w['unit'], '1.0000', '10.0000');
    PricingSeed::assignment($list, 'branch', $w['till']['branch']);

    ['sale' => $sale, 'line' => $line] = qrSold($w, '2');

    expect(SalesSeed::row('sale_items', $line)['line_total'])->toEqual('22.0000');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-14 10:00:00.000'));
    SalesSeed::connection()->table('price_lists')->where('id', $list)->update(['starts_at' => '2026-11-13 00:00:00.000']);

    $approval = qrApproval($w, ['type' => 'refund', 'reference_type' => 'sale', 'reference_id' => $sale]);

    SalesRefusals::expect(SalesRefusal::OverRefund, fn () => app(RefundSaleHandler::class)->handle(new RefundSale([
        'terminal_id' => $w['till']['terminal'], 'reference_sale_id' => $sale, 'approval_id' => $approval,
        'lines' => [['refund_of_item_id' => $line, 'unit_id' => $w['unit'], 'quantity' => '1']],
        'tenders' => [['payment_method_id' => $w['cash'], 'offered' => '11']],
    ], $w['till']['cashier'])));

    $all = qrRefund($w, $sale, $line, '2', '22');

    expect($all['line_total'])->toEqual('22.0000');
});
