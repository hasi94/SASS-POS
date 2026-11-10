<?php

declare(strict_types=1);

use Flexipos\Contracts\Customers\CreditEntryType;
use Flexipos\Contracts\Customers\CreditLedgerRefused;
use Flexipos\Contracts\Customers\CreditRefusal;
use Flexipos\Customers\Ledger\CreditLedgerCalculator;
use Flexipos\Customers\Ledger\CreditOutcome;
use Flexipos\Customers\Ledger\CreditPosition;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| CreditLedgerCalculator (plan step 6, Ruling 4; AC-23, AC-24, AC-41)
|--------------------------------------------------------------------------
*/

function creditCalcMixed(mixed $value): mixed
{
    return $value;
}

function creditCalcPosition(
    string $balance = '0',
    bool $creditAllowed = true,
    string $creditLimit = '1000000.0000',
    bool $active = true,
): CreditPosition {
    return new CreditPosition($balance, $creditAllowed, $creditLimit, $active);
}

it('adds a debit to an opening balance of zero', function () {
    $outcome = CreditLedgerCalculator::apply(creditCalcPosition('0'), CreditEntryType::Opening, 100, 0);

    expect($outcome->balanceAfter)->toBe('100.0000');
});

it('subtracts a credit from a balance', function () {
    $outcome = CreditLedgerCalculator::apply(creditCalcPosition('100'), CreditEntryType::Payment, 0, '30.5');

    expect($outcome->balanceAfter)->toBe('69.5000');
});

it('never reports a negative zero', function () {
    $outcome = CreditLedgerCalculator::apply(creditCalcPosition('-20'), CreditEntryType::Adjustment, 20, 0);

    expect($outcome->balanceAfter)->toBe('0.0000');
});

it('is exact at the smallest step', function () {
    $outcome = CreditLedgerCalculator::apply(creditCalcPosition('0'), CreditEntryType::Opening, '0.0001', 0);

    expect($outcome->balanceAfter)->toBe('0.0001');
});

it('refuses a result over DECIMAL(18,4)', function () {
    $refused = null;

    try {
        CreditLedgerCalculator::apply(creditCalcPosition('99999999999999.9999'), CreditEntryType::Opening, '0.0001', 0);
    } catch (CreditLedgerRefused $e) {
        $refused = $e;
    }

    expect($refused)->not->toBeNull()
        ->and($refused->reason)->toBe(CreditRefusal::OutOfRange);
});

it('refuses a float debit with a TypeError, never a business refusal', function () {
    expect(fn () => CreditLedgerCalculator::apply(creditCalcPosition('0'), CreditEntryType::Opening, creditCalcMixed(2.5), 0))
        ->toThrow(TypeError::class);
});

dataset('malformed amounts', [
    'exponent' => ['1e3'],
    'negative on an unsigned side' => ['-5'],
    'five decimals' => ['1.00001'],
    'blank' => [''],
]);

it('refuses a malformed debit string', function (string $malformed) {
    $refused = null;

    try {
        CreditLedgerCalculator::apply(creditCalcPosition('0'), CreditEntryType::Opening, $malformed, 0);
    } catch (CreditLedgerRefused $e) {
        $refused = $e;
    }

    expect($refused)->not->toBeNull()
        ->and($refused->reason)->toBe(CreditRefusal::InvalidAmount);
})->with('malformed amounts');

it('accepts an int amount', function () {
    $outcome = CreditLedgerCalculator::apply(creditCalcPosition('0'), CreditEntryType::Opening, 5, 0);

    expect($outcome->balanceAfter)->toBe('5.0000');
});

it('refuses both sides zero', function () {
    $refused = null;

    try {
        CreditLedgerCalculator::apply(creditCalcPosition('0'), CreditEntryType::Adjustment, 0, 0);
    } catch (CreditLedgerRefused $e) {
        $refused = $e;
    }

    expect($refused->reason)->toBe(CreditRefusal::ZeroAmount);
});

it('refuses both sides non-zero', function () {
    $refused = null;

    try {
        CreditLedgerCalculator::apply(creditCalcPosition('0'), CreditEntryType::Adjustment, 5, 5);
    } catch (CreditLedgerRefused $e) {
        $refused = $e;
    }

    expect($refused->reason)->toBe(CreditRefusal::BothSides);
});

dataset('debit-only types', [
    'credit_sale' => [CreditEntryType::CreditSale],
    'debit_note' => [CreditEntryType::DebitNote],
]);

it('refuses a debit-only type entered on the credit side', function (CreditEntryType $type) {
    $refused = null;

    try {
        CreditLedgerCalculator::apply(creditCalcPosition('0'), $type, 0, 10);
    } catch (CreditLedgerRefused $e) {
        $refused = $e;
    }

    expect($refused->reason)->toBe(CreditRefusal::WrongSide);
})->with('debit-only types');

dataset('credit-only types', [
    'payment' => [CreditEntryType::Payment],
    'refund' => [CreditEntryType::Refund],
    'credit_note' => [CreditEntryType::CreditNote],
    'write_off' => [CreditEntryType::WriteOff],
]);

it('refuses a credit-only type entered on the debit side', function (CreditEntryType $type) {
    $refused = null;

    try {
        CreditLedgerCalculator::apply(creditCalcPosition('0'), $type, 10, 0);
    } catch (CreditLedgerRefused $e) {
        $refused = $e;
    }

    expect($refused->reason)->toBe(CreditRefusal::WrongSide);
})->with('credit-only types');

it('accepts opening and adjustment on either side', function () {
    expect(CreditLedgerCalculator::apply(creditCalcPosition('0'), CreditEntryType::Opening, 10, 0)->balanceAfter)->toBe('10.0000')
        ->and(CreditLedgerCalculator::apply(creditCalcPosition('0'), CreditEntryType::Opening, 0, 10)->balanceAfter)->toBe('-10.0000')
        ->and(CreditLedgerCalculator::apply(creditCalcPosition('0'), CreditEntryType::Adjustment, 10, 0)->balanceAfter)->toBe('10.0000')
        ->and(CreditLedgerCalculator::apply(creditCalcPosition('0'), CreditEntryType::Adjustment, 0, 10)->balanceAfter)->toBe('-10.0000');
});

it('accepts a credit_sale that lands exactly on the limit', function () {
    $outcome = CreditLedgerCalculator::apply(creditCalcPosition('80', true, '100'), CreditEntryType::CreditSale, 20, 0);

    expect($outcome->balanceAfter)->toBe('100.0000');
});

it('refuses a credit_sale one cent over the limit', function () {
    $refused = null;

    try {
        CreditLedgerCalculator::apply(creditCalcPosition('80', true, '100'), CreditEntryType::CreditSale, '20.0001', 0);
    } catch (CreditLedgerRefused $e) {
        $refused = $e;
    }

    expect($refused->reason)->toBe(CreditRefusal::OverLimit);
});

it('checks the limit against the resulting balance, never the amount alone', function () {
    // Balance 80, limit 100, a sale of 30: the amount (30) is under the
    // limit, but the resulting balance (110) is not.
    $refused = null;

    try {
        CreditLedgerCalculator::apply(creditCalcPosition('80', true, '100'), CreditEntryType::CreditSale, 30, 0);
    } catch (CreditLedgerRefused $e) {
        $refused = $e;
    }

    expect($refused->reason)->toBe(CreditRefusal::OverLimit);
});

it('refuses any credit_sale amount when credit is not allowed, whatever the limit', function () {
    $refused = null;

    try {
        CreditLedgerCalculator::apply(creditCalcPosition('0', false, '1000'), CreditEntryType::CreditSale, '0.0001', 0);
    } catch (CreditLedgerRefused $e) {
        $refused = $e;
    }

    expect($refused->reason)->toBe(CreditRefusal::CreditNotAllowed);
});

it('refuses a credit_sale against an inactive customer', function () {
    $refused = null;

    try {
        CreditLedgerCalculator::apply(creditCalcPosition('0', true, '1000', false), CreditEntryType::CreditSale, 10, 0);
    } catch (CreditLedgerRefused $e) {
        $refused = $e;
    }

    expect($refused->reason)->toBe(CreditRefusal::CustomerNotActive);
});

it('applies the same limit rules to debit_note', function () {
    $refused = null;

    try {
        CreditLedgerCalculator::apply(creditCalcPosition('80', true, '100'), CreditEntryType::DebitNote, 30, 0);
    } catch (CreditLedgerRefused $e) {
        $refused = $e;
    }

    expect($refused->reason)->toBe(CreditRefusal::OverLimit);
});

dataset('limit-exempt types past the limit', [
    'opening' => [CreditEntryType::Opening, 100, 0],
    'payment is credit-only, so it reduces the balance and cannot trip the limit' => [CreditEntryType::Payment, 0, 1],
    'adjustment debit' => [CreditEntryType::Adjustment, 100, 0],
]);

it('never applies the limit rule to a type other than credit_sale or debit_note', function (CreditEntryType $type, int $debit, int $credit) {
    $outcome = CreditLedgerCalculator::apply(creditCalcPosition('80', true, '100'), $type, $debit, $credit);

    expect($outcome)->toBeInstanceOf(CreditOutcome::class);
})->with('limit-exempt types past the limit');

it('reports wrong_side before over_limit when both apply', function () {
    // A credit_sale entered on the credit side (wrong side) that would also
    // be over the limit if it were a debit: wrong_side wins.
    $refused = null;

    try {
        CreditLedgerCalculator::apply(creditCalcPosition('80', true, '100'), CreditEntryType::CreditSale, 0, 50);
    } catch (CreditLedgerRefused $e) {
        $refused = $e;
    }

    expect($refused->reason)->toBe(CreditRefusal::WrongSide);
});

it('folds a ten-entry sequence into the expected running balances', function () {
    $entries = [
        ['debit' => 100, 'credit' => 0],
        ['debit' => 0, 'credit' => 30],
        ['debit' => 20, 'credit' => 0],
        ['debit' => 0, 'credit' => 90],
        ['debit' => '0.0001', 'credit' => 0],
        ['debit' => 0, 'credit' => '0.0001'],
        ['debit' => 500, 'credit' => 0],
        ['debit' => 0, 'credit' => 500],
        ['debit' => 1, 'credit' => 0],
        ['debit' => 0, 'credit' => 1],
    ];

    expect(CreditLedgerCalculator::fold($entries))->toBe([
        '100.0000', '70.0000', '90.0000', '0.0000', '0.0001', '0.0000', '500.0000', '0.0000', '1.0000', '0.0000',
    ]);
});
