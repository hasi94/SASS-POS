<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Invariants;

use Closure;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLine;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLineHandler;
use Flexipos\Sales\Features\AddTender\AddTender;
use Flexipos\Sales\Features\AddTender\AddTenderHandler;
use Flexipos\Sales\Features\CloseCashShift\CloseCashShift;
use Flexipos\Sales\Features\CloseCashShift\CloseCashShiftHandler;
use Flexipos\Sales\Features\CloseCashShift\CloseCashShiftResult;
use Flexipos\Sales\Features\CompleteSale\CompleteSale;
use Flexipos\Sales\Features\CompleteSale\CompleteSaleHandler;
use Flexipos\Sales\Features\CreateSale\CreateSale;
use Flexipos\Sales\Features\CreateSale\CreateSaleHandler;
use Flexipos\Sales\Features\GetSale\GetSale;
use Flexipos\Sales\Features\GetSale\GetSaleHandler;
use Flexipos\Sales\Features\HoldSale\HoldSale;
use Flexipos\Sales\Features\HoldSale\HoldSaleHandler;
use Flexipos\Sales\Features\ListApprovalRequests\ListApprovalRequests;
use Flexipos\Sales\Features\ListApprovalRequests\ListApprovalRequestsHandler;
use Flexipos\Sales\Features\ListCashMovements\ListCashMovements;
use Flexipos\Sales\Features\ListCashMovements\ListCashMovementsHandler;
use Flexipos\Sales\Features\ListCashShifts\ListCashShifts;
use Flexipos\Sales\Features\ListCashShifts\ListCashShiftsHandler;
use Flexipos\Sales\Features\ListSaleLines\ListSaleLines;
use Flexipos\Sales\Features\ListSaleLines\ListSaleLinesHandler;
use Flexipos\Sales\Features\ListSales\ListSales;
use Flexipos\Sales\Features\ListSales\ListSalesHandler;
use Flexipos\Sales\Features\OpenCashShift\OpenCashShift;
use Flexipos\Sales\Features\OpenCashShift\OpenCashShiftHandler;
use Flexipos\Sales\Features\RecallSale\RecallSale;
use Flexipos\Sales\Features\RecallSale\RecallSaleHandler;
use Flexipos\Sales\Features\ReconcileCashShift\ReconcileCashShift;
use Flexipos\Sales\Features\ReconcileCashShift\ReconcileCashShiftHandler;
use Flexipos\Sales\Features\RefundSale\RefundSale;
use Flexipos\Sales\Features\RefundSale\RefundSaleHandler;
use Flexipos\Sales\Features\VoidSale\VoidSale;
use Flexipos\Sales\Features\VoidSale\VoidSaleHandler;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Registry\SalesPermissions;
use Flexipos\Sales\Support\Money;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 22 - the cross-slice invariant sweep (the NFRs; AC-14, AC-21, AC-27A, AC-28, AC-12B)
|--------------------------------------------------------------------------
|
| One scripted day, run through the real slices: open a shift; three sales with
| split and credit tenders; a fully discounted zero-total sale; a sale with an
| overridden line; a sale held at one terminal and recalled at another; a part-paid
| sale voided; a sale refunded in part, one tender back to credit; the overridden
| line refunded in part; close; reconcile. Then every table is read whole and the
| invariants are asserted over all of it, not over the rows a slice's own test made.
|
| Two figures are worked out by hand and written as literals: the shift's
| `expected_cash`, and the five sale numbers. Everything else is compared with
| what the day's own rows say it must be.
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/sales-fixture.php';

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
 * A draft sale on a terminal, built through the slices: its lines are `[quantity, ?discount id, ?override price]`
 * (an override carries a decided `price_override` approval, as the add step consumes one), its tenders
 * `[method key, offered]`. It is completed when asked to be.
 *
 * @param  array<string, mixed>  $d
 * @param  list<array{0: string, 1?: string|null, 2?: string|null}>  $lines
 * @param  list<array{0: string, 1: string}>  $tenders
 * @return array{sale: string, items: list<string>, approvals: list<string>}
 */
function sivSale(Closure $write, array $d, string $terminal, array $lines, array $tenders, ?string $customer = null, bool $complete = true): array
{
    $sale = $write(fn () => app(CreateSaleHandler::class)->handle(new CreateSale(array_filter(['terminal_id' => $terminal, 'customer_id' => $customer]), $d['cashier'])))->saleId;
    $approvals = [];

    foreach ($lines as $line) {
        $fields = ['sale_id' => $sale, 'product_id' => $d['product'], 'unit_id' => $d['unit'], 'quantity' => $line[0]];

        if (($line[1] ?? null) !== null) {
            $fields['discount_id'] = $line[1];
        }

        if (($line[2] ?? null) !== null) {
            $approval = SalesSeed::approval($d['branch'], $d['cashier'], [
                'type' => 'price_override',
                'reference_type' => 'sale',
                'reference_id' => $sale,
                'payload' => json_encode(['product_id' => $d['product'], 'unit_id' => $d['unit'], 'unit_price' => $line[2]]),
                'status' => 'approved',
                'decided_by' => $d['manager'],
                'decided_at' => SalesSeed::NOW,
                'decision_method' => 'pin_local',
            ]);
            $fields['unit_price'] = $line[2];
            $fields['approval_id'] = $approval;
            $approvals[] = $approval;
        }

        $write(fn () => app(AddSaleLineHandler::class)->handle(new AddSaleLine($fields, $d['cashier'])));
    }

    foreach ($tenders as [$method, $offered]) {
        $write(fn () => app(AddTenderHandler::class)->handle(new AddTender(['id' => (string) Str::ulid(), 'sale_id' => $sale, 'payment_method_id' => $d[$method], 'offered' => $offered], $d['cashier'])));
    }

    if ($complete) {
        $write(fn () => app(CompleteSaleHandler::class)->handle(new CompleteSale(['sale_id' => $sale], $d['cashier'])));
    }

    return ['sale' => $sale, 'items' => SalesSeed::connection()->table('sale_items')->where('sale_id', $sale)->orderBy('line_no')->pluck('id')->all(), 'approvals' => $approvals];
}

/**
 * A decided refund approval for a sale, as the decide step leaves one.
 *
 * @param  array<string, mixed>  $d
 */
function sivRefundApproval(array $d, string $sale): string
{
    return SalesSeed::approval($d['branch'], $d['cashier'], [
        'type' => 'refund',
        'reference_type' => 'sale',
        'reference_id' => $sale,
        'status' => 'approved',
        'decided_by' => $d['manager'],
        'decided_at' => SalesSeed::NOW,
        'decision_method' => 'pin_local',
    ]);
}

/**
 * Every table, whole, as rows keyed by id (a decimal as the database hands it back: use `Money::of` before comparing).
 *
 * @return list<array<string, mixed>>
 */
function sivRows(string $table): array
{
    return SalesSeed::connection()->table($table)->get()->map(static fn (object $r): array => (array) $r)->all();
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return list<array<string, mixed>>
 */
function sivWhere(array $rows, string $column, string $value): array
{
    return array_values(array_filter($rows, static fn (array $r): bool => strtoupper((string) $r[$column]) === strtoupper($value)));
}

/**
 * The scripted day. Returns what the assertions need to know about it.
 *
 * @return array<string, mixed>
 */
function sivDay(): array
{
    app(SyncPermissionRegistryHandler::class)->handle(new SyncPermissionRegistry);

    $writes = 0;
    $write = function (callable $operation) use (&$writes): mixed {
        $writes++;

        return $operation();
    };

    $branch = SalesSeed::branch();
    $cashier = SalesSeed::employee('cashier');
    $manager = SalesSeed::employee('manager');
    $supervisor = AccessSeed::employee('supervisor');
    AccessSeed::attach($supervisor, $branch);
    AccessSeed::assign($supervisor, AccessSeed::role('role_supervisor', [SalesPermissions::SHIFTS_RECONCILE]), $branch);

    $group = SalesWorld::taxGroup('TX10', '10.0000');
    $catalogue = SalesWorld::product('P1', '100.0000', $group, ['allow_price_override' => true]);
    $free = PricingSeed::discount('FREE', ['type' => 'percentage', 'value' => '100.0000', 'usage_limit' => 5]);

    $d = [
        'branch' => $branch,
        'cashier' => $cashier,
        'manager' => $manager,
        'product' => $catalogue['product'],
        'unit' => $catalogue['unit'],
        'cash' => SalesSeed::paymentMethod('CASH', ['type' => 'cash', 'allows_change' => true]),
        'card' => SalesSeed::paymentMethod('CARD', ['type' => 'card']),
        'credit' => SalesSeed::paymentMethod('CREDIT', ['type' => 'credit']),
    ];

    $first = SalesSeed::terminal($branch, 'T1');
    $shift = $write(fn () => app(OpenCashShiftHandler::class)->handle(new OpenCashShift(['terminal_id' => $first['id'], 'opening_cash' => '100.0000'], $cashier)))->shiftId;
    $second = SalesWorld::till('T2', $branch, $cashier);
    $customer = SalesWorld::creditCustomer('Credit Ada');

    // Three sales. 1: two units, split cash and credit. 2: one unit, all on credit. 3: one unit, held at T1 and recalled at T2.
    $one = sivSale($write, $d, $first['id'], [['2']], [['cash', '120'], ['credit', '100']], $customer);
    $two = sivSale($write, $d, $first['id'], [['1']], [['credit', '110']], $customer);
    $three = sivSale($write, $d, $first['id'], [['1']], [], null, false);
    $write(fn () => app(HoldSaleHandler::class)->handle(new HoldSale(['sale_id' => $three['sale'], 'terminal_id' => $first['id']], $cashier)));
    $write(fn () => app(RecallSaleHandler::class)->handle(new RecallSale(['sale_id' => $three['sale'], 'terminal_id' => $second['terminal']], $cashier)));
    $write(fn () => app(AddTenderHandler::class)->handle(new AddTender(['id' => (string) Str::ulid(), 'sale_id' => $three['sale'], 'payment_method_id' => $d['cash'], 'offered' => '110'], $cashier)));
    $write(fn () => app(CompleteSaleHandler::class)->handle(new CompleteSale(['sale_id' => $three['sale']], $cashier)));

    // The zero-total sale, and the sale with an overridden line (3 at 80.0000 against a list of 100.0000: 240 + 24 = 264).
    $zero = sivSale($write, $d, $first['id'], [['1', $free]], []);
    $overridden = sivSale($write, $d, $first['id'], [['3', null, '80.0000']], [['cash', '264']]);

    // A part-paid sale, voided: it needs no approval, and each tender is mirrored.
    $part = sivSale($write, $d, $first['id'], [['1']], [['cash', '50']], null, false);
    $tendersBefore = sivWhere(sivRows('sale_tenders'), 'sale_id', $part['sale']);
    $void = $write(fn () => app(VoidSaleHandler::class)->handle(new VoidSale(['sale_id' => $part['sale'], 'reason' => 'Customer left'], $cashier)));

    // Sale 1 refunded in part (one unit: 60 cash and 50 back to credit), then the overridden line in part (one unit: 88 cash).
    $refundOneApproval = sivRefundApproval($d, $one['sale']);
    $refundOne = $write(fn () => app(RefundSaleHandler::class)->handle(new RefundSale([
        'terminal_id' => $first['id'],
        'reference_sale_id' => $one['sale'],
        'lines' => [['refund_of_item_id' => $one['items'][0], 'unit_id' => $d['unit'], 'quantity' => '1']],
        'tenders' => [['payment_method_id' => $d['cash'], 'offered' => '60'], ['payment_method_id' => $d['credit'], 'offered' => '50']],
        'approval_id' => $refundOneApproval,
    ], $cashier)));

    $refundOverriddenApproval = sivRefundApproval($d, $overridden['sale']);
    $refundOverridden = $write(fn () => app(RefundSaleHandler::class)->handle(new RefundSale([
        'terminal_id' => $first['id'],
        'reference_sale_id' => $overridden['sale'],
        'lines' => [['refund_of_item_id' => $overridden['items'][0], 'unit_id' => $d['unit'], 'quantity' => '1']],
        'tenders' => [['payment_method_id' => $d['cash'], 'offered' => '88']],
        'approval_id' => $refundOverriddenApproval,
    ], $cashier)));

    // Close with the hand-checked figure: 100 opening + 120 + 264 cash taken - 60 - 88 cash paid back.
    /** @var CloseCashShiftResult $closed */
    $closed = $write(fn () => app(CloseCashShiftHandler::class)->handle(new CloseCashShift(['shift_id' => $shift, 'counted_cash' => '336.0000'], $cashier)));
    $write(fn () => app(ReconcileCashShiftHandler::class)->handle(new ReconcileCashShift(['shift_id' => $shift], $supervisor)));

    return compact('d', 'writes', 'shift', 'one', 'two', 'three', 'zero', 'overridden', 'part', 'tendersBefore', 'void', 'refundOne', 'refundOverridden', 'closed') + [
        'approvals' => ['override' => $overridden['approvals'][0], 'refundOne' => $refundOneApproval, 'refundOverridden' => $refundOverriddenApproval],
        'customer' => $customer,
        'second' => $second,
    ];
}

it('keeps every invariant over a scripted day: numbering, money, voids, the ledger, refunds, approvals, audit and the cash drawer (AC-14, AC-21, AC-27A, AC-28, AC-12B)', function () {
    $day = sivDay();
    $sales = sivRows('sales');
    $items = sivRows('sale_items');
    $tenders = sivRows('sale_tenders');
    $byId = static fn (array $rows): array => array_combine(array_map(static fn (array $r): string => strtoupper((string) $r['id']), $rows), $rows);
    $saleById = $byId($sales);
    $itemById = $byId($items);

    // Numbering: five completions, one sequence, no gap and no repeat; the zero-total sale is one of them.
    $numbers = array_map(static fn (array $s): string => (string) $s['number'], array_values(array_filter($sales, static fn (array $s): bool => $s['type'] === 'sale' && $s['number'] !== null)));
    sort($numbers);

    expect($numbers)->toBe(['S000001', 'S000002', 'S000003', 'S000004', 'S000005'])
        ->and($saleById[$day['zero']['sale']]['number'])->not->toBeNull();

    $zeroAudits = array_values(array_filter(SalesSeed::audit(), static fn (array $a): bool => $a['action'] === 'sales_sale_completed' && strtoupper((string) $a['subject_id']) === strtoupper($day['zero']['sale'])));

    expect($zeroAudits)->toHaveCount(1)
        ->and($saleById[$day['zero']['sale']]['total'])->toEqual('0.0000');

    // Money: every document's tenders add up to its total (minus it for a refund, nothing for a voided sale).
    foreach ($sales as $sale) {
        $mine = sivWhere($tenders, 'sale_id', (string) $sale['id']);
        $sum = Money::sum(array_map(static fn (array $t): string => Money::of($t['amount']), $mine));
        $label = (string) ($sale['number'] ?? $sale['id']);

        $expected = match (true) {
            $sale['status'] === 'voided' => Money::ZERO,
            $sale['type'] === 'refund' => Money::negate(Money::of($sale['total'])),
            in_array($sale['status'], ['completed', 'partially_refunded', 'refunded'], true) => Money::of($sale['total']),
            default => $sum,
        };

        expect(Money::equals($sum, $expected))->toBeTrue("the tenders of [{$label}] sum to {$sum}, not {$expected}");
    }

    // Voids: one exact mirror per original tender, and the originals are as they were before the void.
    expect($day['void']->mirrorTenderIds)->toHaveCount(count($day['tendersBefore']));

    foreach ($day['tendersBefore'] as $original) {
        $mirrors = array_values(array_filter(sivWhere($tenders, 'sale_id', $day['part']['sale']), static fn (array $t): bool => in_array(strtoupper((string) $t['id']), array_map('strtoupper', $day['void']->mirrorTenderIds), true) && $t['payment_method_id'] === $original['payment_method_id']));

        expect($mirrors)->toHaveCount(1)
            ->and(Money::equals(Money::of($mirrors[0]['amount']), Money::negate(Money::of($original['amount']))))->toBeTrue()
            ->and($byId(sivWhere($tenders, 'sale_id', $day['part']['sale']))[strtoupper((string) $original['id'])])->toBe($original);
    }

    // Credit: every credit tender (sale, refund) has exactly one ledger row keyed on it, and no ledger row is keyed on anything else.
    $ledger = sivRows('customer_ledger');
    $creditTenders = array_values(array_filter($tenders, static fn (array $t): bool => $t['method_type'] === 'credit'));

    expect($creditTenders)->toHaveCount(3)->and($ledger)->toHaveCount(3);

    foreach ($creditTenders as $tender) {
        $keyed = array_values(array_filter($ledger, static fn (array $l): bool => $l['reference_type'] === 'sale_tender' && strtoupper((string) $l['reference_id']) === strtoupper((string) $tender['id'])));

        expect($keyed)->toHaveCount(1);
    }

    // Quantities: nothing is refunded beyond what was sold, and a refund line is itself never refunded.
    foreach ($items as $item) {
        expect(Money::compare(Money::of($item['refunded_quantity']), Money::of($item['base_quantity'])))->toBeLessThanOrEqual(0);
    }

    // AC-27A: a refund line of an overridden original has its price and unit, no override marker, and never more than was charged.
    $paidBack = [];

    foreach ($items as $item) {
        if ($item['refund_of_item_id'] === null) {
            continue;
        }

        $original = $itemById[strtoupper((string) $item['refund_of_item_id'])];

        if ($original['price_overridden_by'] === null) {
            continue;
        }

        expect(Money::equals(Money::of($item['unit_price']), Money::of($original['unit_price'])))->toBeTrue()
            ->and(strtoupper((string) $item['unit_id']))->toBe(strtoupper((string) $original['unit_id']))
            ->and($item['price_overridden_by'])->toBeNull();

        $key = strtoupper((string) $original['id']);
        $paidBack[$key] = Money::add($paidBack[$key] ?? Money::ZERO, Money::of($item['line_total']));

        expect(Money::compare($paidBack[$key], Money::of($original['line_total'])))->toBeLessThanOrEqual(0);
    }

    expect($paidBack)->toHaveCount(1)
        ->and($paidBack[strtoupper($day['overridden']['items'][0])])->toBe('88.0000');

    // Approvals: each consumed at most once, and only the three the day used.
    $consumed = array_values(array_filter(sivRows('approval_requests'), static fn (array $a): bool => $a['consumed_at'] !== null));
    $consumedIds = array_map(static fn (array $a): string => strtoupper((string) $a['id']), $consumed);
    sort($consumedIds);
    $used = array_map('strtoupper', array_values($day['approvals']));
    sort($used);

    expect($consumedIds)->toBe($used);

    // AC-12B: no refund tender took a tendered figure or gave change.
    $refundIds = array_map(static fn (array $s): string => strtoupper((string) $s['id']), array_values(array_filter($sales, static fn (array $s): bool => $s['type'] === 'refund')));

    expect($refundIds)->toHaveCount(2);

    foreach ($tenders as $tender) {
        if (in_array(strtoupper((string) $tender['sale_id']), $refundIds, true)) {
            expect($tender['tendered'])->toBeNull()->and($tender['change_given'])->toEqual('0.0000');
        }
    }

    // Audit: one sales row per sales write, only declared actions; one customers row per ledger posting.
    $audit = SalesSeed::audit();
    $salesAudit = array_values(array_filter($audit, static fn (array $a): bool => $a['module'] === 'sales'));
    $customersAudit = array_values(array_filter($audit, static fn (array $a): bool => $a['module'] === 'customers' && $a['action'] === 'customers_credit_ledger_entry_recorded'));
    $actions = array_values(array_unique(array_map(static fn (array $a): string => (string) $a['action'], $salesAudit)));

    expect($salesAudit)->toHaveCount($day['writes'])
        ->and(array_diff($actions, SalesAuditActions::ALL))->toBe([])
        ->and($customersAudit)->toHaveCount(count($ledger));

    // The six read slices write nothing.
    $before = count(SalesSeed::audit());
    app(GetSaleHandler::class)->handle(new GetSale($day['one']['sale']));
    app(ListSalesHandler::class)->handle(new ListSales($day['d']['branch']));
    app(ListSaleLinesHandler::class)->handle(new ListSaleLines($day['one']['sale']));
    app(ListCashShiftsHandler::class)->handle(new ListCashShifts($day['d']['branch']));
    app(ListCashMovementsHandler::class)->handle(new ListCashMovements($day['shift']));
    app(ListApprovalRequestsHandler::class)->handle(new ListApprovalRequests($day['d']['branch']));

    expect(count(SalesSeed::audit()))->toBe($before);

    // The drawer: 100 + 120 + 264 - 60 - 88 = 336, counted exactly.
    expect($day['closed']->expectedCash)->toBe('336.0000')
        ->and($day['closed']->variance)->toBe('0.0000')
        ->and(SalesSeed::row('cash_shifts', $day['shift']))->toMatchArray(['status' => 'reconciled']);
});
