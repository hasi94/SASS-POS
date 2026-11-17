<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\ClosePurchaseOrder;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\ClosePurchaseOrder\ClosePurchaseOrder;
use Flexipos\Purchasing\Features\ClosePurchaseOrder\ClosePurchaseOrderHandler;
use Flexipos\Purchasing\Features\ClosePurchaseOrder\ClosePurchaseOrderResult;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\PurchasingOutbox;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 19b - ClosePurchaseOrder (AC-65, AC-66, AC-67, AC-97)
|--------------------------------------------------------------------------
|
| A partly or fully received order is closed with a reason; the abandoned quantity
| is left as stored; a repeat is a no-op; every other status is refused by name.
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
function puCloseOrder(array $fields, ?string $actorId): ClosePurchaseOrderResult
{
    /** @var ClosePurchaseOrderHandler $handler */
    $handler = app(ClosePurchaseOrderHandler::class);

    return $handler->handle(new ClosePurchaseOrder($fields, $actorId));
}

/**
 * A partly received order (two lines of 10, 4 received on the first) by default.
 *
 * @return array{desk: array<string, string>, order: string, first: string, second: string, closer: string}
 */
function puCloseWorld(string $status = 'partially_received'): array
{
    $desk = PurchasingWorld::desk('k1');
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], [
        'status' => $status,
        'created_by' => $desk['buyer'],
        'updated_by' => $desk['buyer'],
        'total' => '22.0000',
    ]);
    $group = PurchasingSeed::taxGroup('CLO');

    return [
        'desk' => $desk,
        'order' => $order,
        'first' => PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], $group, 1, ['quantity' => '10.0000', 'base_quantity' => '10.0000', 'received_quantity' => '4.0000', 'received_base_quantity' => '4.0000']),
        'second' => PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], $group, 2, ['quantity' => '10.0000', 'base_quantity' => '10.0000']),
        'closer' => AccessSeed::actorHolding('closer', [PurchasingPermissions::ORDERS_CLOSE], $desk['branch']),
    ];
}

/**
 * @param  array<string, mixed>  $fields
 */
function puCloseRefused(PurchasingRefusal $reason, array $fields, ?string $actorId, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puCloseOrder($fields, $actorId), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-65: closes a partly received order with a reason, leaves every line and total as stored, writes one audit row and the closed event', function (string $status) {
    $w = puCloseWorld($status);
    $linesBefore = PurchasingSeed::connection()->table('purchase_order_items')->orderBy('line_no')->get()->map(static fn (object $r): array => (array) $r)->all();
    $totalBefore = PurchasingSeed::row('purchase_orders', $w['order'])['total'];

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puCloseOrder(['order_id' => strtolower($w['order']), 'reason' => ' Supplier will not ship the rest '], strtolower($w['closer']));

    expect($result->changed)->toBeTrue()
        ->and($result->order['status'])->toBe('closed')
        ->and(PurchasingSeed::row('purchase_orders', $w['order']))->toMatchArray([
            'status' => 'closed',
            'total' => $totalBefore,
            'updated_by' => $w['closer'],
            'updated_at' => '2026-11-17 10:00:00.000',
        ])
        ->and(PurchasingSeed::connection()->table('purchase_order_items')->orderBy('line_no')->get()->map(static fn (object $r): array => (array) $r)->all())->toBe($linesBefore);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $w['closer'],
        'branch_id' => $w['desk']['branch'],
        'action' => PurchasingAuditActions::ORDER_CLOSED,
        'subject_type' => 'purchase_order',
        'subject_id' => $w['order'],
    ])
        ->and($audit['before']['status'])->toBe($status)
        ->and($audit['after']['status'])->toBe('closed')
        ->and($audit['after']['reason'])->toBe('Supplier will not ship the rest');

    $events = PurchasingOutbox::events();

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('purchase_order.closed')
        ->and($events[0]['branch_id'])->toBe($w['desk']['branch'])
        ->and($events[0]['payload']['order_id'])->toBe($w['order'])
        ->and($events[0]['payload']['location_id'])->toBe($w['desk']['location'])
        ->and(array_column($events[0]['payload']['lines'], 'received_base_quantity'))->toBe(['4.0000', '0.0000'])
        ->and(array_column($events[0]['payload']['lines'], 'base_quantity'))->toBe(['10.0000', '10.0000']);
})->with([
    'partially_received' => ['partially_received'],
    'received' => ['received'],
]);

it('AC-65: a second close succeeds, writes nothing, and still checks the reason', function () {
    $w = puCloseWorld();

    puCloseOrder(['order_id' => $w['order'], 'reason' => 'done'], $w['closer']);
    $before = PurchasingRefusals::state();

    $again = puCloseOrder(['order_id' => $w['order'], 'reason' => 'done again'], $w['closer']);

    expect($again->changed)->toBeFalse()
        ->and(PurchasingRefusals::state())->toBe($before);

    puCloseRefused(PurchasingRefusal::Validation, ['order_id' => $w['order']], $w['closer']);
});

it('AC-65 and AC-67: every other status is invalid_transition naming it, and nothing is written', function (string $status) {
    $w = puCloseWorld($status);
    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::InvalidTransition, fn () => puCloseOrder(['order_id' => $w['order'], 'reason' => 'x'], $w['closer']));

    expect($refused->getMessage())->toContain($status)
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'draft' => ['draft'],
    'pending_approval' => ['pending_approval'],
    'approved' => ['approved'],
    'sent' => ['sent'],
    'cancelled' => ['cancelled'],
]);

it('AC-65: a missing, blank, non-string or over-long reason is validation, and 500 characters is accepted', function (mixed $reason, bool $accepted) {
    $w = puCloseWorld();
    $fields = ['order_id' => $w['order']];

    if ($reason !== 'omit') {
        $fields['reason'] = $reason;
    }

    if ($accepted) {
        expect(puCloseOrder($fields, $w['closer'])->changed)->toBeTrue();

        return;
    }

    puCloseRefused(PurchasingRefusal::Validation, $fields, $w['closer']);
})->with([
    'omitted' => ['omit', false],
    'blank' => ['  ', false],
    'null' => [null, false],
    'array' => [['x'], false],
    '501' => [str_repeat('a', 501), false],
    '500' => [str_repeat('a', 500), true],
]);

it('AC-91: an unknown field, a missing or malformed id and an unknown order are refused', function () {
    $w = puCloseWorld();

    puCloseRefused(PurchasingRefusal::Validation, ['order_id' => $w['order'], 'reason' => 'x', 'status' => 'closed'], $w['closer']);
    puCloseRefused(PurchasingRefusal::Validation, ['reason' => 'x'], $w['closer']);
    puCloseRefused(PurchasingRefusal::Validation, ['order_id' => 'nope', 'reason' => 'x'], $w['closer']);
    puCloseRefused(PurchasingRefusal::NotFound, ['order_id' => (string) Str::ulid(), 'reason' => 'x'], $w['closer']);
});

it('AC-65 and AC-85: the close key at the stored branch is the key; manage, reopen, another branch s holder and no actor are refused', function () {
    $w = puCloseWorld();
    $other = AccessSeed::actorHolding('otherk', [PurchasingPermissions::ORDERS_REOPEN], $w['desk']['branch']);
    $scoped = AccessSeed::actorHolding('scopedk', [PurchasingPermissions::ORDERS_CLOSE], PurchasingSeed::branch());
    $fields = ['order_id' => $w['order'], 'reason' => 'x'];

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puCloseOrder($fields, $w['desk']['buyer']));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puCloseOrder($fields, $other));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puCloseOrder($fields, $scoped));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puCloseOrder($fields, null));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-86: a suspended employee is refused with the resolver s reason', function () {
    $w = puCloseWorld();
    PurchasingSeed::connection()->table('employees')->where('id', $w['closer'])->update(['status' => 'suspended']);

    $refused = PurchasingRefusals::capture(fn () => puCloseOrder(['order_id' => $w['order'], 'reason' => 'x'], $w['closer']));

    expect($refused->reason)->toBe(PurchasingRefusal::NotAuthorised)
        ->and($refused->cause)->toBe(RefusalReason::EmployeeUnavailable);
});
