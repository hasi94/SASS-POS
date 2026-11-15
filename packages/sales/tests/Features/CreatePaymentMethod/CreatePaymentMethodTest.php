<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\CreatePaymentMethod;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\CreatePaymentMethod\CreatePaymentMethod;
use Flexipos\Sales\Features\CreatePaymentMethod\CreatePaymentMethodHandler;
use Flexipos\Sales\Features\CreatePaymentMethod\CreatePaymentMethodResult;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 11 - CreatePaymentMethod (AC-16; R-38, F-8)
|--------------------------------------------------------------------------
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
 * @param  array<string, mixed>  $fields
 */
function createPaymentMethod(array $fields, ?string $actorId = null): CreatePaymentMethodResult
{
    /** @var CreatePaymentMethodHandler $handler */
    $handler = app(CreatePaymentMethodHandler::class);

    return $handler->handle(new CreatePaymentMethod($fields, $actorId));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function methodInput(array $overrides = []): array
{
    return array_merge(['code' => 'CASH', 'name' => 'Cash', 'type' => 'cash'], $overrides);
}

it('creates a method with its documented defaults, and writes one audit row', function () {
    $result = createPaymentMethod(methodInput());

    $row = SalesSeed::row('payment_methods', $result->paymentMethodId);

    expect($row)->toMatchArray(['code' => 'CASH', 'name' => 'Cash', 'type' => 'cash', 'rounding_rule' => null, 'surcharge_percent' => null, 'deleted_at' => null])
        ->and((bool) $row['is_active'])->toBeTrue()
        ->and((bool) $row['opens_drawer'])->toBeFalse()
        ->and((bool) $row['requires_reference'])->toBeFalse()
        ->and((bool) $row['allows_change'])->toBeFalse()
        ->and((bool) $row['allows_refund'])->toBeTrue()
        ->and((bool) $row['allows_partial'])->toBeTrue()
        ->and((int) $row['sort_order'])->toBe(0)
        ->and((int) $row['version'])->toBe(1);

    expect(SalesSeed::onlyAuditRow())->toMatchArray([
        'module' => 'sales',
        'action' => 'sales_payment_method_created',
        'subject_type' => 'payment_method',
        'subject_id' => $result->paymentMethodId,
        'before' => null,
    ]);
});

it('stores every field it was given', function () {
    $result = createPaymentMethod(methodInput([
        'code' => 'CARD', 'name' => 'Visa', 'type' => 'card', 'is_active' => false, 'opens_drawer' => true,
        'requires_reference' => true, 'allows_change' => true, 'allows_refund' => false, 'allows_partial' => false,
        'rounding_rule' => 'nearest_5', 'surcharge_percent' => '2.5', 'sort_order' => 7,
    ]));

    $row = SalesSeed::row('payment_methods', $result->paymentMethodId);

    expect((bool) $row['is_active'])->toBeFalse()
        ->and((bool) $row['opens_drawer'])->toBeTrue()
        ->and((bool) $row['requires_reference'])->toBeTrue()
        ->and((bool) $row['allows_change'])->toBeTrue()
        ->and((bool) $row['allows_refund'])->toBeFalse()
        ->and((bool) $row['allows_partial'])->toBeFalse()
        ->and($row['rounding_rule'])->toBe('nearest_5')
        ->and((float) $row['surcharge_percent'])->toBe(2.5)
        ->and((int) $row['sort_order'])->toBe(7);
});

it('accepts each of the eight types, and only those, exactly as written', function (string $type) {
    expect(createPaymentMethod(methodInput(['code' => 'M-'.$type, 'type' => $type]))->paymentMethodId)->toBeString();
})->with(['cash', 'card', 'digital', 'credit', 'voucher', 'bank', 'loyalty', 'other']);

it('refuses a type that is not one of the eight, including by case', function (mixed $type) {
    SalesRefusals::expect(SalesRefusal::Validation, fn () => createPaymentMethod(methodInput(['type' => $type])));
})->with(['unknown' => ['crypto'], 'upper case' => ['CASH'], 'capitalised' => ['Cash'], 'blank' => [''], 'an int' => [1], 'null' => [null]]);

it('refuses a blank or missing code and name, and an over-long one', function (array $overrides) {
    SalesRefusals::expect(SalesRefusal::Validation, fn () => createPaymentMethod(methodInput($overrides)));
})->with([
    'blank code' => [['code' => '  ']],
    'no code' => [['code' => null]],
    'a 51 character code' => [['code' => str_repeat('A', 51)]],
    'blank name' => [['name' => '']],
    'a 151 character name' => [['name' => str_repeat('N', 151)]],
]);

it('refuses a duplicate code, including by case, and a soft-deleted holder of it', function () {
    createPaymentMethod(methodInput(['code' => 'CASH']));

    SalesRefusals::expect(SalesRefusal::Duplicate, fn () => createPaymentMethod(methodInput(['code' => 'CASH'])));
    SalesRefusals::expect(SalesRefusal::Duplicate, fn () => createPaymentMethod(methodInput(['code' => 'cash'])));

    SalesSeed::paymentMethod('GONE', ['deleted_at' => SalesSeed::NOW]);

    SalesRefusals::expect(SalesRefusal::Duplicate, fn () => createPaymentMethod(methodInput(['code' => 'gone'])));

    expect(SalesSeed::connection()->table('payment_methods')->count())->toBe(2);
});

it('refuses a surcharge that does not fit DECIMAL(9,4): negative, over 99999.9999, or with a fifth decimal', function (mixed $surcharge) {
    SalesRefusals::expect(SalesRefusal::Validation, fn () => createPaymentMethod(methodInput(['surcharge_percent' => $surcharge])));
})->with(['negative' => ['-1'], 'too large' => ['100000'], 'a fifth decimal' => ['1.00005'], 'a float' => [1.5], 'text' => ['two']]);

it('accepts the extremes of the surcharge range', function (string $surcharge) {
    expect(createPaymentMethod(methodInput(['code' => 'S'.$surcharge, 'surcharge_percent' => $surcharge]))->paymentMethodId)->toBeString();
})->with(['0', '99999.9999', '0.0001']);

it('refuses a boolean given as a string or a number', function (string $flag, mixed $value) {
    SalesRefusals::expect(SalesRefusal::Validation, fn () => createPaymentMethod(methodInput([$flag => $value])));
})->with([
    'a string' => ['is_active', 'true'],
    'a number' => ['allows_change', 1],
]);

it('refuses the deferred columns, the system columns and any unknown field as unknown (F-8)', function (string $field) {
    SalesRefusals::expect(SalesRefusal::Validation, fn () => createPaymentMethod(methodInput([$field => 'x'])));
})->with(['financial_account_id', 'gateway', 'settings', 'id', 'version', 'deleted_at', 'created_at', 'bogus']);

it('writes nothing when it refuses', function () {
    SalesRefusals::capture(fn () => createPaymentMethod(methodInput(['type' => 'bogus'])));

    expect(SalesSeed::connection()->table('payment_methods')->count())->toBe(0)
        ->and(SalesSeed::audit())->toBe([]);
});
