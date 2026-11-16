<?php

namespace Flexipos\Finance\Tests\Support;

use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Support\Fields;
use Flexipos\Finance\Support\Input;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| Strict input parsing (T-032 plan step 5; AC-2, AC-4, AC-5, AC-9, AC-32, AC-48)
|--------------------------------------------------------------------------
|
| Pure datasets: no database, no container. Each refusal is pinned by its
| reason, because "it threw" would also pass for a TypeError.
*/

/**
 * Run a parse that must refuse, and return what it refused with.
 */
function financeRefusalOf(callable $parse): FinanceRefused
{
    try {
        $parse();
    } catch (FinanceRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('The input was accepted but should have been refused.');
}

// ---- code (AC-2) ----------------------------------------------------------

it('stores a code upper-cased', function (string $submitted, string $stored) {
    expect(Input::code($submitted))->toBe($stored);
})->with([
    'lower case' => ['ab1', 'AB1'],
    'mixed case' => ['Ab1', 'AB1'],
    'already upper' => ['AB1', 'AB1'],
    'one digit' => ['1', '1'],
    'dot and dash inside' => ['a.b-1', 'A.B-1'],
    'trailing dot' => ['A.', 'A.'],
    'exactly twenty characters' => [str_repeat('A', 20), str_repeat('A', 20)],
]);

it('refuses a malformed code as invalid_code and names the field', function (mixed $submitted) {
    $refused = financeRefusalOf(fn () => Input::code($submitted));

    expect($refused->reason)->toBe(FinanceRefusal::InvalidCode)
        ->and($refused->field)->toBe('code');
})->with([
    'empty' => [''],
    'twenty-one characters' => [str_repeat('A', 21)],
    'internal space' => ['A B'],
    'leading space' => [' AB1'],
    'trailing space' => ['AB1 '],
    'trailing newline, the dollar trap' => ["AB1\n"],
    'trailing tab' => ["AB1\t"],
    'null byte' => ["A\0B"],
    'leading dot' => ['.AB'],
    'leading dash' => ['-AB'],
    'full-width letter' => ["\u{FF21}1"],
    'arabic-indic digits' => ["\u{0661}\u{0662}"],
    'underscore' => ['A_B'],
    'null' => [null],
    'integer' => [123],
    'array' => [['A']],
    'boolean' => [true],
]);

// ---- text (AC-4, AC-34) ---------------------------------------------------

it('trims a name and counts characters rather than bytes', function () {
    $multibyte = str_repeat("\u{00E9}", 150);

    expect(Input::text('  Cash  ', 150, true, 'name'))->toBe('Cash')
        ->and(Input::text($multibyte, 150, true, 'name'))->toBe($multibyte)
        ->and(strlen($multibyte))->toBe(300);
});

it('refuses a name of 151 characters rather than truncating it', function () {
    $refused = financeRefusalOf(fn () => Input::text(str_repeat("\u{00E9}", 151), 150, true, 'name'));

    expect($refused->reason)->toBe(FinanceRefusal::Validation)
        ->and($refused->field)->toBe('name');
});

it('refuses a malformed text value', function (mixed $submitted) {
    expect(financeRefusalOf(fn () => Input::text($submitted, 150, true, 'name'))->reason)
        ->toBe(FinanceRefusal::Validation);
})->with([
    'empty' => [''],
    'spaces only' => ['   '],
    'non-breaking space only' => ["\u{00A0}\u{00A0}"],
    'bell character' => ["a\x07b"],
    'trailing newline' => ["Cash\n"],
    'null byte' => ["Ca\0sh"],
    'invalid utf-8' => ["\xC3\x28"],
    'null when required' => [null],
    'integer' => [5],
    'array' => [['x']],
]);

it('accepts null for an optional text and refuses blank and over-long ones', function () {
    expect(Input::text(null, 500, false, 'description'))->toBeNull()
        ->and(Input::text('A note', 500, false, 'description'))->toBe('A note')
        ->and(financeRefusalOf(fn () => Input::text('', 500, false, 'description'))->reason)->toBe(FinanceRefusal::Validation)
        ->and(financeRefusalOf(fn () => Input::text(str_repeat('a', 501), 500, false, 'description'))->reason)->toBe(FinanceRefusal::Validation)
        ->and(Input::text(str_repeat('a', 500), 500, false, 'description'))->toBe(str_repeat('a', 500));
});

// ---- enum (AC-5) ----------------------------------------------------------

enum FinanceInputProbe: string
{
    case Asset = 'asset';
    case Bank = 'bank';
}

it('matches an enum value exactly and returns the case', function () {
    expect(Input::enum('asset', FinanceInputProbe::class, 'type'))->toBe(FinanceInputProbe::Asset);
});

it('refuses an enum value that differs in case, padding or spelling', function (mixed $submitted) {
    $refused = financeRefusalOf(fn () => Input::enum($submitted, FinanceInputProbe::class, 'type'));

    expect($refused->reason)->toBe(FinanceRefusal::Validation)
        ->and($refused->field)->toBe('type');
})->with([
    'capitalised' => ['Asset'],
    'upper case' => ['ASSET'],
    'leading space' => [' asset'],
    'trailing space' => ['asset '],
    'unknown' => ['unknown'],
    'empty' => [''],
    'null' => [null],
    'integer' => [1],
    'array' => [['asset']],
]);

// ---- flag and small integer (AC-9) ----------------------------------------

it('accepts only a JSON boolean as a flag', function () {
    expect(Input::flag(true, 'is_postable'))->toBeTrue()
        ->and(Input::flag(false, 'is_postable'))->toBeFalse();
});

it('refuses every non-boolean flag', function (mixed $submitted) {
    expect(financeRefusalOf(fn () => Input::flag($submitted, 'is_postable'))->reason)->toBe(FinanceRefusal::Validation);
})->with([
    'string true' => ['true'],
    'one' => [1],
    'zero' => [0],
    'yes' => ['yes'],
    'null' => [null],
]);

it('accepts an integer from 0 to 65535 and nothing else', function () {
    expect(Input::smallUnsigned(0, 'sort_order'))->toBe(0)
        ->and(Input::smallUnsigned(65535, 'sort_order'))->toBe(65535);
});

it('refuses a sort order outside the range or of another type', function (mixed $submitted) {
    expect(financeRefusalOf(fn () => Input::smallUnsigned($submitted, 'sort_order'))->reason)->toBe(FinanceRefusal::Validation);
})->with([
    'negative' => [-1],
    'one over' => [65536],
    'numeric string' => ['5'],
    'float with no fraction' => [1.0],
    'null' => [null],
    'boolean' => [true],
]);

// ---- date (AC-32, AC-48) --------------------------------------------------

it('accepts a real strict date, including a leap day', function (string $date) {
    expect(Input::date($date, 'start_date'))->toBe($date);
})->with([
    'ordinary' => ['2026-01-01'],
    'leap day' => ['2028-02-29'],
    'last day of year' => ['2026-12-31'],
]);

it('refuses a date that is not a real strict Y-m-d', function (mixed $submitted) {
    $refused = financeRefusalOf(fn () => Input::date($submitted, 'start_date'));

    expect($refused->reason)->toBe(FinanceRefusal::InvalidDate)
        ->and($refused->field)->toBe('start_date');
})->with([
    'february 30th, which createFromFormat rolls over' => ['2026-02-30'],
    'month thirteen' => ['2026-13-01'],
    'non-leap february 29th' => ['2027-02-29'],
    'two-digit year' => ['26-1-1'],
    'unpadded' => ['2026-1-1'],
    'date-time' => ['2026-01-01 00:00:00'],
    'empty' => [''],
    'null' => [null],
    'trailing newline' => ["2026-01-01\n"],
    'slashes' => ['2026/01/01'],
    'integer' => [20260101],
    'arabic-indic digits' => ["\u{0662}\u{0660}\u{0662}\u{0666}-01-01"],
]);

// ---- id -------------------------------------------------------------------

it('folds a ULID to upper case and refuses anything else', function () {
    $ulid = '01jabcdefghjkmnpqrstvwxyz0';

    expect(Input::id($ulid, 'parent_id'))->toBe(strtoupper($ulid))
        ->and(financeRefusalOf(fn () => Input::id('nope', 'parent_id'))->field)->toBe('parent_id')
        ->and(financeRefusalOf(fn () => Input::id(null, 'parent_id'))->reason)->toBe(FinanceRefusal::Validation)
        ->and(financeRefusalOf(fn () => Input::id("01JABCDEFGHJKMNPQRSTVWXYZ0\n", 'parent_id'))->reason)->toBe(FinanceRefusal::Validation)
        ->and(financeRefusalOf(fn () => Input::id('81JABCDEFGHJKMNPQRSTVWXYZ0', 'parent_id'))->reason)->toBe(FinanceRefusal::Validation);
});

// ---- Fields::only (AC-8, D-10) --------------------------------------------

it('accepts a body of allowed keys', function () {
    Fields::only(['code' => 'A', 'name' => 'B'], ['code', 'name', 'description']);

    expect(true)->toBeTrue();
});

it('names every unknown and every forbidden key in one refusal', function () {
    $refused = financeRefusalOf(fn () => Fields::only(
        ['code' => 'A', 'is_system' => true, 'path' => 'x', 'colour' => 'red', 'depth' => 1],
        ['code'],
        ['is_system', 'path', 'depth'],
    ));

    expect($refused->reason)->toBe(FinanceRefusal::Validation)
        ->and($refused->context['fields'])->toBe(['is_system', 'path', 'colour', 'depth'])
        ->and($refused->getMessage())->toContain('is_system', 'path', 'depth', 'colour');
});

it('refuses a forbidden key even when it is also listed as allowed', function () {
    expect(financeRefusalOf(fn () => Fields::only(['currency' => 'LKR'], ['currency'], ['currency']))->context['fields'])
        ->toBe(['currency']);
});

it('refuses a list body, whose keys are integers', function () {
    expect(financeRefusalOf(fn () => Fields::only(['A'], ['code']))->context['fields'])->toBe(['0']);
});
