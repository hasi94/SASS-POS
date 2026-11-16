<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\AddTender;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\AddTender\AddTender;
use Flexipos\Sales\Features\AddTender\AddTenderHandler;
use Flexipos\Sales\Features\AddTender\AddTenderResult;
use Flexipos\Sales\Features\GetSale\GetSale;
use Flexipos\Sales\Features\GetSale\GetSaleHandler;
use Flexipos\Sales\Features\RemoveTender\RemoveTender;
use Flexipos\Sales\Features\RemoveTender\RemoveTenderHandler;
use Flexipos\Sales\Support\Money;
use Flexipos\Sales\Tests\Support\LockMarkingGrammar;
use Flexipos\Sales\Tests\Support\SalesQueryLog;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 16 - AddTender (AC-11, AC-12, AC-12A, AC-37, AC-56; D-29, D-51, D-60; F-22; R-5, R-8)
|--------------------------------------------------------------------------
|
| One figure comes in (`offered`); the amount, the tendered figure and the change
| are what the built TenderRules make of it against the balance. The replay is
| decided right after the sale lock and compares the request, never the outcome.
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
 * A till, a draft sale owing 45.0000, and one payment method of each kind a tender test uses.
 *
 * @return array<string, mixed>
 */
function atWorld(string $total = '45.0000'): array
{
    $till = SalesWorld::till();

    return [
        'till' => $till,
        'sale' => SalesWorld::sale($till, ['subtotal' => $total, 'total' => $total, 'balance_due' => $total]),
        'cash' => SalesSeed::paymentMethod('CASH', ['type' => 'cash', 'allows_change' => true]),
        'card' => SalesSeed::paymentMethod('CARD', ['type' => 'card', 'requires_reference' => true]),
        'credit' => SalesSeed::paymentMethod('CREDIT', ['type' => 'credit']),
    ];
}

/**
 * @param  array<string, mixed>  $fields
 */
function atAdd(array $fields, ?string $actorId): AddTenderResult
{
    /** @var AddTenderHandler $handler */
    $handler = app(AddTenderHandler::class);

    return $handler->handle(new AddTender($fields, $actorId));
}

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function atInput(array $w, string $method, string $offered, array $overrides = []): array
{
    return array_merge(['id' => (string) Str::ulid(), 'sale_id' => $w['sale'], 'payment_method_id' => $w[$method], 'offered' => $offered], $overrides);
}

/**
 * @return array<string, mixed>
 */
function atState(): array
{
    $c = SalesSeed::connection();

    return [
        'sales' => $c->table('sales')->get()->map(fn ($r) => (array) $r)->all(),
        'tenders' => $c->table('sale_tenders')->get()->map(fn ($r) => (array) $r)->all(),
        'holds' => $c->table('sale_holds')->get()->map(fn ($r) => (array) $r)->all(),
        'ledger' => $c->table('customer_ledger')->get()->map(fn ($r) => (array) $r)->all(),
        'audit' => SalesSeed::audit(),
    ];
}

it('gives change on a cash tender: 50 offered against 45 settles 45, records the 50 and returns 5, and the sale moves to pending_payment (AC-11)', function () {
    $w = atWorld();
    $input = atInput($w, 'cash', '50');

    $result = atAdd($input, strtolower($w['till']['cashier']));

    expect($result->replayed)->toBeFalse()
        ->and($result->amount)->toBe('45.0000')
        ->and($result->tendered)->toBe('50.0000')
        ->and($result->changeGiven)->toBe('5.0000')
        ->and(SalesSeed::row('sale_tenders', $result->tenderId))->toMatchArray([
            'sale_id' => $w['sale'],
            'payment_method_id' => $w['cash'],
            'method_code' => 'CASH',
            'method_type' => 'cash',
            'amount' => '45.0000',
            'tendered' => '50.0000',
            'change_given' => '5.0000',
            'reference' => null,
            'payment_status' => 'recorded',
            'paid_at' => '2026-11-12 10:00:00.000',
        ])
        ->and(SalesSeed::row('sales', $w['sale'])['status'])->toBe('pending_payment');

    expect(SalesSeed::onlyAuditRow())->toMatchArray([
        'module' => 'sales',
        'action' => 'sales_tender_added',
        'subject_type' => 'sale_tender',
        'subject_id' => $result->tenderId,
        'actor_id' => $w['till']['cashier'],
        'before' => null,
    ]);
});

it('keeps the caller\'s id as the row id, folded to upper case', function () {
    $w = atWorld();
    $id = (string) Str::ulid();

    $result = atAdd(atInput($w, 'cash', '10', ['id' => strtolower($id)]), $w['till']['cashier']);

    expect($result->tenderId)->toBe($id)->and(SalesSeed::row('sale_tenders', $id)['id'])->toBe($id);
});

it('takes a split payment: cash then card, each against what is left (AC-12)', function () {
    $w = atWorld();

    atAdd(atInput($w, 'cash', '20'), $w['till']['cashier']);
    $card = atAdd(atInput($w, 'card', '25', ['reference' => 'AUTH-1']), $w['till']['cashier']);

    expect($card->amount)->toBe('25.0000')
        ->and(SalesSeed::connection()->table('sale_tenders')->where('sale_id', $w['sale'])->count())->toBe(2)
        ->and(SalesSeed::row('sales', $w['sale'])['status'])->toBe('pending_payment');

    // Nothing is owing now.
    SalesRefusals::expect(SalesRefusal::ExceedsBalance, fn () => atAdd(atInput($w, 'cash', '1'), $w['till']['cashier']));
});

it('refuses the method\'s own rules and writes nothing: inactive, a missing reference, a card over the balance', function (string $case, SalesRefusal $expected) {
    $w = atWorld();

    $input = match ($case) {
        'inactive' => (function () use ($w): array {
            SalesSeed::connection()->table('payment_methods')->where('id', $w['cash'])->update(['is_active' => 0]);

            return atInput($w, 'cash', '10');
        })(),
        'no reference' => atInput($w, 'card', '10'),
        'over' => atInput($w, 'card', '50', ['reference' => 'A']),
        'loyalty' => (function () use ($w): array {
            $w['loyalty'] = SalesSeed::paymentMethod('LOY', ['type' => 'loyalty']);

            return atInput($w, 'loyalty', '10');
        })(),
        default => (function () use ($w): array {
            $w['voucher'] = SalesSeed::paymentMethod('VOU', ['type' => 'voucher']);

            return atInput($w, 'voucher', '10');
        })(),
    };

    $before = atState();

    SalesRefusals::expect($expected, fn () => atAdd($input, $w['till']['cashier']));

    expect(atState())->toBe($before);
})->with([
    'inactive' => ['inactive', SalesRefusal::MethodInactive],
    'no reference' => ['no reference', SalesRefusal::ReferenceRequired],
    'over' => ['over', SalesRefusal::ExceedsBalance],
    'loyalty' => ['loyalty', SalesRefusal::UnsupportedMethodType],
    'voucher' => ['voucher', SalesRefusal::UnsupportedMethodType],
]);

it('takes an ordinary sale tender on a method with allows_refund = false: the flag is not consulted (AC-12A)', function () {
    $w = atWorld();
    SalesSeed::connection()->table('payment_methods')->where('id', $w['cash'])->update(['allows_refund' => 0]);

    expect(atAdd(atInput($w, 'cash', '10'), $w['till']['cashier'])->amount)->toBe('10.0000');
});

it('refuses a soft-deleted or unknown payment method as not_found, and a sale that is not there', function () {
    $w = atWorld();
    SalesSeed::connection()->table('payment_methods')->where('id', $w['cash'])->update(['deleted_at' => SalesSeed::NOW]);

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => atAdd(atInput($w, 'cash', '10'), $w['till']['cashier']));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => atAdd(atInput($w, 'card', '10', ['payment_method_id' => (string) Str::ulid(), 'reference' => 'A']), $w['till']['cashier']));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => atAdd(atInput($w, 'card', '10', ['sale_id' => (string) Str::ulid(), 'reference' => 'A']), $w['till']['cashier']));
});

it('refuses a credit tender on a sale with no customer, and posts no ledger entry for one that has a customer (AC-12, F-3)', function () {
    $w = atWorld();

    SalesRefusals::expect(SalesRefusal::CustomerRequired, fn () => atAdd(atInput($w, 'credit', '20'), $w['till']['cashier']));

    expect(SalesSeed::connection()->table('sale_tenders')->count())->toBe(0)
        ->and(SalesSeed::row('sales', $w['sale'])['status'])->toBe('draft');

    $customer = SalesWorld::creditCustomer();
    SalesSeed::connection()->table('sales')->where('id', $w['sale'])->update(['customer_id' => $customer]);

    $result = atAdd(atInput($w, 'credit', '20'), $w['till']['cashier']);

    // Credit is posted at completion, never here.
    expect($result->amount)->toBe('20.0000')
        ->and(SalesSeed::connection()->table('customer_ledger')->count())->toBe(0)
        ->and(SalesSeed::connection()->table('audit_logs')->where('module', 'customers')->count())->toBe(0);
});

it('refuses a tender on a sale with nothing owing, and on a sale that is finished (Ruling 4)', function (string $status) {
    $w = atWorld();
    SalesSeed::connection()->table('sales')->where('id', $w['sale'])->update(['status' => $status]);

    SalesRefusals::expect(SalesRefusal::SaleNotEditable, fn () => atAdd(atInput($w, 'cash', '10'), $w['till']['cashier']));
})->with(['completed', 'voided', 'refunded', 'partially_refunded']);

it('refuses a tender on a draft with no balance as exceeds_balance', function () {
    $w = atWorld('0.0000');

    SalesRefusals::expect(SalesRefusal::ExceedsBalance, fn () => atAdd(atInput($w, 'cash', '5'), $w['till']['cashier']));

    expect(SalesSeed::row('sales', $w['sale'])['status'])->toBe('draft');
});

it('refuses what the caller must not send: the amount, the tendered figure, the change, the paid time', function (string $field) {
    $w = atWorld();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => atAdd(atInput($w, 'cash', '10', [$field => '10']), $w['till']['cashier']));
})->with(['amount', 'tendered', 'change_given', 'paid_at', 'method_code', 'method_type', 'payment_status', 'gateway_transaction_id']);

it('refuses a bad figure offered, a bad id, a bad reference and a missing actor as validation', function (array $overrides) {
    $w = atWorld();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => atAdd(atInput($w, 'cash', '10', $overrides), $w['till']['cashier']));
})->with([
    'zero' => [['offered' => '0']],
    'negative' => [['offered' => '-5']],
    'a float' => [['offered' => 10.5]],
    'five decimals' => [['offered' => '1.00001']],
    'fifteen digits' => [['offered' => '100000000000000']],
    'missing' => [['offered' => null]],
    'a bad id' => [['id' => 'x']],
    'a bad sale id' => [['sale_id' => 'x']],
    'a bad method id' => [['payment_method_id' => 'x']],
    'a reference over 100 characters' => [['reference' => str_repeat('x', 101)]],
    'a non-string reference' => [['reference' => ['x']]],
]);

it('needs a live employee as the actor', function () {
    $w = atWorld();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => atAdd(atInput($w, 'cash', '10'), null));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => atAdd(atInput($w, 'cash', '10'), (string) Str::ulid()));
});

describe('idempotency by the caller\'s id (AC-37, AC-56)', function () {
    it('returns the original for a replay of the same request, writing nothing more', function () {
        $w = atWorld();
        $input = atInput($w, 'cash', '10');

        $first = atAdd($input, $w['till']['cashier']);
        $before = atState();

        $second = atAdd($input, $w['till']['cashier']);

        expect($second->replayed)->toBeTrue()
            ->and($second->tenderId)->toBe($first->tenderId)
            ->and($second->amount)->toBe('10.0000')
            ->and(atState())->toBe($before);
    });

    it('compares the figure by value: "10" replays "10.0000" (R-8)', function () {
        $w = atWorld();
        $id = (string) Str::ulid();

        atAdd(atInput($w, 'cash', '10.0000', ['id' => $id]), $w['till']['cashier']);

        expect(atAdd(atInput($w, 'cash', '10', ['id' => $id]), $w['till']['cashier'])->replayed)->toBeTrue();
    });

    it('is replay_conflict for a different request under the id: another figure, method, reference or sale', function (string $change) {
        $w = atWorld();
        $other = SalesWorld::sale($w['till'], ['total' => '45.0000']);
        $id = (string) Str::ulid();

        atAdd(atInput($w, 'cash', '10', ['id' => $id]), $w['till']['cashier']);
        $before = atState();

        $different = match ($change) {
            'figure' => atInput($w, 'cash', '11', ['id' => $id]),
            'method' => atInput($w, 'card', '10', ['id' => $id, 'reference' => 'A']),
            'reference' => atInput($w, 'cash', '10', ['id' => $id, 'reference' => 'NEW']),
            default => atInput($w, 'cash', '10', ['id' => $id, 'sale_id' => $other]),
        };

        SalesRefusals::expect(SalesRefusal::ReplayConflict, fn () => atAdd($different, $w['till']['cashier']));

        expect(atState())->toBe($before);
    })->with(['figure', 'method', 'reference', 'sale']);

    it('compares what was offered, not what was settled: a cash 50 against 45 replays as 50 and conflicts as 45', function () {
        $w = atWorld();
        $id = (string) Str::ulid();

        atAdd(atInput($w, 'cash', '50', ['id' => $id]), $w['till']['cashier']);

        expect(atAdd(atInput($w, 'cash', '50', ['id' => $id]), $w['till']['cashier'])->replayed)->toBeTrue();

        SalesRefusals::expect(SalesRefusal::ReplayConflict, fn () => atAdd(atInput($w, 'cash', '45', ['id' => $id]), $w['till']['cashier']));
    });

    it('still returns the original once the sale has completed, before any state check', function () {
        $w = atWorld();
        $input = atInput($w, 'cash', '45');

        atAdd($input, $w['till']['cashier']);
        SalesSeed::connection()->table('sales')->where('id', $w['sale'])->update(['status' => 'completed']);

        expect(atAdd($input, $w['till']['cashier'])->replayed)->toBeTrue();
    });

    it('adds the tender again when it was removed in between, while the sale is still open (AC-56, D-60)', function () {
        $w = atWorld();
        $input = atInput($w, 'cash', '10');

        $first = atAdd($input, $w['till']['cashier']);

        /** @var RemoveTenderHandler $remove */
        $remove = app(RemoveTenderHandler::class);
        $remove->handle(new RemoveTender(['sale_id' => $w['sale'], 'tender_id' => $first->tenderId], $w['till']['cashier']));

        expect(SalesSeed::connection()->table('sale_tenders')->count())->toBe(0);

        $again = atAdd($input, $w['till']['cashier']);

        expect($again->replayed)->toBeFalse()
            ->and(SalesSeed::connection()->table('sale_tenders')->count())->toBe(1)
            ->and(SalesSeed::row('sales', $w['sale'])['status'])->toBe('pending_payment');
    });
});

describe('a sale held from pending_payment is recalled by tendering it (F-22, D-51)', function () {
    /**
     * @return array<string, mixed>
     */
    function atHeldWorld(): array
    {
        $w = atWorld();
        $b = SalesWorld::secondTill($w['till']);

        SalesSeed::tender($w['sale'], $w['cash'], 'cash', '20.0000');
        SalesSeed::connection()->table('sales')->where('id', $w['sale'])->update(['status' => 'held']);
        SalesSeed::connection()->table('sale_holds')->insert([
            'id' => (string) Str::ulid(), 'sale_id' => $w['sale'], 'action' => 'hold', 'from_status' => 'pending_payment', 'to_status' => 'held',
            'terminal_id' => $w['till']['terminal'], 'employee_id' => $w['till']['cashier'], 'created_at' => SalesSeed::NOW,
        ]);

        $w['b'] = $b;

        return $w;
    }

    it('writes one recall row, moves the sale to the tendering terminal and its shift, and leaves it pending_payment with the tender', function () {
        $w = atHeldWorld();
        $b = $w['b'];

        $result = atAdd(atInput($w, 'cash', '25', ['terminal_id' => $b['terminal']]), $b['cashier']);

        $recalls = SalesSeed::connection()->table('sale_holds')->where('sale_id', $w['sale'])->where('action', 'recall')->get();

        expect($result->amount)->toBe('25.0000')
            ->and($recalls)->toHaveCount(1)
            ->and((array) $recalls[0])->toMatchArray(['from_status' => 'held', 'to_status' => 'pending_payment', 'terminal_id' => $b['terminal'], 'employee_id' => $b['cashier']])
            ->and(SalesSeed::row('sales', $w['sale']))->toMatchArray([
                'status' => 'pending_payment',
                'terminal_id' => $b['terminal'],
                'shift_id' => $b['shift'],
                // The cashier who rang the sale up is never changed (D-24).
                'employee_id' => $w['till']['cashier'],
            ])
            ->and(SalesSeed::connection()->table('sale_tenders')->where('sale_id', $w['sale'])->count())->toBe(2);
    });

    it('writes no second recall row when the same tender is replayed', function () {
        $w = atHeldWorld();
        $input = atInput($w, 'cash', '25', ['terminal_id' => $w['b']['terminal']]);

        atAdd($input, $w['b']['cashier']);
        $replay = atAdd($input, $w['b']['cashier']);

        expect($replay->replayed)->toBeTrue()
            ->and(SalesSeed::connection()->table('sale_holds')->where('action', 'recall')->count())->toBe(1);
    });

    it('is no_open_shift at a terminal with none, and writes nothing', function () {
        $w = atHeldWorld();
        $closed = SalesSeed::terminal($w['till']['branch'], 'T9');
        $before = atState();

        SalesRefusals::expect(SalesRefusal::NoOpenShift, fn () => atAdd(atInput($w, 'cash', '25', ['terminal_id' => $closed['id']]), $w['b']['cashier']));

        expect(atState())->toBe($before);
    });

    it('needs the terminal it is taken at, and a terminal of the sale\'s own branch', function () {
        $w = atHeldWorld();
        $foreign = WorldOtherBranch::terminal();

        SalesRefusals::expect(SalesRefusal::Validation, fn () => atAdd(atInput($w, 'cash', '25'), $w['b']['cashier']));
        SalesRefusals::expect(SalesRefusal::NotFound, fn () => atAdd(atInput($w, 'cash', '25', ['terminal_id' => $foreign]), $w['b']['cashier']));
        SalesRefusals::expect(SalesRefusal::NotFound, fn () => atAdd(atInput($w, 'cash', '25', ['terminal_id' => (string) Str::ulid()]), $w['b']['cashier']));
    });

    it('is sale_not_editable for a sale held from draft: recall it first', function () {
        $w = atWorld();
        SalesSeed::connection()->table('sales')->where('id', $w['sale'])->update(['status' => 'held']);
        SalesSeed::connection()->table('sale_holds')->insert([
            'id' => (string) Str::ulid(), 'sale_id' => $w['sale'], 'action' => 'hold', 'from_status' => 'draft', 'to_status' => 'held',
            'terminal_id' => $w['till']['terminal'], 'employee_id' => $w['till']['cashier'], 'created_at' => SalesSeed::NOW,
        ]);
        $before = atState();

        SalesRefusals::expect(SalesRefusal::SaleNotEditable, fn () => atAdd(atInput($w, 'cash', '10', ['terminal_id' => $w['till']['terminal']]), $w['till']['cashier']));

        expect(atState())->toBe($before);
    });

    it('takes the tendering terminal\'s shift first (shared), then the sale FOR UPDATE, then the method (Ruling 7)', function () {
        $w = atHeldWorld();
        $input = atInput($w, 'cash', '25', ['terminal_id' => $w['b']['terminal']]);

        $inside = SalesQueryLog::inTransaction(SalesQueryLog::during(fn () => atAdd($input, $w['b']['cashier'])));

        expect($inside[0])->toContain('"cash_shifts"')->and($inside[0])->toContain(LockMarkingGrammar::SHARED)
            ->and($inside[1])->toContain('"sales"')->and($inside[1])->toContain(LockMarkingGrammar::FOR_UPDATE);
    });
});

it('takes the sale FOR UPDATE first and the payment method under a shared lock before the balance check (Ruling 7, R-5)', function () {
    $w = atWorld();

    $inside = SalesQueryLog::inTransaction(SalesQueryLog::during(fn () => atAdd(atInput($w, 'cash', '10'), $w['till']['cashier'])));

    $methodRead = null;
    $tenderSum = null;

    foreach ($inside as $position => $sql) {
        if ($methodRead === null && str_contains($sql, '"payment_methods"')) {
            $methodRead = $position;
        }

        if ($tenderSum === null && str_contains($sql, '"amount"') && str_contains($sql, '"sale_tenders"') && str_starts_with($sql, 'select')) {
            $tenderSum = $position;
        }
    }

    expect($inside[0])->toContain('"sales"')->and($inside[0])->toContain(LockMarkingGrammar::FOR_UPDATE)
        ->and($inside[$methodRead])->toContain(LockMarkingGrammar::SHARED)
        ->and($tenderSum)->toBeGreaterThan($methodRead);
});

it('is the figure the rules decide that is stored, to the cent, for a method with fractions (R-8)', function () {
    $w = atWorld('45.5050');

    $result = atAdd(atInput($w, 'cash', '100.1'), $w['till']['cashier']);

    expect($result->amount)->toBe('45.5050')
        ->and($result->changeGiven)->toBe('54.5950')
        ->and(Money::of(SalesSeed::row('sale_tenders', $result->tenderId)['change_given']))->toBe('54.5950');
});

/**
 * A terminal in a branch of its own, for the cross-branch row.
 */
final class WorldOtherBranch
{
    public static function terminal(): string
    {
        return SalesSeed::terminal(SalesSeed::branch(), 'TX')['id'];
    }
}

it('keeps the header\'s paid_total the exact sum of the tenders and balance_due the total less it, and GetSale shows both (W-3)', function () {
    $w = atWorld();

    atAdd(atInput($w, 'cash', '20'), $w['till']['cashier']);

    expect(SalesSeed::row('sales', $w['sale']))->toMatchArray(['paid_total' => '20.0000', 'balance_due' => '25.0000']);

    // Cash over the balance settles the balance, never the offer: 30 offered against 25 left adds 25.
    atAdd(atInput($w, 'cash', '30'), $w['till']['cashier']);

    $header = SalesSeed::row('sales', $w['sale']);

    expect($header)->toMatchArray(['paid_total' => '45.0000', 'balance_due' => '0.0000']);

    /** @var GetSaleHandler $get */
    $get = app(GetSaleHandler::class);
    $shown = $get->handle(new GetSale($w['sale']));

    expect($shown->sale)->toMatchArray(['paid_total' => '45.0000', 'balance_due' => '0.0000']);
});

it('leaves the header\'s paid_total alone when a tender is refused (W-3)', function () {
    $w = atWorld();

    atAdd(atInput($w, 'cash', '10'), $w['till']['cashier']);

    SalesRefusals::expect(SalesRefusal::ExceedsBalance, fn () => atAdd(atInput($w, 'card', '99', ['reference' => 'A']), $w['till']['cashier']));

    expect(SalesSeed::row('sales', $w['sale']))->toMatchArray(['paid_total' => '10.0000', 'balance_due' => '35.0000']);
});
