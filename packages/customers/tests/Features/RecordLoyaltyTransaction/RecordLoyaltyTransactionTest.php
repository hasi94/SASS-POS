<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\RecordLoyaltyTransaction;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Customers\LoyaltyLedgerRefused;
use Flexipos\Contracts\Customers\LoyaltyPosting;
use Flexipos\Contracts\Customers\LoyaltyRefusal;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Features\RecordLoyaltyTransaction\RecordLoyaltyTransaction;
use Flexipos\Customers\Features\RecordLoyaltyTransaction\RecordLoyaltyTransactionHandler;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 12 - RecordLoyaltyTransaction, the writer and tier
| recomputation (AC-28 to AC-33, D-23, D-30, F-6, F-15, F-18)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 14:00:00.000'));
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
function recordLoyaltyInput(array $overrides = []): array
{
    return array_merge([
        'type' => 'earn',
        'points' => '10.0000',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $fields
 */
function recordLoyalty(array $fields, ?string $actorId = null): LoyaltyPosting
{
    /** @var RecordLoyaltyTransactionHandler $handler */
    $handler = app(RecordLoyaltyTransactionHandler::class);

    return $handler->handle(new RecordLoyaltyTransaction($fields, $actorId));
}

function expectLoyaltyRefusal(LoyaltyRefusal $reason, callable $operation): void
{
    try {
        $operation();
    } catch (LoyaltyLedgerRefused $refused) {
        expect($refused->reason)->toBe($reason);

        return;
    }

    expect(false)->toBeTrue('Expected a LoyaltyLedgerRefused to be thrown.');
}

it('records an earn, matching loyalty_transactions.balance_after to customers.loyalty_points, with one audit row (AC-30)', function () {
    $customerId = CustomersSeed::customer('Jane', ['loyalty_points' => '5.0000']);
    $programId = CustomersSeed::program();

    $posting = recordLoyalty(recordLoyaltyInput(['customer_id' => $customerId, 'program_id' => $programId]));

    $customer = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();
    $row = (array) CustomersSeed::connection()->table('loyalty_transactions')->where('id', $posting->transactionId)->first();

    expect((float) $customer['loyalty_points'])->toBe(15.0)
        ->and((float) $row['balance_after'])->toBe(15.0)
        ->and($posting->balanceAfter)->toBe('15.0000')
        ->and($posting->replayed)->toBeFalse();

    expect(CustomersSeed::onlyAuditRow())->toMatchArray([
        'module' => 'customers',
        'action' => 'customers_loyalty_transaction_recorded',
        'subject_type' => 'loyalty_transaction',
        'subject_id' => $posting->transactionId,
    ]);
});

it('refuses an unknown program, zero points and an unknown employee', function () {
    $customerId = CustomersSeed::customer('Jane');
    $programId = CustomersSeed::program();

    expectLoyaltyRefusal(LoyaltyRefusal::UnknownProgram, fn () => recordLoyalty(recordLoyaltyInput(['customer_id' => $customerId, 'program_id' => (string) Str::ulid()])));
    expectLoyaltyRefusal(LoyaltyRefusal::ZeroPoints, fn () => recordLoyalty(recordLoyaltyInput(['customer_id' => $customerId, 'program_id' => $programId, 'points' => '0'])));
    expectLoyaltyRefusal(LoyaltyRefusal::UnknownEmployee, fn () => recordLoyalty(recordLoyaltyInput(['customer_id' => $customerId, 'program_id' => $programId, 'employee_id' => (string) Str::ulid()])));
});

it('refuses a soft-deleted or merged customer (D-30)', function () {
    $programId = CustomersSeed::program();
    $deleted = CustomersSeed::customer('Deleted', ['deleted_at' => CustomersSeed::NOW]);
    $merged = CustomersSeed::customer('Merged', ['merged_into_id' => CustomersSeed::customer('Target')]);

    expectLoyaltyRefusal(LoyaltyRefusal::CustomerNotLive, fn () => recordLoyalty(recordLoyaltyInput(['customer_id' => $deleted, 'program_id' => $programId])));
    expectLoyaltyRefusal(LoyaltyRefusal::CustomerNotLive, fn () => recordLoyalty(recordLoyaltyInput(['customer_id' => $merged, 'program_id' => $programId])));
});

it('refuses earn or redeem on an inactive program, while adjust is accepted (F-6, D-23)', function () {
    $customerId = CustomersSeed::customer('Jane', ['loyalty_points' => '10.0000']);
    $programId = CustomersSeed::program('Retired', ['is_active' => false]);

    expectLoyaltyRefusal(LoyaltyRefusal::ProgramInactive, fn () => recordLoyalty(recordLoyaltyInput(['customer_id' => $customerId, 'program_id' => $programId, 'type' => 'earn', 'points' => '1'])));
    expectLoyaltyRefusal(LoyaltyRefusal::ProgramInactive, fn () => recordLoyalty(recordLoyaltyInput(['customer_id' => $customerId, 'program_id' => $programId, 'type' => 'redeem', 'points' => '-1'])));

    $posting = recordLoyalty(recordLoyaltyInput(['customer_id' => $customerId, 'program_id' => $programId, 'type' => 'adjust', 'points' => '5']));

    expect($posting->points)->toBe('5.0000');
});

it('a redeem past the balance writes nothing (AC-31)', function () {
    $customerId = CustomersSeed::customer('Jane', ['loyalty_points' => '10.0000']);
    $programId = CustomersSeed::program();

    expectLoyaltyRefusal(LoyaltyRefusal::InsufficientPoints, fn () => recordLoyalty(recordLoyaltyInput([
        'customer_id' => $customerId, 'program_id' => $programId, 'type' => 'redeem', 'points' => '-10.0001',
    ])));

    $customer = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();
    expect((float) $customer['loyalty_points'])->toBe(10.0)
        ->and(CustomersSeed::connection()->table('loyalty_transactions')->where('customer_id', $customerId)->count())->toBe(0)
        ->and(CustomersSeed::audit())->toBe([]);
});

it('earning across the silver threshold sets loyalty_tier, and redeeming back below it reverts the tier (AC-32)', function () {
    $customerId = CustomersSeed::customer('Jane', ['loyalty_points' => '90.0000']);
    $programId = CustomersSeed::program('Tiered', ['tier_rules' => json_encode([
        ['tier' => 'bronze', 'min_points' => '0'],
        ['tier' => 'silver', 'min_points' => '100'],
    ])]);

    recordLoyalty(recordLoyaltyInput(['customer_id' => $customerId, 'program_id' => $programId, 'type' => 'earn', 'points' => '20']));

    $customer = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();
    expect((float) $customer['loyalty_points'])->toBe(110.0)
        ->and($customer['loyalty_tier'])->toBe('silver');

    recordLoyalty(recordLoyaltyInput(['customer_id' => $customerId, 'program_id' => $programId, 'type' => 'redeem', 'points' => '-15']));

    $customer = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();
    expect((float) $customer['loyalty_points'])->toBe(95.0)
        ->and($customer['loyalty_tier'])->toBe('bronze');
});

it('a caller id replay returns the same id, replayed, and a different payload is replay_conflict (F-15)', function () {
    $customerId = CustomersSeed::customer('Jane');
    $programId = CustomersSeed::program();
    $id = (string) Str::ulid();

    $input = recordLoyaltyInput(['id' => $id, 'customer_id' => $customerId, 'program_id' => $programId]);

    $first = recordLoyalty($input);
    $second = recordLoyalty($input);

    expect($second->transactionId)->toBe($first->transactionId)
        ->and($second->replayed)->toBeTrue()
        ->and(CustomersSeed::connection()->table('loyalty_transactions')->count())->toBe(1)
        ->and(CustomersSeed::audit())->toHaveCount(1);

    expectLoyaltyRefusal(LoyaltyRefusal::ReplayConflict, fn () => recordLoyalty(array_merge($input, ['points' => '20.0000'])));
});

it('refuses a field the operation does not take', function () {
    $customerId = CustomersSeed::customer('Jane');
    $programId = CustomersSeed::program();

    expect(fn () => recordLoyalty(recordLoyaltyInput(['customer_id' => $customerId, 'program_id' => $programId, 'extra' => 1])))
        ->toThrow(CustomersRefused::class);
});
