<?php

namespace Flexipos\Finance\Tests\Journal;

use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Contracts\Finance\ReversalRequest;
use Flexipos\Finance\Journal\ParsedReversal;
use Flexipos\Finance\Journal\ReversalInput;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| The pure checks of a reversal request (T-033 plan step B2)
|--------------------------------------------------------------------------
|
| AC-52, AC-53, AC-56 shape halves: entry id, then date, then reason.
*/

const RI_ENTRY = '01JABCDEFGHJKMNPQRSTVWXYZ5';

/**
 * @param  array<string, mixed>  $over
 */
function riRequest(array $over = []): ReversalRequest
{
    return ReversalRequest::fromArray(array_replace([
        'entry_id' => RI_ENTRY,
        'entry_date' => '2026-02-01',
        'reason' => 'Wrong account',
    ], $over), null);
}

function riRefusal(ReversalRequest $request): PostingRefused
{
    try {
        ReversalInput::parse($request);
    } catch (PostingRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('The request was accepted but should have been refused.');
}

it('parses a valid reversal into its stored form', function () {
    $parsed = ReversalInput::parse(new ReversalRequest(strtolower(RI_ENTRY), '2026-02-01', '  Wrong account  ', 'actor'));

    expect($parsed)->toBeInstanceOf(ParsedReversal::class)
        ->and($parsed->entryId)->toBe(RI_ENTRY)
        ->and($parsed->entryDate)->toBe('2026-02-01')
        ->and($parsed->reason)->toBe('Wrong account')
        ->and($parsed->actorId)->toBe('actor');
});

it('refuses a malformed entry id as entry_not_found', function (mixed $id) {
    $refused = riRefusal(riRequest(['entry_id' => $id]));

    expect($refused->reason)->toBe(PostingRefusal::EntryNotFound)
        ->and($refused->field)->toBe('entry_id');
})->with([
    'null' => [null],
    'short' => ['abc'],
    'empty' => [''],
    'an integer' => [5],
    'a trailing newline' => [substr(RI_ENTRY, 0, 25)."\n"],
]);

it('refuses a missing or malformed date as invalid_date and never defaults it', function (mixed $date) {
    $refused = riRefusal(riRequest(['entry_date' => $date]));

    expect($refused->reason)->toBe(PostingRefusal::InvalidDate)
        ->and($refused->field)->toBe('entry_date');
})->with([
    'null' => [null],
    'empty' => [''],
    'a day that does not exist' => ['2026-02-30'],
    'a date-time' => ['2026-02-01T00:00:00'],
    'unpadded' => ['2026-2-1'],
]);

it('AC-56: refuses a missing or blank reason as reason_required', function (mixed $reason) {
    $refused = riRefusal(riRequest(['reason' => $reason]));

    expect($refused->reason)->toBe(PostingRefusal::ReasonRequired)
        ->and($refused->field)->toBe('reason');
})->with([
    'null' => [null],
    'empty' => [''],
    'spaces' => ['   '],
    'tabs and newlines' => ["\t\n "],
]);

it('AC-56: refuses an unusable reason as invalid_reason', function (mixed $reason) {
    $refused = riRefusal(riRequest(['reason' => $reason]));

    expect($refused->reason)->toBe(PostingRefusal::InvalidReason)
        ->and($refused->field)->toBe('reason');
})->with([
    'five hundred and one characters' => [str_repeat('r', 501)],
    'a control character' => ["a\x01b"],
    'a trailing newline after text' => ["why\n"],
    'invalid UTF-8' => ["a\xC3\x28"],
    'an integer' => [12],
]);

it('AC-56: accepts 500 multi-byte characters', function () {
    $reason = str_repeat('é', 500);

    expect(ReversalInput::parse(riRequest(['reason' => $reason]))->reason)->toBe($reason);
});

it('reports the first wrong field in request order', function () {
    expect(riRefusal(riRequest(['entry_id' => 'bad', 'entry_date' => 'bad', 'reason' => null]))->reason)
        ->toBe(PostingRefusal::EntryNotFound)
        ->and(riRefusal(riRequest(['entry_date' => 'bad', 'reason' => null]))->reason)
        ->toBe(PostingRefusal::InvalidDate);
});
