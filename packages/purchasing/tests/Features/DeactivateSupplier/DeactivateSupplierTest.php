<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\DeactivateSupplier;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\DeactivateSupplier\DeactivateSupplier;
use Flexipos\Purchasing\Features\DeactivateSupplier\DeactivateSupplierHandler;
use Flexipos\Purchasing\Features\DeactivateSupplier\DeactivateSupplierResult;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 10 - DeactivateSupplier (AC-13, AC-17, AC-18)
|--------------------------------------------------------------------------
|
| Idempotent by outcome: a repeat succeeds and writes nothing. The effects of an
| inactive supplier on new lines, orders and submits are proved with the slices
| that refuse them.
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

function puDeactivateSupplier(string $supplierId, ?string $actorId): DeactivateSupplierResult
{
    /** @var DeactivateSupplierHandler $handler */
    $handler = app(DeactivateSupplierHandler::class);

    return $handler->handle(new DeactivateSupplier($supplierId, $actorId));
}

it('AC-13: deactivates an active supplier, moves updated_by and updated_at, and writes one audit row', function () {
    $actor = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);
    $supplier = PurchasingSeed::supplier('A', ['bank_details' => '{"account":"42"}']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 08:30:00.000'));

    $result = puDeactivateSupplier(strtolower($supplier), $actor);

    expect($result->changed)->toBeTrue()
        ->and(PurchasingSeed::row('suppliers', $supplier))->toMatchArray([
            'is_active' => 0,
            'updated_by' => $actor,
            'updated_at' => '2026-11-17 08:30:00.000',
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $actor,
        'branch_id' => null,
        'module' => PurchasingAuditActions::MODULE,
        'action' => PurchasingAuditActions::SUPPLIER_DEACTIVATED,
        'subject_type' => 'supplier',
        'subject_id' => $supplier,
    ])
        ->and($audit['before']['is_active'])->toBeTrue()
        ->and($audit['after']['is_active'])->toBeFalse()
        ->and($audit['before']['bank_details'])->toBe('[redacted]')
        ->and($audit['after']['bank_details'])->toBe('[redacted]')
        ->and($audit['changed_fields'])->toBe(['is_active', 'updated_at', 'updated_by'])
        ->and(str_contains((string) json_encode($audit), '"42"'))->toBeFalse();
});

it('AC-13: deactivating an inactive supplier succeeds and writes nothing', function () {
    $actor = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);
    $supplier = PurchasingSeed::supplier('A', ['is_active' => false]);
    $before = PurchasingRefusals::state();

    $result = puDeactivateSupplier($supplier, $actor);

    expect($result->changed)->toBeFalse()
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-13: an unknown, malformed or soft-deleted supplier is not_found or validation, and writes nothing', function () {
    $actor = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);
    $deleted = PurchasingSeed::supplier('D', ['deleted_at' => PurchasingSeed::NOW]);

    foreach ([
        [PurchasingRefusal::NotFound, (string) Str::ulid()],
        [PurchasingRefusal::NotFound, $deleted],
        [PurchasingRefusal::Validation, 'nope'],
    ] as [$reason, $id]) {
        $before = PurchasingRefusals::state();

        PurchasingRefusals::expect($reason, fn () => puDeactivateSupplier($id, $actor), $id);

        expect(PurchasingRefusals::state())->toBe($before);
    }
});

it('AC-18: refuses a caller without the manage key with the resolver s reason, and writes nothing', function (string $key) {
    $supplier = PurchasingSeed::supplier('A');
    $actor = puActor('limited', [$key]);
    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puDeactivateSupplier($supplier, $actor));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);

    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puDeactivateSupplier($supplier, null));
})->with([
    'view only' => [PurchasingPermissions::SUPPLIERS_VIEW],
    'an order key' => [PurchasingPermissions::ORDERS_MANAGE],
]);
