<?php

namespace Flexipos\Catalog\Tests\Units;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Catalog\Units\InheritedFactor;
use Flexipos\Contracts\Catalog\FactorSource;
use Flexipos\Contracts\Catalog\UnitRefusal;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Catalog\UnitResolver;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 2 - UnitResolver::snapshotInherited() (AC-38, AC-26's unit half,
| D-32, F-7)
|--------------------------------------------------------------------------
|
| A refund line inherits the factor of the line it refers to and never
| re-walks the ladder (docs/database.md section 6.5). Three outcomes: the
| referenced unit (that line's factor, source `inherited`), the product's own
| stock unit (`1.000000`, source `stock_unit`), anything else refused
| `inherited_unit_mismatch`. The ladder is proved unused by editing
| `unit_conversions` after the "sale" and watching the answer not move.
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
 * @return array{each: string, case: string, dozen: string, product: string}
 */
function siSeed(int $eachPrecision = 0, int $casePrecision = 0): array
{
    $each = CatalogSeed::unit('each', 'count', ['precision' => $eachPrecision]);
    $case = CatalogSeed::unit('case', 'count', ['precision' => $casePrecision]);
    $dozen = CatalogSeed::unit('dozen', 'count', ['precision' => 0]);
    $product = CatalogSeed::product('sku1', CatalogSeed::category('drinks'), $each);

    CatalogSeed::connection()->table('unit_conversions')->insert([
        ['id' => (string) Str::ulid(), 'product_id' => null, 'from_unit_id' => $case, 'to_unit_id' => $each, 'factor' => '24.000000'],
        ['id' => (string) Str::ulid(), 'product_id' => null, 'from_unit_id' => $dozen, 'to_unit_id' => $each, 'factor' => '12.000000'],
    ]);

    return ['each' => $each, 'case' => $case, 'dozen' => $dozen, 'product' => $product];
}

it('keeps the referenced line factor for the same unit, after unit_conversions is edited to 12 (the section 6.5 walk-through)', function () {
    $seed = siSeed();

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);

    CatalogSeed::connection()->table('unit_conversions')->where('from_unit_id', $seed['case'])->update(['factor' => '12.000000']);

    // The ladder now says 12. The sold line said 24, and the refund keeps 24.
    $snapshot = $resolver->snapshotInherited($seed['product'], $seed['case'], '24.000000', $seed['case'], 2);

    expect($snapshot->unitFactor)->toBe('24.000000')
        ->and($snapshot->baseQuantity)->toBe('48.0000')
        ->and($snapshot->quantity)->toBe('2.0000')
        ->and($snapshot->unitCode)->toBe('case')
        ->and($snapshot->source)->toBe(FactorSource::Inherited);

    // And the ladder really is what changed: a fresh snapshot reads 12.
    expect($resolver->snapshot($seed['product'], $seed['case'], 2)->unitFactor)->toBe('12.000000');
});

it('uses 1.000000 for the stock unit whatever factor the caller passes, with source stock_unit', function () {
    $seed = siSeed();

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);

    $snapshot = $resolver->snapshotInherited($seed['product'], $seed['case'], '24.000000', $seed['each'], 5);

    expect($snapshot->unitFactor)->toBe('1.000000')
        ->and($snapshot->baseQuantity)->toBe('5.0000')
        ->and($snapshot->source)->toBe(FactorSource::StockUnit);
});

it('refuses a third unit as inherited_unit_mismatch, even one the ladder could reach', function () {
    $seed = siSeed();

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);

    // The dozen resolves through the ladder (12). A refund may not use it.
    expect($resolver->resolve($seed['product'], $seed['dozen'])->factor)->toBe('12.000000');

    try {
        $resolver->snapshotInherited($seed['product'], $seed['case'], '24.000000', $seed['dozen'], 1);
        Assert::fail('Expected a refusal.');
    } catch (UnitResolutionRefused $refused) {
        expect($refused->reason)->toBe(UnitRefusal::InheritedUnitMismatch)
            ->and($refused->reason->value)->toBe('inherited_unit_mismatch');
    }
});

it('still rounds the quantity to the line unit precision before the base quantity', function () {
    $seed = siSeed(eachPrecision: 0, casePrecision: 1);

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);

    // 2.25 -> 2.3 (HALF_UP at one decimal), then 2.3 x 24 = 55.2: not a whole
    // base quantity on a precision-0 stock unit.
    try {
        $resolver->snapshotInherited($seed['product'], $seed['case'], '24.000000', $seed['case'], '2.25');
        Assert::fail('Expected a refusal.');
    } catch (UnitResolutionRefused $refused) {
        expect($refused->reason)->toBe(UnitRefusal::FractionalBaseQuantity);
    }

    // 2.5 stays 2.5 at one decimal, x 24 = 60.0: whole.
    $snapshot = $resolver->snapshotInherited($seed['product'], $seed['case'], '24.000000', $seed['case'], '2.5');

    expect($snapshot->quantity)->toBe('2.5000')
        ->and($snapshot->baseQuantity)->toBe('60.0000');
});

it('refuses a fractional base quantity on a precision-0 stock unit (Q-11)', function () {
    $seed = siSeed(eachPrecision: 0, casePrecision: 4);

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);

    try {
        $resolver->snapshotInherited($seed['product'], $seed['case'], '24.000000', $seed['case'], '0.0001');
        Assert::fail('Expected a refusal.');
    } catch (UnitResolutionRefused $refused) {
        expect($refused->reason)->toBe(UnitRefusal::FractionalBaseQuantity);
    }
});

it('refuses an unknown product as unknown_product', function () {
    $seed = siSeed();

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);

    try {
        $resolver->snapshotInherited('01ARZ3NDEKTSV4RRFFQ69G5FAV', $seed['case'], '24.000000', $seed['case'], 1);
        Assert::fail('Expected a refusal.');
    } catch (UnitResolutionRefused $refused) {
        expect($refused->reason)->toBe(UnitRefusal::UnknownProduct);
    }
});

it('never calls the ladder: a unit with no conversion path still inherits its referenced factor', function () {
    $each = CatalogSeed::unit('each');
    $orphan = CatalogSeed::unit('orphan', 'count');
    $product = CatalogSeed::product('sku1', CatalogSeed::category('drinks'), $each);

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);

    // There is no unit_conversions row at all, so resolve() refuses...
    try {
        $resolver->resolve($product, $orphan);
        Assert::fail('Expected the ladder to refuse.');
    } catch (UnitResolutionRefused $refused) {
        expect($refused->reason)->toBe(UnitRefusal::NoConversionPath);
    }

    // ...and the inherited snapshot does not care.
    $snapshot = $resolver->snapshotInherited($product, $orphan, '6.000000', $orphan, 2);

    expect($snapshot->unitFactor)->toBe('6.000000')
        ->and($snapshot->baseQuantity)->toBe('12.0000');
});

it('canonicalises a factor a database driver returned as a number', function (string $given) {
    $seed = siSeed();

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);

    $snapshot = $resolver->snapshotInherited($seed['product'], $seed['case'], $given, $seed['case'], 2);

    expect($snapshot->unitFactor)->toBe('24.000000')
        ->and($snapshot->baseQuantity)->toBe('48.0000');
})->with(['24', '24.0', '24.000000', '24.0000000']);

it('refuses a factor that cannot be one with a named reason, never an InvalidArgumentException', function (string $given, UnitRefusal $expected) {
    $seed = siSeed();

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);

    try {
        $resolver->snapshotInherited($seed['product'], $seed['case'], $given, $seed['case'], 1);
        Assert::fail('Expected a refusal.');
    } catch (UnitResolutionRefused $refused) {
        expect($refused->reason)->toBe($expected);
    }
})->with([
    'below one' => ['0.500000', UnitRefusal::FactorBelowOne],
    'zero' => ['0', UnitRefusal::FactorBelowOne],
    'negative' => ['-24', UnitRefusal::FactorBelowOne],
    'not a number' => ['abc', UnitRefusal::FactorBelowOne],
    'empty' => ['', UnitRefusal::FactorBelowOne],
    'exponent' => ['2.4e1', UnitRefusal::FactorBelowOne],
    'beyond DECIMAL(18,6)' => ['1000000000000', UnitRefusal::FactorOutOfRange],
    'more than six decimals' => ['24.0000001', UnitRefusal::InexactFactor],
]);

it('selects purely: the stock unit wins, then the referenced unit, then a refusal', function () {
    $product = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $stock = '01UNITEACH000000000000000A';
    $case = '01UNITCASE000000000000000A';
    $other = '01UNITOTHER00000000000000A';

    $same = InheritedFactor::select($product, $stock, $case, '24', $case);
    $stockLine = InheritedFactor::select($product, $stock, $case, '24', $stock);

    expect($same->factor)->toBe('24.000000')
        ->and($same->source)->toBe(FactorSource::Inherited)
        ->and($stockLine->factor)->toBe('1.000000')
        ->and($stockLine->source)->toBe(FactorSource::StockUnit);

    // The referenced unit being the stock unit changes nothing: the stock
    // unit is decided first, so a wrong factor cannot survive.
    expect(InheritedFactor::select($product, $stock, $stock, '24', $stock)->factor)->toBe('1.000000');

    expect(fn () => InheritedFactor::select($product, $stock, $case, '24', $other))
        ->toThrow(UnitResolutionRefused::class);
});
