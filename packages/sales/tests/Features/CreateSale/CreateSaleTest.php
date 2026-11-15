<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\CreateSale;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\CreateSale\CreateSale;
use Flexipos\Sales\Features\CreateSale\CreateSaleHandler;
use Flexipos\Sales\Features\CreateSale\CreateSaleResult;
use Flexipos\Sales\Tests\Support\LockMarkingGrammar;
use Flexipos\Sales\Tests\Support\SalesQueryLog;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 13 - CreateSale (AC-1; D-22, D-23; Ruling 7; R-5)
|--------------------------------------------------------------------------
|
| A draft whose identity is the server's: the branch and the location from the
| terminal, the currency and the business date from the branch, the shift from
| the terminal. The shift is locked by id before anything is written.
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
function createSale(array $fields, ?string $actorId): CreateSaleResult
{
    /** @var CreateSaleHandler $handler */
    $handler = app(CreateSaleHandler::class);

    return $handler->handle(new CreateSale($fields, $actorId));
}

it('opens a draft: the branch, the location and the shift from the terminal, the currency from the branch, a business date and a server source', function () {
    $till = SalesWorld::till();

    $result = createSale(['terminal_id' => $till['terminal']], strtolower($till['cashier']));

    expect($result->shiftId)->toBe($till['shift'])
        ->and($result->businessDate)->toBe('2026-11-12')
        ->and(SalesSeed::row('sales', $result->saleId))->toMatchArray([
            'status' => 'draft',
            'type' => 'sale',
            'branch_id' => $till['branch'],
            'location_id' => $till['location'],
            'terminal_id' => $till['terminal'],
            'shift_id' => $till['shift'],
            'employee_id' => $till['cashier'],
            'customer_id' => null,
            'number' => null,
            'business_date' => '2026-11-12',
            'business_date_source' => 'server',
            'currency' => 'LKR',
            'subtotal' => '0.0000',
            'discount_total' => '0.0000',
            'tax_total' => '0.0000',
            'total' => '0.0000',
            'service_charge' => '0.0000',
            'rounding' => '0.0000',
            'paid_total' => '0.0000',
            'change_due' => '0.0000',
            'stock_applied' => 0,
            'cost_total' => null,
            'bill_discount_id' => null,
            'bill_discount_value' => null,
        ]);

    expect(SalesSeed::onlyAuditRow())->toMatchArray([
        'module' => 'sales',
        'action' => 'sales_sale_created',
        'subject_type' => 'sale',
        'subject_id' => $result->saleId,
        'actor_id' => $till['cashier'],
        'before' => null,
        'branch_id' => $till['branch'],
    ]);
});

it('takes the business date from the branch timezone, not from UTC', function () {
    // 22:00 UTC is 03:30 the next day in Asia/Colombo, the fixtures' branch zone.
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-12 22:00:00.000'));
    $till = SalesWorld::till();

    $result = createSale(['terminal_id' => $till['terminal']], $till['cashier']);

    expect($result->businessDate)->toBe('2026-11-13')
        ->and(SalesSeed::row('sales', $result->saleId)['business_date'])->toBe('2026-11-13');
});

it('copies the customer snapshots, and keeps them when the customer is renamed afterwards', function () {
    $till = SalesWorld::till();
    $customer = SalesWorld::customer('Ada Lovelace', ['phone' => '0771112222', 'tax_no' => 'TIN-9']);

    $result = createSale(['terminal_id' => $till['terminal'], 'customer_id' => strtolower($customer)], $till['cashier']);

    SalesSeed::connection()->table('customers')->where('id', $customer)->update(['name' => 'Renamed']);

    expect(SalesSeed::row('sales', $result->saleId))->toMatchArray([
        'customer_id' => $customer,
        'customer_name' => 'Ada Lovelace',
        'customer_phone' => '0771112222',
        'customer_tax_no' => 'TIN-9',
    ]);
});

it('stores a note, trimmed', function () {
    $till = SalesWorld::till();

    $result = createSale(['terminal_id' => $till['terminal'], 'notes' => '  for the party  '], $till['cashier']);

    expect(SalesSeed::row('sales', $result->saleId)['notes'])->toBe('for the party');
});

it('refuses no_open_shift when the terminal has none, and writes nothing', function () {
    $branch = SalesSeed::branch();
    $terminal = SalesSeed::terminal($branch);
    $cashier = SalesSeed::employee('cashier');

    SalesRefusals::expect(SalesRefusal::NoOpenShift, fn () => createSale(['terminal_id' => $terminal['id']], $cashier));

    expect(SalesSeed::connection()->table('sales')->count())->toBe(0)
        ->and(SalesSeed::audit())->toBe([]);
});

it('does not use a closed shift as the open one', function (string $status) {
    $branch = SalesSeed::branch();
    $terminal = SalesSeed::terminal($branch);
    $cashier = SalesSeed::employee('cashier');
    SalesSeed::shift($terminal['id'], $branch, $cashier, 'SH000001', ['status' => $status]);

    SalesRefusals::expect(SalesRefusal::NoOpenShift, fn () => createSale(['terminal_id' => $terminal['id']], $cashier));
})->with(['closed' => ['closed'], 'reconciled' => ['reconciled']]);

it('refuses a customer that is unknown, soft-deleted or merged away as not_found', function (string $case) {
    $till = SalesWorld::till();
    $customer = SalesWorld::customer('Ada');

    if ($case === 'deleted') {
        SalesSeed::connection()->table('customers')->where('id', $customer)->update(['deleted_at' => SalesSeed::NOW]);
    }

    if ($case === 'merged') {
        $survivor = SalesWorld::customer('Survivor');
        SalesSeed::connection()->table('customers')->where('id', $customer)->update(['merged_into_id' => $survivor]);
    }

    $id = $case === 'unknown' ? (string) Str::ulid() : $customer;

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => createSale(['terminal_id' => $till['terminal'], 'customer_id' => $id], $till['cashier']));

    expect(SalesSeed::connection()->table('sales')->count())->toBe(0)
        ->and(SalesSeed::audit())->toBe([]);
})->with(['unknown' => ['unknown'], 'deleted' => ['deleted'], 'merged' => ['merged']]);

it('is not_found for a terminal that is not active, and for an unknown one', function (string $case) {
    $branch = SalesSeed::branch();
    $cashier = SalesSeed::employee('cashier');
    $terminal = $case === 'paused' ? SalesSeed::terminal($branch, 'T1', 'paused')['id'] : (string) Str::ulid();

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => createSale(['terminal_id' => $terminal], $cashier));
})->with(['paused' => ['paused'], 'unknown' => ['unknown']]);

it('needs a live employee as the actor', function () {
    $till = SalesWorld::till();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => createSale(['terminal_id' => $till['terminal']], null));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => createSale(['terminal_id' => $till['terminal']], (string) Str::ulid()));

    expect(SalesSeed::connection()->table('sales')->count())->toBe(0);
});

it('refuses what the server decides, and every sync field, as an unknown field', function (string $field) {
    $till = SalesWorld::till();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => createSale(['terminal_id' => $till['terminal'], $field => 'x'], $till['cashier']));

    expect(SalesSeed::connection()->table('sales')->count())->toBe(0);
})->with([
    'business_date', 'business_date_source', 'bill_discount_id', 'bill_discount_value', 'branch_id', 'location_id', 'shift_id',
    'employee_id', 'currency', 'status', 'type', 'number', 'total', 'origin', 'device_id', 'client_seq', 'client_created_at', 'sync_status', 'synced_at',
]);

it('refuses a malformed terminal or customer id as validation, never a raw error', function (array $fields) {
    $till = SalesWorld::till();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => createSale(array_merge(['terminal_id' => $till['terminal']], $fields), $till['cashier']));
})->with([
    'a customer id that is not a ulid' => [['customer_id' => 'abc']],
    'a customer id that is an int' => [['customer_id' => 5]],
    'a note that is not a string' => [['notes' => ['x']]],
]);

it('takes the shift FOR UPDATE-shared by id as the first statement, and the customer FOR UPDATE after it (Ruling 7, R-5)', function () {
    $till = SalesWorld::till();
    $customer = SalesWorld::customer('Ada');

    $log = SalesQueryLog::inTransaction(SalesQueryLog::during(fn () => createSale(['terminal_id' => $till['terminal'], 'customer_id' => $customer], $till['cashier'])));

    expect($log[0])->toContain('"cash_shifts"')
        ->and($log[0])->toContain(LockMarkingGrammar::SHARED)
        ->and($log[0])->toContain('"id" = ?')
        ->and($log[1])->toContain('"customers"')
        ->and($log[1])->toContain(LockMarkingGrammar::FOR_UPDATE);
});

it('leaves no draft on a shift that closed between the pre-read and the lock (race row)', function () {
    $till = SalesWorld::till();

    // The close commits right after the handler has read the terminal's open shift id and before it locks the shift.
    $closed = false;
    SalesSeed::connection()->listen(static function ($query) use (&$closed, $till): void {
        if (! $closed && str_contains($query->sql, '"cash_shifts" where "terminal_id" = ?')) {
            $closed = true;
            SalesSeed::connection()->table('cash_shifts')->where('id', $till['shift'])->update(['status' => 'closed']);
        }
    });

    SalesRefusals::expect(SalesRefusal::NoOpenShift, fn () => createSale(['terminal_id' => $till['terminal']], $till['cashier']));

    expect($closed)->toBeTrue()
        ->and(SalesSeed::connection()->table('sales')->count())->toBe(0)
        ->and(SalesSeed::audit())->toBe([]);
});
