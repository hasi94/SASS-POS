<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\CreateCategory;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\CreateCategory\CreateCategory;
use Flexipos\Catalog\Features\CreateCategory\CreateCategoryHandler;
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
| T-032 plan step 18 - the income account on a created category
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
function ccaCreate(array $extra): string
{
    /** @var CreateCategoryHandler $handler */
    $handler = app(CreateCategoryHandler::class);

    return $handler->handle(new CreateCategory(['code' => 'c-'.Str::lower((string) Str::ulid()), 'name' => 'Drinks'] + $extra))->categoryId;
}

function ccaIncome(string $categoryId): ?string
{
    $value = CatalogSeed::connection()->table('categories')->where('id', $categoryId)->value('income_account_id');

    return $value === null ? null : (string) $value;
}

it('writes null when no income account is named, and stores a named one folded to upper case (AC-52, AC-55, AC-59)', function () {
    expect(ccaIncome(ccaCreate([])))->toBeNull();

    $account = CatalogSeed::account('CCA-INC', 'revenue', 'sales');

    $id = ccaCreate(['income_account_id' => strtolower($account)]);

    expect(ccaIncome($id))->toBe($account);
});

it('refuses a missing, dead, inactive, header or wrong-kind income account naming the field, and creates nothing (AC-55, AC-56)', function () {
    $cases = [
        [AccountRefusal::AccountNotFound, (string) Str::ulid()],
        [AccountRefusal::AccountNotLive, CatalogSeed::account('CCA-A', 'revenue', 'sales', ['deleted_at' => CatalogSeed::NOW])],
        [AccountRefusal::AccountInactive, CatalogSeed::account('CCA-B', 'revenue', 'sales', ['is_active' => false])],
        [AccountRefusal::AccountNotPostable, CatalogSeed::account('CCA-C', 'revenue', null, ['is_postable' => false])],
        [AccountRefusal::AccountKindMismatch, CatalogSeed::account('CCA-D', 'expense', 'cogs')],
    ];

    foreach ($cases as [$reason, $accountId]) {
        try {
            ccaCreate(['income_account_id' => $accountId]);
            Assert::fail('Expected a Finance refusal.');
        } catch (AccountRefused $refused) {
            expect($refused->reason)->toBe($reason)->and($refused->field)->toBe('income_account_id');
        }
    }

    expect(CatalogSeed::connection()->table('categories')->count())->toBe(0)
        ->and(CatalogSeed::audit())->toBe([]);
});

it('refuses a malformed income account id with the catalog validation naming the field (AC-55)', function () {
    $refused = CatalogRefusals::capture(fn () => ccaCreate(['income_account_id' => 'not-an-id']));

    expect($refused->reason)->toBe(CatalogRefusal::Validation)->and($refused->getMessage())->toContain('income_account_id');
});

it('refuses a cogs account on a category as an unknown field (AC-52)', function () {
    $refused = CatalogRefusals::capture(fn () => ccaCreate(['cogs_account_id' => (string) Str::ulid()]));

    expect($refused->reason)->toBe(CatalogRefusal::Validation);
});
