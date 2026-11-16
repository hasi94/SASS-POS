<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Support;

use Flexipos\Purchasing\Support\Redaction;

/*
|--------------------------------------------------------------------------
| T-034 step 6 - Redaction (Ruling 10, D-9, AC-17)
|--------------------------------------------------------------------------
|
| Pure. A non-null bank_details never reaches an audit image; when it changed the
| after image says so, so the audit trail's own diff still lists the field.
*/

const PU_BANK_A = '{"iban":"LK00 0000 0000 0001","bank":"Example Bank"}';

const PU_BANK_B = '{"iban":"LK99 9999 9999 9999","bank":"Example Bank"}';

it('hides a non-null bank_details in the before image and leaves every other field alone', function () {
    $before = Redaction::before(['code' => 'ACME-1', 'bank_details' => PU_BANK_A, 'name' => 'Acme']);

    expect($before)->toBe(['code' => 'ACME-1', 'bank_details' => '[redacted]', 'name' => 'Acme'])
        ->and(json_encode($before))->not->toContain('LK00');
});

it('leaves a null or absent bank_details and a null image as they are', function () {
    expect(Redaction::before(['code' => 'A', 'bank_details' => null]))->toBe(['code' => 'A', 'bank_details' => null])
        ->and(Redaction::before(['code' => 'A']))->toBe(['code' => 'A'])
        ->and(Redaction::before(null))->toBeNull()
        ->and(Redaction::after(['bank_details' => PU_BANK_A], ['bank_details' => null]))->toBe(['bank_details' => null])
        ->and(Redaction::after(null, null))->toBeNull();
});

it('marks the after image redacted when the value is the one stored', function () {
    expect(Redaction::after(['bank_details' => PU_BANK_A], ['bank_details' => PU_BANK_A, 'name' => 'Acme Ltd']))
        ->toBe(['bank_details' => '[redacted]', 'name' => 'Acme Ltd']);
});

it('marks the after image redacted-and-changed when the value differs, so the field is still listed', function () {
    $before = ['bank_details' => PU_BANK_A];
    $after = ['bank_details' => PU_BANK_B];

    $redactedBefore = Redaction::before($before);
    $redactedAfter = Redaction::after($before, $after);

    expect($redactedAfter)->toBe(['bank_details' => '[redacted, changed]'])
        ->and($redactedAfter['bank_details'])->not->toBe($redactedBefore['bank_details'])
        ->and(json_encode([$redactedBefore, $redactedAfter]))->not->toContain('LK');
});

it('counts a first value (a create, or a null before) as a change', function () {
    expect(Redaction::after(null, ['bank_details' => PU_BANK_A]))->toBe(['bank_details' => '[redacted, changed]'])
        ->and(Redaction::after(['bank_details' => null], ['bank_details' => PU_BANK_A]))->toBe(['bank_details' => '[redacted, changed]']);
});

it('treats two spellings of one JSON object as the same value, key order and spacing aside', function () {
    $same = '{ "bank": "Example Bank", "iban": "LK00 0000 0000 0001" }';

    expect(Redaction::after(['bank_details' => PU_BANK_A], ['bank_details' => $same]))->toBe(['bank_details' => '[redacted]']);
});

it('treats a nested change as a change', function () {
    $a = '{"bank":{"name":"X","branch":"1"}}';
    $b = '{"bank":{"name":"X","branch":"2"}}';

    expect(Redaction::after(['bank_details' => $a], ['bank_details' => $b]))->toBe(['bank_details' => '[redacted, changed]']);
});
