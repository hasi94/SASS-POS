<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\CreateTaxGroup;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\CreateTaxGroup\CreateTaxGroup;
use Flexipos\Pricing\Features\CreateTaxGroup\CreateTaxGroupHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 12 - creating a tax group and the single default (AC-8)
|--------------------------------------------------------------------------
|
| The tenant tree's backfill migration seeds one default `STANDARD` group even
| into an empty database, so every test here starts with exactly one default.
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
function ctgCreate(array $fields): string
{
    /** @var CreateTaxGroupHandler $handler */
    $handler = app(CreateTaxGroupHandler::class);

    return $handler->handle(new CreateTaxGroup($fields))->taxGroupId;
}

/**
 * @return list<string>
 */
function ctgDefaults(): array
{
    return PricingSeed::connection()->table('tax_groups')->where('is_default', true)->pluck('code')->map(fn ($code): string => (string) $code)->all();
}

it('creates a group with the documented defaults and writes one audit row', function () {
    $id = ctgCreate(['code' => 'VAT15', 'name' => 'VAT 15']);

    expect(PricingSeed::row('tax_groups', $id))->toMatchArray(['code' => 'VAT15', 'is_inclusive' => 0, 'is_default' => 0, 'is_active' => 1]);
    expect(PricingSeed::onlyAuditRow())->toMatchArray(['action' => 'pricing_tax_group_created', 'subject_type' => 'tax_group', 'subject_id' => $id]);
});

it('starts from exactly one default, the STANDARD group the backfill seeds', function () {
    expect(ctgDefaults())->toBe(['STANDARD']);
});

it('moves the default when a second group is created as the default, leaving exactly one (AC-8)', function () {
    ctgCreate(['code' => 'A', 'name' => 'A', 'is_default' => true]);

    expect(ctgDefaults())->toBe(['A']);

    ctgCreate(['code' => 'B', 'name' => 'B', 'is_default' => true]);

    expect(ctgDefaults())->toBe(['B']);
});

it('leaves the default alone when a group is created without the flag', function () {
    ctgCreate(['code' => 'A', 'name' => 'A']);

    expect(ctgDefaults())->toBe(['STANDARD']);
});

it('refuses a blank or duplicate code, an inactive default and non-boolean flags, writing nothing', function () {
    ctgCreate(['code' => 'A', 'name' => 'A']);

    $audits = PricingSeed::connection()->table('audit_logs')->count();

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctgCreate(['code' => ' ', 'name' => 'x']), 'blank code');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctgCreate(['code' => 'Z']), 'no name');
    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => ctgCreate(['code' => 'a', 'name' => 'x']), 'code by case');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctgCreate(['code' => 'I', 'name' => 'x', 'is_default' => true, 'is_active' => false]), 'inactive default');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctgCreate(['code' => 'F', 'name' => 'x', 'is_inclusive' => 1]), 'non-boolean flag');

    // A refused inactive default must not have cleared the existing default first.
    expect(ctgDefaults())->toBe(['STANDARD'])
        ->and(PricingSeed::connection()->table('audit_logs')->count())->toBe($audits);
});
