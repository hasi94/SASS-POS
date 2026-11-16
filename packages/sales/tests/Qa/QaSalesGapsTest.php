<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Qa;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Approvals\ApprovalGate;
use Flexipos\Sales\Approvals\ApprovalType;
use Flexipos\Sales\Approvals\ConsumedApproval;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLine;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLineHandler;
use Flexipos\Sales\Features\AddTender\AddTender;
use Flexipos\Sales\Features\AddTender\AddTenderHandler;
use Flexipos\Sales\Features\CompleteSale\CompleteSale;
use Flexipos\Sales\Features\CompleteSale\CompleteSaleHandler;
use Flexipos\Sales\Features\HoldSale\HoldSale;
use Flexipos\Sales\Features\HoldSale\HoldSaleHandler;
use Flexipos\Sales\Features\RecallSale\RecallSale;
use Flexipos\Sales\Features\RecallSale\RecallSaleHandler;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| QA - gaps found by mutation (T-030)
|--------------------------------------------------------------------------
|
| Each row here is the answer to a mutant that survived the shipped suite.
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
 * @param  array<string, mixed>|null  $payload
 */
function qagConsume(ApprovalType $type, string $branch, string $referenceType, ?string $referenceId, ?array $payload, ?string $approvalId): ConsumedApproval
{
    /** @var ApprovalGate $gate */
    $gate = app(ApprovalGate::class);

    return SalesSeed::connection()->transaction(
        fn (): ConsumedApproval => $gate->consume($type, $branch, $referenceType, $referenceId, $payload, $approvalId)
    );
}

// The shipped 'reference type' row of ApprovalGateTest asks for ('goodwill_refund', null), so the reference TYPE and the
// reference ID both differ and the id comparison alone refuses it: a gate that never compared reference_type passed it.
// Here the id is the same and only the type differs, both ways round.
it('refuses a reference_type that differs while the reference id is the same, and consumes nothing (D-47)', function (string $seeded, string $asked) {
    $w = apvWorld();
    $id = SalesSeed::approval($w['branch'], $w['cashier'], [
        'type' => 'refund', 'reference_type' => $seeded, 'reference_id' => $w['sale'],
        'status' => 'approved', 'decided_by' => $w['manager'], 'decided_at' => SalesSeed::NOW, 'decision_method' => 'pin_local',
    ]);

    SalesRefusals::expect(SalesRefusal::ApprovalMismatch, fn () => qagConsume(ApprovalType::Refund, $w['branch'], $asked, $w['sale'], null, $id));

    expect(SalesSeed::row('approval_requests', $id)['consumed_at'])->toBeNull();
})->with([
    'a goodwill-bound row used for a sale' => ['goodwill_refund', 'sale'],
    'a sale-bound row used as goodwill' => ['sale', 'goodwill_refund'],
]);

// AC-14 says the tenders must EQUAL the total. AddTender can never take a sale past its total, so an overpaid pending sale
// can only come from a bad row (a hand edit, a future writer): the completion must still refuse it, not complete it.
// 1 unit of a 10.0000 product on a 10 percent exclusive group is 11.0000; a raw tender of 12.0000 sits on the sale.
it('refuses to complete a sale whose tenders are MORE than its total, as balance_due, writing nothing (AC-14: equal, not at least)', function () {
    $till = SalesWorld::till();
    $group = SalesWorld::taxGroup('TXO', '10.0000');
    $catalogue = SalesWorld::product('PO', '10.0000', $group);
    $cash = SalesSeed::paymentMethod('CASH', ['type' => 'cash']);
    $sale = SalesWorld::sale($till);

    app(AddSaleLineHandler::class)->handle(new AddSaleLine(['sale_id' => $sale, 'product_id' => $catalogue['product'], 'unit_id' => $catalogue['unit'], 'quantity' => '1'], $till['cashier']));

    SalesSeed::tender($sale, $cash, 'cash', '12.0000');
    SalesSeed::connection()->table('sales')->where('id', $sale)->update(['status' => 'pending_payment']);

    $before = SalesSeed::row('sales', $sale);

    $refused = SalesRefusals::expect(SalesRefusal::BalanceDue, fn () => app(CompleteSaleHandler::class)->handle(new CompleteSale(['sale_id' => $sale], $till['cashier'])));

    expect(SalesSeed::row('sales', $sale))->toBe($before)
        ->and($refused->getMessage())->toContain('-1.0000');
});

// The shipped replay row for 'method' changes the reference as well ('A' against none), so the reference comparison refuses it
// and a replay that never compared the payment method passed. Here only the method differs: cash and card, same sale, same
// figure, no reference, the same id.
it('refuses a tender replayed under the same id with only the payment method changed, as replay_conflict (AC-37)', function () {
    $till = SalesWorld::till();
    $group = SalesWorld::taxGroup('TXR', '10.0000');
    $catalogue = SalesWorld::product('PR', '10.0000', $group);
    $cash = SalesSeed::paymentMethod('CASH', ['type' => 'cash', 'allows_partial' => true]);
    $card = SalesSeed::paymentMethod('CARD', ['type' => 'card', 'allows_partial' => true]);
    $sale = SalesWorld::sale($till);
    $id = (string) Str::ulid();

    app(AddSaleLineHandler::class)->handle(new AddSaleLine(['sale_id' => $sale, 'product_id' => $catalogue['product'], 'unit_id' => $catalogue['unit'], 'quantity' => '1'], $till['cashier']));
    app(AddTenderHandler::class)->handle(new AddTender(['id' => $id, 'sale_id' => $sale, 'payment_method_id' => $cash, 'offered' => '5'], $till['cashier']));

    $before = SalesSeed::connection()->table('sale_tenders')->get()->map(fn ($r) => (array) $r)->all();

    SalesRefusals::expect(SalesRefusal::ReplayConflict, fn () => app(AddTenderHandler::class)->handle(new AddTender(['id' => $id, 'sale_id' => $sale, 'payment_method_id' => $card, 'offered' => '5'], $till['cashier'])));

    expect(SalesSeed::connection()->table('sale_tenders')->get()->map(fn ($r) => (array) $r)->all())->toBe($before);
});

// A sale held twice: first from draft (no tender), recalled, tendered, then held again from pending_payment. The LATEST hold
// decides: the sale comes back as pending_payment and its lines stay locked. A reader of the oldest hold would see draft and
// let a line be added to a sale that has money on it (AC-6, AC-17, AC-18, D-36).
it('reads the LATEST hold: a sale held from draft, recalled, tendered and held again is locked while held and recalls to pending_payment', function () {
    $till = SalesWorld::till();
    $group = SalesWorld::taxGroup('TXH', '10.0000');
    $catalogue = SalesWorld::product('PH', '10.0000', $group);
    $card = SalesSeed::paymentMethod('CARD', ['type' => 'card', 'allows_partial' => true]);
    $sale = SalesWorld::sale($till);
    $line = fn () => app(AddSaleLineHandler::class)->handle(new AddSaleLine(['sale_id' => $sale, 'product_id' => $catalogue['product'], 'unit_id' => $catalogue['unit'], 'quantity' => '1'], $till['cashier']));
    $hold = fn () => app(HoldSaleHandler::class)->handle(new HoldSale(['sale_id' => $sale, 'terminal_id' => $till['terminal']], $till['cashier']));
    $recall = fn () => app(RecallSaleHandler::class)->handle(new RecallSale(['sale_id' => $sale, 'terminal_id' => $till['terminal']], $till['cashier']));

    $clock = app(RunnerClock::class);

    $line();
    $hold();
    $clock->advanceMinutes(5);
    $recall();

    // Tendered in full by card: the sale is now pending_payment, with money on it.
    app(AddTenderHandler::class)->handle(new AddTender(['id' => (string) Str::ulid(), 'sale_id' => $sale, 'payment_method_id' => $card, 'offered' => '11'], $till['cashier']));

    expect(SalesSeed::row('sales', $sale)['status'])->toBe('pending_payment');

    $clock->advanceMinutes(5);
    $hold();

    $heldFrom = SalesSeed::connection()->table('sale_holds')->where('sale_id', $sale)->where('action', 'hold')->orderBy('created_at')->pluck('from_status')->all();

    expect($heldFrom)->toBe(['draft', 'pending_payment']);

    SalesRefusals::expect(SalesRefusal::SaleNotEditable, $line);

    $clock->advanceMinutes(5);
    $recall();

    expect(SalesSeed::row('sales', $sale)['status'])->toBe('pending_payment');

    SalesRefusals::expect(SalesRefusal::SaleNotEditable, $line);
});
