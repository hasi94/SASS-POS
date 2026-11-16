<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\DeleteSupplier;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Documents\RequestStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\CreateSupplier\CreateSupplier;
use Flexipos\Purchasing\Features\CreateSupplier\CreateSupplierHandler;
use Flexipos\Purchasing\Features\DeleteSupplier\DeleteSupplier;
use Flexipos\Purchasing\Features\DeleteSupplier\DeleteSupplierHandler;
use Flexipos\Purchasing\Features\DeleteSupplier\DeleteSupplierResult;
use Flexipos\Purchasing\Features\GetSupplier\GetSupplier;
use Flexipos\Purchasing\Features\GetSupplier\GetSupplierHandler;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 10 - DeleteSupplier (AC-14, AC-18)
|--------------------------------------------------------------------------
|
| A soft delete refused while a live request line or a live order needs the
| supplier, row-by-row over every status; the row and its code are retained; a
| repeat is a no-op. The statuses come from the status enums, so a status added
| there fails the exhaustiveness case until it is classified here.
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

function puDeleteSupplier(string $supplierId, ?string $actorId): DeleteSupplierResult
{
    /** @var DeleteSupplierHandler $handler */
    $handler = app(DeleteSupplierHandler::class);

    return $handler->handle(new DeleteSupplier($supplierId, $actorId));
}

/**
 * A branch with a location, a product and a unit: what a document row needs.
 *
 * @return array{branch: string, location: string, product: string, unit: string, employee: string}
 */
function puDeleteWorld(): array
{
    $branch = PurchasingSeed::branch();
    $unit = PricingSeed::unit('EA');

    return [
        'branch' => $branch,
        'location' => PurchasingSeed::location($branch),
        'product' => PurchasingSeed::product('DEL-1', $unit),
        'unit' => $unit,
        'employee' => PurchasingSeed::employee('buyer'),
    ];
}

/**
 * A request in this status with one line naming the supplier.
 *
 * @param  array{branch: string, location: string, product: string, unit: string, employee: string}  $w
 */
function puRequestNaming(array $w, string $supplierId, string $status): string
{
    $request = PurchasingSeed::request($w['branch'], $w['location'], $w['employee'], ['status' => $status]);
    PurchasingSeed::requestItem($request, $w['product'], $w['unit'], 1, ['supplier_id' => $supplierId]);

    return $request;
}

it('AC-14: deletes a supplier nothing needs: deleted_at is set, the row stays, one audit row is written', function () {
    $actor = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);
    $supplier = PurchasingSeed::supplier('A', ['bank_details' => '{"account":"42"}']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 08:30:00.000'));

    $result = puDeleteSupplier(strtolower($supplier), $actor);

    expect($result->changed)->toBeTrue()
        ->and(PurchasingSeed::row('suppliers', $supplier))->toMatchArray([
            'deleted_at' => '2026-11-17 08:30:00.000',
            'updated_by' => $actor,
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'action' => PurchasingAuditActions::SUPPLIER_DELETED,
        'subject_type' => 'supplier',
        'subject_id' => $supplier,
        'actor_id' => $actor,
    ])
        ->and($audit['before']['deleted_at'])->toBeNull()
        ->and($audit['after']['deleted_at'])->toBe('2026-11-17 08:30:00.000')
        ->and($audit['before']['bank_details'])->toBe('[redacted]')
        ->and(str_contains((string) json_encode($audit), '"42"'))->toBeFalse();
});

it('AC-14: a request line naming the supplier blocks the delete while the request is live, and not once it is finished', function (string $status, bool $blocks) {
    $actor = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);
    $w = puDeleteWorld();
    $supplier = PurchasingSeed::supplier('A');
    puRequestNaming($w, $supplier, $status);

    if ($blocks) {
        $before = PurchasingRefusals::state();

        PurchasingRefusals::expect(PurchasingRefusal::SupplierInUse, fn () => puDeleteSupplier($supplier, $actor), $status);

        expect(PurchasingRefusals::state())->toBe($before)
            ->and(PurchasingSeed::row('suppliers', $supplier)['deleted_at'])->toBeNull();

        return;
    }

    expect(puDeleteSupplier($supplier, $actor)->changed)->toBeTrue();
})->with(array_combine(
    array_map(static fn (RequestStatus $status): string => 'request '.$status->value, RequestStatus::cases()),
    array_map(static fn (RequestStatus $status): array => [$status->value, $status->isLive()], RequestStatus::cases()),
));

it('AC-14: an order for the supplier blocks the delete while it is open, including sent and partially_received, and not once it is finished', function (string $status, bool $blocks) {
    $actor = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);
    $w = puDeleteWorld();
    $supplier = PurchasingSeed::supplier('A');
    PurchasingSeed::order($supplier, $w['branch'], $w['location'], ['status' => $status]);

    if ($blocks) {
        $before = PurchasingRefusals::state();

        PurchasingRefusals::expect(PurchasingRefusal::SupplierInUse, fn () => puDeleteSupplier($supplier, $actor), $status);

        expect(PurchasingRefusals::state())->toBe($before);

        return;
    }

    expect(puDeleteSupplier($supplier, $actor)->changed)->toBeTrue();
})->with(array_combine(
    array_map(static fn (OrderStatus $status): string => 'order '.$status->value, OrderStatus::cases()),
    array_map(static fn (OrderStatus $status): array => [$status->value, $status->isLive()], OrderStatus::cases()),
));

it('AC-14: pins the blocking statuses exactly as the spec lists them', function () {
    $requests = array_map(static fn (RequestStatus $s): string => $s->value, array_values(array_filter(RequestStatus::cases(), static fn (RequestStatus $s): bool => $s->isLive())));
    $orders = array_map(static fn (OrderStatus $s): string => $s->value, array_values(array_filter(OrderStatus::cases(), static fn (OrderStatus $s): bool => $s->isLive())));

    expect($requests)->toBe(['draft', 'pending_approval', 'approved'])
        ->and($orders)->toBe(['draft', 'pending_approval', 'approved', 'sent', 'partially_received']);
});

it('AC-14: a live request that names another supplier, or no supplier, does not block', function () {
    $actor = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);
    $w = puDeleteWorld();
    $supplier = PurchasingSeed::supplier('A');
    $other = PurchasingSeed::supplier('B');

    puRequestNaming($w, $other, 'draft');

    $unnamed = PurchasingSeed::request($w['branch'], $w['location'], $w['employee'], ['status' => 'approved']);
    PurchasingSeed::requestItem($unnamed, $w['product'], $w['unit']);

    PurchasingSeed::order($other, $w['branch'], $w['location'], ['status' => 'approved']);

    expect(puDeleteSupplier($supplier, $actor)->changed)->toBeTrue();
});

it('AC-14: the code stays reserved, get is not_found and the delete is idempotent', function () {
    $actor = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);

    /** @var CreateSupplierHandler $create */
    $create = app(CreateSupplierHandler::class);
    $made = $create->handle(new CreateSupplier(['code' => 'KEEP-1', 'name' => 'Keep'], $actor));
    PurchasingSeed::connection()->table('audit_logs')->delete();

    puDeleteSupplier($made->supplierId, $actor);

    PurchasingRefusals::expect(PurchasingRefusal::DuplicateCode, fn () => $create->handle(new CreateSupplier(['code' => 'keep-1', 'name' => 'Again'], $actor)));
    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => app(GetSupplierHandler::class)->handle(new GetSupplier($made->supplierId, $actor)));

    expect(PurchasingSeed::connection()->table('suppliers')->where('id', $made->supplierId)->count())->toBe(1);

    $before = PurchasingRefusals::state();

    $again = puDeleteSupplier($made->supplierId, $actor);

    expect($again->changed)->toBeFalse()
        ->and(PurchasingRefusals::state())->toBe($before)
        ->and(PurchasingSeed::audit())->toHaveCount(1);
});

it('AC-14: an unknown or malformed id is not_found or validation, and writes nothing', function () {
    $actor = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);

    foreach ([[PurchasingRefusal::NotFound, (string) Str::ulid()], [PurchasingRefusal::Validation, 'nope']] as [$reason, $id]) {
        $before = PurchasingRefusals::state();

        PurchasingRefusals::expect($reason, fn () => puDeleteSupplier($id, $actor), $id);

        expect(PurchasingRefusals::state())->toBe($before);
    }
});

it('AC-18: refuses a caller without the manage key with the resolver s reason, and writes nothing', function (string $key) {
    $supplier = PurchasingSeed::supplier('A');
    $actor = puActor('limited', [$key]);
    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puDeleteSupplier($supplier, $actor));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);

    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puDeleteSupplier($supplier, null));
})->with([
    'view only' => [PurchasingPermissions::SUPPLIERS_VIEW],
    'an order key' => [PurchasingPermissions::ORDERS_MANAGE],
]);
