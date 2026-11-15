<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Registry;

use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Audit\DeclaresAuditActions;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Registry\SalesPermissions;
use Flexipos\Sales\SalesServiceProvider;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 chunk B - what the package declares and refuses
|--------------------------------------------------------------------------
|
| The refusal vocabulary is a closed list (plan section 4), the audit actions
| come from the handlers' own constants, and the one permission key built so far
| reaches the tenant's `permissions` table through the real registry sync, as a
| branch-scoped key (R-25).
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

it('holds exactly the refusal codes the plan lists, in their six areas', function () {
    $codes = array_map(static fn (SalesRefusal $reason): string => $reason->value, SalesRefusal::cases());

    expect($codes)->toBe([
        'validation', 'not_found', 'duplicate', 'in_use', 'out_of_range', 'invalid_transition', 'numbering_refused',
        'no_open_shift', 'shift_already_open', 'shift_not_open', 'shift_has_open_sales', 'shift_closed',
        'sale_not_editable', 'unit_unresolvable', 'unresolvable_price', 'pricing_refused', 'price_override_not_allowed', 'discount_with_override', 'empty_sale', 'product_not_sellable',
        'customer_required', 'credit_refused', 'method_inactive', 'unsupported_method_type', 'reference_required', 'exceeds_balance', 'partial_not_allowed', 'balance_due', 'tender_not_removable',
        'approval_required', 'approval_not_approved', 'approval_already_used', 'approval_mismatch', 'approval_not_pending', 'self_approval_not_allowed', 'pin_invalid', 'not_authorised',
        'sale_not_voidable', 'not_refundable', 'over_refund', 'fully_refunded', 'inherited_unit_mismatch', 'replay_conflict',
    ])->and($codes)->toHaveCount(43);
});

it('declares its audit actions to the catalogue under the module sales, each once', function () {
    $provider = new SalesServiceProvider(app());

    expect($provider)->toBeInstanceOf(DeclaresAuditActions::class)
        ->and($provider->auditActions())->toBe(['sales' => SalesAuditActions::ALL])
        ->and(SalesAuditActions::ALL)->toBe(array_values(array_unique(SalesAuditActions::ALL)))
        ->and(SalesAuditActions::ALL)->not->toBe([]);

    foreach (SalesAuditActions::ALL as $action) {
        expect($action)->toStartWith('sales_');
    }
});

it('declares sales.approvals.decide as a branch-scoped key', function () {
    $declarations = SalesPermissions::declarations();

    expect($declarations)->toHaveCount(1)
        ->and($declarations[0]->key)->toBe('sales.approvals.decide')
        ->and($declarations[0]->module)->toBe('sales')
        ->and($declarations[0]->isBranchScoped)->toBeTrue()
        ->and((new SalesServiceProvider(app()))->permissionDeclarations())->toEqual($declarations);
});

it('reaches the permissions table through the real registry sync, branch-scoped', function () {
    /** @var SyncPermissionRegistryHandler $sync */
    $sync = app(SyncPermissionRegistryHandler::class);
    $sync->handle(new SyncPermissionRegistry);

    $row = SalesSeed::connection()->table('permissions')->where('key', 'sales.approvals.decide')->first();

    expect($row)->not->toBeNull()
        ->and($row->module)->toBe('sales')
        ->and((bool) $row->is_branch_scoped)->toBeTrue();
});
