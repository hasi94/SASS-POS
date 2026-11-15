<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\CreateTaxAuthority;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\CreateTaxAuthority\CreateTaxAuthority;
use Flexipos\Pricing\Features\CreateTaxAuthority\CreateTaxAuthorityHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 11 - creating a tax authority (AC-4)
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
function ctaCreate(array $fields): string
{
    /** @var CreateTaxAuthorityHandler $handler */
    $handler = app(CreateTaxAuthorityHandler::class);

    return $handler->handle(new CreateTaxAuthority($fields))->taxAuthorityId;
}

it('creates an authority and writes one audit row', function () {
    $id = ctaCreate(['code' => 'IRD', 'name' => 'Inland Revenue', 'country' => 'LK', 'registration_no' => 'R-1', 'filing_frequency' => 'monthly']);

    expect(PricingSeed::row('tax_authorities', $id))->toMatchArray([
        'code' => 'IRD',
        'name' => 'Inland Revenue',
        'country' => 'LK',
        'registration_no' => 'R-1',
        'filing_frequency' => 'monthly',
    ]);

    expect(PricingSeed::onlyAuditRow())->toMatchArray([
        'module' => 'pricing',
        'action' => 'pricing_tax_authority_created',
        'subject_type' => 'tax_authority',
        'subject_id' => $id,
    ]);
});

it('refuses a blank code, a missing name and a duplicate code also by case, writing nothing', function () {
    ctaCreate(['code' => 'IRD', 'name' => 'Inland Revenue']);

    $before = PricingSeed::connection()->table('audit_logs')->count();

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctaCreate(['code' => '  ', 'name' => 'X']), 'blank code');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctaCreate(['code' => 'B']), 'no name');
    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => ctaCreate(['code' => 'IRD', 'name' => 'Other']), 'same code');
    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => ctaCreate(['code' => 'ird', 'name' => 'Other']), 'code by case');

    expect(PricingSeed::connection()->table('tax_authorities')->count())->toBe(1)
        ->and(PricingSeed::connection()->table('audit_logs')->count())->toBe($before);
});

it('refuses the two account columns as unknown fields, because they are not built (F-17)', function (string $field) {
    $refused = PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctaCreate(['code' => 'IRD', 'name' => 'X', $field => 'anything']));

    expect($refused->getMessage())->toContain($field);
})->with(['payable_account_id', 'receivable_account_id']);

it('checks the country and the filing frequency', function () {
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctaCreate(['code' => 'A', 'name' => 'X', 'country' => 'lk']), 'lower-case country');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctaCreate(['code' => 'A', 'name' => 'X', 'country' => 'LKA']), 'three letters');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctaCreate(['code' => 'A', 'name' => 'X', 'filing_frequency' => 'weekly']), 'unknown frequency');

    foreach (['monthly', 'quarterly', 'yearly'] as $i => $frequency) {
        ctaCreate(['code' => "F{$i}", 'name' => 'X', 'filing_frequency' => $frequency]);
    }

    expect(PricingSeed::connection()->table('tax_authorities')->count())->toBe(3);
});
