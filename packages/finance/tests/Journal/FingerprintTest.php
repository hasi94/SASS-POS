<?php

namespace Flexipos\Finance\Tests\Journal;

use Flexipos\Contracts\Finance\JournalSourceType;
use Flexipos\Contracts\Finance\PostingRequest;
use Flexipos\Contracts\Finance\ReversalRequest;
use Flexipos\Finance\Journal\Fingerprint;
use Flexipos\Finance\Journal\ParsedPosting;
use Flexipos\Finance\Journal\PostingInput;
use Flexipos\Finance\Journal\ReversalInput;

/*
|--------------------------------------------------------------------------
| The payload fingerprint (T-033 plan step B4; AC-35, AC-36, D-6, D-30)
|--------------------------------------------------------------------------
|
| Pure. The golden cases hash a literal JSON text, so the canonical shape is
| pinned independently of the code that builds it: a change to that shape
| changes every stored fingerprint, and these cases are what notices.
*/

const FP_A = '01JABCDEFGHJKMNPQRSTVWXYZ0';
const FP_B = '01JABCDEFGHJKMNPQRSTVWXYZ1';
const FP_SRC = '01JABCDEFGHJKMNPQRSTVWXYZ2';
const FP_DIM = '01JABCDEFGHJKMNPQRSTVWXYZ3';

/**
 * A request body; `$over` replaces top-level keys.
 *
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function fpBody(array $over = []): array
{
    return array_replace([
        'source_type' => 'sale',
        'source_id' => FP_SRC,
        'entry_date' => '2026-01-15',
        'description' => 'A sale',
        'branch_id' => null,
        'lines' => [
            ['account_id' => FP_A, 'debit' => '10', 'memo' => 'cash'],
            ['account_id' => FP_B, 'credit' => '10'],
        ],
    ], $over);
}

/**
 * @param  array<string, mixed>  $over
 */
function fpParsed(array $over = [], ?string $actor = null): ParsedPosting
{
    return PostingInput::parse(PostingRequest::fromArray(fpBody($over), $actor));
}

/**
 * @param  array<string, mixed>  $over
 */
function fpPrint(array $over = []): string
{
    return Fingerprint::ofPosting(fpParsed($over));
}

it('is lower-case hex SHA-256 of the canonical JSON of the stored payload', function () {
    $json = '["2026-01-15",null,"A sale",'
        .'[["'.FP_A.'","10.0000","0.0000","cash",null,null,null,null,null,null,null],'
        .'["'.FP_B.'","0.0000","10.0000",null,null,null,null,null,null,null,null]]]';

    expect(fpPrint())->toBe(hash('sha256', $json))
        ->and(fpPrint())->toMatch('/\A[0-9a-f]{64}\z/');
});

it('hashes the dimensions in their fixed order', function () {
    $lines = [
        ['account_id' => FP_A, 'debit' => '1', 'product_id' => FP_DIM, 'branch_id' => FP_DIM],
        ['account_id' => FP_B, 'credit' => '1'],
    ];
    $json = '["2026-01-15",null,"A sale",'
        .'[["'.FP_A.'","1.0000","0.0000",null,"'.FP_DIM.'",null,null,null,null,null,"'.FP_DIM.'"],'
        .'["'.FP_B.'","0.0000","1.0000",null,null,null,null,null,null,null,null]]]';

    expect(fpPrint(['lines' => $lines]))->toBe(hash('sha256', $json));
});

it('writes unicode and slashes unescaped', function () {
    $json = '["2026-01-15",null,"Café €5/7",'
        .'[["'.FP_A.'","10.0000","0.0000","cash",null,null,null,null,null,null,null],'
        .'["'.FP_B.'","0.0000","10.0000",null,null,null,null,null,null,null,null]]]';

    expect(fpPrint(['description' => 'Café €5/7']))->toBe(hash('sha256', $json));
});

it('AC-35, D-30: hashes alike two spellings of one stored payload', function () {
    $a = fpBody(['description' => ' A sale ', 'lines' => [
        ['account_id' => strtolower(FP_A), 'debit' => '10', 'memo' => ' cash '],
        ['account_id' => FP_B, 'credit' => '10.0'],
    ]]);
    $b = fpBody(['lines' => [
        ['account_id' => FP_A, 'debit' => '10.0000', 'memo' => 'cash'],
        ['account_id' => FP_B, 'credit' => '10.00'],
    ]]);

    expect(Fingerprint::ofPosting(PostingInput::parse(PostingRequest::fromArray($a, null))))
        ->toBe(Fingerprint::ofPosting(PostingInput::parse(PostingRequest::fromArray($b, null))));
});

it('AC-35: any difference in the payload is a different fingerprint', function (array $change) {
    expect(fpPrint($change))->not->toBe(fpPrint());
})->with([
    'the entry date' => [['entry_date' => '2026-01-16']],
    'the branch' => [['branch_id' => FP_DIM]],
    'the description' => [['description' => 'A sale.']],
    'no description' => [['description' => null]],
    'a third line' => [['lines' => [
        ['account_id' => FP_A, 'debit' => '10', 'memo' => 'cash'],
        ['account_id' => FP_B, 'credit' => '9'],
        ['account_id' => FP_B, 'credit' => '1'],
    ]]],
    'the line order' => [['lines' => [
        ['account_id' => FP_B, 'credit' => '10'],
        ['account_id' => FP_A, 'debit' => '10', 'memo' => 'cash'],
    ]]],
    'a line account' => [['lines' => [
        ['account_id' => FP_B, 'debit' => '10', 'memo' => 'cash'],
        ['account_id' => FP_B, 'credit' => '10'],
    ]]],
    'a value by one unit in the last place' => [['lines' => [
        ['account_id' => FP_A, 'debit' => '10.0001', 'memo' => 'cash'],
        ['account_id' => FP_B, 'credit' => '10.0001'],
    ]]],
    'the side of a line' => [['lines' => [
        ['account_id' => FP_A, 'credit' => '10', 'memo' => 'cash'],
        ['account_id' => FP_B, 'debit' => '10'],
    ]]],
    'a memo' => [['lines' => [
        ['account_id' => FP_A, 'debit' => '10', 'memo' => 'Cash'],
        ['account_id' => FP_B, 'credit' => '10'],
    ]]],
    'a dimension' => [['lines' => [
        ['account_id' => FP_A, 'debit' => '10', 'memo' => 'cash', 'customer_id' => FP_DIM],
        ['account_id' => FP_B, 'credit' => '10'],
    ]]],
    'the same id in a different dimension' => [['lines' => [
        ['account_id' => FP_A, 'debit' => '10', 'memo' => 'cash', 'supplier_id' => FP_DIM],
        ['account_id' => FP_B, 'credit' => '10'],
    ]]],
]);

it('AC-36: leaves the actor and the source key out', function () {
    $base = fpPrint();

    expect(Fingerprint::ofPosting(fpParsed([], 'someone-else')))->toBe($base)
        ->and(fpPrint(['source_id' => FP_DIM]))->toBe($base)
        ->and(fpPrint(['source_type' => 'expense']))->toBe($base)
        ->and(fpPrint(['source_purpose' => 'revenue']))->toBe($base);
});

it('keeps the source type typed through parsing', function () {
    expect(fpParsed()->sourceType)->toBe(JournalSourceType::Sale);
});

it('fingerprints a reversal as the date and the trimmed reason', function () {
    $parsed = ReversalInput::parse(ReversalRequest::fromArray([
        'entry_id' => FP_A,
        'entry_date' => '2026-02-01',
        'reason' => '  Wrong account  ',
    ], null));

    expect(Fingerprint::ofReversal($parsed->entryDate, $parsed->reason))
        ->toBe(hash('sha256', '["2026-02-01","Wrong account"]'))
        ->and(Fingerprint::ofReversal('2026-02-02', 'Wrong account'))
        ->not->toBe(Fingerprint::ofReversal('2026-02-01', 'Wrong account'))
        ->and(Fingerprint::ofReversal('2026-02-01', 'Other'))
        ->not->toBe(Fingerprint::ofReversal('2026-02-01', 'Wrong account'));
});
