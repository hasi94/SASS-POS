<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\CreateProduct;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\CreateProduct\CreateProduct;
use Flexipos\Catalog\Features\CreateProduct\CreateProductHandler;
use Flexipos\Catalog\Products\ProductType;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Contracts\Pricing\TaxGroupDirectory;
use Flexipos\Contracts\Pricing\TaxGroupState;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 13 - the tax group on a new product (AC-3)
|--------------------------------------------------------------------------
|
| Catalog never reads `tax_groups`: the group is validated and defaulted through
| the published `TaxGroupDirectory`. The test seeds that one table directly only
| to give the directory something to answer from.
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-08 12:00:00.000'));
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
function cptgCreate(array $fields): string
{
    /** @var CreateProductHandler $handler */
    $handler = app(CreateProductHandler::class);

    return $handler->handle(new CreateProduct($fields))->productId;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function cptgGroup(string $code, array $overrides = []): string
{
    $id = (string) Str::ulid();

    CatalogSeed::connection()->table('tax_groups')->insert(array_merge([
        'id' => $id,
        'created_at' => CatalogSeed::NOW,
        'updated_at' => CatalogSeed::NOW,
        'code' => $code,
        'name' => 'Group '.$code,
        'is_inclusive' => false,
        'is_default' => false,
        'is_active' => true,
    ], $overrides));

    return $id;
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function cptgFields(array $extra = []): array
{
    return array_merge([
        'type' => ProductType::Simple->value,
        'sku' => 'sku-'.Str::random(6),
        'name' => 'Cola',
        'category_id' => CatalogSeed::category('c'.Str::random(6)),
        'stock_unit_id' => CatalogSeed::unit('u'.Str::random(6)),
    ], $extra);
}

it('writes the tenant default tax group when none is named, and leaves the purchase group null', function () {
    $default = (string) CatalogSeed::connection()->table('tax_groups')->where('is_default', true)->value('id');

    $id = cptgCreate(cptgFields());

    $row = (array) CatalogSeed::connection()->table('products')->where('id', $id)->first();

    expect($row['tax_group_id'])->toBe($default)
        ->and($row['purchase_tax_group_id'])->toBeNull();
});

it('writes a named active tax group, folding a lower-case id to the stored upper case (K-10)', function () {
    $group = cptgGroup('VAT15');
    $purchase = cptgGroup('PURCH');

    $id = cptgCreate(cptgFields(['tax_group_id' => strtolower($group), 'purchase_tax_group_id' => strtolower($purchase)]));

    $row = (array) CatalogSeed::connection()->table('products')->where('id', $id)->first();

    expect($row['tax_group_id'])->toBe($group)
        ->and($row['purchase_tax_group_id'])->toBe($purchase);
});

it('refuses an unknown or an inactive tax group, naming the field, and writes nothing', function () {
    $inactive = cptgGroup('OLD', ['is_active' => false]);

    $unknown = CatalogRefusals::expect(CatalogRefusal::UnknownTaxGroup, fn () => cptgCreate(cptgFields(['tax_group_id' => (string) Str::ulid()])));
    $retired = CatalogRefusals::expect(CatalogRefusal::InactiveTaxGroup, fn () => cptgCreate(cptgFields(['tax_group_id' => $inactive])));
    $purchaseUnknown = CatalogRefusals::expect(CatalogRefusal::UnknownTaxGroup, fn () => cptgCreate(cptgFields(['purchase_tax_group_id' => (string) Str::ulid()])));
    CatalogRefusals::expect(CatalogRefusal::InactiveTaxGroup, fn () => cptgCreate(cptgFields(['purchase_tax_group_id' => $inactive])));

    expect($unknown->getMessage())->toContain('tax_group_id')
        ->and($retired->getMessage())->toContain('tax_group_id')
        ->and($purchaseUnknown->getMessage())->toContain('purchase_tax_group_id')
        ->and(CatalogSeed::connection()->table('products')->count())->toBe(0)
        ->and(CatalogSeed::connection()->table('audit_logs')->count())->toBe(0);
});

it('refuses an explicit null tax group rather than writing the null the application exists to prevent', function () {
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => cptgCreate(cptgFields(['tax_group_id' => null])));

    expect(CatalogSeed::connection()->table('products')->count())->toBe(0);
});

it('refuses no_default_tax_group when none is named and the tenant has no active default, writing nothing', function () {
    CatalogSeed::connection()->table('tax_groups')->update(['is_default' => false]);

    CatalogRefusals::expect(CatalogRefusal::NoDefaultTaxGroup, fn () => cptgCreate(cptgFields()));

    // An inactive group that is still flagged default is no default at all.
    CatalogSeed::connection()->table('tax_groups')->update(['is_default' => true, 'is_active' => false]);

    CatalogRefusals::expect(CatalogRefusal::NoDefaultTaxGroup, fn () => cptgCreate(cptgFields()));

    expect(CatalogSeed::connection()->table('products')->count())->toBe(0)
        ->and(CatalogSeed::connection()->table('audit_logs')->count())->toBe(0);
});

it('asks the published directory and not the table: a bound double decides the default', function () {
    $table = (string) CatalogSeed::connection()->table('tax_groups')->where('is_default', true)->value('id');
    $chosen = cptgGroup('DOUBLE');

    app()->instance(TaxGroupDirectory::class, new class($chosen) implements TaxGroupDirectory
    {
        public function __construct(private readonly ?string $chosen) {}

        public function state(string $taxGroupId, bool $lock = false): TaxGroupState
        {
            return TaxGroupState::Active;
        }

        public function defaultGroupId(bool $lock = false): ?string
        {
            return $this->chosen;
        }
    });

    $id = cptgCreate(cptgFields());

    expect($table)->not->toBe($chosen)
        ->and(CatalogSeed::connection()->table('products')->where('id', $id)->value('tax_group_id'))->toBe($chosen);
});

it('leaves no product with a null tax group after any create path (F-3, the application-level NOT NULL)', function () {
    cptgCreate(cptgFields());
    cptgCreate(cptgFields(['tax_group_id' => cptgGroup('VAT15')]));
    cptgCreate(cptgFields(['purchase_tax_group_id' => cptgGroup('PURCH')]));

    expect(CatalogSeed::connection()->table('products')->whereNull('tax_group_id')->count())->toBe(0)
        ->and(CatalogSeed::connection()->table('products')->count())->toBe(3);
});

/**
 * A directory that records every question it is asked, as `[lock, transaction
 * level of the tenant connection]`, and answers from the real one (W-2, D-39).
 *
 * @return TaxGroupDirectory&object{calls: list<array{string, bool, int}>}
 */
function cptgRecordingDirectory(): TaxGroupDirectory
{
    return new class(app(TaxGroupDirectory::class)) implements TaxGroupDirectory
    {
        /** @var list<array{string, bool, int}> */
        public array $calls = [];

        public function __construct(private readonly TaxGroupDirectory $real) {}

        public function state(string $taxGroupId, bool $lock = false): TaxGroupState
        {
            $this->calls[] = ['state', $lock, CatalogSeed::connection()->transactionLevel()];

            return $this->real->state($taxGroupId, $lock);
        }

        public function defaultGroupId(bool $lock = false): ?string
        {
            $this->calls[] = ['defaultGroupId', $lock, CatalogSeed::connection()->transactionLevel()];

            return $this->real->defaultGroupId($lock);
        }
    };
}

it('asks the directory for a locking read, inside the product transaction, on the named, purchase and default paths (W-2)', function () {
    $directory = cptgRecordingDirectory();
    app()->instance(TaxGroupDirectory::class, $directory);

    cptgCreate(cptgFields(['tax_group_id' => cptgGroup('VAT15'), 'purchase_tax_group_id' => cptgGroup('PURCH')]));
    cptgCreate(cptgFields());

    $names = array_map(static fn (array $call): string => $call[0], $directory->calls);

    // Named group and purchase group (two state calls), then the default path.
    expect($names)->toBe(['state', 'state', 'defaultGroupId']);

    foreach ($directory->calls as [$name, $lock, $level]) {
        expect($lock)->toBeTrue("{$name} must pass lock: true")
            ->and($level)->toBeGreaterThanOrEqual(1, "{$name} must run inside the transaction");
    }
});
