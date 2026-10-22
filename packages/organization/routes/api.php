<?php

use App\Http\Controllers\Organization\ChangeBranchStatusController;
use App\Http\Controllers\Organization\ChangeLocationStatusController;
use App\Http\Controllers\Organization\ChangeTerminalStatusController;
use App\Http\Controllers\Organization\ClearSettingController;
use App\Http\Controllers\Organization\CreateBranchController;
use App\Http\Controllers\Organization\CreateTerminalController;
use App\Http\Controllers\Organization\CreateWarehouseController;
use App\Http\Controllers\Organization\GetBranchController;
use App\Http\Controllers\Organization\GetLocationController;
use App\Http\Controllers\Organization\GetTerminalController;
use App\Http\Controllers\Organization\ListBranchesController;
use App\Http\Controllers\Organization\ListLocationsController;
use App\Http\Controllers\Organization\ListSettingsController;
use App\Http\Controllers\Organization\ListTerminalDevicesController;
use App\Http\Controllers\Organization\ListTerminalsController;
use App\Http\Controllers\Organization\ReissueActivationCodeController;
use App\Http\Controllers\Organization\RevokeTerminalController;
use App\Http\Controllers\Organization\SetSettingController;
use App\Http\Controllers\Organization\UpdateBranchController;
use App\Http\Controllers\Organization\UpdateLocationController;
use App\Http\Controllers\Organization\UpdateTerminalController;
use App\Http\Controllers\Terminal\ListTerminalSettingsController;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Middleware\RequireTenantToken;
use App\Http\Middleware\RequireTerminalToken;
use Flexipos\Organization\Registry\OrganizationPermissions;
use Illuminate\Support\Facades\Route;

/*
 * The tenant routes of T-014, mounted by OrganizationServiceProvider.
 *
 * The shape is `packages/access/routes/api.php`'s: the `api` middleware group,
 * an explicit `api/v1/...` prefix, an invokable controller for every route,
 * and **exactly two middleware on every employee route**:
 * `RequireTenantToken` (verify the token, enter the shop, refuse a stale
 * claim set) and `RequireTenantPermission` declaring the one key the route
 * needs. The declaration is a middleware parameter so the host's route sweep
 * can read it back out of the router.
 *
 * **The keys are the constants, never the strings**: `OrganizationPermissions`
 * is where the eight keys are declared and synced from.
 *
 * **There is no DELETE route for a branch, a location, a terminal or a device**
 * (D-4, D-16, AC-17): they are deactivated, paused or revoked, and the route
 * list is asserted to contain none. **No route verifies a PIN** (AC-60).
 */

Route::middleware(['api', RequireTenantToken::class])
    ->prefix('api/v1/organization')
    ->group(function (): void {
        // Section B - branches.
        Route::get('/branches', ListBranchesController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::BRANCHES_VIEW)
            ->name('organization.branches.list');

        Route::post('/branches', CreateBranchController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::BRANCHES_MANAGE)
            ->name('organization.branches.create');

        Route::get('/branches/{branch}', GetBranchController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::BRANCHES_VIEW)
            ->name('organization.branches.get');

        Route::patch('/branches/{branch}', UpdateBranchController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::BRANCHES_MANAGE)
            ->name('organization.branches.update');

        Route::put('/branches/{branch}/status', ChangeBranchStatusController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::BRANCHES_MANAGE)
            ->name('organization.branches.status');

        // Section C - locations and warehouses. Only a warehouse can be created
        // by hand; the store of a branch is made with the branch.
        Route::get('/locations', ListLocationsController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::LOCATIONS_VIEW)
            ->name('organization.locations.list');

        Route::post('/locations', CreateWarehouseController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::LOCATIONS_MANAGE)
            ->name('organization.locations.create');

        Route::get('/locations/{location}', GetLocationController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::LOCATIONS_VIEW)
            ->name('organization.locations.get');

        Route::patch('/locations/{location}', UpdateLocationController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::LOCATIONS_MANAGE)
            ->name('organization.locations.update');

        Route::put('/locations/{location}/status', ChangeLocationStatusController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::LOCATIONS_MANAGE)
            ->name('organization.locations.status');

        // Section D - terminals. There is no route that un-revokes one (AC-40).
        Route::get('/terminals', ListTerminalsController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::TERMINALS_VIEW)
            ->name('organization.terminals.list');

        Route::post('/terminals', CreateTerminalController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::TERMINALS_MANAGE)
            ->name('organization.terminals.create');

        Route::get('/terminals/{terminal}', GetTerminalController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::TERMINALS_VIEW)
            ->name('organization.terminals.get');

        Route::get('/terminals/{terminal}/devices', ListTerminalDevicesController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::TERMINALS_VIEW)
            ->name('organization.terminals.devices');

        Route::patch('/terminals/{terminal}', UpdateTerminalController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::TERMINALS_MANAGE)
            ->name('organization.terminals.update');

        Route::post('/terminals/{terminal}/activation-code', ReissueActivationCodeController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::TERMINALS_MANAGE)
            ->name('organization.terminals.activation-code');

        Route::put('/terminals/{terminal}/status', ChangeTerminalStatusController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::TERMINALS_MANAGE)
            ->name('organization.terminals.status');

        Route::post('/terminals/{terminal}/revoke', RevokeTerminalController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::TERMINALS_MANAGE)
            ->name('organization.terminals.revoke');

        // Section E - settings. The scope is in the query on a read and in the
        // body on a write; a value is never read from the URL (AC-47).
        Route::get('/settings', ListSettingsController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::SETTINGS_VIEW)
            ->name('organization.settings.list');

        Route::put('/settings', SetSettingController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::SETTINGS_MANAGE)
            ->name('organization.settings.set');

        Route::post('/settings/clear', ClearSettingController::class)
            ->middleware(RequireTenantPermission::class.':'.OrganizationPermissions::SETTINGS_MANAGE)
            ->name('organization.settings.clear');
    });

/*
 * A terminal's own settings (AC-50): terminal token only, no employee
 * permission, and the terminal is the token's, never a path or query value.
 */
Route::middleware(['api', RequireTerminalToken::class])
    ->prefix('api/v1/terminal')
    ->group(function (): void {
        Route::get('/settings', ListTerminalSettingsController::class)
            ->name('terminal.settings');
    });
