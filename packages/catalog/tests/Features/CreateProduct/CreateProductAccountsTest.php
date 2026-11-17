<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\CreateProduct;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\CreateProduct\CreateProduct;
use Flexipos\Catalog\Features\CreateProduct\CreateProductHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Contracts\Finance\AccountRefusal;
use Flexipos\Contracts\Finance\AccountRefused;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 18 - the three account columns on a created product
| (AC-55, AC-56, AC-59)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-16 12:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @param  array<string, mixed>  $extra
 */
function cpaCreate(array $extra): string
{
    /** @var CreateProductHandler $handler */
    $handler = app(CreateProductHandler::class);

    // One category and one unit per test, however many products it creates.
    $connection = CatalogSeed::connection();
    $category = $connection->table('categories')->value('id') ?? CatalogSeed::category('drinks');
    $unit = $connection->table('units')->value('id') ?? CatalogSeed::unit('each');

    return $handler->handle(new CreateProduct([
        'type' => 'simple',
        'sku' => 'sku-'.Str::lower((string) Str::ulid()),
        'name' => 'Cola',
        'category_id' => (string) $category,
        'stock_unit_id' => (string) $unit,
    ] + $extra))->productId;
}

/**
 * @return array<string, mixed>
 */
function cpaRow(string $productId): array
{
    return (array) CatalogSeed::connection()->table('products')->where('id', $productId)->first();
}

it('writes null in all three account columns when none is named (AC-52, AC-59)', function () {
    $row = cpaRow(cpaCreate([]));

    expect($row['income_account_id'])->toBeNull()
        ->and($row['cogs_account_id'])->toBeNull()
        ->and($row['inventory_account_id'])->toBeNull();
});

it('stores the three accounts named, folded to upper case, and the audit row carries them (AC-55, AC-56, AC-59)', function () {
    $accounts = [
        'income_account_id' => CatalogSeed::account('CPA-INC', 'revenue', 'sales'),
        'cogs_account_id' => CatalogSeed::account('CPA-COGS', 'expense', 'cogs'),
        'inventory_account_id' => CatalogSeed::account('CPA-INV', 'asset', 'inventory'),
    ];

    $id = cpaCreate(['income_account_id' => strtolower($accounts['income_account_id'])] + $accounts);

    expect(cpaRow($id))->toMatchArray($accounts);

    expect(CatalogSeed::onlyAuditRow()['after'])->toMatchArray($accounts);
});

it('accepts an explicit null for an account field without asking Finance', function () {
    expect(cpaRow(cpaCreate(['income_account_id' => null]))['income_account_id'])->toBeNull();
});

it('refuses a wrong-kind, dead, inactive, header or missing account naming the field, and creates nothing (AC-55, AC-56)', function () {
    $cases = [
        [AccountRefusal::AccountKindMismatch, 'cogs_account_id', CatalogSeed::account('CPA-A', 'revenue', 'sales')],
        [AccountRefusal::AccountNotLive, 'income_account_id', CatalogSeed::account('CPA-B', 'revenue', 'sales', ['deleted_at' => CatalogSeed::NOW])],
        [AccountRefusal::AccountInactive, 'inventory_account_id', CatalogSeed::account('CPA-C', 'asset', 'inventory', ['is_active' => false])],
        [AccountRefusal::AccountNotPostable, 'income_account_id', CatalogSeed::account('CPA-D', 'revenue', null, ['is_postable' => false])],
        [AccountRefusal::AccountNotFound, 'cogs_account_id', (string) Str::ulid()],
    ];

    foreach ($cases as [$reason, $field, $accountId]) {
        try {
            cpaCreate([$field => $accountId]);
            Assert::fail('Expected a Finance refusal.');
        } catch (AccountRefused $refused) {
            expect($refused->reason)->toBe($reason)->and($refused->field)->toBe($field);
        }
    }

    expect(CatalogSeed::connection()->table('products')->count())->toBe(0)
        ->and(CatalogSeed::audit())->toBe([]);
});

it('refuses a malformed account id with the catalog validation naming the field (AC-55)', function (string $field) {
    $refused = CatalogRefusals::capture(fn () => cpaCreate([$field => 'not-an-id']));

    expect($refused->reason)->toBe(CatalogRefusal::Validation)->and($refused->getMessage())->toContain($field);
})->with(['income_account_id', 'cogs_account_id', 'inventory_account_id']);
