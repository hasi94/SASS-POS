<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\UpdateProduct;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\UpdateProduct\UpdateProduct;
use Flexipos\Catalog\Features\UpdateProduct\UpdateProductHandler;
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
| T-032 plan step 18 - the three account columns on an updated product
| (AC-55, AC-56, AC-58 with F-3, AC-59)
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
 * @param  array<string, mixed>  $fields
 */
function upaUpdate(string $productId, array $fields): void
{
    /** @var UpdateProductHandler $handler */
    $handler = app(UpdateProductHandler::class);

    $handler->handle(new UpdateProduct($productId, $fields));
}

function upaProduct(): string
{
    return CatalogSeed::product('sku1', CatalogSeed::category('drinks'), CatalogSeed::unit('each'));
}

/**
 * @return array<string, mixed>
 */
function upaRow(string $productId): array
{
    return (array) CatalogSeed::connection()->table('products')->where('id', $productId)->first();
}

/**
 * The account refusal an operation throws, failing when it succeeds.
 */
function upaRefusal(callable $operation): AccountRefused
{
    try {
        $operation();
    } catch (AccountRefused $refused) {
        return $refused;
    }

    Assert::fail('The operation was expected to be refused by Finance and succeeded.');
}

/**
 * One account per field, each of the right kind.
 *
 * @return array<string, string>
 */
function upaGoodAccounts(): array
{
    return [
        'income_account_id' => CatalogSeed::account('UPA-INC', 'revenue', 'sales'),
        'cogs_account_id' => CatalogSeed::account('UPA-COGS', 'expense', 'cogs'),
        'inventory_account_id' => CatalogSeed::account('UPA-INV', 'asset', 'inventory'),
    ];
}

it('assigns an account of the right kind to each of the three fields, folds a lower-case id, and reads it back (AC-55, AC-56, AC-59)', function () {
    $id = upaProduct();
    $accounts = upaGoodAccounts();

    upaUpdate($id, [
        'income_account_id' => strtolower($accounts['income_account_id']),
        'cogs_account_id' => $accounts['cogs_account_id'],
        'inventory_account_id' => $accounts['inventory_account_id'],
    ]);

    expect(upaRow($id))->toMatchArray($accounts);
});

it('clears a field with null without asking Finance, and leaves an omitted field unchanged (AC-55)', function () {
    $id = upaProduct();
    $accounts = upaGoodAccounts();

    upaUpdate($id, $accounts);

    // The income account is deleted behind the product's back: clearing it still works, since null is not read.
    CatalogSeed::connection()->table('accounts')->where('id', $accounts['income_account_id'])->update(['deleted_at' => CatalogSeed::NOW]);

    upaUpdate($id, ['income_account_id' => null, 'name' => 'Renamed']);

    expect(upaRow($id))->toMatchArray([
        'income_account_id' => null,
        'cogs_account_id' => $accounts['cogs_account_id'],
        'inventory_account_id' => $accounts['inventory_account_id'],
        'name' => 'Renamed',
    ]);
});

it('does not re-read an account whose value is unchanged, so an account deactivated since cannot block an unrelated edit (AC-55)', function () {
    $id = upaProduct();
    $accounts = upaGoodAccounts();

    upaUpdate($id, $accounts);

    CatalogSeed::connection()->table('accounts')->where('id', $accounts['cogs_account_id'])->update(['is_active' => false]);

    upaUpdate($id, ['cogs_account_id' => $accounts['cogs_account_id'], 'name' => 'Same account']);

    expect(upaRow($id))->toMatchArray(['cogs_account_id' => $accounts['cogs_account_id'], 'name' => 'Same account']);

    // Naming a different inactive account is still refused.
    $other = CatalogSeed::account('UPA-OTH', 'expense', 'cogs', ['is_active' => false]);

    expect(upaRefusal(fn () => upaUpdate($id, ['cogs_account_id' => $other]))->reason)->toBe(AccountRefusal::AccountInactive);
});

it('refuses each of the five reasons on each field, naming the field, and writes nothing (AC-55, AC-56)', function (string $field, string $type, ?string $subtype) {
    $id = upaProduct();
    $before = upaRow($id);

    $wrong = $field === 'income_account_id' ? CatalogSeed::account('WRONG', 'asset', 'cash') : CatalogSeed::account('WRONG', 'revenue', 'sales');
    $cases = [
        [AccountRefusal::AccountNotFound, (string) Str::ulid()],
        [AccountRefusal::AccountNotLive, CatalogSeed::account('DEAD', $type, $subtype, ['deleted_at' => CatalogSeed::NOW])],
        [AccountRefusal::AccountInactive, CatalogSeed::account('OFF', $type, $subtype, ['is_active' => false])],
        [AccountRefusal::AccountNotPostable, CatalogSeed::account('HDR', $type, $subtype, ['is_postable' => false])],
        [AccountRefusal::AccountKindMismatch, $wrong],
    ];

    foreach ($cases as [$reason, $accountId]) {
        $refused = upaRefusal(fn () => upaUpdate($id, ['name' => 'Changed', $field => $accountId]));

        expect($refused->reason)->toBe($reason)
            ->and($refused->field)->toBe($field);
    }

    expect(upaRow($id))->toBe($before)
        ->and(CatalogSeed::audit())->toBe([]);
})->with([
    'income' => ['income_account_id', 'revenue', 'sales'],
    'cogs' => ['cogs_account_id', 'expense', 'cogs'],
    'inventory' => ['inventory_account_id', 'asset', 'inventory'],
]);

it('refuses a malformed id with the catalog validation naming the field, before Finance is asked (AC-55)', function (string $field) {
    $id = upaProduct();
    $before = upaRow($id);

    foreach (['nope', 123, ['x'], str_repeat('Z', 26), ''] as $bad) {
        $refused = CatalogRefusals::capture(fn () => upaUpdate($id, [$field => $bad]));

        expect($refused->reason)->toBe(CatalogRefusal::Validation)
            ->and($refused->getMessage())->toContain($field);
    }

    expect(upaRow($id))->toBe($before);
})->with(['income_account_id', 'cogs_account_id', 'inventory_account_id']);

it('refuses an account update on a soft-deleted product as not found, and a name update too, writing nothing (AC-58, F-3)', function () {
    $id = upaProduct();
    $accounts = upaGoodAccounts();

    CatalogSeed::connection()->table('products')->where('id', $id)->update(['deleted_at' => CatalogSeed::NOW]);

    $before = upaRow($id);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => upaUpdate($id, ['income_account_id' => $accounts['income_account_id']]), 'account update');
    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => upaUpdate($id, ['name' => 'Revived']), 'name update');

    expect(upaRow($id))->toBe($before)
        ->and(CatalogSeed::audit())->toBe([]);
});

it('writes the account ids into the one audit row, before and after (AC-59)', function () {
    $id = upaProduct();
    $accounts = upaGoodAccounts();

    upaUpdate($id, ['income_account_id' => $accounts['income_account_id']]);

    $audit = CatalogSeed::onlyAuditRow();

    expect($audit['before']['income_account_id'])->toBeNull()
        ->and($audit['after']['income_account_id'])->toBe($accounts['income_account_id']);
});
