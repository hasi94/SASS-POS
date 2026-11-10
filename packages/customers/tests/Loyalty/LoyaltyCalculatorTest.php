<?php

declare(strict_types=1);

use Flexipos\Contracts\Customers\LoyaltyLedgerRefused;
use Flexipos\Contracts\Customers\LoyaltyRefusal;
use Flexipos\Contracts\Customers\LoyaltyTransactionType;
use Flexipos\Customers\Loyalty\LoyaltyCalculator;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| LoyaltyCalculator (plan step 7, Ruling 5; AC-30 to AC-32, AC-41)
|--------------------------------------------------------------------------
*/

function loyaltyCalcMixed(mixed $value): mixed
{
    return $value;
}

function loyaltyCalcRefused(callable $call): ?LoyaltyLedgerRefused
{
    try {
        $call();
    } catch (LoyaltyLedgerRefused $e) {
        return $e;
    }

    return null;
}

it('adds an earn to a balance', function () {
    $outcome = LoyaltyCalculator::apply('10', LoyaltyTransactionType::Earn, '2.5');

    expect($outcome->balanceAfter)->toBe('12.5000');
});

it('redeems exactly to zero', function () {
    $outcome = LoyaltyCalculator::apply('12.5', LoyaltyTransactionType::Redeem, '-12.5');

    expect($outcome->balanceAfter)->toBe('0.0000');
});

it('refuses a redeem that would go below zero', function () {
    $refused = loyaltyCalcRefused(fn () => LoyaltyCalculator::apply('12.5', LoyaltyTransactionType::Redeem, '-12.5001'));

    expect($refused->reason)->toBe(LoyaltyRefusal::InsufficientPoints);
});

dataset('may go below zero', [
    'expire' => [LoyaltyTransactionType::Expire, '-5'],
    'adjust' => [LoyaltyTransactionType::Adjust, '-5'],
]);

it('allows expire and adjust to take the balance below zero', function (LoyaltyTransactionType $type, string $points) {
    $outcome = LoyaltyCalculator::apply('2', $type, $points);

    expect($outcome->balanceAfter)->toBe('-3.0000');
})->with('may go below zero');

dataset('wrong sign', [
    'negative earn' => [LoyaltyTransactionType::Earn, '-1'],
    'positive redeem' => [LoyaltyTransactionType::Redeem, '1'],
    'positive expire' => [LoyaltyTransactionType::Expire, '1'],
]);

it('refuses a type whose hard sign is violated', function (LoyaltyTransactionType $type, string $points) {
    $refused = loyaltyCalcRefused(fn () => LoyaltyCalculator::apply('10', $type, $points));

    expect($refused->reason)->toBe(LoyaltyRefusal::WrongSign);
})->with('wrong sign');

it('allows transfer and adjust either sign', function () {
    expect(LoyaltyCalculator::apply('10', LoyaltyTransactionType::Transfer, '5')->balanceAfter)->toBe('15.0000')
        ->and(LoyaltyCalculator::apply('10', LoyaltyTransactionType::Transfer, '-5')->balanceAfter)->toBe('5.0000')
        ->and(LoyaltyCalculator::apply('10', LoyaltyTransactionType::Adjust, '5')->balanceAfter)->toBe('15.0000')
        ->and(LoyaltyCalculator::apply('10', LoyaltyTransactionType::Adjust, '-5')->balanceAfter)->toBe('5.0000');
});

it('refuses zero points', function () {
    $refused = loyaltyCalcRefused(fn () => LoyaltyCalculator::apply('10', LoyaltyTransactionType::Adjust, 0));

    expect($refused->reason)->toBe(LoyaltyRefusal::ZeroPoints);
});

it('refuses five decimal places', function () {
    $refused = loyaltyCalcRefused(fn () => LoyaltyCalculator::apply('10', LoyaltyTransactionType::Earn, '1.00001'));

    expect($refused->reason)->toBe(LoyaltyRefusal::InvalidPoints);
});

it('refuses a float with a TypeError, never a business refusal', function () {
    expect(fn () => LoyaltyCalculator::apply('10', LoyaltyTransactionType::Earn, loyaltyCalcMixed(2.5)))
        ->toThrow(TypeError::class);
});
