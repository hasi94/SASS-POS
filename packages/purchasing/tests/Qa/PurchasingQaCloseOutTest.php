<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Qa;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Flexipos\Purchasing\Features\UpdatePurchaseOrderLine\UpdatePurchaseOrderLine;
use Flexipos\Purchasing\Features\UpdatePurchaseOrderLine\UpdatePurchaseOrderLineHandler;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 QA close-out - behaviours a mutation of the production code left green
|--------------------------------------------------------------------------
|
| Each test here was added because a deliberate fault in the production code
| survived the developer's suite (see docs/qa/T-034-suppliers-purchase-orders.md,
| the mutation table). Every one was shown red against its fault.
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/purchasing-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    puEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

it('AC-44 and AC-49: an edited line is priced at the order s own date, for a cost change and for a quantity change, not at the day of the edit', function () {
    $desk = PurchasingWorld::desk('qc1');
    $tax = PricingSeed::tax(PricingSeed::authority('QCA'), 'QCAVAT');
    // 10 percent until the end of 2026-11-16 (the day the fixture clock stands at), 20 percent from the next day.
    PricingSeed::rate($tax, '10.0000', '2020-01-01', '2026-11-16');
    PricingSeed::rate($tax, '20.0000', '2026-11-17');
    $group = PricingSeed::group('QCAG');
    PricingSeed::member($group, $tax, 1);

    // An order dated before the change, holding one stored line; the edit is made "today" which is the same day here,
    // so the clock is moved to after the change to make the edit day differ from the order day.
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], [
        'order_date' => '2026-11-10',
        'created_by' => $desk['buyer'],
        'updated_by' => $desk['buyer'],
    ]);
    $line = PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], $group, 1, [
        'quantity' => '10.0000', 'base_quantity' => '10.0000', 'unit_cost' => '10.0000', 'subtotal' => '100.0000',
        'tax_amount' => '10.0000', 'line_total' => '110.0000',
    ]);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-20 09:00:00.000'));

    /** @var UpdatePurchaseOrderLineHandler $handler */
    $handler = app(UpdatePurchaseOrderLineHandler::class);

    // a cost change: re-priced through the stored quad
    $byCost = $handler->handle(new UpdatePurchaseOrderLine(['order_id' => $order, 'line_id' => $line, 'unit_cost' => '20'], $desk['buyer']));

    expect($byCost->line['subtotal'])->toBe('200.0000')
        ->and($byCost->line['tax_amount'])->toBe('20.0000');

    // a quantity change: re-snapshotted and re-priced
    $byQuantity = $handler->handle(new UpdatePurchaseOrderLine(['order_id' => $order, 'line_id' => $line, 'quantity' => '30'], $desk['buyer']));

    expect($byQuantity->line['subtotal'])->toBe('600.0000')
        ->and($byQuantity->line['tax_amount'])->toBe('60.0000');
});
