<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\UpdatePaymentMethod;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\UpdatePaymentMethod\UpdatePaymentMethod;
use Flexipos\Sales\Features\UpdatePaymentMethod\UpdatePaymentMethodHandler;
use Flexipos\Sales\Tests\Support\LockMarkingGrammar;
use Flexipos\Sales\Tests\Support\SalesQueryLog;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 11 - UpdatePaymentMethod (AC-16; Ruling 7 row 4; R-38)
|--------------------------------------------------------------------------
|
| The method row is locked first. Deactivating it is refused `in_use` while a
| draft, held or pending_payment sale holds a tender on it, and accepted once
| that sale has completed.
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
function updatePaymentMethod(string $id, array $fields, ?string $actorId = null): void
{
    /** @var UpdatePaymentMethodHandler $handler */
    $handler = app(UpdatePaymentMethodHandler::class);

    $handler->handle(new UpdatePaymentMethod($id, $fields, $actorId));
}

/**
 * A method with one tender on a sale in the given status.
 *
 * @return array{method: string, sale: string, tender: string}
 */
function methodWithTender(string $saleStatus, string $code = 'CASH'): array
{
    $branch = SalesSeed::branch();
    $method = SalesSeed::paymentMethod($code);
    $sale = SalesSeed::sale($branch, SalesSeed::location($branch), SalesSeed::employee('cashier'), ['status' => $saleStatus]);

    return ['method' => $method, 'sale' => $sale, 'tender' => SalesSeed::tender($sale, $method, 'cash', '10.0000')];
}

it('updates the fields it was given, bumps the version, and writes one audit row with before and after', function () {
    $id = SalesSeed::paymentMethod('CASH');

    updatePaymentMethod($id, ['name' => 'Cash drawer', 'surcharge_percent' => '1.5', 'allows_change' => true]);

    $row = SalesSeed::row('payment_methods', $id);

    expect($row['name'])->toBe('Cash drawer')
        ->and((float) $row['surcharge_percent'])->toBe(1.5)
        ->and((bool) $row['allows_change'])->toBeTrue()
        ->and($row['code'])->toBe('CASH')
        ->and((int) $row['version'])->toBe(2)
        ->and($row['updated_at'])->toBe('2026-11-12 10:00:00.000');

    $audit = SalesSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['action' => 'sales_payment_method_updated', 'subject_type' => 'payment_method', 'subject_id' => $id])
        ->and($audit['before']['name'])->toBe('Method CASH')
        ->and($audit['after']['name'])->toBe('Cash drawer');
});

it('refuses an empty update, a null for a required field, and the fields it does not take', function (array $fields) {
    $id = SalesSeed::paymentMethod('CASH');

    SalesRefusals::expect(SalesRefusal::Validation, fn () => updatePaymentMethod($id, $fields));
})->with([
    'nothing' => [[]],
    'a null name' => [['name' => null]],
    'a null type' => [['type' => null]],
    'a bad type' => [['type' => 'CASH']],
    'a null flag' => [['is_active' => null]],
    'a gateway' => [['gateway' => 'stripe']],
    'financial_account_id' => [['financial_account_id' => 'x']],
    'a version' => [['version' => 9]],
]);

it('refuses a code that is another method\'s, in any case, and lets a method keep its own', function () {
    $cash = SalesSeed::paymentMethod('CASH');
    SalesSeed::paymentMethod('CARD');

    SalesRefusals::expect(SalesRefusal::Duplicate, fn () => updatePaymentMethod($cash, ['code' => 'card']));

    updatePaymentMethod($cash, ['code' => 'cash']);

    expect(SalesSeed::row('payment_methods', $cash)['code'])->toBe('cash');
});

it('is not_found for an unknown, malformed or soft-deleted method', function () {
    $gone = SalesSeed::paymentMethod('GONE', ['deleted_at' => SalesSeed::NOW]);

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => updatePaymentMethod($gone, ['name' => 'x']));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => updatePaymentMethod('01HZZZZZZZZZZZZZZZZZZZZZZZ', ['name' => 'x']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => updatePaymentMethod('nope', ['name' => 'x']));
});

it('refuses to deactivate a method a draft, held or pending sale holds a tender on, naming the count (AC-16)', function (string $status) {
    $w = methodWithTender($status);
    SalesSeed::tender($w['sale'], $w['method'], 'cash', '5.0000');

    $refused = SalesRefusals::expect(SalesRefusal::InUse, fn () => updatePaymentMethod($w['method'], ['is_active' => false]));

    expect($refused->getMessage())->toContain('2')
        ->and((bool) SalesSeed::row('payment_methods', $w['method'])['is_active'])->toBeTrue()
        ->and(SalesSeed::audit())->toBe([]);
})->with(['draft' => ['draft'], 'held' => ['held'], 'pending_payment' => ['pending_payment']]);

it('accepts the deactivation once that sale has completed, voided or been refunded', function (string $status) {
    $w = methodWithTender($status);

    updatePaymentMethod($w['method'], ['is_active' => false]);

    expect((bool) SalesSeed::row('payment_methods', $w['method'])['is_active'])->toBeFalse();
})->with(['completed' => ['completed'], 'voided' => ['voided'], 'refunded' => ['refunded'], 'partially_refunded' => ['partially_refunded']]);

it('does not apply the guard to a change that is not a deactivation', function () {
    $w = methodWithTender('draft');

    updatePaymentMethod($w['method'], ['name' => 'Renamed', 'is_active' => true]);

    expect(SalesSeed::row('payment_methods', $w['method'])['name'])->toBe('Renamed');
});

it('does not count another method\'s tenders', function () {
    $w = methodWithTender('draft', 'CASH');
    $other = SalesSeed::paymentMethod('CARD');

    updatePaymentMethod($other, ['is_active' => false]);

    expect((bool) SalesSeed::row('payment_methods', $other)['is_active'])->toBeFalse()
        ->and((bool) SalesSeed::row('payment_methods', $w['method'])['is_active'])->toBeTrue();
});

it('takes the method row FOR UPDATE as its first statement, then counts tenders with a plain read (Ruling 7, row 4)', function () {
    $w = methodWithTender('completed');

    $log = SalesQueryLog::inTransaction(SalesQueryLog::during(fn () => updatePaymentMethod($w['method'], ['is_active' => false])));

    expect($log[0])->toStartWith('select')
        ->and($log[0])->toContain('"payment_methods"')
        ->and($log[0])->toContain(LockMarkingGrammar::FOR_UPDATE)
        ->and($log[1])->toContain('"sale_tenders"')
        ->and($log[1])->not->toContain('/*');
});
