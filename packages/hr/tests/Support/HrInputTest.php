<?php

namespace Flexipos\Hr\Tests\Support;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Support\Input;

/*
|--------------------------------------------------------------------------
| Strict input parsing (T-038 plan step 5; AC-16, AC-17, AC-32, AC-41, D-19, D-41)
|--------------------------------------------------------------------------
|
| Pure datasets: no database, no container. Each refusal is pinned by its
| reason and field, because "it threw" would also pass for a TypeError. No
| message may echo the value it refused.
*/

// ---- code (AC-17, D-41) ---------------------------------------------------

it('stores a code upper-cased', function (string $submitted, string $stored) {
    expect(Input::code($submitted))->toBe($stored);
})->with([
    'the plan example' => ['fin-01', 'FIN-01'],
    'mixed case' => ['Ab1', 'AB1'],
    'already upper' => ['AB1', 'AB1'],
    'one letter' => ['A', 'A'],
    'one digit' => ['9', '9'],
    'dot and dash inside' => ['A.B-1', 'A.B-1'],
    'exactly twenty characters' => [str_repeat('A', 20), str_repeat('A', 20)],
]);

it('refuses a malformed code as validation naming the field', function (mixed $submitted) {
    $refused = HrRefusals::expect(HrRefusal::Validation, fn () => Input::code($submitted));

    expect($refused->field)->toBe('code');
})->with([
    'not a string' => [12],
    'null' => [null],
    'empty' => [''],
    'internal space' => ['FIN 01'],
    'leading space' => [' FIN'],
    'trailing space' => ['FIN '],
    'trailing newline, the dollar trap' => ["FIN\n"],
    'a tab' => ["FIN\t01"],
    'a NUL byte' => ["FIN\x0001"],
    'leading dot' => ['.AB'],
    'leading dash' => ['-AB'],
    'a non-ASCII letter' => ['FIN-É'],
    'a full-width letter' => ['ＡB'],
    'twenty-one characters' => [str_repeat('A', 21)],
]);

it('names the field it was asked about', function () {
    expect(HrRefusals::capture(fn () => Input::code('', 'department_code'))->field)->toBe('department_code');
});

// ---- text (D-19, AC-16) ---------------------------------------------------

it('trims a text value', function () {
    expect(Input::text('  Sales  ', 150, true, 'name'))->toBe('Sales')
        ->and(Input::text("\u{00A0}Sales\u{00A0}", 150, true, 'name'))->toBe('Sales');
});

it('refuses a blank required text and reads a blank optional one as null', function () {
    HrRefusals::expect(HrRefusal::Validation, fn () => Input::text('   ', 150, true, 'name'));
    HrRefusals::expect(HrRefusal::Validation, fn () => Input::text(null, 150, true, 'name'));

    expect(Input::text('   ', 150, false, 'notes'))->toBeNull()
        ->and(Input::text('', 150, false, 'notes'))->toBeNull()
        ->and(Input::text(null, 150, false, 'notes'))->toBeNull();
});

it('counts the width in characters: 150 accepted, 151 refused', function () {
    expect(Input::text(str_repeat('é', 150), 150, true, 'name'))->toBe(str_repeat('é', 150));

    HrRefusals::expect(HrRefusal::Validation, fn () => Input::text(str_repeat('é', 151), 150, true, 'name'));
});

it('refuses a non-string, a control character and invalid UTF-8', function (mixed $submitted) {
    $refused = HrRefusals::expect(HrRefusal::Validation, fn () => Input::text($submitted, 150, true, 'name'));

    expect($refused->field)->toBe('name');
})->with([
    'an integer' => [5],
    'an array' => [['x']],
    'a boolean' => [true],
    'a trailing newline' => ["Sales\n"],
    'a NUL byte' => ["Sa\x00les"],
    'an escape character' => ["Sa\x1Bles"],
    'invalid UTF-8' => ["Sa\xC3\x28les"],
]);

it('never echoes the refused value', function () {
    $sentinel = 'SENTINEL-4471-ZZ';

    $refused = HrRefusals::capture(fn () => Input::text($sentinel.str_repeat('x', 200), 10, true, 'nic'));
    $other = HrRefusals::capture(fn () => Input::date($sentinel, 'dob'));
    $code = HrRefusals::capture(fn () => Input::code($sentinel.'!', 'code'));

    foreach ([$refused, $other, $code] as $each) {
        expect($each->getMessage())->not->toContain($sentinel);
    }
});

// ---- enum, flag, integer, date, id ----------------------------------------

enum HrInputTestKind: string
{
    case One = 'one';
}

it('matches an enum exactly, with no trimming and no case folding', function () {
    expect(Input::enum('one', HrInputTestKind::class, 'kind'))->toBe(HrInputTestKind::One);

    foreach (['One', ' one', 'one ', '', 1, null] as $bad) {
        HrRefusals::expect(HrRefusal::Validation, fn () => Input::enum($bad, HrInputTestKind::class, 'kind'));
    }
});

it('accepts only a JSON boolean', function () {
    expect(Input::flag(true, 'f'))->toBeTrue()
        ->and(Input::flag(false, 'f'))->toBeFalse();

    foreach (['true', 1, 0, null, 'false', []] as $bad) {
        HrRefusals::expect(HrRefusal::Validation, fn () => Input::flag($bad, 'f'));
    }
});

it('accepts an integer inside its range and nothing else (a designation level, AC-32)', function () {
    expect(Input::integer(0, 0, 255, 'level'))->toBe(0)
        ->and(Input::integer(255, 0, 255, 'level'))->toBe(255);

    foreach ([256, -1, '5', 5.0, null, true] as $bad) {
        HrRefusals::expect(HrRefusal::Validation, fn () => Input::integer($bad, 0, 255, 'level'));
    }
});

it('accepts a real calendar date and refuses a rolled-over or malformed one (AC-41)', function () {
    expect(Input::date('2024-02-29', 'joining_date'))->toBe('2024-02-29');

    foreach (['2025-02-30', '2025-13-01', '2025-00-10', '2025-1-1', '25-01-01', '2025-01-01 ', "2025-01-01\n", 20250101, null] as $bad) {
        $refused = HrRefusals::expect(HrRefusal::Validation, fn () => Input::date($bad, 'joining_date'));

        expect($refused->field)->toBe('joining_date');
    }
});

it('accepts a ULID, folds it to upper case, and refuses anything else', function () {
    expect(Input::id('01jabcdefghjkmnpqrstvwxyz0', 'id'))->toBe('01JABCDEFGHJKMNPQRSTVWXYZ0');

    foreach (['', '01JABC', '01JABCDEFGHJKMNPQRSTVWXYZI', null, 5] as $bad) {
        HrRefusals::expect(HrRefusal::Validation, fn () => Input::id($bad, 'id'));
    }
});
