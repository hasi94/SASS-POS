<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\CreateTax;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\CreateTax\CreateTax;
use Flexipos\Pricing\Features\CreateTax\CreateTaxHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 11 - creating a tax (AC-5, F-9)
|--------------------------------------------------------------------------
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
function ctCreate(array $fields): string
{
    /** @var CreateTaxHandler $handler */
    $handler = app(CreateTaxHandler::class);

    return $handler->handle(new CreateTax($fields))->taxId;
}

it('creates a tax with the documented defaults and writes one audit row', function () {
    $authority = PricingSeed::authority('IRD');

    $id = ctCreate(['authority_id' => strtolower($authority), 'code' => 'VAT', 'name' => 'VAT', 'type' => 'percentage', 'applies_to' => 'both']);

    expect(PricingSeed::row('taxes', $id))->toMatchArray([
        'authority_id' => $authority,
        'code' => 'VAT',
        'type' => 'percentage',
        'applies_to' => 'both',
        'tax_code' => null,
        'is_compound' => 0,
        'is_recoverable' => 0,
        'is_withholding' => 0,
        'is_active' => 1,
    ]);

    expect(PricingSeed::onlyAuditRow())->toMatchArray(['action' => 'pricing_tax_created', 'subject_type' => 'tax', 'subject_id' => $id]);
});

it('refuses an unknown authority, a missing required field and a bad enum', function () {
    $authority = PricingSeed::authority('IRD');
    $base = ['authority_id' => $authority, 'code' => 'VAT', 'name' => 'VAT', 'type' => 'percentage', 'applies_to' => 'both'];

    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => ctCreate([...$base, 'authority_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']), 'unknown authority');

    foreach (['authority_id', 'code', 'name', 'type', 'applies_to'] as $required) {
        $fields = $base;
        unset($fields[$required]);

        PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctCreate($fields), "missing {$required}");
    }

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctCreate([...$base, 'type' => 'flat']), 'bad type');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctCreate([...$base, 'applies_to' => 'sales']), 'bad applies_to');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctCreate([...$base, 'is_compound' => 'yes']), 'non-boolean flag');

    expect(PricingSeed::connection()->table('taxes')->count())->toBe(0)
        ->and(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
});

it('refuses a fixed tax that is also compound, and accepts either alone (F-9)', function () {
    $authority = PricingSeed::authority('IRD');
    $base = ['authority_id' => $authority, 'name' => 'T', 'applies_to' => 'both'];

    PricingAdminRefusals::expect(PricingAdminRefusal::FixedCannotCompound, fn () => ctCreate([...$base, 'code' => 'A', 'type' => 'fixed', 'is_compound' => true]));

    ctCreate([...$base, 'code' => 'B', 'type' => 'fixed', 'is_compound' => false]);
    ctCreate([...$base, 'code' => 'C', 'type' => 'percentage', 'is_compound' => true]);

    expect(PricingSeed::connection()->table('taxes')->count())->toBe(2);
});

it('refuses a duplicate code also by case', function () {
    $authority = PricingSeed::authority('IRD');
    $fields = ['authority_id' => $authority, 'name' => 'T', 'type' => 'percentage', 'applies_to' => 'both'];

    ctCreate([...$fields, 'code' => 'VAT']);

    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => ctCreate([...$fields, 'code' => 'vat']));
});
