<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Finance;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\DeclaresAccountReferrers;
use Flexipos\Customers\CustomersServiceProvider;
use Flexipos\Customers\Finance\CustomerAccountReferrers;
use Flexipos\Customers\Support\TenantConnection;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 18 - what customers tells Finance about an account
| (AC-23, AC-24, AC-57: live and deleted counted separately, every count a
| locking read)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function carsReferrers(): CustomerAccountReferrers
{
    return new CustomerAccountReferrers(app(TenantConnection::class));
}

it('is declared by the customers provider, so Finance finds it without naming customers', function () {
    $loaded = array_map(static fn (object $provider): string => $provider::class, app()->getProviders(DeclaresAccountReferrers::class));

    expect($loaded)->toContain(CustomersServiceProvider::class)
        ->and((new CustomersServiceProvider(app()))->accountReferrers())->toBe([CustomerAccountReferrers::class]);
});

it('counts live and soft-deleted (including merged-away) customers separately (AC-23, AC-24)', function () {
    $account = CustomersSeed::account('CARS-AR', 'asset', 'receivable');
    $other = CustomersSeed::account('CARS-OT', 'asset', 'receivable');

    CustomersSeed::customer('A', ['receivable_account_id' => $account]);
    CustomersSeed::customer('B', ['receivable_account_id' => $account]);
    CustomersSeed::customer('C', ['receivable_account_id' => $account, 'deleted_at' => CustomersSeed::NOW]);

    $referrers = carsReferrers()->referrersOf($account);

    expect($referrers)->toHaveCount(1)
        ->and($referrers[0]->kind)->toBe('customers.receivable_account_id')
        ->and($referrers[0]->live)->toBe(2)
        ->and($referrers[0]->deleted)->toBe(1);

    $none = carsReferrers()->referrersOf($other);

    expect([$none[0]->live, $none[0]->deleted])->toBe([0, 0]);
});

it('reads both counts under a shared lock (AC-57)', function () {
    $account = CustomersSeed::account('CARS-LK', 'asset', 'receivable');
    $connection = CustomersSeed::connection();

    $original = $connection->getQueryGrammar();
    $connection->setQueryGrammar(new MySqlGrammar($connection));

    try {
        $log = array_column($connection->pretend(fn () => carsReferrers()->referrersOf($account)), 'query');
    } finally {
        $connection->setQueryGrammar($original);
    }

    expect($log)->toHaveCount(2);

    foreach ($log as $sql) {
        expect($sql)->toEndWith(' lock in share mode');
    }
});
