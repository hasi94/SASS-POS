<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Ledger;

use Brick\Math\BigDecimal;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Customers\CreditEntryType;
use Flexipos\Contracts\Customers\CreditLedgerEntry;
use Flexipos\Contracts\Customers\CustomerCreditLedger;
use Flexipos\Customers\Ledger\CreditLedgerCalculator;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 10 - the invariant oracle: after every write,
| customers.credit_balance equals the last balance_after, which equals
| CreditLedgerCalculator::fold() over the stored chain (AC-23, AC-24).
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 12:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

it('holds the fold() invariant across a mixed 40-entry sequence, written through the contract', function () {
    $customerId = CustomersSeed::customer('Jane', ['currency' => 'USD', 'credit_allowed' => true, 'credit_limit' => '999999.0000']);

    /** @var CustomerCreditLedger $ledger */
    $ledger = app(CustomerCreditLedger::class);

    $sequence = [];

    for ($i = 0; $i < 40; $i++) {
        $isDebit = $i % 2 === 0;
        $amount = sprintf('%d.%02d00', ($i % 10) + 1, ($i * 7) % 100);

        $sequence[] = ['debit' => $isDebit ? $amount : '0', 'credit' => $isDebit ? '0' : $amount];

        $ledger->record(new CreditLedgerEntry(
            customerId: $customerId,
            type: CreditEntryType::Adjustment,
            referenceType: 'invariant_test',
            referenceId: (string) Str::ulid(),
            debit: $isDebit ? $amount : 0,
            credit: $isDebit ? 0 : $amount,
            currency: 'USD',
        ));
    }

    $expected = CreditLedgerCalculator::fold($sequence);

    $rows = CustomersSeed::connection()->table('customer_ledger')
        ->where('customer_id', $customerId)
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(40);

    foreach ($rows as $index => $row) {
        $stored = BigDecimal::of((string) $row->balance_after);
        $folded = BigDecimal::of($expected[$index]);

        expect($stored->compareTo($folded))->toBe(0, "Row [{$index}]: stored [{$stored}] does not equal folded [{$folded}].");
    }

    $customer = (array) CustomersSeed::connection()->table('customers')->where('id', $customerId)->first();

    $storedBalance = BigDecimal::of((string) $customer['credit_balance']);
    $foldedBalance = BigDecimal::of($expected[39]);

    expect($storedBalance->compareTo($foldedBalance))->toBe(0);
});
