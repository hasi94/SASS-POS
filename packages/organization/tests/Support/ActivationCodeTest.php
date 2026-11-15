<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Support;

use Flexipos\Contracts\Organization\ActivationCodeFormat;
use Flexipos\Organization\Support\ActivationCode;
use InvalidArgumentException;

/*
|--------------------------------------------------------------------------
| T-014 step 2 and 10 - the activation code (AC-26, D-17, D-33)
|--------------------------------------------------------------------------
|
| The alphabet is pinned by its **length and by what it excludes**, never by
| agreement with a copy of itself: 31 symbols, none of `0`, `O`, `1`, `I`,
| `L`. The uniformity case is a bound, wide enough that a correct draw never
| fails it (nine standard deviations) and tight enough that a generator
| stuck on a subset of the alphabet does.
|
| The hash is compared with `hash('sha256', ...)` computed here, so the test
| cannot agree with a wrong implementation by sharing its literal.
*/

it('has thirty-one characters and none of the five ambiguous ones (D-33)', function () {
    $alphabet = ActivationCodeFormat::ALPHABET;

    expect(strlen($alphabet))->toBe(31)
        ->and(count(array_unique(str_split($alphabet))))->toBe(31);

    foreach (['0', 'O', '1', 'I', 'L'] as $excluded) {
        expect(str_contains($alphabet, $excluded))->toBeFalse("[{$excluded}] must not be in the alphabet");
    }

    // Every other upper-case letter and digit is there: 36 - 5 = 31.
    $everything = array_merge(range('A', 'Z'), array_map('strval', range(0, 9)));
    $expected = array_values(array_diff($everything, ['0', 'O', '1', 'I', 'L']));
    $actual = str_split($alphabet);
    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected);
});

it('draws a secret of two groups of four from the alphabet and nothing else', function () {
    for ($i = 0; $i < 200; $i++) {
        $secret = ActivationCode::generateSecret();

        expect(preg_match(ActivationCodeFormat::SECRET_PATTERN, $secret))->toBe(1)
            ->and(strlen($secret))->toBe(9)
            ->and($secret[4])->toBe('-');
    }
});

it('draws every symbol of the alphabet, close to equally often', function () {
    $counts = array_fill_keys(str_split(ActivationCodeFormat::ALPHABET), 0);
    $draws = 8000;

    for ($i = 0; $i < $draws; $i++) {
        foreach (str_split(str_replace('-', '', ActivationCode::generateSecret())) as $character) {
            $counts[$character]++;
        }
    }

    $expected = ($draws * 8) / 31;

    foreach ($counts as $character => $count) {
        expect($count)->toBeGreaterThan($expected * 0.8, "[{$character}] was drawn too rarely")
            ->and($count)->toBeLessThan($expected * 1.2, "[{$character}] was drawn too often");
    }
});

it('makes two secrets that differ', function () {
    $secrets = [];

    for ($i = 0; $i < 50; $i++) {
        $secrets[ActivationCode::generateSecret()] = true;
    }

    expect(count($secrets))->toBeGreaterThan(48);
});

it('hashes the eight characters without their hyphen, as sha-256 hex', function () {
    $hash = ActivationCode::hashOf('ABCD-EFGH');

    expect($hash)->toBe(hash('sha256', 'ABCDEFGH'))
        ->and(strlen($hash))->toBe(64)
        ->and(preg_match('/\A[0-9a-f]{64}\z/', $hash))->toBe(1)
        ->and($hash)->not->toBe(hash('sha256', 'ABCD-EFGH'));
});

it('formats and parses a code both ways, upper-casing and splitting from the right', function () {
    $printed = ActivationCode::format('TEN-000123', 'ABCD-EFGH');

    expect($printed)->toBe('TEN-000123-ABCD-EFGH')
        ->and(ActivationCodeFormat::parse($printed))->toBe(['tenantPart' => 'TEN-000123', 'secret' => 'ABCD-EFGH'])
        // The input is upper-cased, so a lower-case code is the same code.
        ->and(ActivationCodeFormat::parse('ten-000123-abcd-efgh'))->toBe(['tenantPart' => 'TEN-000123', 'secret' => 'ABCD-EFGH'])
        ->and(ActivationCodeFormat::parse('SHOP-ABCD-EFGH'))->toBe(['tenantPart' => 'SHOP', 'secret' => 'ABCD-EFGH']);
});

it('refuses every shape that is not a code, with no exception', function (mixed $submitted) {
    expect(ActivationCodeFormat::parse($submitted))->toBeNull();
})->with([
    'not a string' => [['TEN-ABCD-EFGH']],
    'an integer' => [12345],
    'null' => [null],
    'empty' => [''],
    'no tenant part' => ['ABCD-EFGH'],
    'a leading hyphen as the tenant part' => ['-ABCD-EFGH'],
    'a character outside the alphabet in the secret' => ['TEN-ABC0-EFGH'],
    'an ambiguous letter in the secret' => ['TEN-ABCL-EFGH'],
    'a secret group of three' => ['TEN-ABC-EFGH'],
    'a secret group of five' => ['TEN-ABCDE-EFGH'],
    'no separator' => ['TENABCD-EFGH'],
    'a tenant part ending in a hyphen' => ['TEN--ABCD-EFGH'],
    'an invalid tenant character' => ['TE N-ABCD-EFGH'],
    'trailing text' => ['TEN-ABCD-EFGH '],
    'over the length cap' => [str_repeat('A', 70).'-ABCD-EFGH'],
]);

it('will not format a code from parts that are not shaped like one', function () {
    expect(fn () => ActivationCode::format('ten', 'ABCD-EFGH'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ActivationCode::format('TEN', 'abcd-efgh'))->toThrow(InvalidArgumentException::class);
});
