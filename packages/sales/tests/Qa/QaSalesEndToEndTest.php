<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Qa;

use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
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
use Flexipos\Sales\Features\ReconcileCashShift\ReconcileCashShift;
use Flexipos\Sales\Features\ReconcileCashShift\ReconcileCashShiftHandler;
use Flexipos\Sales\Features\RefundSale\RefundSale;
use Flexipos\Sales\Features\RefundSale\RefundSaleHandler;
use Flexipos\Sales\Features\RequestApproval\RequestApproval;
use Flexipos\Sales\Features\RequestApproval\RequestApprovalHandler;
use Flexipos\Sales\Features\VoidSale\VoidSale;
use Flexipos\Sales\Features\VoidSale\VoidSaleHandler;
use Flexipos\Sales\Registry\SalesPermissions;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| QA - T-030 end to end through the REAL approval chain, and hand-computed money
|--------------------------------------------------------------------------
|
| Every shipped consuming-write test seeds its approval row by hand
| (SalesSeed::approval). That proves the gate against the row shape the test
| author believed RequestApproval/DecideApproval write. These rows go through
| RequestApproval -> DecideApproval (a real PIN, a real permission) -> the
| consuming write, so a shape mismatch between the two halves cannot hide.
|
| Every expected figure is worked by hand in the comment above its test and is
| never read back from the code under test.
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
function qaeWorld(string $price, bool $inclusive = false): array
{
    // The real registry sync is what makes sales.approvals.decide and sales.shifts.reconcile known to the resolver.
    app(SyncPermissionRegistryHandler::class)->handle(new SyncPermissionRegistry);

    $till = SalesWorld::till();
    $group = SalesWorld::taxGroup('TXQ', '10.0000', $inclusive);
    $catalogue = SalesWorld::product('PQ', $price, $group, ['allow_price_override' => true]);

    $manager = apvEmployeeWithPin('manager');
    AccessSeed::attach($manager, $till['branch']);
    AccessSeed::assign($manager, AccessSeed::role('role_manager', [APV_KEY]), $till['branch']);

    return [
        'till' => $till,
        'product' => $catalogue['product'],
        'unit' => $catalogue['unit'],
        'manager' => $manager,
        'cash' => SalesSeed::paymentMethod('CASH', ['type' => 'cash', 'allows_change' => true]),
        'card' => SalesSeed::paymentMethod('CARD', ['type' => 'card']),
    ];
}

/**
 * A decided approval, made the way the till makes one: the cashier requests, the manager decides by PIN.
 *
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $request
 */
function qaeApproval(array $w, array $request): string
{
    $id = app(RequestApprovalHandler::class)->handle(new RequestApproval(array_merge(['branch_id' => $w['till']['branch']], $request), $w['till']['cashier']))->approvalId;

    app(DecideApprovalHandler::class)->handle(new DecideApproval(['approval_id' => $id, 'employee_code' => 'manager', 'pin' => APV_PIN, 'decision' => 'approved']));

    return $id;
}

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $fields
 */
function qaeLine(array $w, string $sale, string $quantity, array $fields = []): string
{
    return app(AddSaleLineHandler::class)->handle(new AddSaleLine(array_merge(['sale_id' => $sale, 'product_id' => $w['product'], 'unit_id' => $w['unit'], 'quantity' => $quantity], $fields), $w['till']['cashier']))->lineId;
}

/**
 * @param  array<string, mixed>  $w
 */
function qaeTender(array $w, string $sale, string $method, string $offered): void
{
    app(AddTenderHandler::class)->handle(new AddTender(['id' => (string) Str::ulid(), 'sale_id' => $sale, 'payment_method_id' => $w[$method], 'offered' => $offered], $w['till']['cashier']));
}

/**
 * @param  array<string, mixed>  $w
 */
function qaeComplete(array $w, string $sale): void
{
    app(CompleteSaleHandler::class)->handle(new CompleteSale(['sale_id' => $sale], $w['till']['cashier']));
}

// Hand-worked. Product 24.0000 on a 10 percent INCLUSIVE group, 3 units:
//   gross 72.0000, net = 72 / 1.1 = 65.454545... -> 65.4545, tax = 72 - 65.4545 = 6.5455 (the last member absorbs the residue)
//   line_total 72.0000, sale total 72.0000, tax_total 6.5455.
// Tenders: card 30 (exact, no change); then cash offered 50 against the 42 still owing: amount 42, tendered 50, change 8.
//   paid_total = 30 + 42 = 72.0000, change_due 8.0000.
// Shift (opening 100.0000): the card never touches the drawer, the cash counts by AMOUNT: expected 100 + 42 = 142.0000
//   (a mutant that counted `tendered` would say 150).
//   counted 135.5 -> variance -6.5000 (short). The close figures are gross 72.0000 (an inclusive subtotal is the gross),
//   discounts 0.0000, tax 6.5455, net 72.0000, by method CARD 30.0000 and CASH 42.0000.
// Reconcile by a supervisor holding the key at the branch; the figures are not touched.
it('works an inclusive-tax sale with a split, change-giving tender, and closes the shift with a short drawer (hand-computed)', function () {
    $w = qaeWorld('24.0000', inclusive: true);
    $sale = SalesWorld::sale($w['till']);
    $line = qaeLine($w, $sale, '3');

    expect(SalesSeed::row('sale_items', $line))->toMatchArray(['subtotal' => '72.0000', 'discount_amount' => '0.0000', 'tax_amount' => '6.5455', 'line_total' => '72.0000'])
        ->and(SalesSeed::row('sales', $sale))->toMatchArray(['total' => '72.0000', 'tax_total' => '6.5455']);

    qaeTender($w, $sale, 'card', '30');

    $cash = app(AddTenderHandler::class)->handle(new AddTender(['id' => (string) Str::ulid(), 'sale_id' => $sale, 'payment_method_id' => $w['cash'], 'offered' => '50'], $w['till']['cashier']));

    expect($cash->amount)->toBe('42.0000')->and($cash->tendered)->toBe('50.0000')->and($cash->changeGiven)->toBe('8.0000');

    $done = app(CompleteSaleHandler::class)->handle(new CompleteSale(['sale_id' => $sale], $w['till']['cashier']));

    expect($done->paidTotal)->toBe('72.0000')->and($done->changeDue)->toBe('8.0000')->and($done->number)->toBe('S000001');

    $closed = app(CloseCashShiftHandler::class)->handle(new CloseCashShift(['shift_id' => $w['till']['shift'], 'counted_cash' => '135.5'], $w['till']['cashier']));

    expect($closed->expectedCash)->toBe('142.0000')
        ->and($closed->variance)->toBe('-6.5000')
        ->and($closed->gross)->toBe('72.0000')
        ->and($closed->discounts)->toBe('0.0000')
        ->and($closed->tax)->toBe('6.5455')
        ->and($closed->net)->toBe('72.0000')
        ->and($closed->salesCount)->toBe(1)
        ->and($closed->totalsByMethod)->toBe(['CARD' => '30.0000', 'CASH' => '42.0000']);

    $supervisor = AccessSeed::employee('supervisor');
    AccessSeed::attach($supervisor, $w['till']['branch']);
    AccessSeed::assign($supervisor, AccessSeed::role('role_supervisor', [SalesPermissions::SHIFTS_RECONCILE]), $w['till']['branch']);

    $reconciled = app(ReconcileCashShiftHandler::class)->handle(new ReconcileCashShift(['shift_id' => $w['till']['shift']], $supervisor));

    expect($reconciled->status)->toBe('reconciled')
        ->and(SalesSeed::row('cash_shifts', $w['till']['shift']))->toMatchArray(['status' => 'reconciled', 'reconciled_by' => $supervisor, 'variance' => '-6.5000', 'expected_cash' => '142.0000']);
});

// Hand-worked. 10 percent INCLUSIVE group, list 24.0000, one line of 3 sold at an approved override of 7.3333:
//   subtotal = 7.3333 x 3 = 21.9999;  net = 21.9999 / 1.1 = 19.99990909 -> 19.9999;  tax = 21.9999 - 19.9999 = 2.0000
//   line_total 21.9999, paid in cash exactly.
// Refund 1 unit (the price CHARGED, 7.3333, not the list 24): net = 7.3333 / 1.1 = 6.66663636 -> 6.6666, tax 0.6667,
//   line_total 7.3333.   The original becomes partially_refunded, refunded_quantity 1.0000.
// Refund 2 units: subtotal 14.6666, net = 14.6666 / 1.1 = 13.33327272 -> 13.3333, tax 1.3333, line_total 14.6666.
//   7.3333 + 14.6666 = 21.9999 = exactly what was charged: never more.  The original becomes refunded.
// A third refund is fully_refunded. Both refund approvals and the override approval come through RequestApproval/DecideApproval.
it('refunds an overridden inclusive-tax line in two parts at the price charged, summing to exactly what was paid (hand-computed, real approvals)', function () {
    $w = qaeWorld('24.0000', inclusive: true);
    $sale = SalesWorld::sale($w['till']);

    $override = qaeApproval($w, ['type' => 'price_override', 'reference_type' => 'sale', 'reference_id' => $sale, 'payload' => ['product_id' => $w['product'], 'unit_id' => $w['unit'], 'unit_price' => '7.3333']]);
    $line = qaeLine($w, $sale, '3', ['unit_price' => '7.3333', 'approval_id' => $override]);

    $original = SalesSeed::row('sale_items', $line);

    expect($original)->toMatchArray(['unit_price' => '7.3333', 'list_price' => '24.0000', 'subtotal' => '21.9999', 'tax_amount' => '2.0000', 'line_total' => '21.9999', 'price_overridden_by' => $w['manager']])
        ->and(SalesSeed::row('approval_requests', $override)['consumed_at'])->not->toBeNull();

    qaeTender($w, $sale, 'cash', '21.9999');
    qaeComplete($w, $sale);

    $refund = static function (string $quantity, string $payout) use ($w, $sale, $line) {
        $approval = qaeApproval($w, ['type' => 'refund', 'reference_type' => 'sale', 'reference_id' => $sale]);

        return app(RefundSaleHandler::class)->handle(new RefundSale([
            'terminal_id' => $w['till']['terminal'],
            'reference_sale_id' => $sale,
            'approval_id' => $approval,
            'lines' => [['refund_of_item_id' => $line, 'unit_id' => $w['unit'], 'quantity' => $quantity]],
            'tenders' => [['payment_method_id' => $w['cash'], 'offered' => $payout]],
        ], $w['till']['cashier']));
    };

    $first = $refund('1', '7.3333');
    $firstLine = SalesSeed::connection()->table('sale_items')->where('sale_id', $first->refundId)->first();

    expect($first->originalStatus)->toBe('partially_refunded')
        ->and($first->total)->toBe('7.3333')
        ->and((array) $firstLine)->toMatchArray(['unit_price' => '7.3333', 'subtotal' => '7.3333', 'tax_amount' => '0.6667', 'line_total' => '7.3333', 'price_overridden_by' => null, 'discount_amount' => '0.0000'])
        ->and(SalesSeed::row('sale_items', $line)['refunded_quantity'])->toEqual(1);

    $second = $refund('2', '14.6666');
    $secondLine = SalesSeed::connection()->table('sale_items')->where('sale_id', $second->refundId)->first();

    expect($second->originalStatus)->toBe('refunded')
        ->and((array) $secondLine)->toMatchArray(['unit_price' => '7.3333', 'subtotal' => '14.6666', 'tax_amount' => '1.3333', 'line_total' => '14.6666']);

    // 7.3333 + 14.6666 = 21.9999: the two refunds pay back exactly what the line charged.
    expect(bcadd((string) $firstLine->line_total, (string) $secondLine->line_total, 4))->toBe('21.9999')
        ->and(bcadd((string) $firstLine->line_total, (string) $secondLine->line_total, 4))->toBe(bcadd('0', (string) $original['line_total'], 4));

    SalesRefusals::expect(SalesRefusal::FullyRefunded, fn () => $refund('1', '7.3333'));
});

// Hand-worked. 10.0000 exclusive 10 percent, one unit with a 10 percent item discount: 10.0000 - 1.0000 = 9.0000, tax 0.9000,
// total 9.9000, paid in cash. Completing consumes the discount: used_count 0 -> 1.
// A VOID of the completed sale (real approval chain) posts a -9.9000 mirror and sets the sale voided, but D-37 says usage is NEVER released:
// used_count stays 1. The shift nets to zero cash for the sale: 100 + 9.9 - 9.9 = 100.0000.
// Reusing the same approval is approval_already_used.
it('voids a completed discounted sale through the real approval chain, never releases the discount usage, and refuses the approval twice (AC-22, D-37, AC-24)', function () {
    $w = qaeWorld('10.0000');
    $discount = PricingSeed::discount('QAD', []);
    $sale = SalesWorld::sale($w['till']);

    qaeLine($w, $sale, '1', ['discount_id' => $discount]);

    expect(SalesSeed::row('sales', $sale)['total'])->toEqual('9.9000');

    qaeTender($w, $sale, 'cash', '9.9');
    qaeComplete($w, $sale);

    expect(SalesSeed::row('discounts', $discount)['used_count'])->toEqual(1);

    $approval = qaeApproval($w, ['type' => 'void', 'reference_type' => 'sale', 'reference_id' => $sale]);

    $voided = app(VoidSaleHandler::class)->handle(new VoidSale(['sale_id' => $sale, 'reason' => 'Rung up twice', 'approval_id' => $approval], $w['till']['cashier']));

    expect(SalesSeed::row('sales', $sale))->toMatchArray(['status' => 'voided', 'voided_by' => $w['till']['cashier']])
        ->and(SalesSeed::row('sale_tenders', $voided->mirrorTenderIds[0])['amount'])->toEqual('-9.9000')
        ->and(SalesSeed::row('discounts', $discount)['used_count'])->toEqual(1)
        ->and(SalesSeed::row('approval_requests', $approval)['consumed_at'])->not->toBeNull();

    SalesRefusals::expect(SalesRefusal::InvalidTransition, fn () => app(VoidSaleHandler::class)->handle(new VoidSale(['sale_id' => $sale, 'reason' => 'again', 'approval_id' => $approval], $w['till']['cashier'])));

    // A second sale cannot spend the first sale's already-consumed void approval either.
    $other = SalesWorld::sale($w['till']);
    qaeLine($w, $other, '1');
    qaeTender($w, $other, 'cash', '11');
    qaeComplete($w, $other);

    SalesRefusals::expect(SalesRefusal::ApprovalAlreadyUsed, fn () => app(VoidSaleHandler::class)->handle(new VoidSale(['sale_id' => $other, 'reason' => 'steal', 'approval_id' => $approval], $w['till']['cashier'])));

    expect(SalesSeed::row('sales', $other)['status'])->toBe('completed');

    $closed = app(CloseCashShiftHandler::class)->handle(new CloseCashShift(['shift_id' => $w['till']['shift'], 'counted_cash' => '111'], $w['till']['cashier']));

    // 100 opening + 9.9 - 9.9 (voided, nets to zero) + 11.0 (the second sale) = 111.0000.
    expect($closed->expectedCash)->toBe('111.0000')->and($closed->variance)->toBe('0.0000')->and($closed->salesCount)->toBe(1);
});

// A goodwill refund and a gated discount, each through the real chain. Goodwill: list 10.0000 + 10 percent = 11.0000 paid out in cash,
// bound to goodwill_refund / null, so a sale-bound approval cannot be used for it and the goodwill one cannot be used twice.
it('takes a goodwill refund and a gated discount through the real approval chain (AC-23a, AC-29)', function () {
    $w = qaeWorld('10.0000');

    $goodwill = qaeApproval($w, ['type' => 'refund', 'reference_type' => 'goodwill_refund', 'reference_id' => null]);

    $result = app(RefundSaleHandler::class)->handle(new RefundSale([
        'terminal_id' => $w['till']['terminal'],
        'approval_id' => $goodwill,
        'lines' => [['product_id' => $w['product'], 'unit_id' => $w['unit'], 'quantity' => '1']],
        'tenders' => [['payment_method_id' => $w['cash'], 'offered' => '11']],
    ], $w['till']['cashier']));

    expect($result->total)->toBe('11.0000')->and($result->originalStatus)->toBeNull()
        ->and(SalesSeed::row('sales', $result->refundId))->toMatchArray(['type' => 'refund', 'reference_sale_id' => null, 'number' => 'R000001']);

    SalesRefusals::expect(SalesRefusal::ApprovalAlreadyUsed, fn () => app(RefundSaleHandler::class)->handle(new RefundSale([
        'terminal_id' => $w['till']['terminal'],
        'approval_id' => $goodwill,
        'lines' => [['product_id' => $w['product'], 'unit_id' => $w['unit'], 'quantity' => '1']],
        'tenders' => [['payment_method_id' => $w['cash'], 'offered' => '11']],
    ], $w['till']['cashier'])));

    $gated = PricingSeed::discount('QAG', ['requires_approval' => true]);
    $sale = SalesWorld::sale($w['till']);

    SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => qaeLine($w, $sale, '1', ['discount_id' => $gated]));

    $approval = qaeApproval($w, ['type' => 'discount', 'reference_type' => 'sale', 'reference_id' => $sale, 'payload' => ['discount_id' => $gated]]);
    $line = qaeLine($w, $sale, '1', ['discount_id' => $gated, 'approval_id' => $approval]);

    expect(SalesSeed::row('sale_items', $line)['line_total'])->toEqual('9.9000')
        ->and(SalesSeed::row('approval_requests', $approval)['consumed_at'])->not->toBeNull();
});

// BUG-1 (QA). One decided price_override approval, bound to a sale, a product, a unit and a price, is consumed by line 1.
// AC-7: an override is "accepted only when accompanied by a decided approval_requests row"; AC-55 / D-59 relax that for
// UpdateSaleLine re-pricing THE SAME LINE. A second, NEW line of the same product at the same price carries no approval at all,
// yet AddSaleLine lets LineApprovals::priorConsumed() match it against line 1's already-consumed row, so one approval
// authorises any number of extra override lines (and any quantity) in the sale, with price_overridden_by set to the old approver.
// The same holds for a gated discount (AC-23a).
it('refuses a SECOND new line at an override price without its own approval, as approval_required (AC-7, AC-24; BUG-1)', function () {
    $w = qaeWorld('10.0000');
    $sale = SalesWorld::sale($w['till']);

    $override = qaeApproval($w, ['type' => 'price_override', 'reference_type' => 'sale', 'reference_id' => $sale, 'payload' => ['product_id' => $w['product'], 'unit_id' => $w['unit'], 'unit_price' => '8.0000']]);
    qaeLine($w, $sale, '1', ['unit_price' => '8.0000', 'approval_id' => $override]);

    expect(SalesSeed::connection()->table('approval_requests')->whereNotNull('consumed_at')->count())->toBe(1);

    SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => qaeLine($w, $sale, '100', ['unit_price' => '8.0000']));

    expect(SalesSeed::connection()->table('sale_items')->where('sale_id', $sale)->count())->toBe(1);
});

it('refuses a SECOND new line with a gated discount without its own approval, as approval_required (AC-23a; BUG-1)', function () {
    $w = qaeWorld('10.0000');
    $gated = PricingSeed::discount('QAGATE', ['requires_approval' => true]);
    $sale = SalesWorld::sale($w['till']);

    $approval = qaeApproval($w, ['type' => 'discount', 'reference_type' => 'sale', 'reference_id' => $sale, 'payload' => ['discount_id' => $gated]]);
    qaeLine($w, $sale, '1', ['discount_id' => $gated, 'approval_id' => $approval]);

    SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => qaeLine($w, $sale, '100', ['discount_id' => $gated]));

    expect(SalesSeed::connection()->table('sale_items')->where('sale_id', $sale)->count())->toBe(1);
});
