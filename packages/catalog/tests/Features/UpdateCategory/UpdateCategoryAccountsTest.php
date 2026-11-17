<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\UpdateCategory;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\UpdateCategory\UpdateCategory;
use Flexipos\Catalog\Features\UpdateCategory\UpdateCategoryHandler;
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
| T-032 plan step 18 - the income account on an updated category
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
function ucaUpdate(string $categoryId, array $fields): void
{
    /** @var UpdateCategoryHandler $handler */
    $handler = app(UpdateCategoryHandler::class);

    $handler->handle(new UpdateCategory($categoryId, $fields));
}

/**
 * @return array<string, mixed>
 */
function ucaRow(string $categoryId): array
{
    return (array) CatalogSeed::connection()->table('categories')->where('id', $categoryId)->first();
}

it('assigns an income account, folding a lower-case id, clears it with null, and leaves it unchanged when omitted (AC-55, AC-59)', function () {
    $id = CatalogSeed::category('drinks');
    $account = CatalogSeed::account('UCA-INC', 'revenue', 'sales');

    ucaUpdate($id, ['income_account_id' => strtolower($account)]);

    expect(ucaRow($id)['income_account_id'])->toBe($account);

    ucaUpdate($id, ['name' => 'Beverages']);

    expect(ucaRow($id)['income_account_id'])->toBe($account);

    // Clearing is not a read: the account may have been deleted since.
    CatalogSeed::connection()->table('accounts')->where('id', $account)->update(['deleted_at' => CatalogSeed::NOW]);

    ucaUpdate($id, ['income_account_id' => null]);

    expect(ucaRow($id)['income_account_id'])->toBeNull();
});

it('refuses each of the five reasons naming the field, and writes nothing (AC-55, AC-56)', function () {
    $id = CatalogSeed::category('drinks');
    $before = ucaRow($id);

    $cases = [
        [AccountRefusal::AccountNotFound, (string) Str::ulid()],
        [AccountRefusal::AccountNotLive, CatalogSeed::account('UCA-A', 'revenue', 'sales', ['deleted_at' => CatalogSeed::NOW])],
        [AccountRefusal::AccountInactive, CatalogSeed::account('UCA-B', 'revenue', 'sales', ['is_active' => false])],
        [AccountRefusal::AccountNotPostable, CatalogSeed::account('UCA-C', 'revenue', null, ['is_postable' => false])],
        [AccountRefusal::AccountKindMismatch, CatalogSeed::account('UCA-D', 'asset', 'cash')],
    ];

    foreach ($cases as [$reason, $accountId]) {
        try {
            ucaUpdate($id, ['name' => 'Changed', 'income_account_id' => $accountId]);
            Assert::fail('Expected a Finance refusal.');
        } catch (AccountRefused $refused) {
            expect($refused->reason)->toBe($reason)->and($refused->field)->toBe('income_account_id');
        }
    }

    expect(ucaRow($id))->toBe($before)->and(CatalogSeed::audit())->toBe([]);
});

it('refuses a malformed income account id with the catalog validation naming the field (AC-55)', function () {
    $id = CatalogSeed::category('drinks');

    $refused = CatalogRefusals::capture(fn () => ucaUpdate($id, ['income_account_id' => 'not-an-id']));

    expect($refused->reason)->toBe(CatalogRefusal::Validation)->and($refused->getMessage())->toContain('income_account_id');
});

it('refuses an account update and a name update on a soft-deleted category as not found, writing nothing (AC-58, F-3)', function () {
    $id = CatalogSeed::category('drinks');
    $account = CatalogSeed::account('UCA-OK', 'revenue', 'sales');

    CatalogSeed::connection()->table('categories')->where('id', $id)->update(['deleted_at' => CatalogSeed::NOW]);

    $before = ucaRow($id);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => ucaUpdate($id, ['income_account_id' => $account]), 'account update');
    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => ucaUpdate($id, ['name' => 'Revived']), 'name update');

    expect(ucaRow($id))->toBe($before)->and(CatalogSeed::audit())->toBe([]);
});
