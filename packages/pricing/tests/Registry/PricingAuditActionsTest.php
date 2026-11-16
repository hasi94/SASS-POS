<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Registry;

use Flexipos\Access\Support\AuditCatalogue;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 11 to 15 - the declared audit actions
|--------------------------------------------------------------------------
|
| The list is built from each handler's own ACTION constant, so the check that
| matters is that every handler is in it exactly once and that the host's
| catalogue, which is what `AuditTrail::record()` validates against, knows them.
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

it('declares one action per write slice, each once, all under the pricing module', function () {
    expect(PricingAuditActions::ALL)->toHaveCount(21)
        ->and(array_unique(PricingAuditActions::ALL))->toHaveCount(21)
        ->and(PricingAuditActions::MODULE)->toBe('pricing');

    foreach (PricingAuditActions::ALL as $action) {
        expect($action)->toStartWith('pricing_');
    }
});

it('is known to the host audit catalogue under the pricing module', function () {
    /** @var AuditCatalogue $catalogue */
    $catalogue = app(AuditCatalogue::class);

    expect($catalogue->modules())->toContain('pricing');

    foreach (PricingAuditActions::ALL as $action) {
        expect($catalogue->knows('pricing', $action))->toBeTrue();
    }

    expect($catalogue->knows('pricing', 'pricing_no_such_action'))->toBeFalse();
});

it('has a handler constant for every slice folder, so a new slice cannot be left out', function () {
    $folders = array_map('basename', glob(dirname(__DIR__, 2).'/src/Features/*', GLOB_ONLYDIR) ?: []);

    $actions = [];

    foreach ($folders as $folder) {
        $handler = "Flexipos\\Pricing\\Features\\{$folder}\\{$folder}Handler";
        $actions[] = constant("{$handler}::ACTION");
    }

    sort($actions);
    $declared = PricingAuditActions::ALL;
    sort($declared);

    expect($actions)->toBe($declared);
});
