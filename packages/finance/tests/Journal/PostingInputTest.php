<?php

namespace Flexipos\Finance\Tests\Journal;

use Flexipos\Contracts\Finance\JournalDimension;
use Flexipos\Contracts\Finance\JournalSourceType;
use Flexipos\Contracts\Finance\PostingLine;
use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Contracts\Finance\PostingRequest;
use Flexipos\Finance\Journal\ParsedPosting;
use Flexipos\Finance\Journal\PostingInput;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| The pure checks of a posting request (T-033 plan step B2)
|--------------------------------------------------------------------------
|
| AC-6 to AC-16, AC-6a and the order of D-21. No database and no container.
| Each refusal is pinned by its code and its field path, because "it threw"
| would also pass for a TypeError.
*/

const PI_ACCOUNT_A = '01JABCDEFGHJKMNPQRSTVWXYZ0';
const PI_ACCOUNT_B = '01JABCDEFGHJKMNPQRSTVWXYZ1';
const PI_SOURCE = '01JABCDEFGHJKMNPQRSTVWXYZ2';
const PI_OTHER = '01JABCDEFGHJKMNPQRSTVWXYZ3';

/**
 * A valid request body; `$over` replaces top-level keys.
 *
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function piBody(array $over = []): array
{
    return array_replace([
        'source_type' => 'sale',
        'source_id' => PI_SOURCE,
        'entry_date' => '2026-01-15',
        'description' => 'A sale',
        'branch_id' => null,
        'lines' => [
            ['account_id' => PI_ACCOUNT_A, 'debit' => '10.00'],
            ['account_id' => PI_ACCOUNT_B, 'credit' => '10.00'],
        ],
    ], $over);
}

/**
 * @param  array<string, mixed>  $over
 */
function piRequest(array $over = [], ?string $actor = null): PostingRequest
{
    return PostingRequest::fromArray(piBody($over), $actor);
}

/**
 * Two lines with `$first` replacing the first line's keys.
 *
 * @param  array<string, mixed>  $first
 * @return list<array<string, mixed>>
 */
function piLinesWith(array $first): array
{
    return [
        array_replace(['account_id' => PI_ACCOUNT_A, 'debit' => '10.00'], $first),
        ['account_id' => PI_ACCOUNT_B, 'credit' => '10.00'],
    ];
}

function piRefusal(PostingRequest $request): PostingRefused
{
    try {
        PostingInput::parse($request);
    } catch (PostingRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('The request was accepted but should have been refused.');
}

it('parses a valid request into its stored form', function () {
    $parsed = PostingInput::parse(piRequest([
        'source_id' => strtolower(PI_SOURCE),
        'source_purpose' => 'revenue',
        'description' => '  A sale  ',
        'branch_id' => strtolower(PI_OTHER),
        'lines' => [
            ['account_id' => strtolower(PI_ACCOUNT_A), 'debit' => '12.5', 'memo' => ' cash ', 'customer_id' => strtolower(PI_OTHER)],
            ['account_id' => PI_ACCOUNT_B, 'credit' => '12.50'],
        ],
    ], 'actor'));

    expect($parsed)->toBeInstanceOf(ParsedPosting::class)
        ->and($parsed->sourceType)->toBe(JournalSourceType::Sale)
        ->and($parsed->sourceId)->toBe(PI_SOURCE)
        ->and($parsed->sourcePurpose)->toBe('revenue')
        ->and($parsed->entryDate)->toBe('2026-01-15')
        ->and($parsed->description)->toBe('A sale')
        ->and($parsed->branchId)->toBe(PI_OTHER)
        ->and($parsed->totalDebit)->toBe('12.5000')
        ->and($parsed->totalCredit)->toBe('12.5000')
        ->and($parsed->actorId)->toBe('actor')
        ->and($parsed->lines[0]['account_id'])->toBe(PI_ACCOUNT_A)
        ->and($parsed->lines[0]['debit'])->toBe('12.5000')
        ->and($parsed->lines[0]['credit'])->toBe('0.0000')
        ->and($parsed->lines[0]['memo'])->toBe('cash')
        ->and($parsed->lines[0]['dimensions']['customer_id'])->toBe(PI_OTHER)
        ->and($parsed->lines[1]['memo'])->toBeNull();
});

it('carries all seven dimensions in the fixed order, null where none was named', function () {
    $parsed = PostingInput::parse(piRequest(['lines' => piLinesWith(['product_id' => PI_OTHER, 'branch_id' => PI_OTHER])]));

    expect(array_keys($parsed->lines[1]['dimensions']))
        ->toBe(array_map(fn (JournalDimension $d): string => $d->value, JournalDimension::cases()))
        ->and($parsed->lines[1]['dimensions'])->each->toBeNull()
        ->and($parsed->lines[0]['dimensions']['product_id'])->toBe(PI_OTHER)
        ->and($parsed->lines[0]['dimensions']['branch_id'])->toBe(PI_OTHER);
});

// ---- AC-6, AC-6a: source_type ----------------------------------------------

it('AC-6: refuses a source_type that is not exactly one value of the list', function (mixed $type) {
    $refused = piRefusal(piRequest(['source_type' => $type]));

    expect($refused->reason)->toBe(PostingRefusal::InvalidSourceType)
        ->and($refused->field)->toBe('source_type');
})->with([
    'capitalised' => ['Sale'],
    'upper case' => ['SALE'],
    'leading space' => [' sale'],
    'trailing newline' => ["sale\n"],
    'empty' => [''],
    'null' => [null],
    'unknown' => ['unknown'],
    'an integer' => [1],
]);

it('accepts every source type of the list except reversal', function (string $value) {
    $parsed = PostingInput::parse(piRequest(['source_type' => $value]));

    expect($parsed->sourceType->value)->toBe($value);
})->with(array_values(array_map(
    fn (JournalSourceType $type): array => [$type->value],
    array_filter(JournalSourceType::cases(), fn (JournalSourceType $type): bool => $type !== JournalSourceType::Reversal),
)));

it('AC-6a: refuses a reversal posting before it looks at source_id', function (mixed $sourceId) {
    $refused = piRefusal(piRequest(['source_type' => 'reversal', 'source_id' => $sourceId]));

    expect($refused->reason)->toBe(PostingRefusal::InvalidSourceType)
        ->and($refused->field)->toBe('source_type');
})->with([
    'a ULID' => [PI_SOURCE],
    'the id of a stored entry' => [PI_OTHER],
    'absent' => [null],
    'malformed, which would otherwise be invalid_source_id' => ['not-a-ulid'],
]);

// ---- AC-7: source_id ---------------------------------------------------------

it('AC-7: refuses a missing or malformed source_id for a document type', function (mixed $sourceId) {
    $refused = piRefusal(piRequest(['source_id' => $sourceId]));

    expect($refused->reason)->toBe(PostingRefusal::InvalidSourceId)
        ->and($refused->field)->toBe('source_id');
})->with([
    'null' => [null],
    'twenty-five characters' => [substr(PI_SOURCE, 0, 25)],
    'twenty-seven characters' => [PI_SOURCE.'0'],
    'a trailing newline at twenty-six' => [substr(PI_SOURCE, 0, 25)."\n"],
    'an integer' => [12],
    'empty' => [''],
]);

it('AC-7: lets manual and opening omit source_id but not malform it', function (string $type) {
    expect(PostingInput::parse(piRequest(['source_type' => $type, 'source_id' => null]))->sourceId)->toBeNull()
        ->and(piRefusal(piRequest(['source_type' => $type, 'source_id' => 'bad']))->reason)
        ->toBe(PostingRefusal::InvalidSourceId)
        ->and(piRefusal(piRequest(['source_type' => $type, 'source_id' => '']))->reason)
        ->toBe(PostingRefusal::InvalidSourceId);
})->with(['manual', 'opening']);

// ---- AC-8: source_purpose ----------------------------------------------------

it('AC-8: refuses a malformed source_purpose', function (mixed $purpose) {
    $refused = piRefusal(piRequest(['source_purpose' => $purpose]));

    expect($refused->reason)->toBe(PostingRefusal::InvalidSourcePurpose)
        ->and($refused->field)->toBe('source_purpose');
})->with([
    'empty' => [''],
    'the stored sentinel' => ['-'],
    'starts with a digit' => ['1abc'],
    'upper case' => ['Revenue'],
    'thirty-one characters' => [str_repeat('a', 31)],
    'a trailing newline, the dollar trap' => ["rev\n"],
    'a space' => ['a b'],
    'an integer' => [5],
]);

it('AC-8: accepts one to thirty characters', function (string $purpose) {
    expect(PostingInput::parse(piRequest(['source_purpose' => $purpose]))->sourcePurpose)->toBe($purpose);
})->with([
    'one character' => ['a'],
    'with digits and underscores' => ['cost_of_goods_2'],
    'thirty characters' => [str_repeat('a', 30)],
]);

it('AC-8: refuses a purpose without a source_id', function () {
    $refused = piRefusal(piRequest(['source_type' => 'manual', 'source_id' => null, 'source_purpose' => 'revenue']));

    expect($refused->reason)->toBe(PostingRefusal::InvalidSourcePurpose)
        ->and($refused->field)->toBe('source_purpose');
});

// ---- AC-9: entry_date --------------------------------------------------------

it('AC-9: refuses a date that is not a real strict Y-m-d', function (mixed $date) {
    $refused = piRefusal(piRequest(['entry_date' => $date]));

    expect($refused->reason)->toBe(PostingRefusal::InvalidDate)
        ->and($refused->field)->toBe('entry_date');
})->with([
    'a day that does not exist' => ['2026-02-30'],
    'unpadded' => ['2026-1-1'],
    'a date-time' => ['2026-01-15 10:00:00'],
    'a leading space' => [' 2026-01-15'],
    'a trailing newline' => ["2026-01-15\n"],
    'null' => [null],
    'empty' => [''],
    'an integer' => [20260115],
]);

it('AC-9: accepts a real leap day and does not default the date', function () {
    expect(PostingInput::parse(piRequest(['entry_date' => '2028-02-29']))->entryDate)->toBe('2028-02-29');
});

// ---- AC-10, AC-14: description and memo --------------------------------------

it('AC-10: accepts 500 multi-byte characters and refuses 501', function () {
    $ok = str_repeat('é', 500);

    expect(PostingInput::parse(piRequest(['description' => $ok]))->description)->toBe($ok)
        ->and(piRefusal(piRequest(['description' => str_repeat('é', 501)]))->reason)
        ->toBe(PostingRefusal::InvalidDescription);
});

it('AC-10, AC-14: refuses a description with a control character, bad UTF-8 or a non-string', function (mixed $description) {
    $refused = piRefusal(piRequest(['description' => $description]));

    expect($refused->reason)->toBe(PostingRefusal::InvalidDescription)
        ->and($refused->field)->toBe('description');
})->with([
    'a control character inside' => ["a\x01b"],
    'a trailing newline after text' => ["text\n"],
    'a null byte' => ["a\0b"],
    'invalid UTF-8' => ["a\xC3\x28"],
    'an integer' => [7],
]);

it('AC-14: stores a blank description or memo as null and trims the rest', function () {
    $blank = PostingInput::parse(piRequest([
        'description' => " \t ",
        'lines' => piLinesWith(['memo' => '   ']),
    ]));
    $padded = PostingInput::parse(piRequest(['description' => ' x ', 'lines' => piLinesWith(['memo' => " y\u{00A0}"])]));

    expect($blank->description)->toBeNull()
        ->and($blank->lines[0]['memo'])->toBeNull()
        ->and(PostingInput::parse(piRequest(['description' => '']))->description)->toBeNull()
        ->and($padded->description)->toBe('x')
        ->and($padded->lines[0]['memo'])->toBe('y');
});

it('AC-14: accepts a 255-character memo and refuses 256 or a control character', function () {
    expect(PostingInput::parse(piRequest(['lines' => piLinesWith(['memo' => str_repeat('m', 255)])]))->lines[0]['memo'])
        ->toBe(str_repeat('m', 255));

    foreach ([str_repeat('m', 256), "a\x01", 12] as $memo) {
        $refused = piRefusal(piRequest(['lines' => piLinesWith(['memo' => $memo])]));

        expect($refused->reason)->toBe(PostingRefusal::InvalidMemo)
            ->and($refused->field)->toBe('lines.0.memo');
    }
});

// ---- AC-11, AC-13, AC-15: ids ------------------------------------------------

it('AC-11: refuses a malformed branch_id and accepts null', function (mixed $branch) {
    $refused = piRefusal(piRequest(['branch_id' => $branch]));

    expect($refused->reason)->toBe(PostingRefusal::InvalidBranch)
        ->and($refused->field)->toBe('branch_id');
})->with([
    'short' => ['abc'],
    'empty' => [''],
    'an integer' => [3],
    'a trailing newline' => [substr(PI_OTHER, 0, 25)."\n"],
]);

it('AC-13: names the line of a malformed account id', function () {
    $body = piBody();
    $body['lines'][1]['account_id'] = 'nope';
    $refused = piRefusal(PostingRequest::fromArray($body, null));

    expect($refused->reason)->toBe(PostingRefusal::InvalidAccountId)
        ->and($refused->field)->toBe('lines.1.account_id');
});

it('AC-13: refuses a missing account id', function () {
    $refused = piRefusal(piRequest(['lines' => piLinesWith(['account_id' => null])]));

    expect($refused->reason)->toBe(PostingRefusal::InvalidAccountId)
        ->and($refused->field)->toBe('lines.0.account_id');
});

it('AC-15: names the line and the dimension of a malformed dimension id', function (string $dimension) {
    $refused = piRefusal(piRequest(['lines' => piLinesWith([$dimension => 'bad'])]));

    expect($refused->reason)->toBe(PostingRefusal::InvalidDimension)
        ->and($refused->field)->toBe('lines.0.'.$dimension);
})->with(array_map(fn (JournalDimension $dimension): array => [$dimension->value], JournalDimension::cases()));

it('AC-15: refuses a dimension key outside the seven, naming it, on a directly built request', function () {
    $request = new PostingRequest('sale', PI_SOURCE, null, '2026-01-15', null, null, [
        new PostingLine(PI_ACCOUNT_A, '10', '0', null, ['colour' => PI_OTHER]),
        new PostingLine(PI_ACCOUNT_B, '0', '10', null, ['project_ref' => PI_OTHER, 'branch_id' => PI_OTHER]),
    ], null);

    $refused = piRefusal($request);

    expect($refused->reason)->toBe(PostingRefusal::UnknownField)
        ->and($refused->fields)->toBe(['lines.0.colour', 'lines.1.project_ref']);
});

// ---- AC-12: lines ------------------------------------------------------------

it('AC-12: refuses fewer than 2 and more than 200 lines', function () {
    $line = ['account_id' => PI_ACCOUNT_A, 'debit' => '1'];

    expect(piRefusal(piRequest(['lines' => []]))->reason)->toBe(PostingRefusal::TooFewLines)
        ->and(piRefusal(piRequest(['lines' => [$line]]))->reason)->toBe(PostingRefusal::TooFewLines)
        ->and(piRefusal(piRequest(['lines' => array_fill(0, 201, $line)]))->reason)->toBe(PostingRefusal::TooManyLines)
        ->and(PostingInput::parse(piRequest(['lines' => [$line, ['account_id' => PI_ACCOUNT_B, 'credit' => '1']]]))->lines)
        ->toHaveCount(2);
});

it('AC-12: accepts exactly 200 lines', function () {
    $lines = array_merge(
        array_fill(0, 100, ['account_id' => PI_ACCOUNT_A, 'debit' => '1']),
        array_fill(0, 100, ['account_id' => PI_ACCOUNT_B, 'credit' => '1']),
    );

    expect(PostingInput::parse(piRequest(['lines' => $lines]))->lines)->toHaveCount(200);
});

it('AC-12: refuses lines that are not a list', function (mixed $lines) {
    $refused = piRefusal(piRequest(['lines' => $lines]));

    expect($refused->reason)->toBe(PostingRefusal::InvalidLines)
        ->and($refused->field)->toBe('lines');
})->with([
    'a map' => [['a' => ['account_id' => PI_ACCOUNT_A], 'b' => ['account_id' => PI_ACCOUNT_B]]],
    'a string' => ['lines'],
    'null' => [null],
    'an integer' => [2],
]);

it('AC-12: refuses a line that is not an object, naming it', function () {
    $refused = piRefusal(piRequest(['lines' => [['account_id' => PI_ACCOUNT_A, 'debit' => '1'], 'x']]));

    expect($refused->reason)->toBe(PostingRefusal::InvalidLines)
        ->and($refused->field)->toBe('lines.1');
});

// ---- the order of checks (D-21) ----------------------------------------------

it('reports the first wrong field in request order', function () {
    $refused = piRefusal(piRequest(['entry_date' => 'bad', 'description' => "a\x01", 'branch_id' => 'bad']));

    expect($refused->reason)->toBe(PostingRefusal::InvalidDate);

    $refused = piRefusal(piRequest(['description' => "a\x01", 'branch_id' => 'bad']));

    expect($refused->reason)->toBe(PostingRefusal::InvalidDescription);
});

it('reports the first wrong line, then the first wrong field of that line', function () {
    $lines = [
        ['account_id' => PI_ACCOUNT_A, 'debit' => '1'],
        ['account_id' => 'bad', 'debit' => '1', 'memo' => "a\x01"],
        ['account_id' => 'worse', 'credit' => '2'],
    ];

    $refused = piRefusal(piRequest(['lines' => $lines]));

    expect($refused->field)->toBe('lines.1.account_id');

    $lines[1]['account_id'] = PI_ACCOUNT_B;
    $refused = piRefusal(piRequest(['lines' => $lines]));

    expect($refused->field)->toBe('lines.1.memo');
});

it('reports a shape error ahead of an amount error', function () {
    $refused = piRefusal(piRequest(['lines' => [
        ['account_id' => PI_ACCOUNT_A, 'debit' => 'x'],
        ['account_id' => 'bad', 'credit' => '1'],
    ]]));

    expect($refused->reason)->toBe(PostingRefusal::InvalidAccountId);
});

it('reports an amount error ahead of an imbalance', function () {
    $refused = piRefusal(piRequest(['lines' => [
        ['account_id' => PI_ACCOUNT_A, 'debit' => '10'],
        ['account_id' => PI_ACCOUNT_B, 'credit' => '1.00001'],
    ]]));

    expect($refused->reason)->toBe(PostingRefusal::InvalidAmount)
        ->and($refused->field)->toBe('lines.1.credit');
});

it('reports an imbalance with both totals once every line is well formed', function () {
    $refused = piRefusal(piRequest(['lines' => [
        ['account_id' => PI_ACCOUNT_A, 'debit' => '0.3'],
        ['account_id' => PI_ACCOUNT_B, 'credit' => '0.3001'],
    ]]));

    expect($refused->reason)->toBe(PostingRefusal::EntryUnbalanced)
        ->and($refused->context)->toBe(['total_debit' => '0.3000', 'total_credit' => '0.3001']);
});
