<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\CreateTaxRate;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\CreateTaxRate\CreateTaxRate;
use Flexipos\Pricing\Features\CreateTaxRate\CreateTaxRateHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 11 - creating a tax rate and the overlap rule (AC-6)
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
function ctrCreate(array $fields): string
{
    /** @var CreateTaxRateHandler $handler */
    $handler = app(CreateTaxRateHandler::class);

    return $handler->handle(new CreateTaxRate($fields))->taxRateId;
}

function ctrTax(): string
{
    return PricingSeed::tax(PricingSeed::authority('IRD'), 'VAT');
}

it('creates a rate as a percentage stored as typed, and writes one audit row', function () {
    $tax = ctrTax();

    $id = ctrCreate(['tax_id' => $tax, 'rate' => '15.0000', 'effective_from' => '2024-01-01', 'note' => 'standard']);

    expect(PricingSeed::row('tax_rates', $id))->toMatchArray(['tax_id' => $tax, 'effective_from' => '2024-01-01', 'effective_to' => null, 'note' => 'standard'])
        ->and((float) PricingSeed::row('tax_rates', $id)['rate'])->toBe(15.0);

    expect(PricingSeed::onlyAuditRow())->toMatchArray(['action' => 'pricing_tax_rate_created', 'subject_type' => 'tax_rate', 'subject_id' => $id]);
});

it('refuses a negative or over-wide rate, a missing rate, an unknown tax and a bad date', function () {
    $tax = ctrTax();
    $base = ['tax_id' => $tax, 'rate' => '15', 'effective_from' => '2024-01-01'];

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctrCreate([...$base, 'rate' => '-1']), 'negative');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctrCreate([...$base, 'rate' => '100000']), 'six integer digits');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctrCreate([...$base, 'rate' => '1.00005']), 'five decimals');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctrCreate([...$base, 'rate' => 0.15]), 'a float');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctrCreate(['tax_id' => $tax, 'effective_from' => '2024-01-01']), 'no rate');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctrCreate([...$base, 'effective_from' => '2024-13-01']), 'bad date');
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => ctrCreate([...$base, 'tax_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']), 'unknown tax');

    expect(PricingSeed::connection()->table('tax_rates')->count())->toBe(0)
        ->and(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
});

it('accepts a zero rate, which is a legitimate exempt rate', function () {
    ctrCreate(['tax_id' => ctrTax(), 'rate' => '0', 'effective_from' => '2024-01-01']);

    expect(PricingSeed::connection()->table('tax_rates')->count())->toBe(1);
});

it('refuses an effective_to before the effective_from, and accepts an empty range edge', function () {
    $tax = ctrTax();

    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => ctrCreate(['tax_id' => $tax, 'rate' => '5', 'effective_from' => '2024-06-01', 'effective_to' => '2024-05-31']));

    ctrCreate(['tax_id' => $tax, 'rate' => '5', 'effective_from' => '2024-06-01', 'effective_to' => '2024-06-01']);

    expect(PricingSeed::connection()->table('tax_rates')->count())->toBe(1);
});

it('refuses a second open-ended row for the same tax, because an open end is forever', function () {
    $tax = ctrTax();

    ctrCreate(['tax_id' => $tax, 'rate' => '15', 'effective_from' => '2024-01-01']);

    PricingAdminRefusals::expect(PricingAdminRefusal::Overlap, fn () => ctrCreate(['tax_id' => $tax, 'rate' => '18', 'effective_from' => '2026-01-01']), 'later open row');
    PricingAdminRefusals::expect(PricingAdminRefusal::Overlap, fn () => ctrCreate(['tax_id' => $tax, 'rate' => '18', 'effective_from' => '2020-01-01']), 'earlier open row');

    expect(PricingSeed::connection()->table('tax_rates')->count())->toBe(1);
});

it('accepts a closed range followed by a range that opens on the day it closes', function () {
    $tax = ctrTax();

    ctrCreate(['tax_id' => $tax, 'rate' => '15', 'effective_from' => '2024-01-01', 'effective_to' => '2025-01-01']);
    ctrCreate(['tax_id' => $tax, 'rate' => '18', 'effective_from' => '2025-01-01']);

    expect(PricingSeed::connection()->table('tax_rates')->count())->toBe(2);
});

it('refuses a range that starts inside, ends inside or swallows an existing one', function (string $from, ?string $to) {
    $tax = ctrTax();

    ctrCreate(['tax_id' => $tax, 'rate' => '15', 'effective_from' => '2024-01-01', 'effective_to' => '2025-01-01']);
    ctrCreate(['tax_id' => $tax, 'rate' => '18', 'effective_from' => '2025-01-01']);

    PricingAdminRefusals::expect(PricingAdminRefusal::Overlap, fn () => ctrCreate(['tax_id' => $tax, 'rate' => '1', 'effective_from' => $from, 'effective_to' => $to]));
})->with([
    'starts inside the closed range' => ['2024-06-01', null],
    'a closed range inside the closed range' => ['2024-03-01', '2024-04-01'],
    'ends the day after the open range starts' => ['2024-12-01', '2025-01-02'],
    'swallows the closed range' => ['2023-01-01', '2025-06-01'],
]);

it('accepts a range wholly before the first existing one, ending on its first day', function () {
    $tax = ctrTax();

    ctrCreate(['tax_id' => $tax, 'rate' => '15', 'effective_from' => '2024-01-01']);
    ctrCreate(['tax_id' => $tax, 'rate' => '10', 'effective_from' => '2023-01-01', 'effective_to' => '2024-01-01']);

    expect(PricingSeed::connection()->table('tax_rates')->count())->toBe(2);
});

it('does not compare ranges across two taxes', function () {
    $authority = PricingSeed::authority('IRD');
    $a = PricingSeed::tax($authority, 'A');
    $b = PricingSeed::tax($authority, 'B');

    ctrCreate(['tax_id' => $a, 'rate' => '15', 'effective_from' => '2024-01-01']);
    ctrCreate(['tax_id' => $b, 'rate' => '15', 'effective_from' => '2024-01-01']);

    expect(PricingSeed::connection()->table('tax_rates')->count())->toBe(2);
});

it('refuses the same effective_from for one tax as a duplicate, backed by the unique key', function () {
    $tax = ctrTax();

    ctrCreate(['tax_id' => $tax, 'rate' => '15', 'effective_from' => '2024-01-01', 'effective_to' => '2025-01-01']);

    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => ctrCreate(['tax_id' => $tax, 'rate' => '16', 'effective_from' => '2024-01-01', 'effective_to' => '2024-02-01']));
});
