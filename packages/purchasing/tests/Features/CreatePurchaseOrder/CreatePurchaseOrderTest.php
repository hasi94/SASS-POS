<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\CreatePurchaseOrder;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Organization\DocumentNumbers;
use Flexipos\Contracts\Organization\DocumentNumbersRefusal;
use Flexipos\Contracts\Organization\DocumentNumbersRefused;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\CreatePurchaseOrder\CreatePurchaseOrder;
use Flexipos\Purchasing\Features\CreatePurchaseOrder\CreatePurchaseOrderHandler;
use Flexipos\Purchasing\Features\CreatePurchaseOrder\CreatePurchaseOrderResult;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\DeadlockingNumbers;
use Flexipos\Purchasing\Tests\Support\PurchasingQueryLog;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 15 - CreatePurchaseOrder (AC-40, AC-41, AC-52, AC-53, AC-90)
|--------------------------------------------------------------------------
|
| The number is the sequence's and is taken last, a refusal consumes no number
| (the whole of `number_sequences` is compared), the currency chain is explicit,
| supplier, branch, the supplier is snapshotted, and a replay of the id is
| answered not written.
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
function puCreateOrder(array $fields, ?string $actorId): CreatePurchaseOrderResult
{
    /** @var CreatePurchaseOrderHandler $handler */
    $handler = app(CreatePurchaseOrderHandler::class);

    return $handler->handle(new CreatePurchaseOrder($fields, $actorId));
}

/**
 * @param  array<string, string>  $desk
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function puOrderFields(array $desk, array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::ulid(),
        'supplier_id' => $desk['supplier'],
        'branch_id' => $desk['branch'],
        'location_id' => $desk['location'],
    ], $overrides);
}

/**
 * Refuse the create with this reason, and prove nothing changed anywhere, the
 * number sequences included.
 *
 * @param  array<string, mixed>  $fields
 */
function puCreateOrderRefused(PurchasingRefusal $reason, array $fields, ?string $actorId, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puCreateOrder($fields, $actorId), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-40: creates a draft with the sequence s first number, the supplier snapshots, the branch-local date, the defaults, and one audit row carrying the branch', function () {
    $desk = PurchasingWorld::desk();
    $supplier = PurchasingSeed::supplier('SNAP', ['name' => 'Acme Foods', 'tax_no' => 'TIN-9', 'payment_terms_days' => 30, 'currency' => 'USD']);
    $fields = puOrderFields($desk, ['supplier_id' => $supplier, 'expected_date' => '2026-11-20', 'terms' => ' Net 30 ', 'notes' => ' Rush ']);

    $result = puCreateOrder(array_merge($fields, ['id' => strtolower($fields['id']), 'supplier_id' => strtolower($supplier)]), strtolower($desk['buyer']));

    expect($result->orderId)->toBe($fields['id'])
        ->and($result->number)->toBe('PO000001')
        ->and($result->replayed)->toBeFalse()
        ->and(PurchasingSeed::row('purchase_orders', $fields['id']))->toMatchArray([
            'number' => 'PO000001',
            'status' => 'draft',
            'supplier_id' => $supplier,
            'supplier_name' => 'Acme Foods',
            'supplier_tax_no' => 'TIN-9',
            'payment_terms_days' => 30,
            'branch_id' => $desk['branch'],
            'location_id' => $desk['location'],
            'request_id' => null,
            'order_date' => '2026-11-16',
            'expected_date' => '2026-11-20',
            'workflow_instance_id' => null,
            'currency' => 'USD',
            'terms' => 'Net 30',
            'notes' => 'Rush',
            'created_by' => $desk['buyer'],
            'updated_by' => $desk['buyer'],
            'created_at' => PurchasingSeed::NOW,
        ])
        ->and($result->order)->toMatchArray([
            'exchange_rate' => '1.00000000',
            'shipping' => '0.0000',
            'subtotal' => '0.0000',
            'discount_total' => '0.0000',
            'tax_total' => '0.0000',
            'total' => '0.0000',
            'received_value' => '0.0000',
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $desk['buyer'],
        'branch_id' => $desk['branch'],
        'module' => PurchasingAuditActions::MODULE,
        'action' => PurchasingAuditActions::ORDER_CREATED,
        'subject_type' => 'purchase_order',
        'subject_id' => $fields['id'],
        'before' => null,
    ])
        ->and($audit['after']['number'])->toBe('PO000001')
        ->and($audit['after']['supplier_name'])->toBe('Acme Foods');
});

it('AC-40: a second order is PO000002, and a shipping figure is the total of an order with no lines (AC-45)', function () {
    $desk = PurchasingWorld::desk();

    $first = puCreateOrder(puOrderFields($desk), $desk['buyer']);
    $second = puCreateOrder(puOrderFields($desk, ['shipping' => '2.5', 'exchange_rate' => '0.00001234']), $desk['buyer']);

    expect([$first->number, $second->number])->toBe(['PO000001', 'PO000002'])
        ->and($second->order)->toMatchArray(['shipping' => '2.5000', 'total' => '2.5000', 'subtotal' => '0.0000', 'exchange_rate' => '0.00001234']);
});

it('D-41: the currency is the explicit one, else the supplier s, else the branch s', function () {
    $desk = PurchasingWorld::desk();
    $withCurrency = PurchasingSeed::supplier('EUR', ['currency' => 'EUR']);

    $explicit = puCreateOrder(puOrderFields($desk, ['supplier_id' => $withCurrency, 'currency' => 'gbp']), $desk['buyer']);
    $supplier = puCreateOrder(puOrderFields($desk, ['supplier_id' => $withCurrency]), $desk['buyer']);
    $branch = puCreateOrder(puOrderFields($desk), $desk['buyer']);

    expect([$explicit->order['currency'], $supplier->order['currency'], $branch->order['currency']])->toBe(['GBP', 'EUR', 'LKR']);
});

it('Q-B4: currency_required is reached only when the explicit currency, the supplier s and a branch seeded with a blank currency are all absent, and consumes no number', function () {
    $desk = PurchasingWorld::desk();
    AccessSeed::connection()->table('branches')->where('id', $desk['branch'])->update(['currency' => '']);

    puCreateOrderRefused(PurchasingRefusal::CurrencyRequired, puOrderFields($desk), $desk['buyer']);

    expect(puCreateOrder(puOrderFields($desk, ['currency' => 'USD']), $desk['buyer'])->number)->toBe('PO000001');

    $supplier = PurchasingSeed::supplier('CUR', ['currency' => 'EUR']);

    expect(puCreateOrder(puOrderFields($desk, ['supplier_id' => $supplier]), $desk['buyer'])->order['currency'])->toBe('EUR');
});

it('AC-19 and D-19: the number, the status, the order date, the totals and the snapshots are the server s, so a caller cannot supply them', function (string $field, mixed $value) {
    $desk = PurchasingWorld::desk();

    puCreateOrderRefused(PurchasingRefusal::Validation, puOrderFields($desk, [$field => $value]), $desk['buyer'], $field);
})->with([
    'number' => ['number', 'PO9'],
    'status' => ['status', 'approved'],
    'order_date' => ['order_date', '2020-01-01'],
    'total' => ['total', '1.0000'],
    'supplier_name' => ['supplier_name', 'Mine'],
    'request_id' => ['request_id', '01JABCDEFGHJKMNPQRSTVWXYZ0'],
    'received_value' => ['received_value', '1.0000'],
]);

it('AC-91 and AC-41: refuses a missing or malformed field as validation, and consumes no number', function (array $overrides) {
    $desk = PurchasingWorld::desk();
    $fields = array_merge(puOrderFields($desk), $overrides);

    foreach ($overrides as $key => $value) {
        if ($value === '__unset__') {
            unset($fields[$key]);
        }
    }

    puCreateOrderRefused(PurchasingRefusal::Validation, $fields, $desk['buyer']);
})->with([
    'no id' => [['id' => '__unset__']],
    'a bad id' => [['id' => 'nope']],
    'no supplier' => [['supplier_id' => '__unset__']],
    'a bad supplier' => [['supplier_id' => 'nope']],
    'no branch' => [['branch_id' => '__unset__']],
    'no location' => [['location_id' => '__unset__']],
    'a date with a time' => [['expected_date' => '2026-11-20 10:00:00']],
    'an impossible date' => [['expected_date' => '2026-02-30']],
    'an expected date before the order date' => [['expected_date' => '2026-11-15']],
    'a two letter currency' => [['currency' => 'US']],
    'a numeric currency' => [['currency' => '840']],
    'a currency that is an int' => [['currency' => 840]],
    'a zero rate' => [['exchange_rate' => '0']],
    'a zero rate with decimals' => [['exchange_rate' => '0.00000000']],
    'a negative rate' => [['exchange_rate' => '-1']],
    'nine decimals of rate' => [['exchange_rate' => '1.123456789']],
    'a float rate' => [['exchange_rate' => 1.5]],
    'a rate of eleven integer digits' => [['exchange_rate' => '12345678901']],
    'a negative shipping' => [['shipping' => '-1']],
    'a float shipping' => [['shipping' => 2.5]],
    'a shipping of five decimals' => [['shipping' => '1.12345']],
    'terms of 1001 characters' => [['terms' => str_repeat('t', 1001)]],
    'notes of 2001 characters' => [['notes' => str_repeat('n', 2001)]],
    'notes that are an array' => [['notes' => ['x']]],
]);

it('AC-41: terms of 1000 characters and notes of 2000 are accepted, and a shipping over 14 integer digits is out_of_range', function () {
    $desk = PurchasingWorld::desk();

    $result = puCreateOrder(puOrderFields($desk, ['terms' => str_repeat('t', 1000), 'notes' => str_repeat('n', 2000)]), $desk['buyer']);

    expect(mb_strlen((string) $result->order['terms']))->toBe(1000)
        ->and(mb_strlen((string) $result->order['notes']))->toBe(2000);

    puCreateOrderRefused(PurchasingRefusal::OutOfRange, puOrderFields($desk, ['shipping' => '123456789012345']), $desk['buyer']);
});

it('AC-41: an unknown, inactive or soft-deleted supplier is refused, and consumes no number', function (string $why, PurchasingRefusal $reason) {
    $desk = PurchasingWorld::desk();

    $supplier = match ($why) {
        'unknown' => (string) Str::ulid(),
        'inactive' => PurchasingSeed::supplier('OFF', ['is_active' => false]),
        default => PurchasingSeed::supplier('DEL', ['deleted_at' => PurchasingSeed::NOW]),
    };

    puCreateOrderRefused($reason, puOrderFields($desk, ['supplier_id' => $supplier]), $desk['buyer'], $why);
})->with([
    'unknown' => ['unknown', PurchasingRefusal::UnknownSupplier],
    'inactive' => ['inactive', PurchasingRefusal::SupplierInactive],
    'soft-deleted' => ['soft-deleted', PurchasingRefusal::UnknownSupplier],
]);

it('AC-41: an unknown or inactive branch is unknown_branch, and consumes no number', function () {
    $desk = PurchasingWorld::desk();
    $inactive = PurchasingSeed::branch(false);
    $inactiveLocation = PurchasingSeed::location($inactive);

    puCreateOrderRefused(PurchasingRefusal::UnknownBranch, puOrderFields($desk, ['branch_id' => (string) Str::ulid()]), $desk['buyer'], 'unknown');
    puCreateOrderRefused(PurchasingRefusal::UnknownBranch, puOrderFields($desk, ['branch_id' => $inactive, 'location_id' => $inactiveLocation]), $desk['buyer'], 'inactive');
});

it('AC-41 and D-40: a location that is not an eligible delivery location of the branch is unknown_location, a central warehouse included', function (string $why) {
    $desk = PurchasingWorld::desk();

    $location = match ($why) {
        'unknown' => (string) Str::ulid(),
        'another branch' => PurchasingSeed::location(PurchasingSeed::branch()),
        'a central warehouse' => PurchasingSeed::location(null, 'CENTRAL'),
        'inactive' => PurchasingSeed::location($desk['branch'], 'OFF', ['is_active' => false]),
        'not purchasable to' => PurchasingSeed::location($desk['branch'], 'NOBUY', ['is_purchasable_to' => false]),
        default => PurchasingSeed::location($desk['branch'], 'DEL', ['deleted_at' => PurchasingSeed::NOW]),
    };

    puCreateOrderRefused(PurchasingRefusal::UnknownLocation, puOrderFields($desk, ['location_id' => $location]), $desk['buyer'], $why);
})->with([
    'unknown' => ['unknown'],
    'another branch' => ['another branch'],
    'a central warehouse' => ['a central warehouse'],
    'inactive' => ['inactive'],
    'not purchasable to' => ['not purchasable to'],
    'soft-deleted' => ['soft-deleted'],
]);

it('AC-40: the order date is the branch s own date, which at 20:00 UTC is already tomorrow in Colombo', function () {
    $desk = PurchasingWorld::desk();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-16 20:00:00.000'));

    $result = puCreateOrder(puOrderFields($desk, ['expected_date' => '2026-11-17']), $desk['buyer']);

    expect($result->order['order_date'])->toBe('2026-11-17');

    puCreateOrderRefused(PurchasingRefusal::Validation, puOrderFields($desk, ['expected_date' => '2026-11-16']), $desk['buyer'], 'the UTC date');
});

it('AC-85: a grant scoped to branch A is refused at branch B with the resolver s reason, a company-wide grant is accepted at both, and nothing is consumed', function () {
    $a = PurchasingWorld::desk('a');
    $b = PurchasingWorld::desk('b');
    $scopedToA = AccessSeed::actorHolding('scoped', [PurchasingPermissions::ORDERS_MANAGE], $a['branch']);
    $companyWide = AccessSeed::actorHolding('director', [PurchasingPermissions::ORDERS_MANAGE]);

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puCreateOrder(puOrderFields($b), $scopedToA));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before)
        ->and(puCreateOrder(puOrderFields($a), $scopedToA)->order['created_by'])->toBe($scopedToA)
        ->and(puCreateOrder(puOrderFields($a), $companyWide)->order['branch_id'])->toBe($a['branch'])
        ->and(puCreateOrder(puOrderFields($b), $companyWide)->order['branch_id'])->toBe($b['branch']);
});

it('AC-84: the view key alone, a request key alone and no key are refused, and a missing actor is validation', function (array $keys) {
    $desk = PurchasingWorld::desk();
    $actor = AccessSeed::actorHolding('limited', $keys, $desk['branch']);

    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puCreateOrder(puOrderFields($desk), $actor));

    expect(PurchasingRefusals::state())->toBe($before);

    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puCreateOrder(puOrderFields($desk), null));
})->with([
    'view only' => [[PurchasingPermissions::ORDERS_VIEW]],
    'a request key' => [[PurchasingPermissions::REQUESTS_MANAGE]],
    'no key' => [[]],
]);

it('AC-86: a suspended employee is refused with the resolver s reason', function () {
    $desk = PurchasingWorld::desk();
    AccessSeed::connection()->table('employees')->where('id', $desk['buyer'])->update(['status' => 'suspended']);

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puCreateOrder(puOrderFields($desk), $desk['buyer']));

    expect($refused->cause)->toBe(RefusalReason::EmployeeUnavailable);
});

it('AC-53: an identical replay returns the original with no second number, no second audit row and nothing else written', function () {
    $desk = PurchasingWorld::desk();
    $fields = puOrderFields($desk, ['expected_date' => '2026-11-20', 'shipping' => '1', 'notes' => 'n', 'currency' => 'USD']);

    $first = puCreateOrder($fields, $desk['buyer']);
    $before = PurchasingRefusals::state();

    $again = puCreateOrder(array_merge($fields, ['id' => strtolower($fields['id'])]), $desk['buyer']);

    expect($again->replayed)->toBeTrue()
        ->and($again->number)->toBe($first->number)
        ->and($again->order)->toBe($first->order)
        ->and(PurchasingRefusals::state())->toBe($before)
        ->and(PurchasingSeed::audit())->toHaveCount(1);
});

it('AC-53: a replay that omits the currency is the chain s answer, so it replays only while the chain still gives the stored currency', function () {
    $desk = PurchasingWorld::desk();
    $fields = puOrderFields($desk);

    puCreateOrder($fields, $desk['buyer']);

    expect(puCreateOrder($fields, $desk['buyer'])->replayed)->toBeTrue();

    PurchasingRefusals::expect(PurchasingRefusal::ReplayConflict, fn () => puCreateOrder(array_merge($fields, ['currency' => 'USD']), $desk['buyer']));
});

it('AC-53: the same id with a different payload or another creator is replay_conflict naming the id, and writes nothing', function (array $change) {
    $desk = PurchasingWorld::desk();
    $fields = puOrderFields($desk, ['expected_date' => '2026-11-20', 'shipping' => '1', 'notes' => 'n']);

    puCreateOrder($fields, $desk['buyer']);

    $actor = $desk['buyer'];

    if (isset($change['other_actor'])) {
        $actor = AccessSeed::actorHolding('second', [PurchasingPermissions::ORDERS_MANAGE], $desk['branch']);
        unset($change['other_actor']);
    }

    if (isset($change['other_location'])) {
        $change['location_id'] = PurchasingSeed::location($desk['branch'], 'SECOND');
        unset($change['other_location']);
    }

    if (isset($change['other_supplier'])) {
        $change['supplier_id'] = PurchasingSeed::supplier('SECOND');
        unset($change['other_supplier']);
    }

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::ReplayConflict, fn () => puCreateOrder(array_merge($fields, $change), $actor));

    expect($refused->getMessage())->toContain($fields['id'])
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'other notes' => [['notes' => 'different']],
    'no notes' => [['notes' => null]],
    'other terms' => [['terms' => 'Net 60']],
    'another date' => [['expected_date' => '2026-11-21']],
    'no date' => [['expected_date' => null]],
    'another shipping' => [['shipping' => '2']],
    'another rate' => [['exchange_rate' => '2']],
    'another location' => [['other_location' => true]],
    'another supplier' => [['other_supplier' => true]],
    'another creator' => [['other_actor' => true]],
]);

it('AC-53: a replay is answered even after the expected date has passed', function () {
    $desk = PurchasingWorld::desk();
    $fields = puOrderFields($desk, ['expected_date' => '2026-11-16']);

    puCreateOrder($fields, $desk['buyer']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-30 09:00:00.000'));

    expect(puCreateOrder($fields, $desk['buyer'])->replayed)->toBeTrue();
});

it('AC-52 and AC-90: a numbering refusal is numbering_refused, or number_too_long for that cause, with the engine s reason as cause, and writes nothing', function (DocumentNumbersRefusal $cause, PurchasingRefusal $expected) {
    $desk = PurchasingWorld::desk();

    app()->instance(DocumentNumbers::class, new class($cause) implements DocumentNumbers
    {
        public function __construct(private readonly DocumentNumbersRefusal $cause) {}

        public function next(string $documentType, ?string $branchId): string
        {
            throw match ($this->cause) {
                DocumentNumbersRefusal::NumberTooLong => DocumentNumbersRefused::numberTooLong('too long'),
                default => DocumentNumbersRefused::unsupportedResetPeriod('no such period'),
            };
        }
    });

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect($expected, fn () => puCreateOrder(puOrderFields($desk), $desk['buyer']));

    expect($refused->cause)->toBe($cause)
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'too long' => [DocumentNumbersRefusal::NumberTooLong, PurchasingRefusal::NumberTooLong],
    'any other' => [DocumentNumbersRefusal::UnsupportedResetPeriod, PurchasingRefusal::NumberingRefused],
]);

it('AC-52: a unique violation on the number is numbering_refused, never an unhandled error, and writes nothing', function () {
    $desk = PurchasingWorld::desk();
    $taken = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location']);
    $takenNumber = (string) PurchasingSeed::row('purchase_orders', $taken)['number'];

    app()->instance(DocumentNumbers::class, new class($takenNumber) implements DocumentNumbers
    {
        public function __construct(private readonly string $number) {}

        public function next(string $documentType, ?string $branchId): string
        {
            return $this->number;
        }
    });

    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect(PurchasingRefusal::NumberingRefused, fn () => puCreateOrder(puOrderFields($desk), $desk['buyer']));

    expect(PurchasingRefusals::state())->toBe($before);
});

it('AC-90: a failure after the number is taken rolls the number back with everything else', function () {
    $desk = PurchasingWorld::desk();

    app()->instance(AuditTrail::class, new class implements AuditTrail
    {
        public function record(string $module, ?string $actorId, string $action, string $subjectType, string $subjectId, ?array $before, ?array $after, ?string $branchId = null): void
        {
            throw new RuntimeException('the audit trail is down');
        }
    });

    $before = PurchasingRefusals::state();

    expect(fn () => puCreateOrder(puOrderFields($desk), $desk['buyer']))->toThrow(RuntimeException::class, 'the audit trail is down')
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('Ruling 7: the supplier is the first row locked, shared, and the number sequence is the last one taken', function () {
    $desk = PurchasingWorld::desk();

    $log = PurchasingQueryLog::duringLocked(fn () => puCreateOrder(puOrderFields($desk), $desk['buyer']));
    $inside = PurchasingQueryLog::inTransaction($log);

    expect($inside[0])->toContain('"suppliers"')->and($inside[0])->toContain('/* lock in share mode */');

    $sequence = array_values(array_filter($inside, static fn (string $sql): bool => str_contains($sql, '"number_sequences"')));
    $insert = array_values(array_filter($inside, static fn (string $sql): bool => str_starts_with($sql, 'insert into "purchase_orders"')));

    expect($sequence)->not->toBe([])
        ->and($insert)->toHaveCount(1)
        ->and(array_search($sequence[0], $inside, true))->toBeGreaterThan(0)
        ->and(array_search($sequence[0], $inside, true))->toBeLessThan(array_search($insert[0], $inside, true));
});

it('Ruling 3: one deadlock on the number is retried from the top and the order is created once, with the first number', function () {
    $desk = PurchasingWorld::desk();

    $numbers = DeadlockingNumbers::wrap(function (int $call): void {
        if ($call === 1) {
            throw DeadlockingNumbers::deadlock();
        }
    });

    $result = puCreateOrder(puOrderFields($desk), $desk['buyer']);

    expect($numbers->calls)->toBe(2)
        ->and($result->number)->toBe('PO000001')
        ->and(PurchasingSeed::connection()->table('purchase_orders')->count())->toBe(1)
        ->and(PurchasingSeed::audit())->toHaveCount(1);
});

it('Ruling 3: a second deadlock surfaces, and nothing is written', function () {
    $desk = PurchasingWorld::desk();

    $numbers = DeadlockingNumbers::wrap(function (): void {
        throw DeadlockingNumbers::deadlock();
    });

    $before = PurchasingRefusals::state();

    expect(fn () => puCreateOrder(puOrderFields($desk), $desk['buyer']))->toThrow(QueryException::class)
        ->and($numbers->calls)->toBe(2)
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('Ruling 3: an error that is not a deadlock is not retried', function () {
    $desk = PurchasingWorld::desk();

    $numbers = DeadlockingNumbers::wrap(function (): void {
        throw new RuntimeException('the sequence table is gone');
    });

    expect(fn () => puCreateOrder(puOrderFields($desk), $desk['buyer']))->toThrow(RuntimeException::class, 'the sequence table is gone')
        ->and($numbers->calls)->toBe(1);
});
