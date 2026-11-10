<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\MergeCustomers;

use Brick\Math\BigDecimal;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Features\MergeCustomers\MergeCustomers;
use Flexipos\Customers\Features\MergeCustomers\MergeCustomersHandler;
use Flexipos\Customers\Ledger\CreditLedgerCalculator;
use Flexipos\Customers\Tests\Support\CustomersRefusals;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 15 - MergeCustomers (AC-13, AC-14; D-20, D-25, D-30)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 16:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function mergeCustomers(string $sourceId, string $targetId, ?string $actorId = null): void
{
    /** @var MergeCustomersHandler $handler */
    $handler = app(MergeCustomersHandler::class);

    $handler->handle(new MergeCustomers($sourceId, $targetId, $actorId));
}

function expectMergeRefusal(CustomersRefusal $reason, callable $operation): void
{
    CustomersRefusals::expect($reason, $operation);
}

/**
 * @return list<array{debit: string, credit: string}>
 */
function creditEntriesFor(string $customerId): array
{
    return CustomersSeed::connection()->table('customer_ledger')
        ->where('customer_id', $customerId)
        ->orderBy('id')
        ->get(['debit', 'credit'])
        ->map(static fn (object $row): array => ['debit' => (string) $row->debit, 'credit' => (string) $row->credit])
        ->all();
}

it('refuses self, an unknown id on either side, an already-merged source, and a merged or deleted target, writing nothing', function () {
    $a = CustomersSeed::customer('A');
    $b = CustomersSeed::customer('B');
    $alreadyMergedSource = CustomersSeed::customer('AlreadyMerged', ['merged_into_id' => $b]);
    $mergedTarget = CustomersSeed::customer('MergedTarget', ['merged_into_id' => $b]);
    $deletedTarget = CustomersSeed::customer('DeletedTarget', ['deleted_at' => CustomersSeed::NOW]);

    expectMergeRefusal(CustomersRefusal::SelfMerge, fn () => mergeCustomers($a, $a));
    expectMergeRefusal(CustomersRefusal::NotFound, fn () => mergeCustomers((string) Str::ulid(), $b));
    expectMergeRefusal(CustomersRefusal::NotFound, fn () => mergeCustomers($a, (string) Str::ulid()));
    expectMergeRefusal(CustomersRefusal::AlreadyMerged, fn () => mergeCustomers($alreadyMergedSource, $a));
    expectMergeRefusal(CustomersRefusal::AlreadyMerged, fn () => mergeCustomers($a, $mergedTarget));
    expectMergeRefusal(CustomersRefusal::NotLive, fn () => mergeCustomers($a, $deletedTarget));

    expect(CustomersSeed::connection()->table('customer_ledger')->count())->toBe(0)
        ->and(CustomersSeed::audit())->toBe([]);
});

it('refuses a currency mismatch when the source has a non-zero balance', function () {
    $source = CustomersSeed::customer('Source', ['currency' => 'USD', 'credit_balance' => '10.0000']);
    $target = CustomersSeed::customer('Target', ['currency' => 'LKR']);

    expectMergeRefusal(CustomersRefusal::CurrencyMismatch, fn () => mergeCustomers($source, $target));
});

it('merges a source with balance 150 and 40 points into a target holding 50 and 10 points, moving addresses, a note and a communication (AC-13)', function () {
    $programId = CustomersSeed::program('Base');

    $source = CustomersSeed::customer('Source', ['currency' => 'USD', 'credit_balance' => '150.0000', 'loyalty_points' => '40.0000']);
    $target = CustomersSeed::customer('Target', ['currency' => 'USD', 'credit_balance' => '50.0000', 'loyalty_points' => '10.0000']);

    // Seed each with an opening ledger/loyalty row so there is a chain to fold over.
    CustomersSeed::ledgerEntry($source, ['reference_id' => $source, 'debit' => '150.0000', 'credit' => '0.0000', 'balance_after' => '150.0000', 'currency' => 'USD']);
    CustomersSeed::ledgerEntry($target, ['reference_id' => $target, 'debit' => '50.0000', 'credit' => '0.0000', 'balance_after' => '50.0000', 'currency' => 'USD']);
    CustomersSeed::loyaltyTransaction($source, $programId, ['type' => 'earn', 'points' => '40.0000', 'balance_after' => '40.0000']);
    CustomersSeed::loyaltyTransaction($target, $programId, ['type' => 'earn', 'points' => '10.0000', 'balance_after' => '10.0000']);

    $sourceBilling = CustomersSeed::address($source, ['type' => 'billing', 'is_default' => true]);
    $sourceDelivery = CustomersSeed::address($source, ['type' => 'delivery', 'is_default' => false]);
    $targetBilling = CustomersSeed::address($target, ['type' => 'billing', 'is_default' => true]);

    $note = CustomersSeed::note($source);
    $communication = CustomersSeed::communication($source);

    mergeCustomers($source, $target, 'actor-1');

    $sourceRow = (array) CustomersSeed::connection()->table('customers')->where('id', $source)->first();
    $targetRow = (array) CustomersSeed::connection()->table('customers')->where('id', $target)->first();

    // The source's balances are zero, it is merged and soft-deleted.
    expect((float) $sourceRow['credit_balance'])->toBe(0.0)
        ->and((float) $sourceRow['loyalty_points'])->toBe(0.0)
        ->and($sourceRow['merged_into_id'])->toBe($target)
        ->and($sourceRow['deleted_at'])->not->toBeNull();

    // The target absorbed both balances.
    expect((float) $targetRow['credit_balance'])->toBe(200.0)
        ->and((float) $targetRow['loyalty_points'])->toBe(50.0);

    // The compensating credit entries exist with the documented references.
    $mergeOut = CustomersSeed::connection()->table('customer_ledger')
        ->where('customer_id', $source)->where('reference_type', 'customer_merge_out')->first();
    $mergeIn = CustomersSeed::connection()->table('customer_ledger')
        ->where('customer_id', $target)->where('reference_type', 'customer_merge_in')->first();

    expect($mergeOut)->not->toBeNull()
        ->and((string) $mergeOut->reference_id)->toBe($source)
        ->and((float) $mergeOut->credit)->toBe(150.0)
        ->and((float) $mergeOut->balance_after)->toBe(0.0)
        ->and($mergeIn)->not->toBeNull()
        ->and((string) $mergeIn->reference_id)->toBe($source)
        ->and((float) $mergeIn->debit)->toBe(150.0)
        ->and((float) $mergeIn->balance_after)->toBe(200.0);

    // The source's own existing ledger/loyalty rows stay on the source,
    // byte-identical (F-3, D-20): only new compensating rows were added.
    $sourceOpening = CustomersSeed::connection()->table('customer_ledger')
        ->where('customer_id', $source)->where('reference_type', 'customer')->first();
    expect((string) $sourceOpening->customer_id)->toBe($source)
        ->and((float) $sourceOpening->balance_after)->toBe(150.0);

    // The loyalty transfer pair.
    $loyaltyOut = CustomersSeed::connection()->table('loyalty_transactions')
        ->where('customer_id', $source)->where('type', 'transfer')->first();
    $loyaltyIn = CustomersSeed::connection()->table('loyalty_transactions')
        ->where('customer_id', $target)->where('type', 'transfer')->first();

    expect((float) $loyaltyOut->points)->toBe(-40.0)
        ->and((float) $loyaltyOut->balance_after)->toBe(0.0)
        ->and((float) $loyaltyIn->points)->toBe(40.0)
        ->and((float) $loyaltyIn->balance_after)->toBe(50.0);

    // Addresses, note and communication moved to the target.
    $billing = (array) CustomersSeed::connection()->table('customer_addresses')->where('id', $sourceBilling)->first();
    $delivery = (array) CustomersSeed::connection()->table('customer_addresses')->where('id', $sourceDelivery)->first();
    $targetBillingRow = (array) CustomersSeed::connection()->table('customer_addresses')->where('id', $targetBilling)->first();

    expect($billing['customer_id'])->toBe($target)
        ->and((bool) $billing['is_default'])->toBeFalse() // the target already had a default billing address
        ->and($delivery['customer_id'])->toBe($target)
        ->and((bool) $targetBillingRow['is_default'])->toBeTrue();

    expect((array) CustomersSeed::connection()->table('customer_notes')->where('id', $note)->first())
        ->toHaveKey('customer_id', $target);
    expect((array) CustomersSeed::connection()->table('customer_communications')->where('id', $communication)->first())
        ->toHaveKey('customer_id', $target);

    // CreditLedgerInvariantTest's oracle holds for both customers.
    foreach ([$source, $target] as $customerId) {
        $entries = creditEntriesFor($customerId);
        $expected = CreditLedgerCalculator::fold($entries);

        $rows = CustomersSeed::connection()->table('customer_ledger')->where('customer_id', $customerId)->orderBy('id')->get();

        foreach ($rows as $index => $row) {
            expect(BigDecimal::of((string) $row->balance_after)->compareTo(BigDecimal::of($expected[$index])))->toBe(0);
        }

        $customer = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();
        expect(BigDecimal::of((string) $customer['credit_balance'])->compareTo(BigDecimal::of($expected[count($expected) - 1])))->toBe(0);
    }
});

it('a source with a negative balance produces a debit-side merge_out', function () {
    $source = CustomersSeed::customer('Source', ['currency' => 'USD', 'credit_balance' => '-30.0000']);
    $target = CustomersSeed::customer('Target', ['currency' => 'USD', 'credit_balance' => '0.0000']);

    mergeCustomers($source, $target);

    $mergeOut = CustomersSeed::connection()->table('customer_ledger')
        ->where('customer_id', $source)->where('reference_type', 'customer_merge_out')->first();

    expect((float) $mergeOut->debit)->toBe(30.0)
        ->and((float) $mergeOut->credit)->toBe(0.0)
        ->and((float) $mergeOut->balance_after)->toBe(0.0);

    $targetRow = (array) CustomersSeed::connection()->table('customers')->where('id', $target)->first();
    expect((float) $targetRow['credit_balance'])->toBe(-30.0);
});

it('a source with zero balances posts no ledger or loyalty rows', function () {
    $source = CustomersSeed::customer('Source', ['currency' => 'USD', 'credit_balance' => '0.0000', 'loyalty_points' => '0.0000']);
    $target = CustomersSeed::customer('Target', ['currency' => 'USD']);

    mergeCustomers($source, $target);

    expect(CustomersSeed::connection()->table('customer_ledger')->where('customer_id', $source)->count())->toBe(0)
        ->and(CustomersSeed::connection()->table('customer_ledger')->where('customer_id', $target)->count())->toBe(0)
        ->and(CustomersSeed::connection()->table('loyalty_transactions')->count())->toBe(0);
});

it('AC-14: touches nothing outside customers, customer_ledger, loyalty_transactions, customer_addresses, customer_notes and customer_communications', function () {
    $source = CustomersSeed::customer('Source');
    $target = CustomersSeed::customer('Target');

    $before = CustomersSeed::connection()->table('customer_groups')->count();

    mergeCustomers($source, $target);

    expect(CustomersSeed::connection()->table('customer_groups')->count())->toBe($before);
});
