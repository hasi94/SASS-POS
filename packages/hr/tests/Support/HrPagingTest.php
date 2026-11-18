<?php

namespace Flexipos\Hr\Tests\Support;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Support\Cursor;
use Flexipos\Hr\Support\PageSize;

/*
|--------------------------------------------------------------------------
| Page size and cursor (T-038 plan step 5; AC-26, AC-38, AC-104, D-25)
|--------------------------------------------------------------------------
|
| Refused, never clamped. A cursor is only ever the canonical encoding of a
| cursor for the list it is presented to.
*/

it('defaults to the configured page size when none is asked', function () {
    expect(PageSize::of(null, 50, 200))->toBe(50)
        ->and(PageSize::of(1, 50, 200))->toBe(1)
        ->and(PageSize::of(200, 50, 200))->toBe(200)
        ->and(PageSize::of(37, 50, 200))->toBe(37);
});

it('refuses a page size outside 1 to 200 and a non-integer, never clamping', function () {
    foreach ([0, 201, -1, 1000, '50', 50.0, true, [], ''] as $bad) {
        $refused = HrRefusals::expect(HrRefusal::PageSizeInvalid, fn () => PageSize::of($bad, 50, 200));

        expect($refused->field)->toBe('page_size');
    }
});

it('takes both figures from its arguments, so config is the only source', function () {
    expect(PageSize::of(null, 7, 9))->toBe(7)
        ->and(PageSize::of(9, 7, 9))->toBe(9);

    HrRefusals::expect(HrRefusal::PageSizeInvalid, fn () => PageSize::of(10, 7, 9));
});

it('round-trips a cursor for its own list', function () {
    $cursor = Cursor::encode('departments', ['FIN-01', '01JABCDEFGHJKMNPQRSTVWXYZ0']);

    expect($cursor)->toMatch('/\A[A-Za-z0-9_\-]+\z/')
        ->and(Cursor::decode($cursor, 'departments', 2))->toBe(['FIN-01', '01JABCDEFGHJKMNPQRSTVWXYZ0']);
});

it('refuses a cursor made for another list', function () {
    $cursor = Cursor::encode('departments', ['A', 'B']);

    HrRefusals::expect(HrRefusal::CursorInvalid, fn () => Cursor::decode($cursor, 'designations', 2));
});

it('refuses half a key or a key with too many parts', function () {
    HrRefusals::expect(HrRefusal::CursorInvalid, fn () => Cursor::decode(Cursor::encode('departments', ['A']), 'departments', 2));
    HrRefusals::expect(HrRefusal::CursorInvalid, fn () => Cursor::decode(Cursor::encode('departments', ['A', 'B', 'C']), 'departments', 2));
    HrRefusals::expect(HrRefusal::CursorInvalid, fn () => Cursor::decode(Cursor::encode('departments', []), 'departments', 2));
});

it('refuses a malformed cursor', function (mixed $bad) {
    $refused = HrRefusals::expect(HrRefusal::CursorInvalid, fn () => Cursor::decode($bad, 'departments', 2));

    expect($refused->field)->toBe('cursor');
})->with([
    'empty' => [''],
    'not a string' => [12],
    'null' => [null],
    'not base64url' => ['!!!'],
    'padding' => [Cursor::encode('departments', ['A', 'B']).'='],
    'base64 of text, not JSON' => [rtrim(strtr(base64_encode('hello'), '+/', '-_'), '=')],
    'JSON, not an object' => [rtrim(strtr(base64_encode('[1,2]'), '+/', '-_'), '=')],
    'JSON with a numeric key part' => [rtrim(strtr(base64_encode('{"l":"departments","k":["A",2]}'), '+/', '-_'), '=')],
    'JSON with a null key part' => [rtrim(strtr(base64_encode('{"l":"departments","k":["A",null]}'), '+/', '-_'), '=')],
    'JSON with extra members' => [rtrim(strtr(base64_encode('{"l":"departments","k":["A","B"],"x":1}'), '+/', '-_'), '=')],
    'JSON with members reordered' => [rtrim(strtr(base64_encode('{"k":["A","B"],"l":"departments"}'), '+/', '-_'), '=')],
    'JSON with a keyed k' => [rtrim(strtr(base64_encode('{"l":"departments","k":{"a":"A","b":"B"}}'), '+/', '-_'), '=')],
    'JSON with insignificant whitespace' => [rtrim(strtr(base64_encode('{"l": "departments", "k": ["A", "B"]}'), '+/', '-_'), '=')],
    'JSON with an escaped character' => [rtrim(strtr(base64_encode('{"l":"departments","k":["\u0041","B"]}'), '+/', '-_'), '=')],
    'over the length cap' => [str_repeat('A', 1025)],
]);

it('does not echo a refused cursor', function () {
    $sentinel = 'SENTINEL-1199-WW';

    $refused = HrRefusals::capture(fn () => Cursor::decode($sentinel, 'departments', 2));

    expect($refused->getMessage())->not->toContain($sentinel);
});
