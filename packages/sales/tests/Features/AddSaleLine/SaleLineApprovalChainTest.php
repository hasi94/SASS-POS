<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\AddSaleLine;

use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLine;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLineHandler;
use Flexipos\Sales\Features\DecideApproval\DecideApproval;
use Flexipos\Sales\Features\DecideApproval\DecideApprovalHandler;
use Flexipos\Sales\Features\RequestApproval\RequestApproval;
use Flexipos\Sales\Features\RequestApproval\RequestApprovalHandler;
use Flexipos\Sales\Features\UpdateSaleLine\UpdateSaleLine;
use Flexipos\Sales\Features\UpdateSaleLine\UpdateSaleLineHandler;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| One approval covers one line (T-030 BUG-1; AC-7, AC-23a, AC-24, AC-55; D-59)
|--------------------------------------------------------------------------
|
| Through the real chain: RequestApproval -> DecideApproval (a real PIN) -> the
| consuming write. A consumed approval covers a re-price of THE LINE that carries
| it (UpdateSaleLine) and nothing else: not a second new line, and not another
| line moved onto the same price or discount.
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/sales-fixture.php';
require_once __DIR__.'/../../Support/approval-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    salesEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @return array<string, mixed>
 */
function slcWorld(): array
{
    app(SyncPermissionRegistryHandler::class)->handle(new SyncPermissionRegistry);

    $till = SalesWorld::till();
    $group = SalesWorld::taxGroup('TXC', '10.0000', false);
    $catalogue = SalesWorld::product('PC', '10.0000', $group, ['allow_price_override' => true]);

    $manager = apvEmployeeWithPin('manager');
    AccessSeed::attach($manager, $till['branch']);
    AccessSeed::assign($manager, AccessSeed::role('role_manager', [APV_KEY]), $till['branch']);

    return ['till' => $till, 'sale' => SalesWorld::sale($till), 'product' => $catalogue['product'], 'unit' => $catalogue['unit']];
}

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $payload
 */
function slcApproval(array $w, string $type, array $payload): string
{
    $id = app(RequestApprovalHandler::class)->handle(new RequestApproval(['branch_id' => $w['till']['branch'], 'type' => $type, 'reference_type' => 'sale', 'reference_id' => $w['sale'], 'payload' => $payload], $w['till']['cashier']))->approvalId;

    app(DecideApprovalHandler::class)->handle(new DecideApproval(['approval_id' => $id, 'employee_code' => 'manager', 'pin' => APV_PIN, 'decision' => 'approved']));

    return $id;
}

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $fields
 */
function slcAdd(array $w, array $fields = []): string
{
    return app(AddSaleLineHandler::class)->handle(new AddSaleLine(array_merge(['sale_id' => $w['sale'], 'product_id' => $w['product'], 'unit_id' => $w['unit'], 'quantity' => '1'], $fields), $w['till']['cashier']))->lineId;
}

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $fields
 */
function slcUpdate(array $w, string $lineId, array $fields): void
{
    app(UpdateSaleLineHandler::class)->handle(new UpdateSaleLine(array_merge(['sale_id' => $w['sale'], 'line_id' => $lineId], $fields), $w['till']['cashier']));
}

function slcConsumed(): int
{
    return SalesSeed::connection()->table('approval_requests')->whereNotNull('consumed_at')->count();
}

it('covers the line that carries a price override and no other line: a second new line, or a plain line moved to the price, needs its own approval', function () {
    $w = slcWorld();
    $payload = ['product_id' => $w['product'], 'unit_id' => $w['unit'], 'unit_price' => '8.0000'];

    $first = slcAdd($w, ['unit_price' => '8.0000', 'approval_id' => slcApproval($w, 'price_override', $payload)]);

    // The same line, re-priced by quantity: covered, no second approval consumed.
    slcUpdate($w, $first, ['quantity' => '3']);
    expect(slcConsumed())->toBe(1)
        ->and(SalesSeed::row('sale_items', $first)['unit_price'])->toEqual('8.0000');

    // A second NEW line at the same override price: refused.
    SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => slcAdd($w, ['unit_price' => '8.0000']));

    // A plain line moved onto the same override price through UpdateSaleLine: refused.
    $plain = slcAdd($w);
    SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => slcUpdate($w, $plain, ['unit_price' => '8.0000']));

    expect(SalesSeed::connection()->table('sale_items')->where('sale_id', $w['sale'])->count())->toBe(2)
        ->and(SalesSeed::row('sale_items', $plain)['price_overridden_by'])->toBeNull()
        ->and(slcConsumed())->toBe(1);

    // With its own approval the second line is accepted.
    $second = slcAdd($w, ['unit_price' => '8.0000', 'approval_id' => slcApproval($w, 'price_override', $payload)]);

    expect(SalesSeed::row('sale_items', $second)['price_overridden_by'])->not->toBeNull()
        ->and(slcConsumed())->toBe(2);
});

it('covers the line that carries a gated discount and no other line: a second new line, or a plain line moved to the discount, needs its own approval', function () {
    $w = slcWorld();
    $gated = PricingSeed::discount('CHAINGATE', ['requires_approval' => true]);

    $first = slcAdd($w, ['discount_id' => $gated, 'approval_id' => slcApproval($w, 'discount', ['discount_id' => $gated])]);

    slcUpdate($w, $first, ['quantity' => '3']);
    expect(slcConsumed())->toBe(1)
        ->and(SalesSeed::row('sale_items', $first)['discount_id'])->toBe($gated);

    SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => slcAdd($w, ['discount_id' => $gated]));

    $plain = slcAdd($w);
    SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => slcUpdate($w, $plain, ['discount_id' => $gated]));

    expect(SalesSeed::connection()->table('sale_items')->where('sale_id', $w['sale'])->count())->toBe(2)
        ->and(SalesSeed::row('sale_items', $plain)['discount_id'])->toBeNull()
        ->and(slcConsumed())->toBe(1);

    $second = slcAdd($w, ['discount_id' => $gated, 'approval_id' => slcApproval($w, 'discount', ['discount_id' => $gated])]);

    expect(SalesSeed::row('sale_items', $second)['discount_id'])->toBe($gated)
        ->and(slcConsumed())->toBe(2);
});
