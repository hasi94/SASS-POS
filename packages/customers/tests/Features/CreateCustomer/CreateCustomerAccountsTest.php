<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\CreateCustomer;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\AccountRefusal;
use Flexipos\Contracts\Finance\AccountRefused;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\CreateCustomer\CreateCustomer;
use Flexipos\Customers\Features\CreateCustomer\CreateCustomerHandler;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 18 - the receivable account on a created customer
| (AC-55, AC-56, AC-59)
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
 * @param  array<string, mixed>  $extra
 */
function ccuaCreate(array $extra): string
{
    /** @var CreateCustomerHandler $handler */
    $handler = app(CreateCustomerHandler::class);

    return $handler->handle(new CreateCustomer(['name' => 'Jane Doe'] + $extra))->customerId;
}

function ccuaReceivable(string $customerId): ?string
{
    $value = CustomersSeed::connection()->table('customers')->where('id', $customerId)->value('receivable_account_id');

    return $value === null ? null : (string) $value;
}

it('writes null when no receivable account is named, and stores a named one folded to upper case, audited (AC-52, AC-55, AC-59)', function () {
    expect(ccuaReceivable(ccuaCreate([])))->toBeNull();

    $account = CustomersSeed::account('CCUA-AR', 'asset', 'receivable');

    $id = ccuaCreate(['receivable_account_id' => strtolower($account)]);

    expect(ccuaReceivable($id))->toBe($account);

    $audit = array_values(array_filter(CustomersSeed::audit(), static fn (array $row): bool => $row['subject_id'] === $id));

    expect($audit)->toHaveCount(1)
        ->and(json_decode((string) $audit[0]['after'], true)['receivable_account_id'])->toBe($account);
});

it('refuses a missing, dead, inactive, header or wrong-kind receivable account naming the field, and creates nothing (AC-55, AC-56)', function () {
    $cases = [
        [AccountRefusal::AccountNotFound, (string) Str::ulid()],
        [AccountRefusal::AccountNotLive, CustomersSeed::account('CCUA-A', 'asset', 'receivable', ['deleted_at' => CustomersSeed::NOW])],
        [AccountRefusal::AccountInactive, CustomersSeed::account('CCUA-B', 'asset', 'receivable', ['is_active' => false])],
        [AccountRefusal::AccountNotPostable, CustomersSeed::account('CCUA-C', 'asset', 'receivable', ['is_postable' => false])],
        [AccountRefusal::AccountKindMismatch, CustomersSeed::account('CCUA-D', 'asset', 'cash')],
    ];

    foreach ($cases as [$reason, $accountId]) {
        try {
            ccuaCreate(['receivable_account_id' => $accountId]);
            Assert::fail('Expected a Finance refusal.');
        } catch (AccountRefused $refused) {
            expect($refused->reason)->toBe($reason)->and($refused->field)->toBe('receivable_account_id');
        }
    }

    expect(CustomersSeed::connection()->table('customers')->count())->toBe(0)
        ->and(CustomersSeed::audit())->toBe([]);
});

it('refuses a malformed receivable account id with the customers validation naming the field (AC-55)', function () {
    $refused = CustomersRefusals::expect(CustomersRefusal::Validation, fn () => ccuaCreate(['receivable_account_id' => 'not-an-id']));

    expect($refused->getMessage())->toContain('receivable_account_id');
});
