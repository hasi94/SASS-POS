<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Registry;

use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\DeclaresPermissions;
use Flexipos\Contracts\Audit\DeclaresAuditActions;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\PurchasingServiceProvider;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 6 - what the package declares and refuses
|--------------------------------------------------------------------------
|
| The refusal vocabulary is a closed list of 32 (plan "Refusal vocabulary"), the
| audit actions are the spec preamble's 28 and nothing else (D-31), and the eight
| permission keys reach the tenant's `permissions` table through the real
| registry sync with the scope the plan gives each (Ruling 9, AC-95).
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

it('holds exactly the 32 refusal codes the plan lists, in their six areas', function () {
    $codes = array_map(static fn (PurchasingRefusal $reason): string => $reason->value, PurchasingRefusal::cases());

    expect($codes)->toBe([
        'validation', 'not_found', 'out_of_range', 'replay_conflict', 'invalid_transition', 'locked', 'not_authorised', 'too_many_lines', 'no_lines',
        'duplicate_code', 'code_immutable', 'supplier_in_use', 'supplier_inactive', 'unknown_supplier', 'supplier_mismatch', 'currency_required',
        'unknown_branch', 'unknown_location', 'unknown_product', 'product_not_purchasable',
        'unit_unresolvable', 'inherited_unit_mismatch', 'pricing_refused', 'no_default_tax_group', 'unknown_tax_group', 'inactive_tax_group',
        'numbering_refused', 'number_too_long', 'workflow_failed',
        'already_received', 'order_closed', 'order_not_open',
    ])->and($codes)->toHaveCount(32)
        ->and($codes)->toBe(array_values(array_unique($codes)));
});

it('declares exactly the spec preamble s 28 audit actions, in its order, each once, under the module purchasing', function () {
    $preamble = [
        'purchasing_supplier_created', 'purchasing_supplier_updated', 'purchasing_supplier_deactivated', 'purchasing_supplier_activated', 'purchasing_supplier_deleted',
        'purchasing_request_created', 'purchasing_request_updated', 'purchasing_request_line_added', 'purchasing_request_line_updated', 'purchasing_request_line_removed',
        'purchasing_request_submitted', 'purchasing_request_approved', 'purchasing_request_rejected', 'purchasing_request_returned_to_draft', 'purchasing_request_cancelled', 'purchasing_request_converted',
        'purchasing_order_created', 'purchasing_order_updated', 'purchasing_order_line_added', 'purchasing_order_line_updated', 'purchasing_order_line_removed',
        'purchasing_order_submitted', 'purchasing_order_approved', 'purchasing_order_returned_to_draft', 'purchasing_order_reopened', 'purchasing_order_cancelled', 'purchasing_order_closed', 'purchasing_order_receipt_applied',
    ];

    $provider = new PurchasingServiceProvider(app());

    expect($provider)->toBeInstanceOf(DeclaresAuditActions::class)
        ->and(PurchasingAuditActions::ALL)->toBe($preamble)
        ->and($preamble)->toHaveCount(28)
        ->and($provider->auditActions())->toBe(['purchasing' => $preamble])
        ->and(PurchasingAuditActions::MODULE)->toBe('purchasing');

    foreach (PurchasingAuditActions::ALL as $action) {
        expect($action)->toStartWith('purchasing_')->and($action)->not->toContain('.');
    }
});

it('declares the eight keys, the two supplier keys tenant-wide and the six document keys branch-scoped', function () {
    $declarations = PurchasingPermissions::declarations();

    $scope = [];
    foreach ($declarations as $declaration) {
        $scope[$declaration->key] = $declaration->isBranchScoped;
        expect($declaration->module)->toBe('purchasing');
    }

    expect($scope)->toBe([
        'purchasing.suppliers.view' => false,
        'purchasing.suppliers.manage' => false,
        'purchasing.requests.view' => true,
        'purchasing.requests.manage' => true,
        'purchasing.orders.view' => true,
        'purchasing.orders.manage' => true,
        'purchasing.orders.close' => true,
        'purchasing.orders.reopen' => true,
    ])->and($declarations)->toHaveCount(8);

    $provider = new PurchasingServiceProvider(app());

    expect($provider)->toBeInstanceOf(DeclaresPermissions::class)
        ->and($provider->permissionDeclarations())->toEqual($declarations);
});

it('reaches the permissions table through the real registry sync with each key s scope (AC-95)', function () {
    /** @var SyncPermissionRegistryHandler $sync */
    $sync = app(SyncPermissionRegistryHandler::class);
    $sync->handle(new SyncPermissionRegistry);

    foreach (PurchasingPermissions::declarations() as $declaration) {
        $row = AccessSeed::connection()->table('permissions')->where('key', $declaration->key)->first();

        expect($row)->not->toBeNull()
            ->and($row->module)->toBe('purchasing')
            ->and((bool) $row->is_branch_scoped)->toBe($declaration->isBranchScoped);
    }
});
