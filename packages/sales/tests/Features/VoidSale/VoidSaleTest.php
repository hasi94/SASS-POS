<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\VoidSale;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Customers\CreditLedgerEntry;
use Flexipos\Contracts\Customers\CreditLedgerPosting;
use Flexipos\Contracts\Customers\CreditRefusal;
use Flexipos\Contracts\Customers\CustomerCreditLedger;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLine;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLineHandler;
use Flexipos\Sales\Features\AddTender\AddTender;
use Flexipos\Sales\Features\AddTender\AddTenderHandler;
use Flexipos\Sales\Features\CompleteSale\CompleteSale;
use Flexipos\Sales\Features\CompleteSale\CompleteSaleHandler;
use Flexipos\Sales\Features\VoidSale\VoidSale;
use Flexipos\Sales\Features\VoidSale\VoidSaleHandler;
use Flexipos\Sales\Features\VoidSale\VoidSaleResult;
use Flexipos\Sales\Tests\Support\LockMarkingGrammar;
use Flexipos\Sales\Tests\Support\SalesQueryLog;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 19 - VoidSale (AC-21, AC-22, AC-24A, AC-48; D-14 to D-16, D-29, D-39, D-47, D-66, D-72)
|--------------------------------------------------------------------------
|
| Sales are built through the real cart, tender and completion slices, so the
| totals and the tender rows are what production writes. The approval is seeded
| as the decide step leaves it (a decided row); the self-decided case is the
| decide step's own refusal and is covered there.
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
 * A till, a 110.0000 product, one method of each kind and a manager who may approve.
 *
 * @return array<string, mixed>
 */
function vsWorld(): array
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
        'credit' => SalesSeed::paymentMethod('CREDIT', ['type' => 'credit']),
        'manager' => SalesSeed::employee('manager'),
    ];
}

/**
 * A sale of one 110.0000 line, with the tenders asked for (`[method, offered]`), taken to `$status`.
 *
 * @param  array<string, mixed>  $w
 * @param  list<array{0: string, 1: string}>  $tenders
 * @return array{sale: string, tenders: list<string>}
 */
function vsSale(array $w, string $status, array $tenders = [], ?string $customer = null): array
{
    $sale = SalesWorld::sale($w['till']);

    if ($customer !== null) {
        SalesSeed::connection()->table('sales')->where('id', $sale)->update(['customer_id' => $customer, 'customer_name' => 'Credit']);
    }

    app(AddSaleLineHandler::class)->handle(new AddSaleLine(['sale_id' => $sale, 'product_id' => $w['product'], 'unit_id' => $w['unit'], 'quantity' => '1'], $w['till']['cashier']));

    $ids = [];

    foreach ($tenders as [$method, $offered]) {
        $ids[] = app(AddTenderHandler::class)->handle(new AddTender(['id' => (string) Str::ulid(), 'sale_id' => $sale, 'payment_method_id' => $w[$method], 'offered' => $offered], $w['till']['cashier']))->tenderId;
    }

    if ($status === 'completed') {
        app(CompleteSaleHandler::class)->handle(new CompleteSale(['sale_id' => $sale], $w['till']['cashier']));
    } elseif ($status === 'held') {
        SalesSeed::connection()->table('sales')->where('id', $sale)->update(['status' => 'held']);
    }

    return ['sale' => $sale, 'tenders' => $ids];
}

function vsCustomer(): string
{
    return SalesWorld::creditCustomer('Credit '.Str::random(4));
}

/**
 * A decided void approval for a sale, as the decide step leaves it.
 *
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $overrides
 */
function vsApproval(array $w, string $sale, array $overrides = []): string
{
    return SalesSeed::approval($w['till']['branch'], $w['till']['cashier'], array_merge([
        'type' => 'void',
        'reference_type' => 'sale',
        'reference_id' => $sale,
        'status' => 'approved',
        'decided_by' => $w['manager'],
        'decided_at' => SalesSeed::NOW,
        'decision_method' => 'pin_local',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $fields
 */
function vsVoid(array $w, string $sale, array $fields = [], ?string $actor = 'cashier'): VoidSaleResult
{
    return app(VoidSaleHandler::class)->handle(new VoidSale(array_merge(['sale_id' => $sale, 'reason' => 'Customer left'], $fields), $actor === 'cashier' ? $w['till']['cashier'] : $actor));
}

/**
 * Every table a void can write, whole, so a stray write fails the comparison.
 *
 * @return array<string, mixed>
 */
function vsState(): array
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

describe('before completion (AC-21; D-14, D-16)', function () {
    it('voids a draft with no approval: voided, voided_by, void_reason and voided_at, and nothing else written', function () {
        $w = vsWorld();
        $made = vsSale($w, 'draft');

        $result = vsVoid($w, $made['sale'], ['reason' => '  Wrong customer  ']);

        expect($result->saleId)->toBe($made['sale'])
            ->and($result->mirrorTenderIds)->toBe([])
            ->and(SalesSeed::row('sales', $made['sale']))->toMatchArray([
                'status' => 'voided',
                'voided_by' => $w['till']['cashier'],
                'void_reason' => 'Wrong customer',
                'voided_at' => '2026-11-12 10:00:00.000',
                'approval_request_id' => null,
            ])
            ->and(SalesSeed::connection()->table('sale_tenders')->count())->toBe(0)
            ->and(SalesSeed::connection()->table('customer_ledger')->count())->toBe(0);

        $audits = array_values(array_filter(SalesSeed::audit(), static fn (array $row): bool => $row['action'] === 'sales_sale_voided'));

        expect($audits)->toHaveCount(1)
            ->and($audits[0])->toMatchArray(['module' => 'sales', 'subject_type' => 'sale', 'subject_id' => $made['sale'], 'actor_id' => $w['till']['cashier']]);
    });

    it('voids a held sale with no approval', function () {
        $w = vsWorld();
        $made = vsSale($w, 'held');

        vsVoid($w, $made['sale']);

        expect(SalesSeed::row('sales', $made['sale'])['status'])->toBe('voided');
    });

    it('voids a pending_payment sale with one negative mirror per tender, the originals byte-identical, and no ledger row (F-3, M6)', function () {
        $w = vsWorld();
        $customer = vsCustomer();
        $made = vsSale($w, 'pending_payment', [['cash', '50'], ['credit', '30'], ['card', '10']], $customer);
        $originals = SalesSeed::connection()->table('sale_tenders')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $auditBefore = count(SalesSeed::audit());

        $result = vsVoid($w, $made['sale']);

        $after = SalesSeed::connection()->table('sale_tenders')->orderBy('id')->get()->map(fn ($r) => (array) $r);
        $untouched = $after->filter(fn (array|object $r): bool => in_array(((array) $r)['id'], $made['tenders'], true))->map(fn ($r) => (array) $r)->values()->all();
        $mirrors = $after->filter(fn ($r): bool => ! in_array(((array) $r)['id'], $made['tenders'], true))->map(fn ($r) => (array) $r)->values();

        // The originals are exactly what they were, field for field.
        expect($untouched)->toBe($originals)
            ->and($mirrors)->toHaveCount(3)
            ->and($result->mirrorTenderIds)->toHaveCount(3)
            ->and($mirrors->pluck('id')->sort()->values()->all())->toBe(collect($result->mirrorTenderIds)->sort()->values()->all());

        $byMethod = $mirrors->mapWithKeys(fn (array $m): array => [$m['method_type'] => $m])->all();

        expect($byMethod['cash']['amount'])->toEqual('-50.0000')
            ->and($byMethod['credit']['amount'])->toEqual('-30.0000')
            ->and($byMethod['card']['amount'])->toEqual('-10.0000')
            ->and($byMethod['cash']['tendered'])->toBeNull()
            ->and($byMethod['cash']['change_given'])->toEqual('0.0000')
            ->and($byMethod['cash']['payment_method_id'])->toBe($w['cash'])
            ->and($byMethod['cash']['sale_id'])->toBe($made['sale'])
            // Nothing was ever posted for a sale that had not completed, so there is nothing to compensate.
            ->and(SalesSeed::connection()->table('customer_ledger')->count())->toBe(0);

        // One sales audit row, no customers audit row.
        expect(array_count_values(array_column(array_slice(SalesSeed::audit(), $auditBefore), 'module')))->toBe(['sales' => 1]);
    });

    it('refuses a voided sale again as invalid_transition and a failed one the same way', function (string $status) {
        $w = vsWorld();
        $made = vsSale($w, 'draft');
        SalesSeed::connection()->table('sales')->where('id', $made['sale'])->update(['status' => $status]);
        $before = vsState();

        $refused = SalesRefusals::expect(SalesRefusal::InvalidTransition, fn () => vsVoid($w, $made['sale']));

        expect($refused->getMessage())->toContain($status)->and(vsState())->toBe($before);
    })->with(['voided', 'failed']);
});

describe('a completed sale (AC-22; D-14, D-15, D-39, D-47)', function () {
    it('is approval_required without an approval, and writes nothing', function () {
        $w = vsWorld();
        $made = vsSale($w, 'completed', [['cash', '110']]);
        $before = vsState();

        SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => vsVoid($w, $made['sale']));

        expect(vsState())->toBe($before);
    });

    it('voids with a valid approval: mirrors, approval consumed, approval_request_id set, one audit row, no stock', function () {
        $w = vsWorld();
        $made = vsSale($w, 'completed', [['cash', '120']]);
        $approval = vsApproval($w, $made['sale']);
        $originals = SalesSeed::connection()->table('sale_tenders')->where('id', $made['tenders'][0])->first();

        $result = vsVoid($w, $made['sale'], ['approval_id' => strtolower($approval)]);

        $mirror = SalesSeed::row('sale_tenders', $result->mirrorTenderIds[0]);

        expect($result->mirrorTenderIds)->toHaveCount(1)
            // The tender is 110 (the change was 10): the mirror is the amount, never the tendered figure.
            ->and($mirror['amount'])->toEqual('-110.0000')
            ->and((array) SalesSeed::connection()->table('sale_tenders')->where('id', $made['tenders'][0])->first())->toBe((array) $originals)
            ->and(SalesSeed::row('sales', $made['sale']))->toMatchArray(['status' => 'voided', 'approval_request_id' => $approval, 'voided_by' => $w['till']['cashier']])
            ->and(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBe('2026-11-12 10:00:00.000')
            ->and(SalesSeed::row('sales', $made['sale'])['stock_applied'])->toEqual(0);
    });

    it('posts one credit_note per credit tender keyed on the mirror id, with a positive credit equal to the mirror\'s magnitude (F-4, R-20)', function () {
        $w = vsWorld();
        $customer = vsCustomer();
        $made = vsSale($w, 'completed', [['credit', '60'], ['credit', '20'], ['cash', '30']], $customer);
        $approval = vsApproval($w, $made['sale']);
        $auditBefore = count(SalesSeed::audit());
        $sold = SalesSeed::connection()->table('customer_ledger')->where('type', 'credit_sale')->count();

        $result = vsVoid($w, $made['sale'], ['approval_id' => $approval]);

        $notes = SalesSeed::connection()->table('customer_ledger')->where('type', 'credit_note')->get();

        expect($sold)->toBe(2)
            ->and($notes)->toHaveCount(2)
            ->and($notes->pluck('reference_type')->unique()->all())->toBe(['sale_tender'])
            ->and($notes->pluck('customer_id')->unique()->all())->toBe([$customer])
            ->and($notes->pluck('currency')->unique()->all())->toBe(['LKR'])
            ->and($notes->pluck('branch_id')->unique()->all())->toBe([$w['till']['branch']])
            ->and($notes->map(fn (object $n): string => (string) (float) $n->credit)->sort()->values()->all())->toBe(['20', '60'])
            ->and($notes->map(fn (object $n): float => (float) $n->debit)->unique()->all())->toBe([0.0]);

        // Keyed on a mirror tender, never on the original tender or the sale.
        $creditMirrors = array_values(array_filter($result->mirrorTenderIds, fn (string $id): bool => SalesSeed::row('sale_tenders', $id)['method_type'] === 'credit'));

        expect($notes->pluck('reference_id')->sort()->values()->all())->toBe(collect($creditMirrors)->sort()->values()->all());

        // One sales audit row, plus exactly one customers audit row per posting.
        expect(array_count_values(array_column(array_slice(SalesSeed::audit(), $auditBefore), 'module')))->toBe(['customers' => 2, 'sales' => 1]);
    });

    it('is shift_closed once the sale\'s shift has closed, with nothing written and the approval unconsumed (D-39)', function () {
        $w = vsWorld();
        $made = vsSale($w, 'completed', [['cash', '110']]);
        $approval = vsApproval($w, $made['sale']);
        SalesSeed::connection()->table('cash_shifts')->where('id', $w['till']['shift'])->update(['status' => 'closed', 'closed_at' => SalesSeed::NOW]);
        $before = vsState();

        SalesRefusals::expect(SalesRefusal::ShiftClosed, fn () => vsVoid($w, $made['sale'], ['approval_id' => $approval]));

        expect(vsState())->toBe($before)->and(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBeNull();
    });

    it('is shift_closed when a close commits between the pre-read and the shift lock, and no mirror lands on the closed shift (R-5)', function () {
        $w = vsWorld();
        $made = vsSale($w, 'completed', [['cash', '110']]);
        $approval = vsApproval($w, $made['sale']);
        $tendersBefore = SalesSeed::connection()->table('sale_tenders')->count();

        // The close lands exactly as the write's transaction opens, after everything the handler read beforehand.
        $closed = false;
        app('events')->listen(TransactionBeginning::class, function () use (&$closed, $w): void {
            if (! $closed) {
                $closed = true;
                SalesSeed::connection()->table('cash_shifts')->where('id', $w['till']['shift'])->update(['status' => 'closed', 'closed_at' => SalesSeed::NOW]);
            }
        });

        SalesRefusals::expect(SalesRefusal::ShiftClosed, fn () => vsVoid($w, $made['sale'], ['approval_id' => $approval]));

        expect(SalesSeed::connection()->table('sale_tenders')->count())->toBe($tendersBefore)
            ->and(SalesSeed::row('sales', $made['sale'])['status'])->toBe('completed')
            ->and(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBeNull();
    });

    it('refuses a partially_refunded or refunded sale, and a refund document, as sale_not_voidable', function (string $status, string $type) {
        $w = vsWorld();
        $made = vsSale($w, 'completed', [['cash', '110']]);
        $approval = vsApproval($w, $made['sale']);
        SalesSeed::connection()->table('sales')->where('id', $made['sale'])->update(['status' => $status, 'type' => $type]);
        $before = vsState();

        SalesRefusals::expect(SalesRefusal::SaleNotVoidable, fn () => vsVoid($w, $made['sale'], ['approval_id' => $approval]));

        expect(vsState())->toBe($before);
    })->with([
        'partially refunded' => ['partially_refunded', 'sale'],
        'refunded' => ['refunded', 'sale'],
        'a refund document' => ['completed', 'refund'],
    ]);

    it('refuses an approval that is not for this void: other sale, other type, not approved, already used', function (string $case) {
        $w = vsWorld();
        $made = vsSale($w, 'completed', [['cash', '110']]);
        $other = vsSale($w, 'draft');

        $approval = match ($case) {
            'another sale' => vsApproval($w, $other['sale']),
            'another type' => vsApproval($w, $made['sale'], ['type' => 'refund']),
            'pending' => vsApproval($w, $made['sale'], ['status' => 'pending', 'decided_by' => null, 'decided_at' => null, 'decision_method' => null]),
            default => vsApproval($w, $made['sale'], ['consumed_at' => SalesSeed::NOW]),
        };

        $expected = match ($case) {
            'pending' => SalesRefusal::ApprovalNotApproved,
            'used' => SalesRefusal::ApprovalAlreadyUsed,
            default => SalesRefusal::ApprovalMismatch,
        };
        $before = vsState();

        SalesRefusals::expect($expected, fn () => vsVoid($w, $made['sale'], ['approval_id' => $approval]));

        expect(vsState())->toBe($before);
    })->with(['another sale', 'another type', 'pending', 'used']);

    it('passes the sale\'s own branch to the gate: an approval seeded at another branch is approval_mismatch and stays unconsumed (AC-24A, D-72)', function () {
        $w = vsWorld();
        $made = vsSale($w, 'completed', [['cash', '110']]);
        $elsewhere = vsApproval($w, $made['sale'], ['branch_id' => SalesSeed::branch()]);
        $here = vsApproval($w, $made['sale']);

        SalesRefusals::expect(SalesRefusal::ApprovalMismatch, fn () => vsVoid($w, $made['sale'], ['approval_id' => $elsewhere]));

        expect(SalesSeed::row('sales', $made['sale'])['status'])->toBe('completed')
            ->and(SalesSeed::row('approval_requests', $elsewhere)['consumed_at'])->toBeNull()
            ->and(SalesSeed::connection()->table('sale_tenders')->count())->toBe(1);

        vsVoid($w, $made['sale'], ['approval_id' => $here]);

        expect(SalesSeed::row('approval_requests', $here)['consumed_at'])->not->toBeNull()
            ->and(SalesSeed::row('sales', $made['sale'])['status'])->toBe('voided');
    });

    it('refuses a replayed credit posting as credit_refused carrying replay_conflict, and writes nothing (R-4, D-66, AC-48)', function () {
        $w = vsWorld();
        $customer = vsCustomer();
        $made = vsSale($w, 'completed', [['credit', '110']], $customer);
        $approval = vsApproval($w, $made['sale']);
        $before = vsState();

        // A ledger that reports every posting as already there: the mirror's key cannot be pre-seeded (the id is the server's).
        app()->instance(CustomerCreditLedger::class, new class implements CustomerCreditLedger
        {
            public function record(CreditLedgerEntry $entry): CreditLedgerPosting
            {
                return new CreditLedgerPosting((string) Str::ulid(), $entry->customerId, $entry->type, $entry->debit, $entry->credit, '0.0000', 'LKR', true);
            }
        });

        $refused = SalesRefusals::expect(SalesRefusal::CreditRefused, fn () => vsVoid($w, $made['sale'], ['approval_id' => $approval]));

        expect($refused->cause)->toBe(CreditRefusal::ReplayConflict)
            ->and(vsState())->toBe($before)
            ->and(SalesSeed::row('sales', $made['sale'])['status'])->toBe('completed');
    });

    it('refuses a customer merged since the sale as credit_refused carrying customer_not_live, and writes nothing (risk 7)', function () {
        $w = vsWorld();
        $customer = vsCustomer();
        $made = vsSale($w, 'completed', [['credit', '110']], $customer);
        $approval = vsApproval($w, $made['sale']);
        SalesSeed::connection()->table('customers')->where('id', $customer)->update(['deleted_at' => SalesSeed::NOW]);
        $before = vsState();

        $refused = SalesRefusals::expect(SalesRefusal::CreditRefused, fn () => vsVoid($w, $made['sale'], ['approval_id' => $approval]));

        expect($refused->cause)->toBe(CreditRefusal::CustomerNotLive)
            ->and(vsState())->toBe($before)
            ->and(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBeNull();
    });

    it('takes the shift shared first, then the sale FOR UPDATE, then the approval, then the customer (Ruling 7, R-5)', function () {
        $w = vsWorld();
        $customer = vsCustomer();
        $made = vsSale($w, 'completed', [['credit', '110']], $customer);
        $approval = vsApproval($w, $made['sale']);

        $inside = SalesQueryLog::inTransaction(SalesQueryLog::during(fn () => vsVoid($w, $made['sale'], ['approval_id' => $approval])));

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
        $customers = $first('customers', LockMarkingGrammar::FOR_UPDATE);

        expect($shift)->toBe(0)
            ->and($sale)->toBeGreaterThan($shift)
            ->and($approvals)->toBeGreaterThan($sale)
            ->and($customers)->toBeGreaterThan($approvals);
    });
});

it('takes no number and writes no stock or general-ledger table (AC-15, AC-31)', function () {
    $w = vsWorld();
    $made = vsSale($w, 'completed', [['cash', '110']]);
    $approval = vsApproval($w, $made['sale']);
    $numbers = SalesSeed::connection()->table('number_sequences')->get()->map(fn ($r) => (array) $r)->all();

    $log = SalesQueryLog::during(fn () => vsVoid($w, $made['sale'], ['approval_id' => $approval]));

    $touched = array_filter($log, static fn (string $sql): bool => preg_match('/(from|into|update|join) "(stock_|journal_|inventory_|number_sequences)/', $sql) === 1);

    expect($touched)->toBe([])
        ->and(SalesSeed::connection()->table('number_sequences')->get()->map(fn ($r) => (array) $r)->all())->toBe($numbers);
});

it('refuses a sale that is not there, a bad id, a missing reason, other fields and a missing actor', function () {
    $w = vsWorld();
    $made = vsSale($w, 'draft');

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => vsVoid($w, (string) Str::ulid()));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => vsVoid($w, 'x'));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => vsVoid($w, $made['sale'], ['reason' => '   ']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => vsVoid($w, $made['sale'], ['reason' => str_repeat('x', 256)]));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => vsVoid($w, $made['sale'], ['status' => 'completed']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => vsVoid($w, $made['sale'], ['approval_id' => 'nope']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => vsVoid($w, $made['sale'], [], null));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => vsVoid($w, $made['sale'], [], (string) Str::ulid()));

    expect(SalesSeed::row('sales', $made['sale'])['status'])->toBe('draft');
});
