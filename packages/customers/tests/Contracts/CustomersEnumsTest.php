<?php

declare(strict_types=1);

use Flexipos\Contracts\Customers\CreditEntryType;
use Flexipos\Contracts\Customers\CreditRefusal;
use Flexipos\Contracts\Customers\LoyaltyRefusal;
use Flexipos\Contracts\Customers\LoyaltyTransactionType;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| The closed vocabularies: types and refusal reasons (plan step 5, Ruling 2)
|--------------------------------------------------------------------------
*/

it('has exactly the eight credit entry types', function () {
    expect(array_map(fn (CreditEntryType $case): string => $case->value, CreditEntryType::cases()))->toBe([
        'payment', 'credit_sale', 'refund', 'credit_note', 'debit_note', 'opening', 'adjustment', 'write_off',
    ]);
});

it('has exactly the five loyalty transaction types', function () {
    expect(array_map(fn (LoyaltyTransactionType $case): string => $case->value, LoyaltyTransactionType::cases()))->toBe([
        'earn', 'redeem', 'adjust', 'expire', 'transfer',
    ]);
});

it('has exactly the fourteen credit refusal reasons', function () {
    expect(array_map(fn (CreditRefusal $case): string => $case->value, CreditRefusal::cases()))->toBe([
        'unknown_customer', 'customer_not_live', 'customer_not_active', 'invalid_amount', 'zero_amount',
        'both_sides', 'wrong_side', 'out_of_range', 'credit_not_allowed', 'over_limit', 'currency_required',
        'currency_mismatch', 'unknown_branch', 'unknown_employee', 'replay_conflict',
    ]);
});

it('has exactly the twelve loyalty refusal reasons', function () {
    expect(array_map(fn (LoyaltyRefusal $case): string => $case->value, LoyaltyRefusal::cases()))->toBe([
        'unknown_customer', 'customer_not_live', 'unknown_program', 'program_inactive', 'invalid_points',
        'zero_points', 'wrong_sign', 'insufficient_points', 'out_of_range', 'invalid_value', 'unknown_employee',
        'replay_conflict',
    ]);
});
