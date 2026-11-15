<?php

declare(strict_types=1);

use Flexipos\Contracts\Customers\LoyaltyPosting;
use Flexipos\Contracts\Customers\LoyaltyTransaction;
use Flexipos\Contracts\Customers\LoyaltyTransactionType;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| LoyaltyTransaction / LoyaltyPosting: shape only (plan step 5, F-15)
|--------------------------------------------------------------------------
*/

function loyaltyShapeUlid(): string
{
    return (string) Str::ulid();
}

it('accepts a well-formed transaction with no caller id', function () {
    $tx = new LoyaltyTransaction(null, loyaltyShapeUlid(), loyaltyShapeUlid(), LoyaltyTransactionType::Earn, 10);

    expect($tx->id)->toBeNull()
        ->and($tx->customerId)->toBe(strtoupper($tx->customerId));
});

it('accepts a well-formed caller-supplied id', function () {
    $id = loyaltyShapeUlid();
    $tx = new LoyaltyTransaction($id, loyaltyShapeUlid(), loyaltyShapeUlid(), LoyaltyTransactionType::Earn, 10);

    expect($tx->id)->toBe(strtoupper($id));
});

it('refuses a malformed caller-supplied id', function () {
    expect(fn () => new LoyaltyTransaction('not-a-ulid', loyaltyShapeUlid(), loyaltyShapeUlid(), LoyaltyTransactionType::Earn, 10))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a malformed programId', function () {
    expect(fn () => new LoyaltyTransaction(null, loyaltyShapeUlid(), 'not-a-ulid', LoyaltyTransactionType::Earn, 10))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts a well-formed posting', function () {
    $posting = new LoyaltyPosting(
        loyaltyShapeUlid(), loyaltyShapeUlid(), loyaltyShapeUlid(), LoyaltyTransactionType::Earn, '10.0000', '10.0000', 'silver',
    );

    expect($posting->tier)->toBe('silver')
        ->and($posting->replayed)->toBeFalse();
});

it('refuses a tier of 51 characters', function () {
    expect(fn () => new LoyaltyPosting(
        loyaltyShapeUlid(), loyaltyShapeUlid(), loyaltyShapeUlid(), LoyaltyTransactionType::Earn, '10.0000', '10.0000', str_repeat('x', 51),
    ))->toThrow(InvalidArgumentException::class);
});
