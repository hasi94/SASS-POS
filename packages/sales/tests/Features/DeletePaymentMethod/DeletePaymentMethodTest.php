<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\DeletePaymentMethod;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\DeletePaymentMethod\DeletePaymentMethod;
use Flexipos\Sales\Features\DeletePaymentMethod\DeletePaymentMethodHandler;
use Flexipos\Sales\Tests\Support\LockMarkingGrammar;
use Flexipos\Sales\Tests\Support\SalesQueryLog;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 11 - DeletePaymentMethod (AC-16; Ruling 7 row 4)
|--------------------------------------------------------------------------
|
| A soft delete that leaves the row in place, refused `in_use` while an open
| sale holds a tender on it, accepted once that sale has completed.
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

function deletePaymentMethod(string $id, ?string $actorId = null): void
{
    /** @var DeletePaymentMethodHandler $handler */
    $handler = app(DeletePaymentMethodHandler::class);

    $handler->handle(new DeletePaymentMethod($id, $actorId));
}

/**
 * @return array{method: string, sale: string}
 */
function deletableMethod(string $saleStatus): array
{
    $branch = SalesSeed::branch();
    $method = SalesSeed::paymentMethod('CASH');
    $sale = SalesSeed::sale($branch, SalesSeed::location($branch), SalesSeed::employee('cashier'), ['status' => $saleStatus]);
    SalesSeed::tender($sale, $method, 'cash', '10.0000');

    return ['method' => $method, 'sale' => $sale];
}

it('soft-deletes the method, leaves the row in place, and writes one audit row', function () {
    $id = SalesSeed::paymentMethod('CASH');

    deletePaymentMethod(strtolower($id));

    $row = SalesSeed::row('payment_methods', $id);

    expect($row['deleted_at'])->toBe('2026-11-12 10:00:00.000')
        ->and($row['code'])->toBe('CASH')
        ->and(SalesSeed::connection()->table('payment_methods')->count())->toBe(1);

    $audit = SalesSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['action' => 'sales_payment_method_deleted', 'subject_type' => 'payment_method', 'subject_id' => $id])
        ->and($audit['before']['deleted_at'])->toBeNull()
        ->and($audit['after']['deleted_at'])->toBe('2026-11-12 10:00:00.000');
});

it('refuses to delete a method an open sale holds a tender on, naming the count, and changes nothing', function (string $status) {
    $w = deletableMethod($status);

    $refused = SalesRefusals::expect(SalesRefusal::InUse, fn () => deletePaymentMethod($w['method']));

    expect($refused->getMessage())->toContain('1')
        ->and(SalesSeed::row('payment_methods', $w['method'])['deleted_at'])->toBeNull()
        ->and(SalesSeed::audit())->toBe([]);
})->with(['draft' => ['draft'], 'held' => ['held'], 'pending_payment' => ['pending_payment']]);

it('accepts the delete once the sale has completed', function () {
    $w = deletableMethod('pending_payment');

    SalesRefusals::capture(fn () => deletePaymentMethod($w['method']));

    SalesSeed::connection()->table('sales')->where('id', $w['sale'])->update(['status' => 'completed']);

    deletePaymentMethod($w['method']);

    expect(SalesSeed::row('payment_methods', $w['method'])['deleted_at'])->not->toBeNull();
});

it('is not_found for a method that is unknown or already deleted', function () {
    $id = SalesSeed::paymentMethod('CASH');
    deletePaymentMethod($id);

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => deletePaymentMethod($id));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => deletePaymentMethod('01HZZZZZZZZZZZZZZZZZZZZZZZ'));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => deletePaymentMethod('nope'));
});

it('takes the method row FOR UPDATE as its first statement (Ruling 7, row 4)', function () {
    $id = SalesSeed::paymentMethod('CASH');

    $log = SalesQueryLog::inTransaction(SalesQueryLog::during(fn () => deletePaymentMethod($id)));

    expect($log[0])->toStartWith('select')
        ->and($log[0])->toContain('"payment_methods"')
        ->and($log[0])->toContain(LockMarkingGrammar::FOR_UPDATE);
});
