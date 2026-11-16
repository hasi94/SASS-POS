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

it('refuses to change the type or the compound flag once the tax has any rate, closed, open or future, and writes nothing (AC-5a)', function (string $field, mixed $value, string $from, ?string $to) {
    $tax = PricingSeed::tax(PricingSeed::authority('IRD'), 'VAT');
    PricingSeed::rate($tax, '15.0000', $from, $to);
    $before = PricingSeed::row('taxes', $tax);

    $refused = PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => utUpdate($tax, [$field => $value]));

    expect($refused->getMessage())->toContain($field)->toContain('create a new tax')
        ->and(PricingSeed::row('taxes', $tax))->toBe($before)
        ->and(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
})->with([
    'type, closed rate' => ['type', 'fixed', '2020-01-01', '2021-01-01'],
    'type, open-ended rate' => ['type', 'fixed', '2020-01-01', null],
    'type, future rate' => ['type', 'fixed', '2099-01-01', null],
    'compound, closed rate' => ['is_compound', true, '2020-01-01', '2021-01-01'],
    'compound, open-ended rate' => ['is_compound', true, '2020-01-01', null],
    'compound, future rate' => ['is_compound', true, '2099-01-01', null],
]);

it('refuses the frozen change before the fixed-cannot-compound check, and leaves the rest editable (AC-5a, D-42)', function () {
    $authority = PricingSeed::authority('IRD');
    $compound = PricingSeed::tax($authority, 'PC', ['is_compound' => true]);
    $plain = PricingSeed::tax($authority, 'PL');

    PricingSeed::rate($compound, '15.0000', '2020-01-01');
    PricingSeed::rate($plain, '15.0000', '2020-01-01');

    // Both rules apply here (fixed + compound); the frozen one is the one reported.
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => utUpdate($compound, ['type' => 'fixed']), 'frozen first');

    // Controls. Re-sending the stored values with rates present is accepted.
    utUpdate($plain, ['type' => 'percentage', 'is_compound' => false, 'name' => 'Renamed']);
    utUpdate($compound, ['is_compound' => true, 'applies_to' => 'sale', 'is_active' => false]);

    expect(PricingSeed::row('taxes', $plain))->toMatchArray(['type' => 'percentage', 'is_compound' => 0, 'name' => 'Renamed'])
        ->and(PricingSeed::row('taxes', $compound))->toMatchArray(['is_compound' => 1, 'applies_to' => 'sale', 'is_active' => 0]);
});

it('still allows the type and the flag to change while the tax has no rate (AC-5a)', function () {
    $tax = PricingSeed::tax(PricingSeed::authority('IRD'), 'VAT');

    utUpdate($tax, ['type' => 'fixed']);
    utUpdate($tax, ['type' => 'percentage', 'is_compound' => true]);

    expect(PricingSeed::row('taxes', $tax))->toMatchArray(['type' => 'percentage', 'is_compound' => 1]);
});
