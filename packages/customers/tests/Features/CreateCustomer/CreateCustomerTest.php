<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\CreateCustomer;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Customers\CreditLedgerRefused;
use Flexipos\Contracts\Customers\CreditRefusal;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\CreateCustomer\CreateCustomer;
use Flexipos\Customers\Features\CreateCustomer\CreateCustomerHandler;
use Flexipos\Customers\Features\CreateCustomer\CreateCustomerResult;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan steps 9 and 11 - creating a customer (AC-6, AC-6a, AC-7 to
| AC-12, AC-7a)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 10:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function createCustomerInput(array $overrides = []): array
{
    return array_merge(['name' => 'Jane Doe'], $overrides);
}

/**
 * @param  array<string, mixed>  $fields
 */
function createCustomer(array $fields, ?string $actorId = null): CreateCustomerResult
{
    /** @var CreateCustomerHandler $handler */
    $handler = app(CreateCustomerHandler::class);

    return $handler->handle(new CreateCustomer($fields, $actorId));
}

it('creates a customer with [SYNC] and maintained-column defaults, and writes one audit row', function () {
    $result = createCustomer(createCustomerInput());

    $row = (array) CustomersSeed::connection()->table('customers')->where('id', $result->customerId)->first();

    expect($row['origin'])->toBe('online')
        ->and($row['sync_status'])->toBe('synced')
        ->and($row['terminal_id'])->toBeNull()
        ->and((float) $row['credit_balance'])->toBe(0.0)
        ->and($row['loyalty_tier'])->toBeNull()
        ->and($row['merged_into_id'])->toBeNull()
        ->and((bool) $row['credit_allowed'])->toBeFalse()
        ->and((float) $row['credit_limit'])->toBe(0.0)
        ->and($row['status'])->toBe('active');

    expect(CustomersSeed::onlyAuditRow())->toMatchArray([
        'module' => 'customers',
        'action' => 'customer_created',
        'subject_type' => 'customer',
        'subject_id' => $result->customerId,
        'before' => null,
    ]);
});

it('refuses a blank name, a duplicate code, an unknown group, a soft-deleted group and a bad status', function () {
    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => createCustomer(createCustomerInput(['name' => ''])));

    createCustomer(createCustomerInput(['code' => 'C1']));
    CustomersRefusals::expect(CustomersRefusal::Duplicate, fn () => createCustomer(createCustomerInput(['code' => 'C1'])));

    CustomersRefusals::expect(CustomersRefusal::NotFound, fn () => createCustomer(createCustomerInput(['group_id' => (string) Str::ulid()])));

    $deletedGroup = CustomersSeed::group('GONE', ['deleted_at' => CustomersSeed::NOW]);
    CustomersRefusals::expect(CustomersRefusal::NotFound, fn () => createCustomer(createCustomerInput(['group_id' => $deletedGroup])));

    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => createCustomer(createCustomerInput(['status' => 'bogus'])));
});

it('accepts two customers with a NULL code', function () {
    createCustomer(createCustomerInput(['name' => 'One']));
    createCustomer(createCustomerInput(['name' => 'Two']));

    expect(CustomersSeed::connection()->table('customers')->count())->toBe(2);
});

it('copies credit_allowed, credit_limit and payment_terms_days from the group at create (AC-7, F-10, D-27)', function () {
    $groupId = CustomersSeed::group('VIP', [
        'credit_allowed' => true,
        'default_credit_limit' => '500.0000',
        'default_payment_terms_days' => 30,
    ]);

    $result = createCustomer(createCustomerInput(['group_id' => $groupId]));
    $row = (array) CustomersSeed::connection()->table('customers')->where('id', $result->customerId)->first();

    expect((bool) $row['credit_allowed'])->toBeTrue()
        ->and((float) $row['credit_limit'])->toBe(500.0)
        ->and((int) $row['payment_terms_days'])->toBe(30);

    // A later change to the group leaves the customer unchanged.
    CustomersSeed::connection()->table('customer_groups')->where('id', $groupId)->update(['default_credit_limit' => '999.0000']);
    $after = (array) CustomersSeed::connection()->table('customers')->where('id', $result->customerId)->first();
    expect((float) $after['credit_limit'])->toBe(500.0);
});

it('lets an explicit value win over the group default', function () {
    $groupId = CustomersSeed::group('VIP', ['credit_allowed' => true, 'default_credit_limit' => '500.0000']);

    $result = createCustomer(createCustomerInput(['group_id' => $groupId, 'credit_allowed' => false, 'credit_limit' => '10.0000']));
    $row = (array) CustomersSeed::connection()->table('customers')->where('id', $result->customerId)->first();

    expect((bool) $row['credit_allowed'])->toBeFalse()
        ->and((float) $row['credit_limit'])->toBe(10.0);
});

it('refuses each maintained column whole, naming the reason, on create (AC-8, F-12)', function () {
    foreach (['credit_balance' => '5.0000', 'loyalty_points' => '5.0000', 'loyalty_tier' => 'gold', 'merged_into_id' => (string) Str::ulid()] as $field => $value) {
        CustomersRefusals::expect(CustomersRefusal::Maintained, fn () => createCustomer(createCustomerInput([$field => $value])), $field);
    }

    expect(CustomersSeed::connection()->table('customers')->count())->toBe(0)
        ->and(CustomersSeed::audit())->toBe([]);
});

it('refuses blocked without a reason, and active with a reason (AC-9)', function () {
    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => createCustomer(createCustomerInput(['status' => 'blocked'])));
    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => createCustomer(createCustomerInput(['status' => 'active', 'blocked_reason' => 'because'])));

    $result = createCustomer(createCustomerInput(['status' => 'blocked', 'blocked_reason' => 'because']));
    $row = (array) CustomersSeed::connection()->table('customers')->where('id', $result->customerId)->first();
    expect($row['status'])->toBe('blocked')->and($row['blocked_reason'])->toBe('because');
});

it('accepts status inactive, and refuses it carrying a blocked_reason (AC-6, AC-9)', function () {
    CustomersRefusals::expect(CustomersRefusal::Validation, fn () => createCustomer(createCustomerInput(['status' => 'inactive', 'blocked_reason' => 'because'])));

    $result = createCustomer(createCustomerInput(['status' => 'inactive']));
    $row = (array) CustomersSeed::connection()->table('customers')->where('id', $result->customerId)->first();
    expect($row['status'])->toBe('inactive')->and($row['blocked_reason'])->toBeNull();
});

it('refuses a command field naming a sale field or a [SYNC] column as unknown (AC-11, AC-6/F-14)', function () {
    foreach (['first_sale_at', 'last_sale_at', 'lifetime_value', 'origin', 'sync_status', 'terminal_id'] as $field) {
        CustomersRefusals::expect(CustomersRefusal::Validation, fn () => createCustomer(createCustomerInput([$field => 'x'])), $field);
    }
});

it('sets possible_duplicate_of, and refuses an unknown id (AC-12)', function () {
    $other = createCustomer(createCustomerInput(['name' => 'Other']))->customerId;

    $result = createCustomer(createCustomerInput(['possible_duplicate_of' => $other]));
    $row = (array) CustomersSeed::connection()->table('customers')->where('id', $result->customerId)->first();
    expect($row['possible_duplicate_of'])->toBe($other);

    CustomersRefusals::expect(CustomersRefusal::NotFound, fn () => createCustomer(createCustomerInput(['possible_duplicate_of' => (string) Str::ulid()])));
});

it('posts one opening ledger entry for a non-zero opening_balance (AC-7a, D-28)', function () {
    $result = createCustomer(createCustomerInput(['currency' => 'USD', 'opening_balance' => '150.0000']));

    $row = (array) CustomersSeed::connection()->table('customers')->where('id', $result->customerId)->first();
    expect((float) $row['credit_balance'])->toBe(150.0)
        ->and((float) $row['opening_balance'])->toBe(150.0);

    $ledgerRows = CustomersSeed::connection()->table('customer_ledger')->where('customer_id', $result->customerId)->get();
    expect($ledgerRows)->toHaveCount(1);
    expect($ledgerRows->first()->type)->toBe('opening')
        ->and((float) $ledgerRows->first()->debit)->toBe(150.0)
        ->and((float) $ledgerRows->first()->credit)->toBe(0.0);

    // Two audit rows: the customer creation, and the ledger entry.
    expect(CustomersSeed::audit())->toHaveCount(2);
});

it('a negative opening_balance posts a credit-side entry', function () {
    $result = createCustomer(createCustomerInput(['currency' => 'USD', 'opening_balance' => '-40.0000']));

    $ledgerRows = CustomersSeed::connection()->table('customer_ledger')->where('customer_id', $result->customerId)->get();
    expect((float) $ledgerRows->first()->credit)->toBe(40.0)
        ->and((float) $ledgerRows->first()->debit)->toBe(0.0);

    $row = (array) CustomersSeed::connection()->table('customers')->where('id', $result->customerId)->first();
    expect((float) $row['credit_balance'])->toBe(-40.0);
});

it('a non-zero opening_balance with no currency writes nothing at all, including no customer row', function () {
    $thrown = null;

    try {
        createCustomer(createCustomerInput(['opening_balance' => '100.0000']));
    } catch (CreditLedgerRefused $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->reason)->toBe(CreditRefusal::CurrencyRequired);

    expect(CustomersSeed::connection()->table('customers')->count())->toBe(0)
        ->and(CustomersSeed::connection()->table('customer_ledger')->count())->toBe(0)
        ->and(CustomersSeed::audit())->toBe([]);
});

it('a zero opening_balance writes no ledger row', function () {
    $result = createCustomer(createCustomerInput(['opening_balance' => '0']));

    expect(CustomersSeed::connection()->table('customer_ledger')->where('customer_id', $result->customerId)->count())->toBe(0)
        ->and(CustomersSeed::audit())->toHaveCount(1);
});
