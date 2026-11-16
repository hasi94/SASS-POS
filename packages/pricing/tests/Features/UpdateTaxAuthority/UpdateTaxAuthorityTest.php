<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\UpdateTaxAuthority;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\UpdateTaxAuthority\UpdateTaxAuthority;
use Flexipos\Pricing\Features\UpdateTaxAuthority\UpdateTaxAuthorityHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 11 - updating a tax authority (AC-4)
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
function utaUpdate(string $id, array $fields): void
{
    /** @var UpdateTaxAuthorityHandler $handler */
    $handler = app(UpdateTaxAuthorityHandler::class);

    $handler->handle(new UpdateTaxAuthority($id, $fields));
}

it('updates the named fields and writes one audit row with the row before and after', function () {
    $id = PricingSeed::authority('IRD');

    utaUpdate($id, ['name' => 'Renamed', 'filing_frequency' => 'yearly']);

    expect(PricingSeed::row('tax_authorities', $id))->toMatchArray(['code' => 'IRD', 'name' => 'Renamed', 'filing_frequency' => 'yearly']);

    $audit = PricingSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['action' => 'pricing_tax_authority_updated', 'subject_id' => $id])
        ->and($audit['before']['name'])->toBe('Authority IRD')
        ->and($audit['after']['name'])->toBe('Renamed');
});

it('refuses an unknown authority, an empty update, an unknown field and a code taken by case', function () {
    $id = PricingSeed::authority('IRD');
    PricingSeed::authority('OTHER');

    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => utaUpdate('01ARZ3NDEKTSV4RRFFQ69G5FAV', ['name' => 'X']), 'unknown');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => utaUpdate($id, []), 'empty');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => utaUpdate($id, ['payable_account_id' => 'x']), 'account column');
    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => utaUpdate($id, ['code' => 'other']), 'code by case');

    expect(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
});

it('lets an authority keep its own code, by case or not', function () {
    $id = PricingSeed::authority('IRD');

    utaUpdate($id, ['code' => 'ird']);

    expect(PricingSeed::row('tax_authorities', $id)['code'])->toBe('ird');
});
