<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\ActivateSupplier;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\ActivateSupplier\ActivateSupplier;
use Flexipos\Purchasing\Features\ActivateSupplier\ActivateSupplierHandler;
use Flexipos\Purchasing\Features\ActivateSupplier\ActivateSupplierResult;
use Flexipos\Purchasing\Features\DeactivateSupplier\DeactivateSupplier;
use Flexipos\Purchasing\Features\DeactivateSupplier\DeactivateSupplierHandler;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 10 - ActivateSupplier (AC-13, AC-18)
|--------------------------------------------------------------------------
|
| Reactivating restores a deactivated supplier; activating an active one is a
| no-op with no audit row.
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

function puActivateSupplier(string $supplierId, ?string $actorId): ActivateSupplierResult
{
    /** @var ActivateSupplierHandler $handler */
    $handler = app(ActivateSupplierHandler::class);

    return $handler->handle(new ActivateSupplier($supplierId, $actorId));
}

it('AC-13: activates an inactive supplier and writes one audit row', function () {
    $actor = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);
    $supplier = PurchasingSeed::supplier('A', ['is_active' => false]);

    $result = puActivateSupplier($supplier, $actor);

    expect($result->changed)->toBeTrue()
        ->and(PurchasingSeed::row('suppliers', $supplier))->toMatchArray(['is_active' => 1, 'updated_by' => $actor]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'action' => PurchasingAuditActions::SUPPLIER_ACTIVATED,
        'subject_type' => 'supplier',
        'subject_id' => $supplier,
        'actor_id' => $actor,
        'branch_id' => null,
    ])
        ->and($audit['before']['is_active'])->toBeFalse()
        ->and($audit['after']['is_active'])->toBeTrue();
});

it('AC-13: deactivate then activate restores the supplier, with one audit row each', function () {
    $actor = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);
    $supplier = PurchasingSeed::supplier('A');

    app(DeactivateSupplierHandler::class)->handle(new DeactivateSupplier($supplier, $actor));
    puActivateSupplier($supplier, $actor);

    expect(PurchasingSeed::row('suppliers', $supplier)['is_active'])->toBe(1)
        ->and(array_column(PurchasingSeed::audit(), 'action'))->toBe([
            PurchasingAuditActions::SUPPLIER_DEACTIVATED,
            PurchasingAuditActions::SUPPLIER_ACTIVATED,
        ]);
});

it('AC-13: activating an active supplier succeeds and writes nothing', function () {
    $actor = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);
    $supplier = PurchasingSeed::supplier('A');
    $before = PurchasingRefusals::state();

    $result = puActivateSupplier($supplier, $actor);

    expect($result->changed)->toBeFalse()
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-13: an unknown, malformed or soft-deleted supplier is not_found or validation, and writes nothing', function () {
    $actor = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);
    $deleted = PurchasingSeed::supplier('D', ['deleted_at' => PurchasingSeed::NOW, 'is_active' => false]);

    foreach ([
        [PurchasingRefusal::NotFound, (string) Str::ulid()],
        [PurchasingRefusal::NotFound, $deleted],
        [PurchasingRefusal::Validation, 'nope'],
    ] as [$reason, $id]) {
        $before = PurchasingRefusals::state();

        PurchasingRefusals::expect($reason, fn () => puActivateSupplier($id, $actor), $id);

        expect(PurchasingRefusals::state())->toBe($before);
    }
});

it('AC-18: refuses a caller without the manage key with the resolver s reason, and writes nothing', function (string $key) {
    $supplier = PurchasingSeed::supplier('A', ['is_active' => false]);
    $actor = puActor('limited', [$key]);
    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puActivateSupplier($supplier, $actor));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);

    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puActivateSupplier($supplier, null));
})->with([
    'view only' => [PurchasingPermissions::SUPPLIERS_VIEW],
    'an order key' => [PurchasingPermissions::ORDERS_MANAGE],
]);
