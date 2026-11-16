<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Engine;

use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Pricing\Engine\RateResolver;
use Flexipos\Pricing\Engine\RateRow;
use Flexipos\Pricing\Tests\Support\Build;

/*
|--------------------------------------------------------------------------
| RateResolver (T-029 step 6, AC-7)
|--------------------------------------------------------------------------
|
| Two rows: 15 % from 2024-01-01 to (exclusive) 2025-01-01, then 18 % from
| 2025-01-01 with no end.
*/

/**
 * @return list<RateRow>
 */
function rateResolverRows(): array
{
    return [
        Build::rate('15.0000', '2024-01-01', '2025-01-01'),
        Build::rate('18.0000', '2025-01-01'),
    ];
}

it('resolves the row in force on the date', function (string $date, string $expected) {
    expect(RateResolver::resolve(rateResolverRows(), $date)->rate)->toBe($expected);
})->with([
    'the last day of the old row' => ['2024-12-31', '15.0000'],
    'effective_to is exclusive: the day it ends the new row applies' => ['2025-01-01', '18.0000'],
    'the first day of the old row' => ['2024-01-01', '15.0000'],
    'long after, the open row still applies' => ['2031-07-04', '18.0000'],
]);

it('refuses a date before the first row as no_effective_rate', function () {
    expect(Build::reasonOf(fn () => RateResolver::resolve(rateResolverRows(), '2023-12-31')))->toBe('no_effective_rate');
});

it('refuses a date in a gap between two closed rows', function () {
    $rows = [Build::rate('15.0000', '2024-01-01', '2024-06-01'), Build::rate('18.0000', '2024-09-01', '2025-01-01')];

    expect(Build::reasonOf(fn () => RateResolver::resolve($rows, '2024-07-15')))->toBe('no_effective_rate')
        // The first row ends on 2024-06-01 (exclusive) and the next starts later: that very day is a gap.
        ->and(Build::reasonOf(fn () => RateResolver::resolve($rows, '2024-06-01')))->toBe('no_effective_rate')
        ->and(RateResolver::resolve($rows, '2024-05-31')->rate)->toBe('15.0000')
        ->and(RateResolver::resolve($rows, '2024-09-01')->rate)->toBe('18.0000');
});

it('refuses no rows at all, never a zero', function () {
    expect(Build::reasonOf(fn () => RateResolver::resolve([], '2025-01-01')))->toBe('no_effective_rate');
});

it('gives the same answer whatever order the rows arrive in', function () {
    $rows = rateResolverRows();

    foreach (['2023-12-31', '2024-12-31', '2025-01-01', '2026-02-02'] as $date) {
        $forward = Build::reasonOf(fn () => RateResolver::resolve($rows, $date)) ?? RateResolver::resolve($rows, $date)->rate;
        $reversed = Build::reasonOf(fn () => RateResolver::resolve(array_reverse($rows), $date)) ?? RateResolver::resolve(array_reverse($rows), $date)->rate;

        expect($reversed)->toBe($forward);
    }
});

it('takes the latest effective_from when two rows both contain the date', function () {
    // Overlap is refused at write (AC-6); the resolver still has a rule for it.
    $rows = [Build::rate('10.0000', '2020-01-01'), Build::rate('12.0000', '2024-01-01')];

    expect(RateResolver::resolve($rows, '2025-01-01')->rate)->toBe('12.0000')
        ->and(RateResolver::resolve(array_reverse($rows), '2025-01-01')->rate)->toBe('12.0000');
});

it('names the tax in the refusal message', function () {
    try {
        RateResolver::resolve([], '2025-01-01', 'VAT');
    } catch (PricingRefused $refused) {
        expect($refused->getMessage())->toContain('VAT')->toContain('2025-01-01');

        return;
    }

    throw new \RuntimeException('Expected a refusal.');
});

it('refuses a malformed row', function () {
    expect(fn () => new RateRow('15.0000', '2025-02-30'))->toThrow(\InvalidArgumentException::class, '[effectiveFrom]')
        ->and(fn () => new RateRow('15.0000', '2025-02-01', '2025-01-01'))->toThrow(\InvalidArgumentException::class, '[effectiveTo]')
        ->and(Build::reasonOf(fn () => new RateRow('-1.0000', '2025-02-01')))->toBe('invalid_amount');
});

/**
 * D-36 (AC-7, AC-30.10): two rows on one `effectiveFrom` are refused whether or
 * not either qualifies, in either order, so the result is a function of the row set.
 *
 * @return array<string, array{list<RateRow>, string, string|null}> rows, date, the winning rate or null when refused
 */
function rateResolverDuplicateCases(): array
{
    return [
        'a qualifying pair on 2024-01-01' => [[Build::rate('15.0000', '2024-01-01', '2024-06-01'), Build::rate('18.0000', '2024-01-01')], '2024-03-01', null],
        'a non-qualifying pair beside a qualifying row' => [[Build::rate('12.0000', '2026-01-01'), Build::rate('13.0000', '2026-01-01'), Build::rate('15.0000', '2024-01-01')], '2025-01-01', null],
        'no duplicate resolves as before' => [[Build::rate('15.0000', '2024-01-01', '2025-01-01'), Build::rate('18.0000', '2025-01-01')], '2025-06-01', '18.0000'],
    ];
}

it('refuses two rows that share an effective_from, in either input order', function (array $rows, string $date, ?string $winner) {
    foreach ([$rows, array_reverse($rows)] as $ordered) {
        if ($winner === null) {
            expect(Build::reasonOf(fn () => RateResolver::resolve($ordered, $date, 'VAT')))->toBe('invalid_amount');
        } else {
            expect(RateResolver::resolve($ordered, $date, 'VAT')->rate)->toBe($winner);
        }
    }
})->with(rateResolverDuplicateCases());

it('names the tax and the date in the duplicate refusal', function () {
    try {
        RateResolver::resolve([Build::rate('15.0000', '2024-01-01'), Build::rate('18.0000', '2024-01-01')], '2025-01-01', 'VAT');
    } catch (PricingRefused $refused) {
        expect($refused->getMessage())->toContain('VAT')->toContain('2024-01-01');

        return;
    }

    throw new \RuntimeException('Expected a refusal.');
});
