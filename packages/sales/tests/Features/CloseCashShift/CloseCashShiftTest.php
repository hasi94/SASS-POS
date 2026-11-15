<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\CloseCashShift;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLine;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLineHandler;
use Flexipos\Sales\Features\AddTender\AddTender;
use Flexipos\Sales\Features\AddTender\AddTenderHandler;
use Flexipos\Sales\Features\CloseCashShift\CloseCashShift;
use Flexipos\Sales\Features\CloseCashShift\CloseCashShiftHandler;
use Flexipos\Sales\Features\CloseCashShift\CloseCashShiftResult;
use Flexipos\Sales\Features\CompleteSale\CompleteSale;
use Flexipos\Sales\Features\CompleteSale\CompleteSaleHandler;
use Flexipos\Sales\Features\RefundSale\RefundSale;
use Flexipos\Sales\Features\RefundSale\RefundSaleHandler;
use Flexipos\Sales\Features\VoidSale\VoidSale;
use Flexipos\Sales\Features\VoidSale\VoidSaleHandler;
use Flexipos\Sales\Tests\Support\LockMarkingGrammar;
use Flexipos\Sales\Tests\Support\SalesQueryLog;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 21 - CloseCashShift (AC-34, AC-35, AC-37; D-21, D-39, D-50; R-5, R-8)
|--------------------------------------------------------------------------
|
| The shift is built through the real slices, so the figures are what the till
| wrote. The mixed-shift row is the hand-checked one: every number in it is
| worked out in the comment above it, never read back from the code under test.
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
 * A till with a 110.0000 product (100 plus 10 percent), cash (gives change) and card, and a manager.
 *
 * @return array<string, mixed>
 */
function ccsWorld(): array
{
    $till = SalesWorld::till();
    $group = SalesWorld::taxGroup('TX10', '10.0000');
    $catalogue = SalesWorld::product('P1', '100.0000', $group);

    return [
        'till' => $till,
        'product' => $catalogue['product'],
        'unit' => $catalogue['unit'],
        'cash' => SalesSeed::paymentMethod('CASH', ['type' => 'cash', 'allows_change' => true]),
        'card' => SalesSeed::paymentMethod('CARD', ['type' => 'card']),
        'manager' => SalesSeed::employee('manager'),
    ];
}

/**
 * A sale of `$quantity` units on the shift, paid by one tender, completed.
 *
 * @param  array<string, mixed>  $w
 * @return array{sale: string, items: list<string>}
 */
function ccsSale(array $w, string $quantity, string $method, string $offered, bool $complete = true): array
{
    $sale = SalesWorld::sale($w['till']);

    app(AddSaleLineHandler::class)->handle(new AddSaleLine(['sale_id' => $sale, 'product_id' => $w['product'], 'unit_id' => $w['unit'], 'quantity' => $quantity], $w['till']['cashier']));
    app(AddTenderHandler::class)->handle(new AddTender(['id' => (string) Str::ulid(), 'sale_id' => $sale, 'payment_method_id' => $w[$method], 'offered' => $offered], $w['till']['cashier']));

    if ($complete) {
        app(CompleteSaleHandler::class)->handle(new CompleteSale(['sale_id' => $sale], $w['till']['cashier']));
    }

    return ['sale' => $sale, 'items' => SalesSeed::connection()->table('sale_items')->where('sale_id', $sale)->orderBy('line_no')->pluck('id')->all()];
}

/**
 * @param  array<string, mixed>  $w
 */
function ccsClose(array $w, string $counted, ?string $shift = null, ?string $actor = 'cashier'): CloseCashShiftResult
{
    return app(CloseCashShiftHandler::class)->handle(new CloseCashShift(['shift_id' => $shift ?? $w['till']['shift'], 'counted_cash' => $counted], $actor === 'cashier' ? $w['till']['cashier'] : $actor));
}

/**
 * @return array<string, mixed>
 */
function ccsState(): array
{
    $c = SalesSeed::connection();
    $rows = static fn (string $table): array => $c->table($table)->get()->map(fn ($r) => (array) $r)->all();

    return ['shifts' => $rows('cash_shifts'), 'sales' => $rows('sales'), 'tenders' => $rows('sale_tenders'), 'audit' => SalesSeed::audit()];
}

describe('the figures (AC-34, AC-35; D-39)', function () {
    // The shift opens with 100.0000. Hand-worked:
    //   A  1 unit, cash 120 offered: tender 110, change 10        -> cash drawer + 110
    //   B  2 units, card 220                                       -> not cash
    //   C  1 unit, cash 110, completed, then voided                -> excluded (a voided sale nets to zero)
    //   R  refund of A's one unit, cash 110 out                    -> cash drawer - 110
    //   movements: cash_in 50, drop 20                             -> + 50 - 20
    //   expected_cash = 100 + 110 - 110 + 50 - 20 = 130.0000
    //   sales_count = 2 (A is refunded and B completed; C is voided)   refunds_count = 1
    //   gross = 100 + 200 = 300.0000   discounts = 0.0000   tax = 10 + 20 = 30.0000
    //   net = (110 + 220) - 110 = 220.0000
    //   totals_by_method = CARD 220.0000, CASH 110 - 110 = 0.0000 (C's tenders are not counted)
    it('computes the expected cash, the variance and every shift figure over a mixed shift, with exact strings (AC-35)', function () {
        $w = ccsWorld();
        $a = ccsSale($w, '1', 'cash', '120');
        ccsSale($w, '2', 'card', '220');
        $c = ccsSale($w, '1', 'cash', '110');

        $approval = SalesSeed::approval($w['till']['branch'], $w['till']['cashier'], [
            'type' => 'void', 'reference_type' => 'sale', 'reference_id' => $c['sale'], 'status' => 'approved',
            'decided_by' => $w['manager'], 'decided_at' => SalesSeed::NOW, 'decision_method' => 'pin_local',
        ]);
        app(VoidSaleHandler::class)->handle(new VoidSale(['sale_id' => $c['sale'], 'reason' => 'Mistake', 'approval_id' => $approval], $w['till']['cashier']));

        $refundApproval = SalesSeed::approval($w['till']['branch'], $w['till']['cashier'], [
            'type' => 'refund', 'reference_type' => 'sale', 'reference_id' => $a['sale'], 'status' => 'approved',
            'decided_by' => $w['manager'], 'decided_at' => SalesSeed::NOW, 'decision_method' => 'pin_local',
        ]);
        app(RefundSaleHandler::class)->handle(new RefundSale([
            'terminal_id' => $w['till']['terminal'], 'reference_sale_id' => $a['sale'], 'approval_id' => $refundApproval,
            'lines' => [['refund_of_item_id' => $a['items'][0], 'unit_id' => $w['unit'], 'quantity' => '1']],
            'tenders' => [['payment_method_id' => $w['cash'], 'offered' => '110']],
        ], $w['till']['cashier']));

        SalesSeed::movement($w['till']['shift'], $w['till']['cashier'], 'cash_in', '50.0000');
        SalesSeed::movement($w['till']['shift'], $w['till']['cashier'], 'drop', '20.0000');

        $result = ccsClose($w, '125');

        expect($result->expectedCash)->toBe('130.0000')
            ->and($result->countedCash)->toBe('125.0000')
            ->and($result->variance)->toBe('-5.0000')
            ->and($result->salesCount)->toBe(2)
            ->and($result->refundsCount)->toBe(1)
            ->and($result->gross)->toBe('300.0000')
            ->and($result->discounts)->toBe('0.0000')
            ->and($result->tax)->toBe('30.0000')
            ->and($result->net)->toBe('220.0000')
            ->and($result->totalsByMethod)->toBe(['CARD' => '220.0000', 'CASH' => '0.0000'])
            ->and($result->replayed)->toBeFalse();

        $row = SalesSeed::row('cash_shifts', $w['till']['shift']);

        expect($row)->toMatchArray([
            'status' => 'closed', 'closed_at' => '2026-11-12 10:00:00.000', 'closed_by' => $w['till']['cashier'],
            'expected_cash' => '130.0000', 'counted_cash' => '125.0000', 'variance' => '-5.0000',
            'gross' => '300.0000', 'discounts' => '0.0000', 'tax' => '30.0000', 'net' => '220.0000',
            'sales_count' => 2, 'refunds_count' => 1,
        ])->and(json_decode((string) $row['totals_by_method'], true))->toBe(['CARD' => '220.0000', 'CASH' => '0.0000']);

        $audits = array_values(array_filter(SalesSeed::audit(), static fn (array $r): bool => $r['action'] === 'sales_cash_shift_closed'));

        expect($audits)->toHaveCount(1)->and($audits[0])->toMatchArray(['module' => 'sales', 'subject_type' => 'cash_shift', 'subject_id' => $w['till']['shift']]);
    });

    it('reads the variance one short, one over and exact', function (string $counted, string $variance) {
        $w = ccsWorld();
        ccsSale($w, '1', 'cash', '110');

        // 100 opening + 110 cash.
        expect(ccsClose($w, $counted)->variance)->toBe($variance);
    })->with([
        'short' => ['205', '-5.0000'],
        'over' => ['215.5', '5.5000'],
        'exact' => ['210', '0.0000'],
    ]);

    it('stores an empty shift as zeros and an empty map', function () {
        $w = ccsWorld();

        $result = ccsClose($w, '100');

        expect($result->expectedCash)->toBe('100.0000')
            ->and($result->variance)->toBe('0.0000')
            ->and($result->salesCount)->toBe(0)
            ->and($result->gross)->toBe('0.0000')
            ->and($result->net)->toBe('0.0000')
            ->and($result->totalsByMethod)->toBe([])
            ->and(SalesSeed::row('cash_shifts', $w['till']['shift'])['totals_by_method'])->toBe('{}');
    });

    it('counts only the shift\'s own sales, not another terminal\'s', function () {
        $w = ccsWorld();
        $second = SalesWorld::secondTill($w['till'], 'T2');
        ccsSale($w, '1', 'cash', '110');
        $other = $w;
        $other['till'] = $second;
        ccsSale($other, '3', 'cash', '330');

        $result = ccsClose($w, '210');

        expect($result->salesCount)->toBe(1)->and($result->gross)->toBe('100.0000')->and($result->expectedCash)->toBe('210.0000');
    });
});

describe('what blocks a close (AC-34; F-15)', function () {
    it('refuses while a draft or a pending_payment sale names the shift, and lets a held sale through', function (string $status, bool $blocks) {
        $w = ccsWorld();
        $open = ccsSale($w, '1', 'cash', '50', false);

        if ($status === 'draft') {
            SalesSeed::connection()->table('sale_tenders')->where('sale_id', $open['sale'])->delete();
        }

        SalesSeed::connection()->table('sales')->where('id', $open['sale'])->update(['status' => $status]);
        $before = ccsState();

        if ($blocks) {
            SalesRefusals::expect(SalesRefusal::ShiftHasOpenSales, fn () => ccsClose($w, '100'));

            expect(ccsState())->toBe($before);
        } else {
            expect(ccsClose($w, '100')->salesCount)->toBe(0);
        }
    })->with([
        'draft' => ['draft', true],
        'pending payment' => ['pending_payment', true],
        'held' => ['held', false],
    ]);

    it('refuses a shift that is not open as shift_not_open', function (string $status) {
        $w = ccsWorld();
        SalesSeed::connection()->table('cash_shifts')->where('id', $w['till']['shift'])->update(['status' => $status]);
        $before = ccsState();

        SalesRefusals::expect(SalesRefusal::ShiftNotOpen, fn () => ccsClose($w, '100'));

        expect(ccsState())->toBe($before);
    })->with(['closing']);

    it('requires counted_cash, as a decimal string', function (mixed $counted) {
        $w = ccsWorld();

        $handler = app(CloseCashShiftHandler::class);

        SalesRefusals::expect(SalesRefusal::Validation, fn () => $handler->handle(new CloseCashShift(array_filter(['shift_id' => $w['till']['shift'], 'counted_cash' => $counted], static fn ($v): bool => $v !== '__absent__'), $w['till']['cashier'])));

        expect(SalesSeed::row('cash_shifts', $w['till']['shift'])['status'])->toBe('open');
    })->with(['absent' => ['__absent__'], 'null' => [null], 'negative' => ['-1'], 'float' => [10.5], 'five places' => ['1.23456'], 'text' => ['ten']]);
});

describe('replay (AC-37; D-50, R-8)', function () {
    it('returns the stored figures for a replay with the same counted cash, numerically compared, with no second audit row', function () {
        $w = ccsWorld();
        ccsSale($w, '1', 'cash', '110');
        $first = ccsClose($w, '210');
        $before = ccsState();

        $again = ccsClose($w, '210.0000');
        $integer = ccsClose($w, '210');

        expect($again->replayed)->toBeTrue()
            ->and($integer->replayed)->toBeTrue()
            ->and($again->expectedCash)->toBe($first->expectedCash)
            ->and($again->variance)->toBe($first->variance)
            ->and($again->totalsByMethod)->toBe($first->totalsByMethod)
            ->and(ccsState())->toBe($before);
    });

    it('refuses a replay with a different counted cash as replay_conflict, on a closed and on a reconciled shift', function (string $status) {
        $w = ccsWorld();
        ccsClose($w, '100');
        SalesSeed::connection()->table('cash_shifts')->where('id', $w['till']['shift'])->update(['status' => $status, 'reconciled_by' => $status === 'reconciled' ? $w['manager'] : null]);
        $before = ccsState();

        SalesRefusals::expect(SalesRefusal::ReplayConflict, fn () => ccsClose($w, '101'));

        expect(ccsState())->toBe($before);
        expect(ccsClose($w, '100')->replayed)->toBeTrue();
    })->with(['closed', 'reconciled']);
});

describe('lookups and lock order (R-5)', function () {
    it('refuses a shift that is not there, a bad id, other fields and a missing or unknown actor', function () {
        $w = ccsWorld();
        $handler = app(CloseCashShiftHandler::class);

        SalesRefusals::expect(SalesRefusal::NotFound, fn () => ccsClose($w, '100', (string) Str::ulid()));
        SalesRefusals::expect(SalesRefusal::Validation, fn () => ccsClose($w, '100', 'x'));
        SalesRefusals::expect(SalesRefusal::Validation, fn () => $handler->handle(new CloseCashShift(['shift_id' => $w['till']['shift'], 'counted_cash' => '100', 'expected_cash' => '5'], $w['till']['cashier'])));
        SalesRefusals::expect(SalesRefusal::Validation, fn () => ccsClose($w, '100', null, null));
        SalesRefusals::expect(SalesRefusal::NotFound, fn () => ccsClose($w, '100', null, (string) Str::ulid()));

        expect(SalesSeed::row('cash_shifts', $w['till']['shift'])['status'])->toBe('open');
    });

    it('takes the shift FOR UPDATE as its first statement, before it reads a single sale, tender or movement', function () {
        $w = ccsWorld();
        ccsSale($w, '1', 'cash', '110');

        $inside = SalesQueryLog::inTransaction(SalesQueryLog::during(fn () => ccsClose($w, '210')));

        expect($inside[0])->toContain('"cash_shifts"')->and($inside[0])->toContain(LockMarkingGrammar::FOR_UPDATE);

        $firstRead = null;

        foreach ($inside as $position => $sql) {
            if (preg_match('/from "(sales|sale_tenders|cash_movements)"/', $sql) === 1) {
                $firstRead = $position;

                break;
            }
        }

        expect($firstRead)->toBeGreaterThan(0);
    });

    it('writes no stock, number or general-ledger table', function () {
        $w = ccsWorld();
        ccsSale($w, '1', 'cash', '110');
        $numbers = SalesSeed::connection()->table('number_sequences')->get()->map(fn ($r) => (array) $r)->all();

        $log = SalesQueryLog::during(fn () => ccsClose($w, '210'));

        $touched = array_filter($log, static fn (string $sql): bool => preg_match('/(from|into|update|join) "(stock_|journal_|inventory_|number_sequences)/', $sql) === 1);

        expect($touched)->toBe([])->and(SalesSeed::connection()->table('number_sequences')->get()->map(fn ($r) => (array) $r)->all())->toBe($numbers);
    });
});
