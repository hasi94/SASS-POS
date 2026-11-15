<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Features\AddTaxGroupTax;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Features\AddTaxGroupTax\AddTaxGroupTax;
use Flexipos\Pricing\Features\AddTaxGroupTax\AddTaxGroupTaxHandler;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use Flexipos\Pricing\Tests\Support\PricingAdminRefusals;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 12 - adding a tax to a group (AC-9)
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
function atgtAdd(array $fields): void
{
    /** @var AddTaxGroupTaxHandler $handler */
    $handler = app(AddTaxGroupTaxHandler::class);

    $handler->handle(new AddTaxGroupTax($fields));
}

it('links a tax to a group at a sequence and writes one audit row', function () {
    $group = PricingSeed::group('G');
    $tax = PricingSeed::tax(PricingSeed::authority('IRD'), 'VAT');

    atgtAdd(['tax_group_id' => strtolower($group), 'tax_id' => $tax, 'sequence' => 2]);

    expect((array) PricingSeed::connection()->table('tax_group_taxes')->where('tax_group_id', $group)->first())->toBe(['tax_group_id' => $group, 'tax_id' => $tax, 'sequence' => 2]);
    expect(PricingSeed::onlyAuditRow())->toMatchArray(['action' => 'pricing_tax_group_tax_added', 'subject_type' => 'tax_group_tax', 'subject_id' => "{$group}:{$tax}"]);
});

it('refuses an unknown group or tax, a missing or out-of-range sequence, and a float sequence', function () {
    $group = PricingSeed::group('G');
    $tax = PricingSeed::tax(PricingSeed::authority('IRD'), 'VAT');

    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => atgtAdd(['tax_group_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'tax_id' => $tax, 'sequence' => 1]), 'unknown group');
    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => atgtAdd(['tax_group_id' => $group, 'tax_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'sequence' => 1]), 'unknown tax');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => atgtAdd(['tax_group_id' => $group, 'tax_id' => $tax]), 'no sequence');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => atgtAdd(['tax_group_id' => $group, 'tax_id' => $tax, 'sequence' => 256]), 'above 255');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => atgtAdd(['tax_group_id' => $group, 'tax_id' => $tax, 'sequence' => -1]), 'negative');
    PricingAdminRefusals::expect(PricingAdminRefusal::Validation, fn () => atgtAdd(['tax_group_id' => $group, 'tax_id' => $tax, 'sequence' => 1.5]), 'a float');

    expect(PricingSeed::connection()->table('tax_group_taxes')->count())->toBe(0)
        ->and(PricingSeed::connection()->table('audit_logs')->count())->toBe(0);
});

it('accepts the sequence edges 0 and 255', function () {
    $group = PricingSeed::group('G');
    $authority = PricingSeed::authority('IRD');

    atgtAdd(['tax_group_id' => $group, 'tax_id' => PricingSeed::tax($authority, 'A'), 'sequence' => 0]);
    atgtAdd(['tax_group_id' => $group, 'tax_id' => PricingSeed::tax($authority, 'B'), 'sequence' => 255]);

    expect(PricingSeed::connection()->table('tax_group_taxes')->count())->toBe(2);
});

it('refuses the same tax twice in a group and the same sequence twice in a group', function () {
    $group = PricingSeed::group('G');
    $authority = PricingSeed::authority('IRD');
    $a = PricingSeed::tax($authority, 'A');
    $b = PricingSeed::tax($authority, 'B');

    atgtAdd(['tax_group_id' => $group, 'tax_id' => $a, 'sequence' => 1]);

    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => atgtAdd(['tax_group_id' => $group, 'tax_id' => $a, 'sequence' => 2]), 'same pair');
    PricingAdminRefusals::expect(PricingAdminRefusal::Duplicate, fn () => atgtAdd(['tax_group_id' => $group, 'tax_id' => $b, 'sequence' => 1]), 'same sequence');

    expect(PricingSeed::connection()->table('tax_group_taxes')->count())->toBe(1);
});

it('lets two groups hold the same tax and the same sequence', function () {
    $authority = PricingSeed::authority('IRD');
    $tax = PricingSeed::tax($authority, 'A');

    atgtAdd(['tax_group_id' => PricingSeed::group('G1'), 'tax_id' => $tax, 'sequence' => 1]);
    atgtAdd(['tax_group_id' => PricingSeed::group('G2'), 'tax_id' => $tax, 'sequence' => 1]);

    expect(PricingSeed::connection()->table('tax_group_taxes')->count())->toBe(2);
});

it('refuses a soft-deleted tax', function () {
    $group = PricingSeed::group('G');
    $tax = PricingSeed::tax(PricingSeed::authority('IRD'), 'A', ['deleted_at' => PricingSeed::NOW]);

    PricingAdminRefusals::expect(PricingAdminRefusal::NotFound, fn () => atgtAdd(['tax_group_id' => $group, 'tax_id' => $tax, 'sequence' => 1]));
});
