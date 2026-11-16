<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Documents;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Purchasing\Documents\ProductFacts;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Tests\Support\PurchasingQueryLog;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 8 - ProductFacts (AC-23, AC-94, D-39)
|--------------------------------------------------------------------------
|
| "Purchasable" is the positive list: not deleted, active, purchasable, stock
| tracked, of a type that is a stocked thing. One query per batch.
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

function puFacts(): ProductFacts
{
    return app(ProductFacts::class);
}

function puFactsOutcome(callable $call): string
{
    try {
        $call();
    } catch (PurchasingRefused $refused) {
        return $refused->code();
    }

    return 'accepted';
}

it('accepts the three stocked types and returns the name and the purchase tax group', function (string $type) {
    $group = PurchasingSeed::taxGroup('PF'.strtoupper(substr($type, 0, 3)));
    $product = PurchasingSeed::product('PF-'.$type, null, ['type' => $type, 'name' => 'Flour '.$type, 'purchase_tax_group_id' => $group]);

    $facts = puFacts()->one(PurchasingSeed::connection(), strtolower($product));

    expect($facts->id)->toBe($product)
        ->and($facts->name)->toBe('Flour '.$type)
        ->and($facts->purchase_tax_group_id)->toBe($group);
})->with(['simple', 'variant', 'composite']);

it('returns a null purchase tax group when the product has none', function () {
    $product = PurchasingSeed::product('PF-NOGRP');

    expect(puFacts()->one(PurchasingSeed::connection(), $product)->purchase_tax_group_id)->toBeNull();
});

it('refuses a product that is not bought as product_not_purchasable, one row per reason (D-39)', function (array $override) {
    $product = PurchasingSeed::product('PF-NP'.substr(md5((string) json_encode($override)), 0, 8), null, $override);

    expect(puFactsOutcome(fn () => puFacts()->one(PurchasingSeed::connection(), $product)))->toBe('product_not_purchasable');
})->with([
    'a variant parent' => [['type' => 'variant_parent']],
    'a bundle' => [['type' => 'bundle']],
    'a non-stock product type' => [['type' => 'service', 'track_stock' => false]],
    'a non-stock type even with stock tracking on' => [['type' => 'service', 'track_stock' => true]],
    'a simple product that is not purchasable' => [['is_purchasable' => false]],
    'a simple product with stock tracking off' => [['track_stock' => false]],
]);

it('refuses a product that is unknown, inactive or soft-deleted as unknown_product, and a malformed id as validation', function () {
    $inactive = PurchasingSeed::product('PF-OFF', null, ['is_active' => false]);
    $deleted = PurchasingSeed::product('PF-DEL', null, ['deleted_at' => PurchasingSeed::NOW]);
    $connection = PurchasingSeed::connection();

    expect(puFactsOutcome(fn () => puFacts()->one($connection, (string) Str::ulid())))->toBe('unknown_product')
        ->and(puFactsOutcome(fn () => puFacts()->one($connection, $inactive)))->toBe('unknown_product')
        ->and(puFactsOutcome(fn () => puFacts()->one($connection, $deleted)))->toBe('unknown_product')
        ->and(puFactsOutcome(fn () => puFacts()->one($connection, 'not-a-ulid')))->toBe('validation');
});

it('says unknown, not not-purchasable, for a product that is both inactive and not purchasable', function () {
    $product = PurchasingSeed::product('PF-BOTH', null, ['is_active' => false, 'is_purchasable' => false]);

    expect(puFactsOutcome(fn () => puFacts()->one(PurchasingSeed::connection(), $product)))->toBe('unknown_product');
});

it('reads a batch with one query, duplicates once, and refuses the whole batch for one bad product', function () {
    $a = PurchasingSeed::product('PF-A');
    $b = PurchasingSeed::product('PF-B');
    $bad = PurchasingSeed::product('PF-BAD', null, ['is_purchasable' => false]);
    $connection = PurchasingSeed::connection();

    $facts = [];
    $log = PurchasingQueryLog::during(function () use (&$facts, $connection, $a, $b): void {
        $facts = puFacts()->purchasable($connection, [$a, $b, strtolower($a), $a]);
    });

    expect($log)->toHaveCount(1)
        ->and($log[0])->toContain('products')
        ->and(array_keys($facts))->toBe([$a, $b])
        ->and(puFacts()->purchasable($connection, []))->toBe([])
        ->and(puFactsOutcome(fn () => puFacts()->purchasable($connection, [$a, $bad, $b])))->toBe('product_not_purchasable');
});
