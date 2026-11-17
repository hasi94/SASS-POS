<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\RemovePurchaseOrderLine;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\RemovePurchaseOrderLine\RemovePurchaseOrderLine;
use Flexipos\Purchasing\Features\RemovePurchaseOrderLine\RemovePurchaseOrderLineHandler;
use Flexipos\Purchasing\Features\RemovePurchaseOrderLine\RemovePurchaseOrderLineResult;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\PurchasingQueryLog;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 16 - RemovePurchaseOrderLine (AC-49, AC-60, AC-61, AC-66, D-48)
|--------------------------------------------------------------------------
|
| The line is deleted under the header lock, its before image is audited, the
| header is re-summed from what is left, and a request line pointing at it is
| cleared in the same transaction.
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
function puRemoveOrderLine(array $fields, ?string $actorId): RemovePurchaseOrderLineResult
{
    /** @var RemovePurchaseOrderLineHandler $handler */
    $handler = app(RemovePurchaseOrderLineHandler::class);

    return $handler->handle(new RemovePurchaseOrderLine($fields, $actorId));
}

/**
 * An order with two lines (11.0000 each) whose header already says so, plus 2.5
 * shipping.
 *
 * @return array{desk: array<string, string>, order: string, first: string, second: string}
 */
function puRemoveWorld(string $status = 'draft'): array
{
    $desk = PurchasingWorld::desk('r1');
    $group = PurchasingSeed::taxGroup('REM');
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], [
        'status' => $status,
        'created_by' => $desk['buyer'],
        'updated_by' => $desk['buyer'],
        'shipping' => '2.5000',
        'subtotal' => '20.0000',
        'tax_total' => '2.0000',
        'total' => '24.5000',
    ]);

    return [
        'desk' => $desk,
        'order' => $order,
        'first' => PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], $group, 1),
        'second' => PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], $group, 2),
    ];
}

/**
 * @param  array<string, mixed>  $fields
 */
function puRemoveOrderLineRefused(PurchasingRefusal $reason, array $fields, ?string $actorId, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puRemoveOrderLine($fields, $actorId), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-49: deletes the line, re-sums the header from what is left, keeps the other line s number, and audits the before image against the order', function () {
    $w = puRemoveWorld();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puRemoveOrderLine(['order_id' => strtolower($w['order']), 'line_id' => strtolower($w['first'])], strtolower($w['desk']['buyer']));

    expect($result->clearedRequestLineIds)->toBe([])
        ->and($result->line['id'])->toBe($w['first'])
        ->and($result->line['line_no'])->toBe(1)
        ->and($result->order)->toMatchArray([
            'subtotal' => '10.0000',
            'discount_total' => '0.0000',
            'tax_total' => '1.0000',
            'total' => '13.5000',
            'updated_at' => '2026-11-17 10:00:00.000',
            'updated_by' => $w['desk']['buyer'],
        ])
        ->and(PurchasingSeed::connection()->table('purchase_order_items')->where('id', $w['first'])->exists())->toBeFalse()
        ->and(PurchasingSeed::row('purchase_order_items', $w['second'])['line_no'])->toBe(2);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $w['desk']['buyer'],
        'branch_id' => $w['desk']['branch'],
        'module' => PurchasingAuditActions::MODULE,
        'action' => PurchasingAuditActions::ORDER_LINE_REMOVED,
        'subject_type' => 'purchase_order',
        'subject_id' => $w['order'],
        'after' => null,
    ])
        ->and($audit['before']['id'])->toBe($w['first'])
        ->and($audit['before']['line_total'])->toBe('11.0000');
});

it('AC-49: removing the last line leaves a valid draft whose totals are zero plus the shipping', function () {
    $w = puRemoveWorld();

    puRemoveOrderLine(['order_id' => $w['order'], 'line_id' => $w['first']], $w['desk']['buyer']);
    $result = puRemoveOrderLine(['order_id' => $w['order'], 'line_id' => $w['second']], $w['desk']['buyer']);

    expect($result->order)->toMatchArray(['subtotal' => '0.0000', 'discount_total' => '0.0000', 'tax_total' => '0.0000', 'total' => '2.5000', 'shipping' => '2.5000', 'status' => 'draft']);
});

it('D-48: removing a line made by a conversion clears the request line s pointer in the same transaction, records it, and leaves the request converted', function () {
    $w = puRemoveWorld();
    $request = PurchasingSeed::request($w['desk']['branch'], $w['desk']['location'], $w['desk']['buyer'], ['status' => 'converted']);
    $requestLine = PurchasingSeed::requestItem($request, $w['desk']['product'], $w['desk']['unit'], 1, ['purchase_order_item_id' => $w['first']]);
    $other = PurchasingSeed::requestItem($request, $w['desk']['product'], $w['desk']['unit'], 2, ['purchase_order_item_id' => $w['second']]);

    $result = puRemoveOrderLine(['order_id' => $w['order'], 'line_id' => $w['first']], $w['desk']['buyer']);

    expect($result->clearedRequestLineIds)->toBe([$requestLine])
        ->and(PurchasingSeed::row('purchase_request_items', $requestLine)['purchase_order_item_id'])->toBeNull()
        ->and(PurchasingSeed::row('purchase_request_items', $other)['purchase_order_item_id'])->toBe($w['second'])
        ->and(PurchasingSeed::row('purchase_requests', $request)['status'])->toBe('converted')
        ->and(PurchasingSeed::onlyAuditRow()['after'])->toBe(['cleared_request_line_ids' => [$requestLine]]);
});

it('AC-60 and AC-61: a pending order is locked, an approved or partly received one is locked naming reopen, and a received, closed or cancelled one is order_closed', function (string $status, PurchasingRefusal $reason) {
    $w = puRemoveWorld($status);

    puRemoveOrderLineRefused($reason, ['order_id' => $w['order'], 'line_id' => $w['first']], $w['desk']['buyer'], $status);
})->with([
    'pending_approval' => [OrderStatus::PendingApproval->value, PurchasingRefusal::Locked],
    'approved' => [OrderStatus::Approved->value, PurchasingRefusal::Locked],
    'partially_received' => [OrderStatus::PartiallyReceived->value, PurchasingRefusal::Locked],
    'received' => [OrderStatus::Received->value, PurchasingRefusal::OrderClosed],
    'closed' => [OrderStatus::Closed->value, PurchasingRefusal::OrderClosed],
    'cancelled' => [OrderStatus::Cancelled->value, PurchasingRefusal::OrderClosed],
]);

it('an approved order s refusal names reopen', function () {
    $w = puRemoveWorld('approved');

    $refused = PurchasingRefusals::expect(PurchasingRefusal::Locked, fn () => puRemoveOrderLine(['order_id' => $w['order'], 'line_id' => $w['first']], $w['desk']['buyer']));

    expect($refused->getMessage())->toContain('Reopen');
});

it('AC-91: an unknown order or line is not_found, another order s line is not_found through this one, a removed line is not_found a second time, and a malformed id or field is validation', function () {
    $w = puRemoveWorld();
    $other = PurchasingSeed::order($w['desk']['supplier'], $w['desk']['branch'], $w['desk']['location']);

    puRemoveOrderLineRefused(PurchasingRefusal::NotFound, ['order_id' => (string) Str::ulid(), 'line_id' => $w['first']], $w['desk']['buyer']);
    puRemoveOrderLineRefused(PurchasingRefusal::NotFound, ['order_id' => $w['order'], 'line_id' => (string) Str::ulid()], $w['desk']['buyer']);
    puRemoveOrderLineRefused(PurchasingRefusal::NotFound, ['order_id' => $other, 'line_id' => $w['first']], $w['desk']['buyer']);
    puRemoveOrderLineRefused(PurchasingRefusal::Validation, ['order_id' => 'nope', 'line_id' => $w['first']], $w['desk']['buyer']);
    puRemoveOrderLineRefused(PurchasingRefusal::Validation, ['order_id' => $w['order'], 'line_id' => 'nope'], $w['desk']['buyer']);
    puRemoveOrderLineRefused(PurchasingRefusal::Validation, ['order_id' => $w['order']], $w['desk']['buyer']);
    puRemoveOrderLineRefused(PurchasingRefusal::Validation, ['order_id' => $w['order'], 'line_id' => $w['first'], 'reason' => 'x'], $w['desk']['buyer']);

    puRemoveOrderLine(['order_id' => $w['order'], 'line_id' => $w['first']], $w['desk']['buyer']);

    puRemoveOrderLineRefused(PurchasingRefusal::NotFound, ['order_id' => $w['order'], 'line_id' => $w['first']], $w['desk']['buyer'], 'second remove');
});

it('AC-85: the permission is asked at the stored branch, and the view key alone, a request key and no key are refused', function () {
    $w = puRemoveWorld();
    $scoped = AccessSeed::actorHolding('scoped', [PurchasingPermissions::ORDERS_MANAGE], PurchasingSeed::branch());
    $viewer = AccessSeed::actorHolding('viewer', [PurchasingPermissions::ORDERS_VIEW], $w['desk']['branch']);
    $requester = AccessSeed::actorHolding('requester', [PurchasingPermissions::REQUESTS_MANAGE], $w['desk']['branch']);
    $fields = ['order_id' => $w['order'], 'line_id' => $w['first']];

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puRemoveOrderLine($fields, $scoped));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puRemoveOrderLine($fields, $viewer));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puRemoveOrderLine($fields, $requester));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puRemoveOrderLine($fields, null));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-86: a suspended employee is refused with the resolver s reason', function () {
    $w = puRemoveWorld();
    AccessSeed::connection()->table('employees')->where('id', $w['desk']['buyer'])->update(['status' => 'suspended']);

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puRemoveOrderLine(['order_id' => $w['order'], 'line_id' => $w['first']], $w['desk']['buyer']));

    expect($refused->cause)->toBe(RefusalReason::EmployeeUnavailable);
});

it('AC-47: a header whose stored lines already overflow refuses the removal that would re-sum it, and nothing changes', function () {
    $w = puRemoveWorld();
    PurchasingSeed::connection()->table('purchase_order_items')->where('id', $w['second'])->update(['line_total' => '99999999999999.0000']);
    PurchasingSeed::orderItem($w['order'], $w['desk']['product'], $w['desk']['unit'], PurchasingSeed::taxGroup('REMOV'), 3, ['line_total' => '99999999999999.0000']);

    puRemoveOrderLineRefused(PurchasingRefusal::OutOfRange, ['order_id' => $w['order'], 'line_id' => $w['first']], $w['desk']['buyer']);
});

it('Ruling 7: the order header is locked first, then the line, before the delete', function () {
    $w = puRemoveWorld();

    $log = PurchasingQueryLog::duringLocked(fn () => puRemoveOrderLine(['order_id' => $w['order'], 'line_id' => $w['first']], $w['desk']['buyer']));
    $inside = PurchasingQueryLog::inTransaction($log);

    $lineLock = array_values(array_filter($inside, static fn (string $sql): bool => str_contains($sql, '"purchase_order_items"') && str_contains($sql, '/* for update */')));
    $delete = array_values(array_filter($inside, static fn (string $sql): bool => str_starts_with($sql, 'delete from "purchase_order_items"')));

    expect($inside[0])->toContain('"purchase_orders"')->and($inside[0])->toContain('/* for update */')
        ->and($lineLock)->not->toBe([])
        ->and($delete)->toHaveCount(1)
        ->and(array_search($lineLock[0], $inside, true))->toBeLessThan(array_search($delete[0], $inside, true));
});
