<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\UpdateTaxGroup;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\UpdateTaxGroup\UpdateTaxGroup;
use Flexipos\Pricing\Features\UpdateTaxGroup\UpdateTaxGroupHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 12 - updating a tax group (AC-8, AC-10, F-6)
|--------------------------------------------------------------------------
|
| The tenant tree seeds one default `STANDARD` group, so the default moves
| between it and whatever a test creates.
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
function utgUpdate(string $id, array $fields): void
{
    /** @var UpdateTaxGroupHandler $handler */
    $handler = app(UpdateTaxGroupHandler::class);

    $handler->handle(new UpdateTaxGroup($id, $fields));
}

/**
 * @return list<string>
 */
function utgDefaults(): array
{
    return PricingSeed::connection()->table('tax_groups')->where('is_default', true)->pluck('code')->map(fn ($code): string => (string) $code)->all();
}

function utgDefaultId(): string
{
    return (string) PricingSeed::connection()->table('tax_groups')->where('is_default', true)->value('id');
}

it('updates the named fields and writes one audit row with the row before and after', function () {
    $id = PricingSeed::group('VAT15');

    utgUpdate($id, ['name' => 'Renamed', 'is_inclusive' => true]);

    expect(PricingSeed::row('tax_groups', $id))->toMatchArray(['name' => 'Renamed', 'is_inclusive' => 1, 'code' => 'VAT15']);

    $audit = PricingSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['action' => 'pricing_tax_group_updated', 'subject_id' => $id])
        ->and($audit['before']['is_inclusive'])->toBe(0)
        ->and($audit['after']['is_inclusive'])->toBe(1);
});

it('moves the default through an update, leaving exactly one (AC-8)', function () {
    $a = PricingSeed::group('A');
    $b = PricingSeed::group('B');

    utgUpdate($a, ['is_default' => true]);
    expect(utgDefaults())->toBe(['A']);

    utgUpdate($b, ['is_default' => true]);
    expect(utgDefaults())->toBe(['B']);
});

it('refuses an unknown group, an empty update, a taken code and an inactive default', function () {
    $a = PricingSeed::group('A');
    PricingSeed::group('B');
    $inactive = PricingSeed::group('I', ['is_active' => false]);

    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => utgUpdate('01ARZ3NDEKTSV4RRFFQ69G5FAV', ['name' => 'x']), 'unknown');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => utgUpdate($a, []), 'empty');
    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => utgUpdate($a, ['code' => 'b']), 'code by case');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => utgUpdate($inactive, ['is_default' => true]), 'making an inactive group the default');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => utgUpdate($a, ['is_default' => true, 'is_active' => false]), 'default and inactive together');

    expect(utgDefaults())->toBe(['STANDARD'])
        ->and(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
});

it('refuses deactivating a group a live product references through either column, naming the count (AC-10)', function (string $column) {
    $group = PricingSeed::group('VAT15');
    PricingSeed::product('P1', null, [$column => $group]);
    PricingSeed::product('P2', null, [$column => $group]);

    $refused = PricingAdminRefusals::expect(PricingAdminRefusal::InUse, fn () => utgUpdate($group, ['is_active' => false]));

    expect($refused->getMessage())->toContain('2 product(s)')
        ->and(PricingSeed::row('tax_groups', $group)['is_active'])->toBe(1);
})->with(['tax_group_id', 'purchase_tax_group_id']);

it('counts a product referencing the group by both columns once, and ignores a soft-deleted product', function () {
    $group = PricingSeed::group('VAT15');
    PricingSeed::product('P1', null, ['tax_group_id' => $group, 'purchase_tax_group_id' => $group]);
    PricingSeed::product('GONE', null, ['tax_group_id' => $group, 'deleted_at' => PricingSeed::NOW]);

    $refused = PricingAdminRefusals::expect(PricingAdminRefusal::InUse, fn () => utgUpdate($group, ['is_active' => false]));

    expect($refused->getMessage())->toContain('1 product(s)');
});

it('accepts deactivation once the only referencing product is soft-deleted, and writes one audit row', function () {
    $group = PricingSeed::group('VAT15');
    $product = PricingSeed::product('P1', null, ['tax_group_id' => $group]);

    PricingSeed::connection()->table('products')->where('id', $product)->update(['deleted_at' => PricingSeed::NOW]);

    utgUpdate($group, ['is_active' => false]);

    expect(PricingSeed::row('tax_groups', $group)['is_active'])->toBe(0)
        ->and(PricingSeed::connection()->table('audit_logs')->count())->toBe(1);
});

it('clears is_default when the default group is deactivated, so no inactive group stays the default (AC-10)', function () {
    $default = utgDefaultId();

    utgUpdate($default, ['is_active' => false]);

    expect(PricingSeed::row('tax_groups', $default))->toMatchArray(['is_active' => 0, 'is_default' => 0])
        ->and(utgDefaults())->toBe([]);
});

it('reactivates a group without making it the default again', function () {
    $default = utgDefaultId();

    utgUpdate($default, ['is_active' => false]);
    utgUpdate($default, ['is_active' => true]);

    expect(PricingSeed::row('tax_groups', $default))->toMatchArray(['is_active' => 1, 'is_default' => 0]);
});
