<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\ApplyReceivedQuantity;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\ApplyReceivedQuantity\ApplyReceivedQuantity;
use Flexipos\Purchasing\Features\ApplyReceivedQuantity\ApplyReceivedQuantityHandler;
use Flexipos\Purchasing\Features\ApplyReceivedQuantity\ApplyReceivedQuantityResult;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Support\Money;
use Flexipos\Purchasing\Tests\Support\PurchasingOutbox;
use Flexipos\Purchasing\Tests\Support\PurchasingQueryLog;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 19c - ApplyReceivedQuantity (AC-69 to AC-80, AC-96, AC-97; D-44, D-47)
|--------------------------------------------------------------------------
|
| The rollup compares base to base on the stored four-decimal strings, so a delivery
| in another unit cannot close a half-empty order; the status follows the stored sums
| in both directions; an application id replays; the header is locked first; and the
| operation carries no permission check of its own.
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
function puApply(array $fields, ?string $actorId = null): ApplyReceivedQuantityResult
{
    /** @var ApplyReceivedQuantityHandler $handler */
    $handler = app(ApplyReceivedQuantityHandler::class);

    return $handler->handle(new ApplyReceivedQuantity($fields, $actorId));
}

/**
 * The fields of one application, with a fresh application id unless given.
 *
 * @return array<string, mixed>
 */
function puReceipt(string $lineId, string|int $base, string|int $quantity, ?string $applicationId = null): array
{
    return [
        'application_id' => $applicationId ?? (string) Str::ulid(),
        'line_id' => $lineId,
        'received_base_delta' => $base,
        'received_quantity_delta' => $quantity,
    ];
}

/**
 * An order of 10 cases at factor 24 (base 240) and a second line of 100 base, in the
 * status given, nothing received.
 *
 * @return array{desk: array<string, string>, order: string, big: string, small: string}
 */
function puApplyWorld(string $status = 'approved'): array
{
    $desk = PurchasingWorld::desk('a1');
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], [
        'status' => $status,
        'created_by' => $desk['buyer'],
        'updated_by' => $desk['buyer'],
        'total' => '22.0000',
    ]);
    $group = PurchasingSeed::taxGroup('APP');

    return [
        'desk' => $desk,
        'order' => $order,
        'big' => PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], $group, 1, ['quantity' => '10.0000', 'unit_factor' => '24.000000', 'base_quantity' => '240.0000']),
        'small' => PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], $group, 2, ['quantity' => '100.0000', 'base_quantity' => '100.0000']),
    ];
}

/**
 * A single-line order of 10 cases at factor 24.
 *
 * @return array{desk: array<string, string>, order: string, line: string}
 */
function puApplySingle(string $status = 'approved'): array
{
    $w = puApplyWorld($status);
    PurchasingSeed::connection()->table('purchase_order_items')->where('id', $w['small'])->delete();

    return ['desk' => $w['desk'], 'order' => $w['order'], 'line' => $w['big']];
}

/**
 * @param  array<string, mixed>  $fields
 */
function puApplyRefused(PurchasingRefusal $reason, array $fields, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puApply($fields), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-69 and AC-70: ten cartons of factor 12 against ten cases of factor 24 leave the order partially_received, and a further 120 base completes it', function () {
    $w = puApplySingle();

    $first = puApply(puReceipt(strtolower($w['line']), '120.0000', '5.0000'), $w['desk']['buyer']);

    expect($first->replayed)->toBeFalse()
        ->and($first->status)->toBe('partially_received')
        ->and($first->line)->toMatchArray(['received_base_quantity' => '120.0000', 'received_quantity' => '5.0000'])
        ->and(PurchasingSeed::row('purchase_orders', $w['order'])['status'])->toBe('partially_received');

    $second = puApply(puReceipt($w['line'], '120.0000', '5.0000'), $w['desk']['buyer']);

    expect($second->status)->toBe('received')
        ->and(PurchasingSeed::row('purchase_order_items', $w['line']))->toMatchArray(['received_base_quantity' => '240.0000', 'received_quantity' => '10.0000'])
        ->and(PurchasingSeed::row('purchase_orders', $w['order'])['status'])->toBe('received');
});

it('AC-70: the status never reads received_quantity, so ten counted of ten ordered does not complete a line whose base is short', function () {
    $w = puApplySingle();

    $result = puApply(puReceipt($w['line'], '120.0000', '10.0000'));

    expect($result->line['received_quantity'])->toBe('10.0000')
        ->and($result->status)->toBe('partially_received');
});

it('AC-71: with two lines one complete and one not is partially_received, and both complete is received', function () {
    $w = puApplyWorld();

    expect(puApply(puReceipt($w['big'], '240.0000', '10.0000'))->status)->toBe('partially_received')
        ->and(puApply(puReceipt($w['small'], '100.0000', '100.0000'))->status)->toBe('received');
});

it('AC-72: 239.9999 of 240.0000 is not complete, and 240.0000 is', function () {
    $w = puApplySingle();

    expect(puApply(puReceipt($w['line'], '239.9999', '9.9999'))->status)->toBe('partially_received')
        ->and(puApply(puReceipt($w['line'], '0.0001', '0.0000'))->status)->toBe('received');
});

it('AC-73: an over-receipt is accepted as given and counts as complete', function () {
    $w = puApplySingle();

    $result = puApply(puReceipt($w['line'], '300.0000', '12.5000'));

    expect($result->status)->toBe('received')
        ->and($result->line)->toMatchArray(['received_base_quantity' => '300.0000', 'received_quantity' => '12.5000']);
});

it('AC-74: a negative delta decreases both columns and the status follows: received back to partially_received, all zero back to approved', function () {
    $w = puApplyWorld();
    puApply(puReceipt($w['big'], '240.0000', '10.0000'));
    expect(puApply(puReceipt($w['small'], '100.0000', '100.0000'))->status)->toBe('received');

    $back = puApply(puReceipt($w['small'], '-40.0000', '-40.0000'));

    expect($back->status)->toBe('partially_received')
        ->and($back->line)->toMatchArray(['received_base_quantity' => '60.0000', 'received_quantity' => '60.0000']);

    puApply(puReceipt($w['small'], '-60.0000', '-60.0000'));
    $zero = puApply(puReceipt($w['big'], '-240.0000', '-10.0000'));

    expect($zero->status)->toBe('approved')
        ->and(PurchasingSeed::row('purchase_orders', $w['order'])['status'])->toBe('approved');
});

it('AC-74: a single-line order reversed to zero from received returns to approved', function () {
    $w = puApplySingle();
    puApply(puReceipt($w['line'], '240.0000', '10.0000'));

    expect(puApply(puReceipt($w['line'], '-240.0000', '-10.0000'))->status)->toBe('approved');
});

it('AC-74: a delta that would take either received column below zero is out_of_range, and a received column too large for DECIMAL(18,4) is too', function (string $case) {
    $w = puApplySingle();
    puApply(puReceipt($w['line'], '120.0000', '5.0000'));

    $fields = match ($case) {
        'base below zero' => puReceipt($w['line'], '-120.0001', '-1.0000'),
        'quantity below zero' => puReceipt($w['line'], '-10.0000', '-5.0001'),
        default => puReceipt($w['line'], '99999999999999.0000', '1.0000'),
    };

    puApplyRefused(PurchasingRefusal::OutOfRange, $fields, $case);
})->with([
    'base below zero' => ['base below zero'],
    'quantity below zero' => ['quantity below zero'],
    'too large' => ['too large'],
]);

it('AC-75 and D-44: draft, pending_approval, sent, closed and cancelled are order_not_open, and nothing changes', function (string $status) {
    $w = puApplySingle($status);

    puApplyRefused(PurchasingRefusal::OrderNotOpen, puReceipt($w['line'], '1.0000', '1.0000'), $status);
})->with([
    'draft' => ['draft'],
    'pending_approval' => ['pending_approval'],
    'sent' => ['sent'],
    'closed' => ['closed'],
    'cancelled' => ['cancelled'],
]);

it('AC-75: an unknown line is not_found and a malformed id is validation', function () {
    puApplyRefused(PurchasingRefusal::NotFound, puReceipt((string) Str::ulid(), '1.0000', '1.0000'));
    puApplyRefused(PurchasingRefusal::Validation, puReceipt('nope', '1.0000', '1.0000'));
    puApplyRefused(PurchasingRefusal::Validation, array_merge(puReceipt((string) Str::ulid(), '1.0000', '1.0000'), ['application_id' => 'nope']));
});

it('D-44: a received order accepts a reversal and an over-receipt', function () {
    $w = puApplySingle('received');
    PurchasingSeed::connection()->table('purchase_order_items')->where('id', $w['line'])->update(['received_base_quantity' => '240.0000', 'received_quantity' => '10.0000']);

    $over = puApply(puReceipt($w['line'], '10.0000', '0.4000'));

    expect($over->status)->toBe('received')
        ->and($over->line['received_base_quantity'])->toBe('250.0000');

    $reversal = puApply(puReceipt($w['line'], '-20.0000', '-1.0000'));

    expect($reversal->status)->toBe('partially_received');
});

it('AC-76: replaying an application id returns the same answer with replayed true, moves no column, and writes no audit row and no event', function () {
    $w = puApplySingle();
    $fields = puReceipt($w['line'], '120.0000', '5.0000');

    $first = puApply($fields, $w['desk']['buyer']);
    $before = PurchasingRefusals::state();

    $again = puApply(array_merge($fields, ['line_id' => strtolower($w['line']), 'application_id' => strtolower($fields['application_id']), 'received_base_delta' => '120', 'received_quantity_delta' => 5]), $w['desk']['buyer']);

    expect($first->replayed)->toBeFalse()
        ->and($again->replayed)->toBeTrue()
        ->and($again->status)->toBe($first->status)
        ->and($again->line)->toBe($first->line)
        ->and($again->applicationId)->toBe($fields['application_id'])
        ->and(PurchasingRefusals::state())->toBe($before)
        ->and(PurchasingSeed::connection()->table('purchase_order_receipt_applications')->count())->toBe(1);
});

it('AC-76: a replay still answers after the order has been closed', function () {
    $w = puApplySingle();
    $fields = puReceipt($w['line'], '120.0000', '5.0000');
    puApply($fields);
    PurchasingSeed::connection()->table('purchase_orders')->where('id', $w['order'])->update(['status' => 'closed']);

    $again = puApply($fields);

    expect($again->replayed)->toBeTrue()
        ->and($again->status)->toBe('closed');
});

it('AC-76: the same application id with another line or other deltas is replay_conflict naming it', function (string $case) {
    $w = puApplyWorld();
    $fields = puReceipt($w['big'], '120.0000', '5.0000');
    puApply($fields);

    $changed = match ($case) {
        'other base' => array_merge($fields, ['received_base_delta' => '120.0001']),
        'other quantity' => array_merge($fields, ['received_quantity_delta' => '5.0001']),
        default => array_merge($fields, ['line_id' => $w['small']]),
    };

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::ReplayConflict, fn () => puApply($changed), $case);

    expect($refused->getMessage())->toContain($fields['application_id'])
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'other base' => ['other base'],
    'other quantity' => ['other quantity'],
    'other line' => ['other line'],
]);

it('AC-77 and D-47: a malformed, float, zero-base, opposite-sign or oversized delta is validation, and nothing changes', function (array $override) {
    $w = puApplySingle();

    puApplyRefused(PurchasingRefusal::Validation, array_merge(puReceipt($w['line'], '1.0000', '1.0000'), $override));
})->with([
    'float base' => [['received_base_delta' => 1.5]],
    'float quantity' => [['received_quantity_delta' => 1.5]],
    'bool' => [['received_base_delta' => true]],
    'null base' => [['received_base_delta' => null]],
    'null quantity' => [['received_quantity_delta' => null]],
    'text' => [['received_base_delta' => 'abc']],
    'five decimals' => [['received_base_delta' => '1.00001']],
    'exponent' => [['received_base_delta' => '1e3']],
    'plus sign' => [['received_base_delta' => '+1']],
    'fifteen integer digits' => [['received_base_delta' => '100000000000000.0000']],
    'zero base' => [['received_base_delta' => '0.0000']],
    'zero base with quantity' => [['received_base_delta' => '0', 'received_quantity_delta' => '1.0000']],
    'positive base negative quantity' => [['received_base_delta' => '1.0000', 'received_quantity_delta' => '-1.0000']],
    'negative base positive quantity' => [['received_base_delta' => '-1.0000', 'received_quantity_delta' => '1.0000']],
    'missing application' => [['application_id' => null]],
    'unknown field' => [['status' => 'received']],
]);

it('AC-77 and D-47: a zero quantity delta beside a non-zero base delta is accepted and leaves the quantity column as it was; ints are accepted', function () {
    $w = puApplySingle();
    puApply(puReceipt($w['line'], '1.0000', '1.0000'));

    $result = puApply(puReceipt($w['line'], 3, 0));

    expect($result->line)->toMatchArray(['received_base_quantity' => '4.0000', 'received_quantity' => '1.0000'])
        ->and(PurchasingSeed::row('purchase_order_receipt_applications', $result->applicationId))->toMatchArray([
            'received_base_delta' => '3.0000',
            'received_quantity_delta' => '0.0000',
        ]);

    $negative = puApply(puReceipt($w['line'], '-3.0000', '0.0000'));

    expect($negative->line['received_base_quantity'])->toBe('1.0000');
});

it('AC-80: one application row, one audit row carrying the application id and both deltas, and the received_applied event', function () {
    $w = puApplySingle();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));
    $fields = puReceipt($w['line'], '120.0000', '5.0000');

    $result = puApply($fields, strtolower($w['desk']['buyer']));

    expect(PurchasingSeed::row('purchase_order_receipt_applications', $fields['application_id']))->toMatchArray([
        'order_id' => $w['order'],
        'order_item_id' => $w['line'],
        'received_base_delta' => '120.0000',
        'received_quantity_delta' => '5.0000',
        'created_by' => $w['desk']['buyer'],
        'created_at' => '2026-11-17 10:00:00.000',
    ])
        ->and(PurchasingSeed::row('purchase_orders', $w['order']))->toMatchArray(['status' => 'partially_received', 'updated_at' => '2026-11-17 10:00:00.000', 'updated_by' => $w['desk']['buyer']]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $w['desk']['buyer'],
        'branch_id' => $w['desk']['branch'],
        'action' => PurchasingAuditActions::ORDER_RECEIPT_APPLIED,
        'subject_type' => 'purchase_order',
        'subject_id' => $w['order'],
    ])
        ->and($audit['before']['received_base_quantity'])->toBe('0.0000')
        ->and($audit['after'])->toMatchArray([
            'application_id' => $fields['application_id'],
            'received_base_delta' => '120.0000',
            'received_quantity_delta' => '5.0000',
            'received_base_quantity' => '120.0000',
            'order_status' => 'partially_received',
        ]);

    $events = PurchasingOutbox::events();

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('purchase_order.received_applied')
        ->and($events[0]['aggregate_id'])->toBe($w['order'])
        ->and($events[0]['branch_id'])->toBe($w['desk']['branch'])
        ->and($events[0]['payload'])->toBe([
            'order_id' => $w['order'],
            'application_id' => $fields['application_id'],
            'line_id' => $w['line'],
            'product_id' => $w['desk']['product'],
            'location_id' => $w['desk']['location'],
            'received_base_delta' => '120.0000',
            'received_quantity_delta' => '5.0000',
            'received_base_quantity' => '120.0000',
            'received_quantity' => '5.0000',
            'status' => 'partially_received',
        ])
        ->and($result->order['status'])->toBe('partially_received');
});

it('AC-80: a receipt that leaves the status as it was does not touch the header', function () {
    $w = puApplyWorld();
    puApply(puReceipt($w['big'], '10.0000', '0.4000'));
    $updatedAt = PurchasingSeed::row('purchase_orders', $w['order'])['updated_at'];

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-18 10:00:00.000'));
    puApply(puReceipt($w['big'], '10.0000', '0.4000'));

    expect(PurchasingSeed::row('purchase_orders', $w['order'])['updated_at'])->toBe($updatedAt);
});

it('AC-96: no permission is checked: a null actor and an employee holding no purchasing key both apply', function () {
    $w = puApplyWorld();
    $nobody = AccessSeed::actorHolding('nobody', [], $w['desk']['branch']);

    $anonymous = puApply(puReceipt($w['big'], '10.0000', '0.4000'), null);
    $plain = puApply(puReceipt($w['big'], '10.0000', '0.4000'), $nobody);

    expect($anonymous->replayed)->toBeFalse()
        ->and($plain->replayed)->toBeFalse()
        ->and(PurchasingSeed::row('purchase_orders', $w['order'])['status'])->toBe('partially_received')
        ->and(PurchasingSeed::audit()[0]['actor_id'])->toBeNull()
        ->and(PurchasingSeed::row('purchase_order_receipt_applications', $anonymous->applicationId)['created_by'])->toBeNull();
});

it('AC-79: only the order, its lines, the applications, the audit trail and the outbox are touched', function () {
    $w = puApplySingle();
    $others = ['stock_levels', 'stock_movements', 'customer_ledger'];
    $present = array_values(array_filter($others, static fn (string $table): bool => PurchasingSeed::connection()->getSchemaBuilder()->hasTable($table)));
    $counts = array_map(static fn (string $table): int => PurchasingSeed::connection()->table($table)->count(), $present);

    puApply(puReceipt($w['line'], '240.0000', '10.0000'));

    expect(array_map(static fn (string $table): int => PurchasingSeed::connection()->table($table)->count(), $present))->toBe($counts);
});

it('Ruling 7: the order header is the first statement inside the transaction and is locked for update, and the line lock comes after it', function () {
    $w = puApplySingle();

    $log = PurchasingQueryLog::duringLocked(fn () => puApply(puReceipt($w['line'], '1.0000', '1.0000')));
    $inside = PurchasingQueryLog::inTransaction($log);

    $lineLock = array_values(array_filter($inside, static fn (string $sql): bool => str_contains($sql, '"purchase_order_items"') && str_contains($sql, '/* for update */')));

    expect($inside[0])->toContain('"purchase_orders"')->and($inside[0])->toContain('/* for update */')
        ->and($lineLock)->not->toBe([])
        ->and(array_search($lineLock[0], $inside, true))->toBeGreaterThan(0);
});

it('AC-96: inside the receiving slice s transaction it is a savepoint: a refused apply leaves the earlier one, and the caller s rollback takes both', function () {
    $w = puApplyWorld();
    $connection = PurchasingSeed::connection();

    $connection->transaction(function () use ($w): void {
        puApply(puReceipt($w['big'], '10.0000', '0.4000'));

        PurchasingRefusals::expect(PurchasingRefusal::OutOfRange, fn () => puApply(puReceipt($w['small'], '-1.0000', '-1.0000')));
    });

    expect(PurchasingSeed::row('purchase_orders', $w['order'])['status'])->toBe('partially_received')
        ->and(PurchasingSeed::connection()->table('purchase_order_receipt_applications')->count())->toBe(1);

    try {
        $connection->transaction(function () use ($w): void {
            puApply(puReceipt($w['big'], '10.0000', '0.4000'));

            throw new RuntimeException('the receive failed after the rollup');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(PurchasingSeed::connection()->table('purchase_order_receipt_applications')->count())->toBe(1)
        ->and(Money::of(PurchasingSeed::row('purchase_order_items', $w['big'])['received_base_quantity']))->toBe('10.0000')
        ->and(count(PurchasingSeed::audit()))->toBe(1)
        ->and(count(PurchasingOutbox::events()))->toBe(1);
});
