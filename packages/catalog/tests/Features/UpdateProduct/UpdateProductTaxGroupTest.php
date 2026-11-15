<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\UpdateProduct;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\UpdateProduct\UpdateProduct;
use Flexipos\Catalog\Features\UpdateProduct\UpdateProductHandler;
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
| T-029 plan step 13 - the tax group on an updated product (AC-3)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-08 13:00:00.000'));
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
function uptgUpdate(string $productId, array $fields): void
{
    /** @var UpdateProductHandler $handler */
    $handler = app(UpdateProductHandler::class);

    $handler->handle(new UpdateProduct($productId, $fields));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function uptgGroup(string $code, array $overrides = []): string
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

function uptgProduct(): string
{
    return CatalogSeed::product('sku1', CatalogSeed::category('drinks'), CatalogSeed::unit('each'));
}

it('changes the tax group to an active one and folds a lower-case id to upper case (K-10)', function () {
    $product = uptgProduct();
    $group = uptgGroup('VAT15');
    $purchase = uptgGroup('PURCH');

    uptgUpdate($product, ['tax_group_id' => strtolower($group), 'purchase_tax_group_id' => strtolower($purchase)]);

    $row = (array) CatalogSeed::connection()->table('products')->where('id', $product)->first();

    expect($row['tax_group_id'])->toBe($group)
        ->and($row['purchase_tax_group_id'])->toBe($purchase);
});

it('refuses tax_group_id null, leaving the row byte-identical and writing no audit row (F-3)', function () {
    $product = uptgProduct();
    uptgUpdate($product, ['tax_group_id' => uptgGroup('VAT15')]);

    $before = (array) CatalogSeed::connection()->table('products')->where('id', $product)->first();
    $audits = CatalogSeed::connection()->table('audit_logs')->count();

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => uptgUpdate($product, ['tax_group_id' => null, 'name' => 'Changed']));

    expect((array) CatalogSeed::connection()->table('products')->where('id', $product)->first())->toBe($before)
        ->and(CatalogSeed::connection()->table('audit_logs')->count())->toBe($audits);
});

it('refuses an unknown or inactive tax group for either column, naming the field', function () {
    $product = uptgProduct();
    $inactive = uptgGroup('OLD', ['is_active' => false]);

    $a = CatalogRefusals::expect(CatalogRefusal::UnknownTaxGroup, fn () => uptgUpdate($product, ['tax_group_id' => (string) Str::ulid()]));
    $b = CatalogRefusals::expect(CatalogRefusal::InactiveTaxGroup, fn () => uptgUpdate($product, ['tax_group_id' => $inactive]));
    $c = CatalogRefusals::expect(CatalogRefusal::UnknownTaxGroup, fn () => uptgUpdate($product, ['purchase_tax_group_id' => (string) Str::ulid()]));
    $d = CatalogRefusals::expect(CatalogRefusal::InactiveTaxGroup, fn () => uptgUpdate($product, ['purchase_tax_group_id' => $inactive]));

    expect($a->getMessage())->toContain('tax_group_id')
        ->and($b->getMessage())->toContain('tax_group_id')
        ->and($c->getMessage())->toContain('purchase_tax_group_id')
        ->and($d->getMessage())->toContain('purchase_tax_group_id');
});

it('lets purchase_tax_group_id be cleared back to null, which the documented column allows', function () {
    $product = uptgProduct();

    uptgUpdate($product, ['purchase_tax_group_id' => uptgGroup('PURCH')]);
    uptgUpdate($product, ['purchase_tax_group_id' => null]);

    expect(CatalogSeed::connection()->table('products')->where('id', $product)->value('purchase_tax_group_id'))->toBeNull();
});

it('asks the directory for a locking read, inside the product transaction, for both columns (W-2)', function () {
    $product = uptgProduct();
    $real = app(TaxGroupDirectory::class);

    $directory = new class($real) implements TaxGroupDirectory
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

    app()->instance(TaxGroupDirectory::class, $directory);

    uptgUpdate($product, ['tax_group_id' => uptgGroup('VAT15'), 'purchase_tax_group_id' => uptgGroup('PURCH')]);

    expect($directory->calls)->toHaveCount(2);

    foreach ($directory->calls as [$name, $lock, $level]) {
        expect($name)->toBe('state')
            ->and($lock)->toBeTrue('state must pass lock: true')
            ->and($level)->toBeGreaterThanOrEqual(1, 'state must run inside the transaction');
    }
});
