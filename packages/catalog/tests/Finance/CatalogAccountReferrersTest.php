<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Finance;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\CatalogServiceProvider;
use Flexipos\Catalog\Finance\CatalogAccountReferrers;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Contracts\Finance\AccountReferrer;
use Flexipos\Contracts\Finance\DeclaresAccountReferrers;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 18 - what catalog tells Finance about an account
| (AC-23, AC-24, AC-57: live and deleted counted separately, every count a
| locking read)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @return array<string, array{live: int, deleted: int}>
 */
function carKinds(string $accountId): array
{
    $kinds = [];

    foreach ((new CatalogAccountReferrers(app(TenantConnection::class)))->referrersOf($accountId) as $referrer) {
        expect($referrer)->toBeInstanceOf(AccountReferrer::class);

        $kinds[$referrer->kind] = ['live' => $referrer->live, 'deleted' => $referrer->deleted];
    }

    return $kinds;
}

it('is declared by the catalog provider, so Finance finds it without naming catalog', function () {
    $loaded = array_map(static fn (object $provider): string => $provider::class, app()->getProviders(DeclaresAccountReferrers::class));

    expect($loaded)->toContain(CatalogServiceProvider::class)
        ->and((new CatalogServiceProvider(app()))->accountReferrers())->toBe([CatalogAccountReferrers::class]);
});

it('reports zero for an account nobody names, one entry per column', function () {
    $account = CatalogSeed::account('CAR-0', 'revenue', 'sales');

    expect(carKinds($account))->toBe([
        'products.income_account_id' => ['live' => 0, 'deleted' => 0],
        'products.cogs_account_id' => ['live' => 0, 'deleted' => 0],
        'products.inventory_account_id' => ['live' => 0, 'deleted' => 0],
        'categories.income_account_id' => ['live' => 0, 'deleted' => 0],
    ]);
});

it('counts live and soft-deleted products and categories separately, per column (AC-23, AC-24)', function () {
    $income = CatalogSeed::account('CAR-INC', 'revenue', 'sales');
    $cogs = CatalogSeed::account('CAR-COGS', 'expense', 'cogs');
    $unit = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks', null, ['income_account_id' => $income]);
    CatalogSeed::category('dead', null, ['income_account_id' => $income, 'deleted_at' => CatalogSeed::NOW]);

    CatalogSeed::product('p1', $category, $unit, ['income_account_id' => $income]);
    CatalogSeed::product('p2', $category, $unit, ['income_account_id' => $income]);
    CatalogSeed::product('p3', $category, $unit, ['income_account_id' => $income, 'deleted_at' => CatalogSeed::NOW]);
    CatalogSeed::product('p4', $category, $unit, ['cogs_account_id' => $cogs, 'deleted_at' => CatalogSeed::NOW]);

    expect(carKinds($income))->toBe([
        'products.income_account_id' => ['live' => 2, 'deleted' => 1],
        'products.cogs_account_id' => ['live' => 0, 'deleted' => 0],
        'products.inventory_account_id' => ['live' => 0, 'deleted' => 0],
        'categories.income_account_id' => ['live' => 1, 'deleted' => 1],
    ])->and(carKinds($cogs)['products.cogs_account_id'])->toBe(['live' => 0, 'deleted' => 1]);
});

it('reads every count under a shared lock (AC-57)', function () {
    $account = CatalogSeed::account('CAR-LOCK', 'revenue', 'sales');
    $connection = CatalogSeed::connection();

    // The stand-in compiles no lock clause, so the statements are compiled by the MySQL grammar
    // and only recorded (pretend() runs nothing).
    $original = $connection->getQueryGrammar();
    $connection->setQueryGrammar(new MySqlGrammar($connection));

    try {
        $log = array_column($connection->pretend(fn () => (new CatalogAccountReferrers(app(TenantConnection::class)))->referrersOf($account)), 'query');
    } finally {
        $connection->setQueryGrammar($original);
    }

    // Two counts (live, deleted) for each of the four columns, every one locked.
    expect($log)->toHaveCount(8);

    foreach ($log as $sql) {
        expect($sql)->toEndWith(' lock in share mode');
    }
});
