<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\ListSuppliers;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\ListSuppliers\ListSuppliers;
use Flexipos\Purchasing\Features\ListSuppliers\ListSuppliersHandler;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Page;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 11 - ListSuppliers (AC-16, AC-18, D-34, D-36)
|--------------------------------------------------------------------------
|
| Keyset by code with `after_code`; an oversize page is clamped to 100; a
| non-positive or malformed size or cursor is validation; the two filters combine;
| a deleted supplier never appears; no row carries bank details.
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/purchasing-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    puEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function puListSuppliers(?string $actorId, mixed $isActive = null, mixed $q = null, mixed $pageSize = null, mixed $afterCode = null): Page
{
    /** @var ListSuppliersHandler $handler */
    $handler = app(ListSuppliersHandler::class);

    return $handler->handle(new ListSuppliers($actorId, $isActive, $q, $pageSize, $afterCode));
}

function puLister(): string
{
    return puActor('lister', [PurchasingPermissions::SUPPLIERS_VIEW]);
}

/**
 * `$count` suppliers, codes `L001` upwards, written in one statement.
 */
function puSeedSuppliers(int $count): void
{
    $rows = [];

    for ($i = 1; $i <= $count; $i++) {
        $rows[] = [
            'id' => (string) Str::ulid(),
            'created_at' => PurchasingSeed::NOW,
            'updated_at' => PurchasingSeed::NOW,
            'code' => 'L'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'name' => 'Supplier '.$i,
            'payment_terms_days' => 0,
            'credit_limit' => '0.0000',
            'is_active' => true,
        ];
    }

    PurchasingSeed::connection()->table('suppliers')->insert($rows);
}

/**
 * @return list<string>
 */
function puCodes(Page $page): array
{
    return array_map(static fn (array $row): string => (string) $row['code'], $page->rows);
}

it('AC-16: lists live suppliers ordered by code, 25 a page by default, and walks the cursor to the end', function () {
    puSeedSuppliers(60);
    $actor = puLister();

    $first = puListSuppliers($actor);
    $second = puListSuppliers($actor, afterCode: $first->nextCursor);
    $third = puListSuppliers($actor, afterCode: $second->nextCursor);

    expect($first->rows)->toHaveCount(25)
        ->and(puCodes($first)[0])->toBe('L001')
        ->and($first->nextCursor)->toBe('L025')
        ->and(puCodes($second)[0])->toBe('L026')
        ->and($second->rows)->toHaveCount(25)
        ->and($third->rows)->toHaveCount(10)
        ->and($third->nextCursor)->toBeNull()
        ->and(array_merge(puCodes($first), puCodes($second), puCodes($third)))->toBe(array_map(
            static fn (int $i): string => 'L'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            range(1, 60),
        ));
});

it('AC-16: an after_code in any case continues after that code', function () {
    puSeedSuppliers(5);

    expect(puCodes(puListSuppliers(puLister(), afterCode: 'l002')))->toBe(['L003', 'L004', 'L005']);
});

it('AC-16: a page_size of 101 is clamped to 100, never refused; 100, 1 and a smaller one are honoured', function () {
    puSeedSuppliers(105);
    $actor = puLister();

    $oversize = puListSuppliers($actor, pageSize: 101);
    $huge = puListSuppliers($actor, pageSize: 100000);
    $exact = puListSuppliers($actor, pageSize: 100);
    $one = puListSuppliers($actor, pageSize: 1);
    $ten = puListSuppliers($actor, pageSize: 10);

    expect($oversize->rows)->toHaveCount(100)
        ->and($oversize->nextCursor)->toBe('L100')
        ->and($huge->rows)->toHaveCount(100)
        ->and($exact->rows)->toHaveCount(100)
        ->and($one->rows)->toHaveCount(1)
        ->and($ten->rows)->toHaveCount(10);
});

it('AC-16: a non-positive or non-integer page_size and a malformed cursor are validation, and an unknown filter value too', function (string $field, mixed $value) {
    puSeedSuppliers(3);
    $actor = puLister();

    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => match ($field) {
        'page_size' => puListSuppliers($actor, pageSize: $value),
        'after_code' => puListSuppliers($actor, afterCode: $value),
        'is_active' => puListSuppliers($actor, isActive: $value),
        default => puListSuppliers($actor, q: $value),
    }, $field);
})->with([
    'page_size 0' => ['page_size', 0],
    'page_size -1' => ['page_size', -1],
    'page_size "x"' => ['page_size', 'x'],
    'page_size "10"' => ['page_size', '10'],
    'page_size 2.5' => ['page_size', 2.5],
    'after_code with a space' => ['after_code', 'bad code'],
    'after_code empty' => ['after_code', ''],
    'after_code an int' => ['after_code', 5],
    'after_code 31 characters' => ['after_code', str_repeat('a', 31)],
    'is_active "yes"' => ['is_active', 'yes'],
    'is_active 1' => ['is_active', 1],
    'q an array' => ['q', ['a']],
    'q over 150' => ['q', str_repeat('a', 151)],
]);

it('AC-16: a cursor past the end, and a filter that matches nothing, are an empty page, not an error', function () {
    puSeedSuppliers(3);
    $actor = puLister();

    $past = puListSuppliers($actor, afterCode: 'ZZZ');
    $none = puListSuppliers($actor, q: 'no such supplier');

    expect($past->rows)->toBe([])
        ->and($past->nextCursor)->toBeNull()
        ->and($none->rows)->toBe([])
        ->and($none->nextCursor)->toBeNull();
});

it('AC-16: is_active and q filter, q is a case-insensitive contains match on code or name, and the filters combine', function () {
    PurchasingSeed::supplier('A', ['code' => 'ACME-1', 'name' => 'Acme Foods']);
    PurchasingSeed::supplier('B', ['code' => 'ACME-2', 'name' => 'Zed Traders', 'is_active' => false]);
    PurchasingSeed::supplier('C', ['code' => 'BETA', 'name' => 'Beta Acme Supplies']);
    PurchasingSeed::supplier('D', ['code' => 'DELTA', 'name' => 'Delta']);
    $actor = puLister();

    expect(puCodes(puListSuppliers($actor, q: 'acme')))->toBe(['ACME-1', 'ACME-2', 'BETA'])
        ->and(puCodes(puListSuppliers($actor, q: 'ACME-')))->toBe(['ACME-1', 'ACME-2'])
        ->and(puCodes(puListSuppliers($actor, q: '  traders ')))->toBe(['ACME-2'])
        ->and(puCodes(puListSuppliers($actor, isActive: false)))->toBe(['ACME-2'])
        ->and(puCodes(puListSuppliers($actor, isActive: true)))->toBe(['ACME-1', 'BETA', 'DELTA'])
        ->and(puCodes(puListSuppliers($actor, isActive: true, q: 'acme')))->toBe(['ACME-1', 'BETA'])
        ->and(puCodes(puListSuppliers($actor, isActive: true, q: 'acme', pageSize: 1)))->toBe(['ACME-1'])
        ->and(puCodes(puListSuppliers($actor, q: '', pageSize: 100)))->toHaveCount(4);
});

it('AC-16: the wildcard characters in q are literal', function () {
    PurchasingSeed::supplier('A', ['code' => 'P1', 'name' => '100% Cotton']);
    PurchasingSeed::supplier('B', ['code' => 'P2', 'name' => 'Plain']);
    PurchasingSeed::supplier('C', ['code' => 'P3', 'name' => 'Under_score']);
    $actor = puLister();

    expect(puCodes(puListSuppliers($actor, q: '%')))->toBe(['P1'])
        ->and(puCodes(puListSuppliers($actor, q: '_')))->toBe(['P3'])
        ->and(puCodes(puListSuppliers($actor, q: '!')))->toBe([]);
});

it('AC-16: a soft-deleted supplier never appears, with or without a filter', function () {
    PurchasingSeed::supplier('A', ['code' => 'LIVE']);
    PurchasingSeed::supplier('B', ['code' => 'GONE', 'deleted_at' => PurchasingSeed::NOW]);
    PurchasingSeed::supplier('C', ['code' => 'GONE2', 'deleted_at' => PurchasingSeed::NOW, 'is_active' => false]);
    $actor = puLister();

    expect(puCodes(puListSuppliers($actor)))->toBe(['LIVE'])
        ->and(puCodes(puListSuppliers($actor, q: 'gone')))->toBe([])
        ->and(puCodes(puListSuppliers($actor, isActive: false)))->toBe([]);
});

it('AC-16: a row is the canonical shape and never carries bank details, whatever the reader holds', function () {
    PurchasingSeed::supplier('A', ['code' => 'ROW', 'credit_limit' => '12.5', 'bank_details' => '{"account":"42"}', 'address' => '{"city":"Kandy"}']);
    $manager = puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]);

    $row = puListSuppliers($manager)->rows[0];

    expect(array_key_exists('bank_details', $row))->toBeFalse()
        ->and($row['credit_limit'])->toBe('12.5000')
        ->and($row['address'])->toBe(['city' => 'Kandy'])
        ->and($row['is_active'])->toBeTrue();
});

it('AC-18: either supplier key may list, and a caller with neither is refused with the resolver s reason', function () {
    PurchasingSeed::supplier('A', ['code' => 'ONE']);

    expect(puCodes(puListSuppliers(puActor('viewer', [PurchasingPermissions::SUPPLIERS_VIEW]))))->toBe(['ONE'])
        ->and(puCodes(puListSuppliers(puActor('mgr', [PurchasingPermissions::SUPPLIERS_MANAGE]))))->toBe(['ONE']);

    $limited = puActor('limited', [PurchasingPermissions::ORDERS_VIEW]);
    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puListSuppliers($limited));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);

    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puListSuppliers(null));
});
