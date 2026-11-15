<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\CloseTaxRate;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\CloseTaxRate\CloseTaxRate;
use Flexipos\Pricing\Features\CloseTaxRate\CloseTaxRateHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 11 - closing a tax rate (AC-6, AC-7)
|--------------------------------------------------------------------------
|
| Only `effective_to` and `note` change. `rate` and `effective_from` are what
| documents resolved against, so naming either is an unknown field.
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
function ctrcClose(string $id, array $fields): void
{
    /** @var CloseTaxRateHandler $handler */
    $handler = app(CloseTaxRateHandler::class);

    $handler->handle(new CloseTaxRate($id, $fields));
}

it('closes an open rate and writes one audit row with the row before and after', function () {
    $tax = PricingSeed::tax(PricingSeed::authority('IRD'), 'VAT');
    $rate = PricingSeed::rate($tax, '15.0000', '2024-01-01');

    ctrcClose($rate, ['effective_to' => '2025-01-01', 'note' => 'replaced']);

    expect(PricingSeed::row('tax_rates', $rate))->toMatchArray(['effective_to' => '2025-01-01', 'note' => 'replaced', 'effective_from' => '2024-01-01']);

    $audit = PricingSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['action' => 'pricing_tax_rate_closed', 'subject_id' => $rate])
        ->and($audit['before']['effective_to'])->toBeNull()
        ->and($audit['after']['effective_to'])->toBe('2025-01-01');
});

it('refuses to change the rate or the effective_from, as unknown fields', function (string $field, string $value) {
    $tax = PricingSeed::tax(PricingSeed::authority('IRD'), 'VAT');
    $rate = PricingSeed::rate($tax, '15.0000', '2024-01-01');

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctrcClose($rate, ['effective_to' => '2025-01-01', $field => $value]));

    expect(PricingSeed::row('tax_rates', $rate))->toMatchArray(['effective_to' => null])
        ->and(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
})->with([
    'rate' => ['rate', '16.0000'],
    'effective_from' => ['effective_from', '2024-02-01'],
]);

it('requires an effective_to, and refuses one that inverts the range', function () {
    $tax = PricingSeed::tax(PricingSeed::authority('IRD'), 'VAT');
    $rate = PricingSeed::rate($tax, '15.0000', '2024-01-01');

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctrcClose($rate, ['note' => 'x']), 'no effective_to');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctrcClose($rate, ['effective_to' => '2023-12-31']), 'before the start');
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => ctrcClose('01ARZ3NDEKTSV4RRFFQ69G5FAV', ['effective_to' => '2025-01-01']), 'unknown rate');
});

it('refuses an effective_to that would make the range overlap the next rate, and accepts one that touches it', function () {
    $tax = PricingSeed::tax(PricingSeed::authority('IRD'), 'VAT');
    // Open-ended: a closed rate cannot be closed again (AC-6a). The later row is raw-seeded.
    $first = PricingSeed::rate($tax, '15.0000', '2024-01-01');
    PricingSeed::rate($tax, '18.0000', '2025-06-01');

    PricingAdminRefusals::expect(PricingAdminRefusal::Overlap, fn () => ctrcClose($first, ['effective_to' => '2025-07-01']));

    ctrcClose($first, ['effective_to' => '2025-06-01']);

    expect(PricingSeed::row('tax_rates', $first)['effective_to'])->toBe('2025-06-01');
});

it('refuses to close a rate that is already closed, earlier, later or equal, and changes nothing (AC-6a)', function (string $newEnd) {
    $tax = PricingSeed::tax(PricingSeed::authority('IRD'), 'VAT');
    $rate = PricingSeed::rate($tax, '15.0000', '2024-01-01', '2025-01-01');
    $before = PricingSeed::row('tax_rates', $rate);

    $refused = PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctrcClose($rate, ['effective_to' => $newEnd, 'note' => 'again']));

    expect($refused->getMessage())->toContain('already closed')
        ->and(PricingSeed::row('tax_rates', $rate))->toBe($before)
        ->and(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
})->with([
    'earlier' => ['2024-06-01'],
    'later' => ['2026-01-01'],
    'equal' => ['2025-01-01'],
]);
