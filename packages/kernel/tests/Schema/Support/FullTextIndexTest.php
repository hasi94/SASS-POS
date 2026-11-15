<?php

use Flexipos\Kernel\Schema\Support\FullTextIndex;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

/**
 * T-027 plan step 2: the `FullTextIndex` helper, ahead of its first real
 * caller (AC-11's note on `products.name`/`short_name`/`sku`).
 */
it('builds the exact statement text, named after the table and every column', function () {
    expect(FullTextIndex::statement('products', ['name', 'short_name', 'sku']))
        ->toBe('ALTER TABLE `products` ADD FULLTEXT `products_name_short_name_sku_fulltext` (`name`, `short_name`, `sku`)');
});

it('is a no-op on SQLite, the suite`s own driver', function () {
    expect(Schema::getConnection()->getDriverName())->toBe('sqlite');

    FullTextIndex::add('products', ['name', 'short_name', 'sku']);
});

it('mutation: removing the driver guard makes the SQLite no-op case throw', function () {
    expect(Schema::getConnection()->getDriverName())->toBe('sqlite');

    // SQLite's own grammar throws on a fullText index; this is what the
    // driver guard exists to prevent.
    expect(fn () => Schema::getConnection()->statement(FullTextIndex::statement('products', ['name', 'short_name', 'sku'])))
        ->toThrow(Exception::class);
});

it('refuses a table or column name that is not [a-z_]+', function () {
    expect(fn () => FullTextIndex::statement('Products', ['name']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => FullTextIndex::statement('products', ['short-name']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => FullTextIndex::statement('products', ['name', 'sku); drop table products']))->toThrow(InvalidArgumentException::class);
});
