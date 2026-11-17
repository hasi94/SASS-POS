<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\RemoveTaxGroupTax;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\RemoveTaxGroupTax\RemoveTaxGroupTax;
use Flexipos\Pricing\Features\RemoveTaxGroupTax\RemoveTaxGroupTaxHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 12 - removing a tax from a group (AC-9)
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

function rtgtRemove(string $group, string $tax): void
{
    /** @var RemoveTaxGroupTaxHandler $handler */
    $handler = app(RemoveTaxGroupTaxHandler::class);

    $handler->handle(new RemoveTaxGroupTax($group, $tax));
}

it('removes the link and writes one audit row holding the removed row', function () {
    $group = PricingSeed::group('G');
    $tax = PricingSeed::tax(PricingSeed::authority('IRD'), 'VAT');
    PricingSeed::member($group, $tax, 3);

    rtgtRemove($group, $tax);

    expect(PricingSeed::connection()->table('tax_group_taxes')->count())->toBe(0);

    $audit = PricingSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['action' => 'pricing_tax_group_tax_removed', 'subject_id' => "{$group}:{$tax}"])
        ->and($audit['before'])->toBe(['tax_group_id' => $group, 'tax_id' => $tax, 'sequence' => 3])
        ->and($audit['after'])->toBeNull();
});

it('accepts removing the last member, leaving a valid empty group (AC-9)', function () {
    $group = PricingSeed::group('G');
    $tax = PricingSeed::tax(PricingSeed::authority('IRD'), 'VAT');
    PricingSeed::member($group, $tax, 1);

    rtgtRemove($group, $tax);

    expect(PricingSeed::row('tax_groups', $group)['is_active'])->toBe(1);
});

it('refuses a link that does not exist, and leaves the other members alone', function () {
    $group = PricingSeed::group('G');
    $authority = PricingSeed::authority('IRD');
    $kept = PricingSeed::tax($authority, 'A');
    $other = PricingSeed::tax($authority, 'B');
    PricingSeed::member($group, $kept, 1);

    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => rtgtRemove($group, $other));
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => rtgtRemove('not-an-id', $kept));

    expect(PricingSeed::connection()->table('tax_group_taxes')->count())->toBe(1)
        ->and(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
});
