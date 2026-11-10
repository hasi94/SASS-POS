<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\RecordCreditLedgerEntry;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Customers\CreditLedgerPosting;
use Flexipos\Contracts\Customers\CreditLedgerRefused;
use Flexipos\Contracts\Customers\CreditRefusal;
use Flexipos\Customers\Features\RecordCreditLedgerEntry\RecordCreditLedgerEntry;
use Flexipos\Customers\Features\RecordCreditLedgerEntry\RecordCreditLedgerEntryHandler;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 10 - RecordCreditLedgerEntry, the writer and its replay
| semantics (AC-19 to AC-24, D-21, F-4, F-8, F-13)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 11:00:00.000'));
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
function recordEntryInput(array $overrides = []): array
{
    return array_merge([
        'type' => 'payment',
        'reference_type' => 'test',
        'reference_id' => (string) Str::ulid(),
        'debit' => 0,
        'credit' => '10.0000',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $fields
 */
function recordEntry(array $fields, ?string $actorId = null): CreditLedgerPosting
{
    /** @var RecordCreditLedgerEntryHandler $handler */
    $handler = app(RecordCreditLedgerEntryHandler::class);

    return $handler->handle(new RecordCreditLedgerEntry($fields, $actorId));
}

function expectCreditRefusal(CreditRefusal $reason, callable $operation): void
{
    try {
        $operation();
    } catch (CreditLedgerRefused $refused) {
        expect($refused->reason)->toBe($reason);

        return;
    }

    expect(false)->toBeTrue('Expected a CreditLedgerRefused to be thrown.');
}

it('records a payment, matching customer_ledger.balance_after to customers.credit_balance, with one audit row (AC-23)', function () {
    $customerId = CustomersSeed::customer('Jane', ['currency' => 'USD', 'credit_balance' => '100.0000']);

    $posting = recordEntry(recordEntryInput(['customer_id' => $customerId]));

    $customer = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();
    $ledgerRow = (array) CustomersSeed::connection()->table('customer_ledger')->where('id', $posting->entryId)->first();

    expect((float) $customer['credit_balance'])->toBe(90.0)
        ->and((float) $ledgerRow['balance_after'])->toBe(90.0)
        ->and($posting->balanceAfter)->toBe('90.0000')
        ->and($posting->replayed)->toBeFalse();

    expect(CustomersSeed::onlyAuditRow())->toMatchArray([
        'module' => 'customers',
        'action' => 'customers_credit_ledger_entry_recorded',
        'subject_type' => 'customer_ledger',
        'subject_id' => $posting->entryId,
    ]);
});

it('refuses an unknown customer, an unknown branch and an unknown employee', function () {
    $customerId = CustomersSeed::customer('Jane', ['currency' => 'USD']);

    expectCreditRefusal(CreditRefusal::UnknownCustomer, fn () => recordEntry(recordEntryInput(['customer_id' => (string) Str::ulid()])));
    expectCreditRefusal(CreditRefusal::UnknownBranch, fn () => recordEntry(recordEntryInput(['customer_id' => $customerId, 'branch_id' => (string) Str::ulid()])));
    expectCreditRefusal(CreditRefusal::UnknownEmployee, fn () => recordEntry(recordEntryInput(['customer_id' => $customerId, 'employee_id' => (string) Str::ulid()])));
});

it('refuses a soft-deleted or merged customer (D-30, F-13)', function () {
    $deleted = CustomersSeed::customer('Deleted', ['currency' => 'USD', 'deleted_at' => CustomersSeed::NOW]);
    $merged = CustomersSeed::customer('Merged', ['currency' => 'USD', 'merged_into_id' => CustomersSeed::customer('Target', ['currency' => 'USD'])]);

    expectCreditRefusal(CreditRefusal::CustomerNotLive, fn () => recordEntry(recordEntryInput(['customer_id' => $deleted])));
    expectCreditRefusal(CreditRefusal::CustomerNotLive, fn () => recordEntry(recordEntryInput(['customer_id' => $merged])));
});

it('refuses no currency, and a mismatched currency (F-8)', function () {
    $noCurrency = CustomersSeed::customer('NoCurrency', ['currency' => null]);
    $usd = CustomersSeed::customer('Usd', ['currency' => 'USD']);

    expectCreditRefusal(CreditRefusal::CurrencyRequired, fn () => recordEntry(recordEntryInput(['customer_id' => $noCurrency])));
    expectCreditRefusal(CreditRefusal::CurrencyMismatch, fn () => recordEntry(recordEntryInput(['customer_id' => $usd, 'currency' => 'LKR'])));
});

it('an over-limit sale writes nothing, with no row, no balance change and no audit row (AC-24)', function () {
    $customerId = CustomersSeed::customer('Jane', ['currency' => 'USD', 'credit_allowed' => true, 'credit_limit' => '100.0000', 'credit_balance' => '80.0000']);

    expectCreditRefusal(CreditRefusal::OverLimit, fn () => recordEntry(recordEntryInput([
        'customer_id' => $customerId, 'type' => 'credit_sale', 'debit' => '30.0000', 'credit' => 0,
    ])));

    $customer = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();
    expect((float) $customer['credit_balance'])->toBe(80.0)
        ->and(CustomersSeed::connection()->table('customer_ledger')->where('customer_id', $customerId)->count())->toBe(0)
        ->and(CustomersSeed::audit())->toBe([]);
});

it('a replay with the same payload returns the same entry id, replayed, one row and one audit row in total (AC-21, D-21)', function () {
    $customerId = CustomersSeed::customer('Jane', ['currency' => 'USD']);
    $input = recordEntryInput(['customer_id' => $customerId]);

    $first = recordEntry($input);
    $second = recordEntry($input);

    expect($second->entryId)->toBe($first->entryId)
        ->and($second->replayed)->toBeTrue();

    $customer = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();
    expect((float) $customer['credit_balance'])->toBe(-10.0)
        ->and(CustomersSeed::connection()->table('customer_ledger')->count())->toBe(1)
        ->and(CustomersSeed::audit())->toHaveCount(1);
});

it('a replay with a different amount is refused as replay_conflict, naming the existing id (D-21)', function () {
    $customerId = CustomersSeed::customer('Jane', ['currency' => 'USD']);
    $input = recordEntryInput(['customer_id' => $customerId]);

    $first = recordEntry($input);

    $refused = null;

    try {
        recordEntry(array_merge($input, ['credit' => '20.0000']));
    } catch (CreditLedgerRefused $e) {
        $refused = $e;
    }

    expect($refused)->not->toBeNull()
        ->and($refused->reason)->toBe(CreditRefusal::ReplayConflict)
        ->and($refused->getMessage())->toContain($first->entryId);
});

it('the backstop: a row seeded by a raw insert still resolves as a replay when the pre-check is bypassed (AC-21)', function () {
    $customerId = CustomersSeed::customer('Jane', ['currency' => 'USD']);
    $referenceId = (string) Str::ulid();

    // Seeded directly, bypassing the handler's own pre-check entirely - the
    // row already exists before the handler is ever called.
    $entryId = CustomersSeed::ledgerEntry($customerId, [
        'type' => 'payment',
        'reference_type' => 'test',
        'reference_id' => $referenceId,
        'debit' => '0.0000',
        'credit' => '10.0000',
        'balance_after' => '-10.0000',
        'currency' => 'USD',
    ]);

    $posting = recordEntry(recordEntryInput(['customer_id' => $customerId, 'reference_id' => $referenceId, 'credit' => '10.0000']));

    expect($posting->entryId)->toBe($entryId)
        ->and($posting->replayed)->toBeTrue();
});
