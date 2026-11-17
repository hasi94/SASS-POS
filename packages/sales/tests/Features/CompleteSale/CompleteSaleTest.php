<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\CompleteSale;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Customers\CreditEntryType;
use Flexipos\Contracts\Customers\CreditLedgerEntry;
use Flexipos\Contracts\Customers\CreditRefusal;
use Flexipos\Contracts\Customers\CustomerCreditLedger;
use Flexipos\Contracts\Organization\DocumentNumbersRefusal;
use Flexipos\Contracts\Pricing\DiscountUsage;
use Flexipos\Contracts\Pricing\PricingRefusal;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLine;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLineHandler;
use Flexipos\Sales\Features\AddTender\AddTender;
use Flexipos\Sales\Features\AddTender\AddTenderHandler;
use Flexipos\Sales\Features\CompleteSale\CompleteSale;
use Flexipos\Sales\Features\CompleteSale\CompleteSaleHandler;
use Flexipos\Sales\Features\CompleteSale\CompleteSaleResult;
use Flexipos\Sales\Features\RemoveSaleLine\RemoveSaleLine;
use Flexipos\Sales\Features\RemoveSaleLine\RemoveSaleLineHandler;
use Flexipos\Sales\Features\RemoveTender\RemoveTender;
use Flexipos\Sales\Features\RemoveTender\RemoveTenderHandler;
use Flexipos\Sales\Support\Money;
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
| T-030 step 17 - CompleteSale
|     (AC-14, AC-14A, AC-15, AC-48 to AC-51, AC-58; D-29, D-31, D-37, D-42, D-65, D-66, D-68, D-74; R-4, R-5, R-20)
|--------------------------------------------------------------------------
|
| Against the real pricing engine, discount usage, credit ledger and numbering.
| Sales are built through the cart and tender slices, so the totals are what the
| engine made of the lines. The deadlock rows prove the retry's shape against a
| numbering that throws the engine's error: NOT VERIFIED on a real engine.
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
 * A till with a 10 percent exclusive product at 100.0000 (110.0000 a unit), and one method of each kind.
 *
 * @return array<string, mixed>
 */
function csWorld(): array
{
    $till = SalesWorld::till();
    $group = SalesWorld::taxGroup('TX10', '10.0000');
    $catalogue = SalesWorld::product('P1', '100.0000', $group);

    return [
        'till' => $till,
        'product' => $catalogue['product'],
        'unit' => $catalogue['unit'],
        'group' => $group,
        'cash' => SalesSeed::paymentMethod('CASH', ['type' => 'cash', 'allows_change' => true]),
        'card' => SalesSeed::paymentMethod('CARD', ['type' => 'card']),
        'credit' => SalesSeed::paymentMethod('CREDIT', ['type' => 'credit']),
    ];
}

/**
 * A draft sale holding the lines asked for, each `[quantity, ?discount]`.
 *
 * @param  array<string, mixed>  $w
 * @param  list<array{0: string, 1?: string|null}>  $lines
 * @param  array<string, mixed>  $saleOverrides
 */
function csSale(array $w, array $lines = [['1']], array $saleOverrides = []): string
{
    $sale = SalesWorld::sale($w['till'], $saleOverrides);

    /** @var AddSaleLineHandler $add */
    $add = app(AddSaleLineHandler::class);

    foreach ($lines as $line) {
        $fields = ['sale_id' => $sale, 'product_id' => $w['product'], 'unit_id' => $w['unit'], 'quantity' => $line[0]];

        if (($line[1] ?? null) !== null) {
            $fields['discount_id'] = $line[1];
        }

        $add->handle(new AddSaleLine($fields, $w['till']['cashier']));
    }

    return $sale;
}

/**
 * @param  array<string, mixed>  $w
 * @return string the tender id
 */
function csTender(array $w, string $sale, string $method, string $offered): string
{
    /** @var AddTenderHandler $add */
    $add = app(AddTenderHandler::class);

    return $add->handle(new AddTender(['id' => (string) Str::ulid(), 'sale_id' => $sale, 'payment_method_id' => $w[$method], 'offered' => $offered], $w['till']['cashier']))->tenderId;
}

function csComplete(string $sale, ?string $actorId): CompleteSaleResult
{
    /** @var CompleteSaleHandler $handler */
    $handler = app(CompleteSaleHandler::class);

    return $handler->handle(new CompleteSale(['sale_id' => $sale], $actorId));
}

/**
 * Every table a completion writes, as one comparable value (the whole tables, so a stray write fails).
 *
 * @return array<string, mixed>
 */
function csState(): array
{
    $c = SalesSeed::connection();
    $rows = static fn (string $table): array => $c->table($table)->get()->map(fn ($r) => (array) $r)->all();

    return [
        'sales' => $rows('sales'),
        'lines' => $rows('sale_items'),
        'tenders' => $rows('sale_tenders'),
        'holds' => $rows('sale_holds'),
        'discounts' => $rows('discounts'),
        'ledger' => $rows('customer_ledger'),
        'customers' => $rows('customers'),
        'numbers' => $rows('number_sequences'),
        'audit' => SalesSeed::audit(),
    ];
}

/**
 * A credit customer, attached to the sale before its tenders.
 */
function csCustomer(string $sale, string $limit = '1000.0000'): string
{
    $customer = SalesWorld::creditCustomer('Credit '.Str::random(4), $limit);

    SalesSeed::connection()->table('sales')->where('id', $sale)->update(['customer_id' => $customer, 'customer_name' => 'Credit']);

    return $customer;
}

it('refuses a short balance as balance_due, naming what is left, and writes nothing (AC-14)', function () {
    $w = csWorld();
    $sale = csSale($w, [['1']]);
    csTender($w, $sale, 'cash', '100');
    $before = csState();

    $refused = SalesRefusals::expect(SalesRefusal::BalanceDue, fn () => csComplete($sale, $w['till']['cashier']));

    expect($refused->getMessage())->toContain('10.0000')->and(csState())->toBe($before);
});

it('checks the balance before it posts anything: a short sale with an over-limit credit tender is balance_due, never credit_refused (M5)', function () {
    $w = csWorld();
    $sale = csSale($w, [['1']]);
    csCustomer($sale, '50.0000');
    // 100 of 110 on credit: the customer's limit is 50, so a posting would be refused over_limit if it were attempted first.
    csTender($w, $sale, 'credit', '100');
    $before = csState();

    SalesRefusals::expect(SalesRefusal::BalanceDue, fn () => csComplete($sale, $w['till']['cashier']));

    expect(csState())->toBe($before);
});

it('completes on an exact balance: completed, completed_at, paid_total, change_due, balance_due 0 and the number S000001 (AC-14)', function () {
    $w = csWorld();
    $sale = csSale($w, [['1']]);
    csTender($w, $sale, 'cash', '120');

    $result = csComplete($sale, strtolower($w['till']['cashier']));

    expect($result->number)->toBe('S000001')
        ->and($result->total)->toBe('110.0000')
        ->and($result->paidTotal)->toBe('110.0000')
        ->and($result->changeDue)->toBe('10.0000')
        ->and(SalesSeed::row('sales', $sale))->toMatchArray([
            'status' => 'completed',
            'number' => 'S000001',
            'completed_at' => '2026-11-12 10:00:00.000',
            'paid_total' => '110.0000',
            'change_due' => '10.0000',
            'balance_due' => '0.0000',
            'credit_checked' => 0,
            'stock_applied' => 0,
            'cost_total' => null,
        ]);

    $audits = array_values(array_filter(SalesSeed::audit(), static fn (array $row): bool => $row['action'] === 'sales_sale_completed'));

    expect($audits)->toHaveCount(1)
        ->and($audits[0])->toMatchArray(['module' => 'sales', 'subject_type' => 'sale', 'subject_id' => $sale, 'actor_id' => $w['till']['cashier']]);
});

it('numbers the next completed sale S000002', function () {
    $w = csWorld();
    $first = csSale($w);
    csTender($w, $first, 'cash', '110');
    $second = csSale($w);
    csTender($w, $second, 'card', '110');

    expect(csComplete($first, $w['till']['cashier'])->number)->toBe('S000001')
        ->and(csComplete($second, $w['till']['cashier'])->number)->toBe('S000002');
});

it('checks for lines before the balance: an empty draft is empty_sale, never balance_due', function () {
    $w = csWorld();
    $sale = SalesWorld::sale($w['till']);

    SalesRefusals::expect(SalesRefusal::EmptySale, fn () => csComplete($sale, $w['till']['cashier']));
});

it('refuses a draft with a positive total and no tenders as balance_due naming the total, not invalid_transition', function () {
    $w = csWorld();
    $sale = csSale($w, [['2']]);

    $refused = SalesRefusals::expect(SalesRefusal::BalanceDue, fn () => csComplete($sale, $w['till']['cashier']));

    expect($refused->getMessage())->toContain('220.0000');
});

it('refuses a status the state machine does not allow as invalid_transition, naming it (AC-51)', function (string $status) {
    $w = csWorld();
    $sale = csSale($w);
    csTender($w, $sale, 'cash', '110');
    SalesSeed::connection()->table('sales')->where('id', $sale)->update(['status' => $status]);
    $before = csState();

    $refused = SalesRefusals::expect(SalesRefusal::InvalidTransition, fn () => csComplete($sale, $w['till']['cashier']));

    expect($refused->getMessage())->toContain($status)->and(csState())->toBe($before);
})->with(['held', 'completed', 'voided', 'refunded', 'partially_refunded', 'failed']);

it('refuses a refund document as invalid_transition', function () {
    $w = csWorld();
    $sale = csSale($w);
    SalesSeed::connection()->table('sales')->where('id', $sale)->update(['type' => 'refund']);

    SalesRefusals::expect(SalesRefusal::InvalidTransition, fn () => csComplete($sale, $w['till']['cashier']));
});

describe('a zero-total sale takes the ordinary path (AC-14A, D-74)', function () {
    it('is numbered in sequence, consumes the discount once, posts no ledger entry and writes exactly one completed audit row', function () {
        $w = csWorld();
        $free = PricingSeed::discount('FREE', ['type' => 'percentage', 'value' => '100.0000', 'usage_limit' => 5]);

        $sale = csSale($w, [['1', $free]]);
        $before = csState();
        $beforeCount = count(SalesSeed::audit());

        expect(SalesSeed::row('sales', $sale)['total'])->toEqual('0.0000');

        $result = csComplete($sale, $w['till']['cashier']);

        $after = csState();

        expect($result->number)->toBe('S000001')
            ->and($result->paidTotal)->toBe('0.0000')
            ->and($result->changeDue)->toBe('0.0000')
            ->and(SalesSeed::row('sales', $sale))->toMatchArray(['status' => 'completed', 'number' => 'S000001', 'paid_total' => '0.0000', 'change_due' => '0.0000', 'balance_due' => '0.0000', 'stock_applied' => 0])
            ->and(SalesSeed::row('discounts', $free)['used_count'])->toEqual(1)
            ->and((int) SalesSeed::connection()->table('number_sequences')->where('document_type', 'sale')->value('next_number'))->toBe(2)
            // No tender rows, no ledger rows, no customers audit row; the whole of these tables is as it was.
            ->and($after['tenders'])->toBe($before['tenders'])
            ->and($after['ledger'])->toBe($before['ledger'])
            ->and($after['customers'])->toBe($before['customers'])
            ->and($after['holds'])->toBe($before['holds'])
            ->and($after['lines'])->toBe($before['lines'])
            ->and(count(SalesSeed::audit()) - $beforeCount)->toBe(1)
            ->and(end($after['audit'])['action'])->toBe('sales_sale_completed');

        // A paid sale completed after it carries the next number.
        $paid = csSale($w);
        csTender($w, $paid, 'cash', '110');

        expect(csComplete($paid, $w['till']['cashier'])->number)->toBe('S000002');
    });

    it('cannot reach pending_payment: a sale with nothing owing takes no tender, so the only zero-total completion is from draft', function () {
        $w = csWorld();
        $free = PricingSeed::discount('FREE', ['type' => 'percentage', 'value' => '100.0000']);
        $sale = csSale($w, [['1', $free]]);

        SalesRefusals::expect(SalesRefusal::ExceedsBalance, fn () => csTender($w, $sale, 'cash', '1'));

        expect(SalesSeed::row('sales', $sale)['status'])->toBe('draft');
    });

    it('still needs a line: a zero-total draft with none is not completable', function () {
        $w = csWorld();

        SalesRefusals::expect(SalesRefusal::EmptySale, fn () => csComplete(SalesWorld::sale($w['till']), $w['till']['cashier']));
    });
});

describe('credit tenders (F-2, F-3, F-4; AC-48, AC-49; R-4, R-20)', function () {
    it('posts one credit_sale per credit tender, keyed on the tender, with positive canonical debits and the sale\'s currency, branch and cashier', function () {
        $w = csWorld();
        $sale = csSale($w, [['2']]);
        $customer = csCustomer($sale);
        $first = csTender($w, $sale, 'credit', '100');
        $second = csTender($w, $sale, 'credit', '50.5');
        csTender($w, $sale, 'cash', '69.5');
        $auditBefore = count(SalesSeed::audit());

        csComplete($sale, $w['till']['cashier']);

        $rows = SalesSeed::connection()->table('customer_ledger')->orderBy('reference_id')->get();

        expect($rows)->toHaveCount(2)
            ->and($rows->pluck('reference_id')->sort()->values()->all())->toBe(collect([$first, $second])->sort()->values()->all())
            ->and($rows->pluck('reference_type')->unique()->all())->toBe(['sale_tender'])
            ->and($rows->pluck('type')->unique()->all())->toBe(['credit_sale'])
            ->and($rows->pluck('currency')->unique()->all())->toBe(['LKR'])
            ->and($rows->pluck('branch_id')->unique()->all())->toBe([$w['till']['branch']])
            ->and($rows->pluck('employee_id')->unique()->all())->toBe([$w['till']['cashier']])
            ->and($rows->pluck('customer_id')->unique()->all())->toBe([$customer]);

        $debits = $rows->mapWithKeys(static fn (object $row): array => [strtoupper((string) $row->reference_id) => (string) (float) $row->debit])->all();

        expect($debits)->toBe([$first => '100', $second => '50.5'])
            ->and($rows->pluck('credit')->map(fn ($c) => (float) $c)->unique()->all())->toBe([0.0])
            ->and(SalesSeed::row('sales', $sale)['credit_checked'])->toEqual(1);

        // One sales audit row, plus exactly one customers audit row per posting.
        $audits = array_slice(SalesSeed::audit(), $auditBefore);
        $byModule = array_count_values(array_column($audits, 'module'));

        expect($byModule)->toBe(['customers' => 2, 'sales' => 1]);
    });

    it('refuses an over-limit customer as credit_refused carrying over_limit, with no number consumed and no row changed', function () {
        $w = csWorld();
        $sale = csSale($w);
        csCustomer($sale, '50.0000');
        csTender($w, $sale, 'credit', '110');
        $before = csState();

        $refused = SalesRefusals::expect(SalesRefusal::CreditRefused, fn () => csComplete($sale, $w['till']['cashier']));

        expect($refused->cause)->toBe(CreditRefusal::OverLimit)
            ->and(csState())->toBe($before)
            ->and(SalesSeed::connection()->table('customer_ledger')->count())->toBe(0)
            // Scoped to the sales document types: T-033's seed migration puts a journal_entry row in every tenant.
            ->and(SalesSeed::connection()->table('number_sequences')->whereIn('document_type', ['sale', 'refund', 'cash_shift'])->count())->toBe(0);
    });

    it('refuses a customer with no currency, or another one, as credit_refused carrying the ledger\'s cause (AC-49)', function (?string $currency, CreditRefusal $cause) {
        $w = csWorld();
        $sale = csSale($w);
        $customer = csCustomer($sale);
        SalesSeed::connection()->table('customers')->where('id', $customer)->update(['currency' => $currency]);
        csTender($w, $sale, 'credit', '110');
        $before = csState();

        $refused = SalesRefusals::expect(SalesRefusal::CreditRefused, fn () => csComplete($sale, $w['till']['cashier']));

        expect($refused->cause)->toBe($cause)->and(csState())->toBe($before);
    })->with([
        'none' => [null, CreditRefusal::CurrencyRequired],
        'another' => ['USD', CreditRefusal::CurrencyMismatch],
    ]);

    it('refuses a replayed posting as credit_refused carrying replay_conflict, and writes nothing (AC-48, D-66, R-4)', function () {
        $w = csWorld();
        $sale = csSale($w);
        $customer = csCustomer($sale);
        $tender = csTender($w, $sale, 'credit', '110');

        // A manual entry pre-seeds the tender's key, with the same customer and amount: the writer reports it as replayed.
        app(CustomerCreditLedger::class)->record(new CreditLedgerEntry($customer, CreditEntryType::CreditSale, 'sale_tender', $tender, '110.0000', '0.0000', 'LKR', $w['till']['branch'], $w['till']['cashier']));

        $before = csState();

        $refused = SalesRefusals::expect(SalesRefusal::CreditRefused, fn () => csComplete($sale, $w['till']['cashier']));

        expect($refused->cause)->toBe(CreditRefusal::ReplayConflict)
            ->and(csState())->toBe($before)
            ->and(SalesSeed::row('sales', $sale)['status'])->toBe('pending_payment')
            ->and(SalesSeed::row('sales', $sale)['number'])->toBeNull()
            // The pre-seeded row is still the only ledger row.
            ->and(SalesSeed::connection()->table('customer_ledger')->count())->toBe(1);
    });
});

describe('discount usage (F-12; AC-41, AC-58; D-65)', function () {
    it('consumes once per distinct discount, in ascending id order, whatever order the lines hold them in', function () {
        $w = csWorld();
        $a = PricingSeed::discount('DA', ['usage_limit' => 5]);
        $b = PricingSeed::discount('DB', ['usage_limit' => 5]);
        [$low, $high] = strcmp($a, $b) < 0 ? [$a, $b] : [$b, $a];

        // Lines in the order high, low, high: three lines, two distinct discounts.
        $sale = csSale($w, [['1', $high], ['1', $low], ['2', $high]]);
        csTender($w, $sale, 'cash', (string) SalesSeed::row('sales', $sale)['total']);

        $spy = new class(app(DiscountUsage::class)) implements DiscountUsage
        {
            /** @var list<string> */
            public array $consumed = [];

            public function __construct(private readonly DiscountUsage $real) {}

            public function consume(string $discountId): void
            {
                $this->consumed[] = $discountId;
                $this->real->consume($discountId);
            }
        };
        app()->instance(DiscountUsage::class, $spy);

        csComplete($sale, $w['till']['cashier']);

        expect($spy->consumed)->toBe([$low, $high])
            ->and(SalesSeed::row('discounts', $a)['used_count'])->toEqual(1)
            ->and(SalesSeed::row('discounts', $b)['used_count'])->toEqual(1);
    });

    it('refuses the whole completion when the last use went to another sale: usage_limit_reached, with nothing written', function () {
        $w = csWorld();
        $limited = PricingSeed::discount('LIM', ['usage_limit' => 1]);
        $sale = csSale($w, [['1', $limited]]);
        csTender($w, $sale, 'cash', (string) SalesSeed::row('sales', $sale)['total']);

        // Another sale takes the discount's only use between this sale's quote and its completion.
        SalesSeed::connection()->table('discounts')->where('id', $limited)->update(['used_count' => 1]);
        $before = csState();

        $refused = SalesRefusals::expect(SalesRefusal::PricingRefused, fn () => csComplete($sale, $w['till']['cashier']));

        expect($refused->cause)->toBe(PricingRefusal::UsageLimitReached)
            ->and($refused->code())->toBe('usage_limit_reached')
            ->and(csState())->toBe($before);
    });

    it('walks the way out of a refused completion: remove the tenders, change the line, tender again and complete (risk 12)', function () {
        $w = csWorld();
        $limited = PricingSeed::discount('LIM', ['usage_limit' => 1]);
        $sale = csSale($w, [['1', $limited]]);
        $tender = csTender($w, $sale, 'cash', (string) SalesSeed::row('sales', $sale)['total']);
        SalesSeed::connection()->table('discounts')->where('id', $limited)->update(['used_count' => 1]);

        SalesRefusals::expect(SalesRefusal::PricingRefused, fn () => csComplete($sale, $w['till']['cashier']));

        // Back to draft by removing the tender; the lines are editable again.
        app(RemoveTenderHandler::class)->handle(new RemoveTender(['sale_id' => $sale, 'tender_id' => $tender], $w['till']['cashier']));

        $line = (string) SalesSeed::connection()->table('sale_items')->where('sale_id', $sale)->value('id');
        app(RemoveSaleLineHandler::class)->handle(new RemoveSaleLine(['sale_id' => $sale, 'line_id' => $line], $w['till']['cashier']));
        app(AddSaleLineHandler::class)->handle(new AddSaleLine(['sale_id' => $sale, 'product_id' => $w['product'], 'unit_id' => $w['unit'], 'quantity' => '1'], $w['till']['cashier']));

        csTender($w, $sale, 'cash', '110');

        expect(csComplete($sale, $w['till']['cashier'])->number)->toBe('S000001')
            ->and(SalesSeed::row('discounts', $limited)['used_count'])->toEqual(1);
    });

    it('still consumes a discount that was deactivated between the quote and the completion, and completes (AC-58, D-65)', function () {
        $w = csWorld();
        $discount = PricingSeed::discount('GONE', ['usage_limit' => 5]);
        $sale = csSale($w, [['1', $discount]]);
        csTender($w, $sale, 'cash', (string) SalesSeed::row('sales', $sale)['total']);

        SalesSeed::connection()->table('discounts')->where('id', $discount)->update(['is_active' => 0]);

        expect(csComplete($sale, $w['till']['cashier'])->number)->toBe('S000001')
            ->and(SalesSeed::row('discounts', $discount)['used_count'])->toEqual(1);
    });
});

describe('numbering (AC-50)', function () {
    it('refuses a sequence the numbering will not allocate as numbering_refused, carrying the cause, and writes nothing, discount usage and credit included', function (string $case, DocumentNumbersRefusal $cause) {
        $w = csWorld();
        $discount = PricingSeed::discount('D5', ['usage_limit' => 5]);
        $sale = csSale($w, [['1', $discount]]);
        csCustomer($sale);
        csTender($w, $sale, 'credit', (string) SalesSeed::row('sales', $sale)['total']);

        SalesSeed::connection()->table('number_sequences')->insert([
            'id' => (string) Str::ulid(), 'branch_id' => null, 'document_type' => 'sale',
            'prefix' => $case === 'long' ? str_repeat('X', 28) : 'S', 'next_number' => 1, 'padding' => 6, 'suffix' => null,
            'reset_period' => $case === 'yearly' ? 'yearly' : 'never', 'last_reset_at' => null, 'created_at' => SalesSeed::NOW, 'updated_at' => SalesSeed::NOW,
        ]);

        $before = csState();

        $refused = SalesRefusals::expect(SalesRefusal::NumberingRefused, fn () => csComplete($sale, $w['till']['cashier']));

        expect($refused->cause)->toBe($cause)
            ->and(csState())->toBe($before)
            ->and(SalesSeed::row('discounts', $discount)['used_count'])->toEqual(0)
            ->and(SalesSeed::connection()->table('customer_ledger')->count())->toBe(0);
    })->with([
        'yearly' => ['yearly', DocumentNumbersRefusal::UnsupportedResetPeriod],
        'too long' => ['long', DocumentNumbersRefusal::NumberTooLong],
    ]);
});

describe('the deadlock retry (NOT VERIFIED on a real engine)', function () {
    /**
     * @return array{w: array<string, mixed>, sale: string, discount: string}
     */
    function csPendingWithCreditAndDiscount(): array
    {
        $w = csWorld();
        $discount = PricingSeed::discount('D5', ['usage_limit' => 5]);
        $sale = csSale($w, [['2', $discount]]);
        csCustomer($sale);
        csTender($w, $sale, 'credit', '100');
        csTender($w, $sale, 'credit', '50');
        csTender($w, $sale, 'cash', Money::subtract(Money::of(SalesSeed::row('sales', $sale)['total']), '150.0000'));

        return ['w' => $w, 'sale' => $sale, 'discount' => $discount];
    }

    it('(a) retries the whole transaction once when the numbering deadlocks on its first call, and the second attempt succeeds', function () {
        ['w' => $w, 'sale' => $sale, 'discount' => $discount] = csPendingWithCreditAndDiscount();
        $auditBefore = count(SalesSeed::audit());

        $wrapper = DeadlockingNumbers::wrap(function (int $call): void {
            if ($call === 1) {
                throw DeadlockingNumbers::deadlock();
            }
        });

        $result = csComplete($sale, $w['till']['cashier']);

        $audits = array_slice(SalesSeed::audit(), $auditBefore);

        expect($wrapper->calls)->toBe(2)
            ->and($result->number)->toBe('S000001')
            ->and((int) SalesSeed::connection()->table('number_sequences')->where('document_type', 'sale')->value('next_number'))->toBe(2)
            // Consumed once, not once per attempt.
            ->and(SalesSeed::row('discounts', $discount)['used_count'])->toEqual(1)
            // One credit_sale per credit tender, one customers audit row per posting, one sales audit row.
            ->and(SalesSeed::connection()->table('customer_ledger')->count())->toBe(2)
            ->and(array_count_values(array_column($audits, 'module')))->toBe(['customers' => 2, 'sales' => 1])
            ->and(SalesSeed::row('sales', $sale)['status'])->toBe('completed');
    });

    it('(b) gives up after the second deadlock, surfacing the engine error with nothing written', function () {
        ['w' => $w, 'sale' => $sale] = csPendingWithCreditAndDiscount();
        $before = csState();

        $wrapper = DeadlockingNumbers::wrap(function (): void {
            throw DeadlockingNumbers::deadlock();
        });

        expect(fn () => csComplete($sale, $w['till']['cashier']))->toThrow(QueryException::class, 'Deadlock found');

        expect($wrapper->calls)->toBe(2)
            ->and(csState())->toBe($before)
            ->and(SalesSeed::row('sales', $sale)['status'])->toBe('pending_payment')
            ->and(SalesSeed::row('sales', $sale)['number'])->toBeNull()
            ->and(SalesSeed::connection()->table('customer_ledger')->count())->toBe(0);
    });

    it('(c) does not retry an error that is not a deadlock', function () {
        ['w' => $w, 'sale' => $sale] = csPendingWithCreditAndDiscount();

        $wrapper = DeadlockingNumbers::wrap(function (): void {
            throw new QueryException('tenant', 'select 1', [], new PDOException('SQLSTATE[HY000]: General error: 1 no such table'));
        });

        expect(fn () => csComplete($sale, $w['till']['cashier']))->toThrow(QueryException::class);

        expect($wrapper->calls)->toBe(1);
    });
});

it('writes no stock and no ledger of any other kind (AC-15)', function () {
    $w = csWorld();
    $sale = csSale($w);
    csTender($w, $sale, 'cash', '110');

    $log = SalesQueryLog::during(fn () => csComplete($sale, $w['till']['cashier']));

    $touched = array_filter($log, static fn (string $sql): bool => preg_match('/(from|into|update|join) "(stock_|journal_|inventory_)/', $sql) === 1);

    expect($touched)->toBe([])
        ->and(SalesSeed::row('sales', $sale)['stock_applied'])->toEqual(0);
});

it('takes the sale FOR UPDATE first, then discounts, then customers, then the number sequence last (Ruling 7)', function () {
    $w = csWorld();
    $discount = PricingSeed::discount('D5', ['usage_limit' => 5]);
    $sale = csSale($w, [['1', $discount]]);
    csCustomer($sale);
    csTender($w, $sale, 'credit', (string) SalesSeed::row('sales', $sale)['total']);

    $inside = SalesQueryLog::inTransaction(SalesQueryLog::during(fn () => csComplete($sale, $w['till']['cashier'])));

    $first = static function (string $table) use ($inside): ?int {
        foreach ($inside as $position => $sql) {
            if (str_contains($sql, '"'.$table.'"') && str_contains($sql, LockMarkingGrammar::FOR_UPDATE)) {
                return $position;
            }
        }

        return null;
    };

    $sales = $first('sales');
    $discounts = $first('discounts');
    $customers = $first('customers');
    $numbers = $first('number_sequences');

    expect($sales)->toBe(0)
        ->and($discounts)->toBeGreaterThan($sales)
        ->and($customers)->toBeGreaterThan($discounts)
        ->and($numbers)->toBeGreaterThan($customers);

    // After the sequence row is locked, no other row is.
    $locksAfter = array_filter(array_slice($inside, $numbers + 1), static fn (string $sql): bool => str_contains($sql, LockMarkingGrammar::FOR_UPDATE) && ! str_contains($sql, 'number_sequences'));

    expect($locksAfter)->toBe([]);
});

it('refuses a sale that is not there, a bad id, other fields and a missing actor', function () {
    $w = csWorld();
    $sale = csSale($w);

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => csComplete((string) Str::ulid(), $w['till']['cashier']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => csComplete('x', $w['till']['cashier']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => csComplete($sale, null));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => csComplete($sale, (string) Str::ulid()));

    /** @var CompleteSaleHandler $handler */
    $handler = app(CompleteSaleHandler::class);

    SalesRefusals::expect(SalesRefusal::Validation, fn () => $handler->handle(new CompleteSale(['sale_id' => $sale, 'total' => '1'], $w['till']['cashier'])));
});
