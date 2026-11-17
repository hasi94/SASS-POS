<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\UpdateCustomer;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\AccountRefusal;
use Flexipos\Contracts\Finance\AccountRefused;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\UpdateCustomer\UpdateCustomer;
use Flexipos\Customers\Features\UpdateCustomer\UpdateCustomerHandler;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 18 - the receivable account on an updated customer
| (AC-55, AC-56, AC-58 including N-3, AC-59)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-16 10:00:00.000'));
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
function ucuaUpdate(string $customerId, array $fields): void
{
    /** @var UpdateCustomerHandler $handler */
    $handler = app(UpdateCustomerHandler::class);

    $handler->handle(new UpdateCustomer($customerId, $fields));
}

/**
 * @return array<string, mixed>
 */
function ucuaRow(string $customerId): array
{
    return (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();
}

it('assigns a receivable account folding a lower-case id, leaves it when omitted, and clears it with null without a read (AC-55, AC-59)', function () {
    $id = CustomersSeed::customer();
    $account = CustomersSeed::account('UCUA-AR', 'asset', 'receivable');

    ucuaUpdate($id, ['version' => 1, 'receivable_account_id' => strtolower($account)]);

    expect(ucuaRow($id)['receivable_account_id'])->toBe($account);

    ucuaUpdate($id, ['version' => 2, 'name' => 'Renamed']);

    expect(ucuaRow($id)['receivable_account_id'])->toBe($account);

    CustomersSeed::connection()->table('accounts')->where('id', $account)->update(['deleted_at' => CustomersSeed::NOW]);

    ucuaUpdate($id, ['version' => 3, 'receivable_account_id' => null]);

    expect(ucuaRow($id)['receivable_account_id'])->toBeNull();
});

it('does not re-read an unchanged account, so one deactivated since cannot block an unrelated edit (AC-55)', function () {
    $account = CustomersSeed::account('UCUA-KEEP', 'asset', 'receivable');
    $id = CustomersSeed::customer('Jane', ['receivable_account_id' => $account]);

    CustomersSeed::connection()->table('accounts')->where('id', $account)->update(['is_active' => false]);

    ucuaUpdate($id, ['version' => 1, 'receivable_account_id' => $account, 'name' => 'Edited']);

    expect(ucuaRow($id))->toMatchArray(['receivable_account_id' => $account, 'name' => 'Edited']);
});

it('refuses each of the five reasons naming the field, and writes nothing (AC-55, AC-56)', function () {
    $id = CustomersSeed::customer();
    $before = ucuaRow($id);

    $cases = [
        [AccountRefusal::AccountNotFound, (string) Str::ulid()],
        [AccountRefusal::AccountNotLive, CustomersSeed::account('UCUA-A', 'asset', 'receivable', ['deleted_at' => CustomersSeed::NOW])],
        [AccountRefusal::AccountInactive, CustomersSeed::account('UCUA-B', 'asset', 'receivable', ['is_active' => false])],
        [AccountRefusal::AccountNotPostable, CustomersSeed::account('UCUA-C', 'asset', 'receivable', ['is_postable' => false])],
        [AccountRefusal::AccountKindMismatch, CustomersSeed::account('UCUA-D', 'revenue', 'sales')],
    ];

    foreach ($cases as [$reason, $accountId]) {
        try {
            ucuaUpdate($id, ['version' => 1, 'name' => 'Changed', 'receivable_account_id' => $accountId]);
            Assert::fail('Expected a Finance refusal.');
        } catch (AccountRefused $refused) {
            expect($refused->reason)->toBe($reason)->and($refused->field)->toBe('receivable_account_id');
        }
    }

    expect(ucuaRow($id))->toBe($before)->and(CustomersSeed::audit())->toBe([]);
});

it('refuses a malformed receivable account id with the customers validation naming the field (AC-55)', function () {
    $id = CustomersSeed::customer();

    $refused = CustomersRefusals::expect(CustomersRefusal::Validation, fn () => ucuaUpdate($id, ['version' => 1, 'receivable_account_id' => 'not-an-id']));

    expect($refused->getMessage())->toContain('receivable_account_id');
});

it('refuses an account update on a soft-deleted or merged-away customer as not found, writing nothing (AC-58, N-3)', function () {
    $account = CustomersSeed::account('UCUA-OK', 'asset', 'receivable');
    $target = CustomersSeed::customer('Target');
    $deleted = CustomersSeed::customer('Deleted', ['deleted_at' => CustomersSeed::NOW]);
    $merged = CustomersSeed::customer('Source', ['deleted_at' => CustomersSeed::NOW, 'merged_into_id' => $target]);

    foreach ([$deleted, $merged] as $id) {
        $before = ucuaRow($id);

        CustomersRefusals::expect(CustomersRefusal::NotFound, fn () => ucuaUpdate($id, ['version' => 1, 'receivable_account_id' => $account]));

        expect(ucuaRow($id))->toBe($before);
    }

    expect(CustomersSeed::audit())->toBe([]);
});
