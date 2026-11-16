<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Documents;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Purchasing\Documents\TaxGroupChain;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 8 - TaxGroupChain (AC-44, D-42)
|--------------------------------------------------------------------------
|
| Explicit, then the product's, then the default, then a refusal; and the chosen
| group's state is decided here, because the engine will tax an inactive group.
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

function puChain(): TaxGroupChain
{
    return app(TaxGroupChain::class);
}

/**
 * The group a call chooses, or the refusal code it ends in.
 */
function puChainOutcome(mixed $explicit, ?string $productGroup): string
{
    try {
        return puChain()->resolve($explicit, $productGroup);
    } catch (PurchasingRefused $refused) {
        return $refused->code();
    }
}

it('prefers the explicit group, then the product s, then the shop default', function () {
    $explicit = PurchasingSeed::taxGroup('CH1');
    $product = PurchasingSeed::taxGroup('CH2');
    // A fresh tenant already holds the default group the backfill migration made.
    $default = (string) PurchasingSeed::connection()->table('tax_groups')->where('is_default', true)->value('id');

    expect(puChainOutcome(strtolower($explicit), $product))->toBe($explicit)
        ->and(puChainOutcome(null, $product))->toBe($product)
        ->and(puChainOutcome(null, null))->toBe($default);
});

it('refuses no_default_tax_group when nothing names a group and the shop has no default', function () {
    PurchasingSeed::connection()->table('tax_groups')->update(['is_default' => false]);

    expect(puChainOutcome(null, null))->toBe('no_default_tax_group');
});

it('refuses an unknown group as unknown_tax_group, whichever step of the chain named it', function () {
    $unknown = (string) Str::ulid();

    expect(puChainOutcome($unknown, null))->toBe('unknown_tax_group')
        ->and(puChainOutcome(null, $unknown))->toBe('unknown_tax_group');
});

it('refuses an inactive group as inactive_tax_group, whichever step of the chain named it (D-42)', function () {
    $inactive = PurchasingSeed::taxGroup('CH4', '10.0000', false, 'both', ['is_active' => false]);

    expect(puChainOutcome($inactive, null))->toBe('inactive_tax_group')
        ->and(puChainOutcome(null, $inactive))->toBe('inactive_tax_group');
});

it('does not fall through to the product s group when the explicit one is refused', function () {
    $good = PurchasingSeed::taxGroup('CH5');

    expect(puChainOutcome((string) Str::ulid(), $good))->toBe('unknown_tax_group');
});

it('accepts a group whose members apply only to sales, which the engine taxes at zero (D-42)', function () {
    $saleOnly = PurchasingSeed::taxGroup('CH6', '10.0000', false, 'sale');

    expect(puChainOutcome($saleOnly, null))->toBe($saleOnly);
});

it('refuses a malformed explicit id as validation', function () {
    expect(puChainOutcome('x', null))->toBe('validation')
        ->and(puChainOutcome(7, null))->toBe('validation');
});
