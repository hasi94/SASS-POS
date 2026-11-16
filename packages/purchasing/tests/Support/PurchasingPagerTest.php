<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Support;

use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Support\Pager;
use Illuminate\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use PDO;

/*
|--------------------------------------------------------------------------
| T-034 step 6 - Pager (AC-16, AC-35, AC-82, D-34)
|--------------------------------------------------------------------------
|
| Validate first, clamp second: an oversize `page_size` is clamped to the
| configured maximum, a non-integer or non-positive one is `validation`, a
| malformed cursor is `validation`. A throwaway in-memory table is enough: the
| pager reads a builder, not a purchasing table.
*/

function puPagerConfig(int $default = 25, int $max = 100): Repository
{
    return new Repository(['purchasing' => ['page_size' => $default, 'max_page_size' => $max]]);
}

/**
 * An in-memory table of `count` rows with ascending codes C001.. and ascending ids.
 */
function puPagerConnection(int $count): Connection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));

    $connection->getSchemaBuilder()->create('rows', function (Blueprint $table): void {
        $table->string('id', 26)->primary();
        $table->string('code', 30);
    });

    for ($i = 1; $i <= $count; $i++) {
        $connection->table('rows')->insert([
            'id' => sprintf('01J%023d', $i),
            'code' => sprintf('C%03d', $i),
        ]);
    }

    return $connection;
}

function puPagerRefusal(callable $call): PurchasingRefused
{
    try {
        $call();
    } catch (PurchasingRefused $refused) {
        return $refused;
    }

    throw new \LogicException('The call was expected to refuse and did not.');
}

it('clamps an oversize page_size to the maximum and never refuses it (D-34)', function () {
    expect(Pager::size(puPagerConfig(), 101))->toBe(100)
        ->and(Pager::size(puPagerConfig(), 100))->toBe(100)
        ->and(Pager::size(puPagerConfig(), 5000))->toBe(100)
        ->and(Pager::size(puPagerConfig(), 7))->toBe(7)
        ->and(Pager::size(puPagerConfig(), 1))->toBe(1);
});

it('uses the configured default when no page_size is sent, and the configured maximum, not a literal', function () {
    expect(Pager::size(puPagerConfig(), null))->toBe(25)
        ->and(Pager::size(puPagerConfig(10, 40), null))->toBe(10)
        ->and(Pager::size(puPagerConfig(10, 40), 41))->toBe(40);
});

it('refuses a non-positive or non-integer page_size as validation, where the kernel pager would use the default', function (mixed $bad) {
    $refused = puPagerRefusal(fn () => Pager::size(puPagerConfig(), $bad));

    expect($refused->code())->toBe('validation');
})->with([
    'zero' => [0],
    'minus one' => [-1],
    'a string of letters' => ['x'],
    'a numeric string' => ['10'],
    'a float' => [10.0],
    'a boolean' => [true],
]);

it('pages documents newest first by id, with the last id of a full page as the cursor', function () {
    $connection = puPagerConnection(5);
    $config = puPagerConfig(2, 100);

    $first = Pager::page($connection->table('rows'), $config, null, null);
    $codes = fn (array $rows): array => array_column($rows, 'code');

    expect($codes($first->rows))->toBe(['C005', 'C004'])
        ->and($first->nextCursor)->toBe(sprintf('01J%023d', 4));

    $second = Pager::page($connection->table('rows'), $config, null, $first->nextCursor);
    expect($codes($second->rows))->toBe(['C003', 'C002']);

    $third = Pager::page($connection->table('rows'), $config, null, $second->nextCursor);
    expect($codes($third->rows))->toBe(['C001'])->and($third->nextCursor)->toBeNull();
});

it('returns a full last page with no cursor, and an empty page past the end without an error', function () {
    $connection = puPagerConnection(2);

    $exact = Pager::page($connection->table('rows'), puPagerConfig(2), null, null);
    $past = Pager::page($connection->table('rows'), puPagerConfig(2), null, sprintf('01J%023d', 1));

    expect($exact->rows)->toHaveCount(2)->and($exact->nextCursor)->toBeNull()
        ->and(array_column($past->rows, 'code'))->toBe([])
        ->and($past->nextCursor)->toBeNull();

    $none = Pager::page($connection->table('rows')->where('code', 'NOPE'), puPagerConfig(), null, null);
    expect($none->rows)->toBe([])->and($none->nextCursor)->toBeNull();
});

it('refuses a malformed before_id as validation', function (mixed $bad) {
    $refused = puPagerRefusal(fn () => Pager::page(puPagerConnection(1)->table('rows'), puPagerConfig(), null, $bad));

    expect($refused->code())->toBe('validation');
})->with(['text' => ['x'], 'too short' => ['01J'], 'an int' => [5]]);

it('pages suppliers ascending by code with after_code, the last code of a full page as the cursor', function () {
    $connection = puPagerConnection(5);
    $config = puPagerConfig(2, 100);

    $first = Pager::pageByCode($connection->table('rows'), $config, null, null);
    expect(array_column($first->rows, 'code'))->toBe(['C001', 'C002'])->and($first->nextCursor)->toBe('C002');

    $second = Pager::pageByCode($connection->table('rows'), $config, null, $first->nextCursor);
    expect(array_column($second->rows, 'code'))->toBe(['C003', 'C004'])->and($second->nextCursor)->toBe('C004');

    $third = Pager::pageByCode($connection->table('rows'), $config, null, $second->nextCursor);
    expect(array_column($third->rows, 'code'))->toBe(['C005'])->and($third->nextCursor)->toBeNull();
});

it('folds a lower-case after_code, and a cursor past the end is an empty page, not an error', function () {
    $connection = puPagerConnection(3);

    $folded = Pager::pageByCode($connection->table('rows'), puPagerConfig(), null, 'c002');
    $past = Pager::pageByCode($connection->table('rows'), puPagerConfig(), null, 'ZZZ');

    expect(array_column($folded->rows, 'code'))->toBe(['C003'])
        ->and($past->rows)->toBe([])
        ->and($past->nextCursor)->toBeNull();
});

it('refuses a malformed after_code and a bad page_size on the supplier pager as validation', function (mixed $afterCode, mixed $pageSize) {
    $refused = puPagerRefusal(fn () => Pager::pageByCode(puPagerConnection(1)->table('rows'), puPagerConfig(), $pageSize, $afterCode));

    expect($refused->code())->toBe('validation');
})->with([
    'an empty cursor' => ['', null],
    'a cursor with a space' => ['A B', null],
    'a cursor starting with a dot' => ['.A', null],
    'a 31 character cursor' => [str_repeat('A', 31), null],
    'an int cursor' => [5, null],
    'a zero page size' => [null, 0],
    'a letter page size' => [null, 'x'],
]);

it('clamps a 101-row request to 100 rows on a real page', function () {
    $connection = puPagerConnection(120);

    $page = Pager::pageByCode($connection->table('rows'), puPagerConfig(), 101, null);

    expect($page->rows)->toHaveCount(100)->and($page->nextCursor)->toBe('C100');
});
