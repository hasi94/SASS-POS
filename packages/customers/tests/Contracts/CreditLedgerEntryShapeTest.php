<?php

declare(strict_types=1);

use Flexipos\Contracts\Customers\CreditEntryType;
use Flexipos\Contracts\Customers\CreditLedgerEntry;
use Flexipos\Contracts\Customers\CreditLedgerPosting;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| CreditLedgerEntry / CreditLedgerPosting: shape only (plan step 5)
|--------------------------------------------------------------------------
*/

function creditShapeUlid(): string
{
    return (string) Str::ulid();
}

it('accepts a well-formed entry', function () {
    $entry = new CreditLedgerEntry(
        creditShapeUlid(),
        CreditEntryType::Payment,
        'customer',
        creditShapeUlid(),
        0,
        '100.0000',
        'usd',
    );

    expect($entry->customerId)->toBe(strtoupper($entry->customerId))
        ->and($entry->referenceType)->toBe('customer')
        ->and($entry->currency)->toBe('USD');
});

it('refuses a malformed customerId', function () {
    expect(fn () => new CreditLedgerEntry('not-a-ulid', CreditEntryType::Payment, 'customer', creditShapeUlid(), 0, 1))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a referenceType of 51 characters', function () {
    expect(fn () => new CreditLedgerEntry(creditShapeUlid(), CreditEntryType::Payment, str_repeat('x', 51), creditShapeUlid(), 0, 1))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a 4-letter currency', function () {
    expect(fn () => new CreditLedgerEntry(creditShapeUlid(), CreditEntryType::Payment, 'customer', creditShapeUlid(), 0, 1, 'usds'))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts a well-formed posting', function () {
    $posting = new CreditLedgerPosting(
        creditShapeUlid(), creditShapeUlid(), CreditEntryType::Payment, '0.0000', '100.0000', '-100.0000', 'usd',
    );

    expect($posting->balanceAfter)->toBe('-100.0000')
        ->and($posting->currency)->toBe('USD')
        ->and($posting->replayed)->toBeFalse();
});

it('refuses a negative debit on a posting', function () {
    expect(fn () => new CreditLedgerPosting(
        creditShapeUlid(), creditShapeUlid(), CreditEntryType::Payment, '-1.0000', '0.0000', '0.0000', 'usd',
    ))->toThrow(InvalidArgumentException::class);
});
