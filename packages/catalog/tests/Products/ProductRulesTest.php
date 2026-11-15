<?php

namespace Flexipos\Catalog\Tests\Products;

use Flexipos\Catalog\Products\ProductRules;
use Flexipos\Catalog\Products\ProductType;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;

/*
|--------------------------------------------------------------------------
| T-027 plan step 11 - ProductRules, pure (AC-19, AC-20's type clause)
|--------------------------------------------------------------------------
|
| Every type against every parentage case, driven by ProductType::cases()
| rather than a string-keyed dataset (plan Ruling 14's vocabulary hazard).
*/

it('refuses parent_id on every type except Variant, and requires it on Variant', function () {
    foreach (ProductType::cases() as $type) {
        if ($type === ProductType::Variant) {
            CatalogRefusals::expect(CatalogRefusal::Validation, fn () => ProductRules::checkParentage($type, null));

            // Accepted with a parent id: no exception means no refusal to inspect.
            ProductRules::checkParentage($type, 'SOME0000000000000000000000');
            expect(true)->toBeTrue();

            continue;
        }

        CatalogRefusals::expect(CatalogRefusal::Validation, fn () => ProductRules::checkParentage($type, 'SOME0000000000000000000000'));

        // Accepted with no parent id.
        ProductRules::checkParentage($type, null);
        expect(true)->toBeTrue();
    }
});

it('accepts a Variant under a live VariantParent', function () {
    ProductRules::checkParent('PARENT0000000000000000000X', true, ProductType::VariantParent);

    expect(true)->toBeTrue();
});

it('refuses a Variant under a missing or soft-deleted parent', function () {
    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => ProductRules::checkParent('PARENT0000000000000000000X', false, null));
});

it('refuses a Variant under a parent that is not a VariantParent', function () {
    foreach ([ProductType::Simple, ProductType::Variant, ProductType::Service, ProductType::Bundle, ProductType::Composite] as $parentType) {
        CatalogRefusals::expect(CatalogRefusal::Validation, fn () => ProductRules::checkParent('PARENT0000000000000000000X', true, $parentType));
    }
});

it('refuses a VariantParent with live children from changing type; accepts changing between other types', function () {
    CatalogRefusals::expect(
        CatalogRefusal::Validation,
        fn () => ProductRules::checkTypeChange('P1', ProductType::VariantParent, ProductType::Simple, true)
    );

    // Control: no live children, the same change is accepted.
    ProductRules::checkTypeChange('P1', ProductType::VariantParent, ProductType::Simple, false);

    // Control: any other type pair is accepted regardless of children.
    ProductRules::checkTypeChange('P1', ProductType::Simple, ProductType::Service, true);

    expect(true)->toBeTrue();
});
