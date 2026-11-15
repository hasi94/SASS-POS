<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\UpdateTax;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\UpdateTax\UpdateTax;
use Flexipos\Pricing\Features\UpdateTax\UpdateTaxHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 11 - updating a tax (AC-5, F-9)
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
function utUpdate(string $id, array $fields): void
{
    /** @var UpdateTaxHandler $handler */
    $handler = app(UpdateTaxHandler::class);

    $handler->handle(new UpdateTax($id, $fields));
}

it('updates the named fields and writes one audit row', function () {
    $tax = PricingSeed::tax(PricingSeed::authority('IRD'), 'VAT');

    utUpdate($tax, ['name' => 'Value added tax', 'is_active' => false, 'tax_code' => 'A']);

    expect(PricingSeed::row('taxes', $tax))->toMatchArray(['name' => 'Value added tax', 'is_active' => 0, 'tax_code' => 'A', 'code' => 'VAT']);
    expect(PricingSeed::onlyAuditRow())->toMatchArray(['action' => 'pricing_tax_updated', 'subject_id' => $tax]);
});

it('does not take the authority as an update field', function () {
    $tax = PricingSeed::tax(PricingSeed::authority('IRD'), 'VAT');

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => utUpdate($tax, ['authority_id' => PricingSeed::authority('OTHER')]));
});

it('refuses fixed together with compound against the row as it would be after the update', function () {
    $authority = PricingSeed::authority('IRD');
    $percentCompound = PricingSeed::tax($authority, 'PC', ['is_compound' => true]);
    $fixed = PricingSeed::tax($authority, 'FX', ['type' => 'fixed']);

    // Changing only the type, or only the flag, still produces the forbidden pair.
    PricingAdminRefusals::expect(PricingAdminRefusal::FixedCannotCompound, fn () => utUpdate($percentCompound, ['type' => 'fixed']), 'type only');
    PricingAdminRefusals::expect(PricingAdminRefusal::FixedCannotCompound, fn () => utUpdate($fixed, ['is_compound' => true]), 'flag only');

    utUpdate($percentCompound, ['type' => 'fixed', 'is_compound' => false]);

    expect(PricingSeed::row('taxes', $percentCompound))->toMatchArray(['type' => 'fixed', 'is_compound' => 0]);
});

it('refuses an unknown tax, an empty update, a null type and a code taken by case', function () {
    $authority = PricingSeed::authority('IRD');
    $tax = PricingSeed::tax($authority, 'VAT');
    PricingSeed::tax($authority, 'OTHER');

    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => utUpdate('01ARZ3NDEKTSV4RRFFQ69G5FAV', ['name' => 'X']));
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => utUpdate($tax, []));
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => utUpdate($tax, ['type' => null]));
    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => utUpdate($tax, ['code' => 'other']));

    expect(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
});
