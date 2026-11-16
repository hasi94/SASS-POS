<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\GetSupplier;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\GetSupplier\GetSupplier;
use Flexipos\Purchasing\Features\GetSupplier\GetSupplierHandler;
use Flexipos\Purchasing\Features\GetSupplier\GetSupplierResult;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 11 - GetSupplier (AC-15, AC-18, D-36)
|--------------------------------------------------------------------------
|
| `bank_details` only to `manage`: absent, not null, for a reader holding `view`
| alone. Either key reads; neither is refused; a deleted supplier is not found.
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

function puGetSupplier(string $supplierId, ?string $actorId): GetSupplierResult
{
    /** @var GetSupplierHandler $handler */
    $handler = app(GetSupplierHandler::class);

    return $handler->handle(new GetSupplier($supplierId, $actorId));
}

/** A supplier with every column filled, bank details included. */
function puFullSupplier(): string
{
    return PurchasingSeed::supplier('FULL', [
        'code' => 'FULL-1',
        'legal_name' => 'Full Legal',
        'contact_name' => 'Kamal',
        'phone' => '011',
        'email' => 'full@example.com',
        'address' => '{"city":"Colombo"}',
        'tax_no' => 'VAT-1',
        'currency' => 'LKR',
        'payment_terms_days' => 30,
        'credit_limit' => '2500.0000',
        'bank_details' => '{"account":"42"}',
        'notes' => 'n',
    ]);
}

it('AC-15: a reader holding manage gets every column, bank details included, in canonical shapes', function () {
    $supplier = puFullSupplier();
    $actor = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);

    $result = puGetSupplier(strtolower($supplier), $actor);

    expect($result->supplier)->toBe([
        'id' => $supplier,
        'created_at' => PurchasingSeed::NOW,
        'updated_at' => PurchasingSeed::NOW,
        'deleted_at' => null,
        'created_by' => null,
        'updated_by' => null,
        'code' => 'FULL-1',
        'name' => 'Supplier FULL',
        'legal_name' => 'Full Legal',
        'contact_name' => 'Kamal',
        'phone' => '011',
        'email' => 'full@example.com',
        'address' => ['city' => 'Colombo'],
        'tax_no' => 'VAT-1',
        'currency' => 'LKR',
        'payment_terms_days' => 30,
        'credit_limit' => '2500.0000',
        'bank_details' => ['account' => '42'],
        'is_active' => true,
        'notes' => 'n',
    ]);
});

it('AC-15: a reader holding only view gets every column except bank_details, which is absent, not null', function () {
    $supplier = puFullSupplier();
    $actor = puActor('viewer', [PurchasingPermissions::SUPPLIERS_VIEW]);

    $row = puGetSupplier($supplier, $actor)->supplier;

    expect(array_key_exists('bank_details', $row))->toBeFalse()
        ->and($row['code'])->toBe('FULL-1')
        ->and($row['credit_limit'])->toBe('2500.0000')
        ->and(str_contains((string) json_encode($row), '42'))->toBeFalse();
});

it('AC-15: a reader holding both keys is a manager and sees the bank details', function () {
    $supplier = puFullSupplier();
    $actor = puActor('both', [PurchasingPermissions::SUPPLIERS_VIEW, PurchasingPermissions::SUPPLIERS_MANAGE]);

    expect(puGetSupplier($supplier, $actor)->supplier['bank_details'])->toBe(['account' => '42']);
});

it('AC-15: a supplier with no bank details has a null there for a manager', function () {
    $supplier = PurchasingSeed::supplier('PLAIN');
    $actor = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);

    $row = puGetSupplier($supplier, $actor)->supplier;

    expect(array_key_exists('bank_details', $row))->toBeTrue()
        ->and($row['bank_details'])->toBeNull();
});

it('AC-15 and AC-18: a caller with neither supplier key is refused not_authorised with the resolver s reason', function (array $keys) {
    $supplier = puFullSupplier();
    $actor = puActor('limited', $keys);
    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puGetSupplier($supplier, $actor));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'no role at all' => [[]],
    'an order key' => [[PurchasingPermissions::ORDERS_VIEW]],
    'a request key' => [[PurchasingPermissions::REQUESTS_MANAGE]],
]);

it('AC-18: a missing actor is validation, and the supplier key is asked with no branch', function () {
    $supplier = puFullSupplier();

    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puGetSupplier($supplier, null));

    expect(puGetSupplier($supplier, puActor('viewer', [PurchasingPermissions::SUPPLIERS_VIEW]))->supplier['code'])->toBe('FULL-1');
});

it('AC-14 and AC-15: a soft-deleted or unknown supplier is not_found, a malformed id is validation, whatever the reader holds', function () {
    $actor = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);
    $deleted = PurchasingSeed::supplier('GONE', ['deleted_at' => PurchasingSeed::NOW]);

    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => puGetSupplier($deleted, $actor));
    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => puGetSupplier((string) Str::ulid(), $actor));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puGetSupplier('nope', $actor));
});
