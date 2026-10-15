<?php

use Flexipos\Auth\Tokens\RefreshTokenValue;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-009 step 5, AC-32: the value, its randomness and its hash
|--------------------------------------------------------------------------
|
| Three properties, and the spec fixes how each is admissible:
|
| - **At least 256 bits of randomness, measured as a length and not as a
|   statistical test.** So the assertion is on the decoded byte count of the
|   value, not on its entropy: a statistical test on one sample proves nothing
|   and fails at random.
| - **The stored form is the SHA-256 hex of the value, 64 characters.** Asserted
|   against `hash('sha256', ...)` computed here rather than against a constant,
|   so the test cannot agree with a wrong implementation by sharing its
|   literal.
| - **The hash is a function of the value only**, which is what makes a
|   presented token findable: two value objects over the same plaintext hash
|   the same, and two generated values do not collide.
|
| The plaintext is a property of this object and of nothing that is persisted.
| The runtime proof that it reaches no column of any master table is AC-32's
| and QA's (step 13); the half this file can carry is that the hash is what the
| row gets, which IssueTokenPairTest asserts against the real row.
|
| No database, no keys, no clock.
|
*/

uses(TestCase::class);

it('carries at least 256 bits, as a length', function () {
    $value = RefreshTokenValue::generate();
    $raw = base64_decode(strtr($value->plaintext(), '-_', '+/'), true);

    expect($raw)->toBeString()
        ->and(strlen((string) $raw))->toBe(RefreshTokenValue::BYTES)
        ->and(strlen((string) $raw) * 8)->toBeGreaterThanOrEqual(256);
});

it('is url-safe, so it never needs escaping on the way to the client', function () {
    $value = RefreshTokenValue::generate()->plaintext();

    expect($value)->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and(str_contains($value, '='))->toBeFalse()
        ->and(str_contains($value, '+'))->toBeFalse()
        ->and(str_contains($value, '/'))->toBeFalse();
});

it('hashes to the sha-256 hex of itself, in 64 lower-case characters', function () {
    $value = RefreshTokenValue::generate();

    expect($value->hash())->toBe(hash('sha256', $value->plaintext()))
        ->and(strlen($value->hash()))->toBe(RefreshTokenValue::HASH_LENGTH)
        ->and($value->hash())->toMatch('/^[0-9a-f]{64}$/')
        ->and($value->hash())->not->toContain($value->plaintext());
});

it('hashes a presented value to the same digest as the issued one', function () {
    $issued = RefreshTokenValue::generate();
    $presented = RefreshTokenValue::fromPlaintext($issued->plaintext());

    expect($presented->hash())->toBe($issued->hash())
        ->and($presented->plaintext())->toBe($issued->plaintext());
});

it('does not repeat itself', function () {
    $values = [];

    for ($i = 0; $i < 16; $i++) {
        $values[] = RefreshTokenValue::generate()->plaintext();
    }

    expect(array_unique($values))->toHaveCount(16);
});

it('refuses an empty presented value instead of hashing nothing', function () {
    // A hash of the empty string is a valid-looking 64 characters that would
    // match any row written by the same mistake; refusing is the only answer
    // that cannot be confused with a lookup miss.
    expect(fn () => RefreshTokenValue::fromPlaintext(''))->toThrow(InvalidArgumentException::class);
});
