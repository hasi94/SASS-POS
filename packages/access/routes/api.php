<?php

use App\Http\Controllers\Access\AssignRoleController;
use App\Http\Controllers\Access\AttachBranchController;
use App\Http\Controllers\Access\ChangeEmployeeStatusController;
use App\Http\Controllers\Access\ClearEmployeePermissionController;
use App\Http\Controllers\Access\CreateEmployeeController;
use App\Http\Controllers\Access\CreateRoleController;
use App\Http\Controllers\Access\DeleteEmployeeController;
use App\Http\Controllers\Access\DeleteRoleController;
use App\Http\Controllers\Access\DetachBranchController;
use App\Http\Controllers\Access\GetEmployeeController;
use App\Http\Controllers\Access\GetMyPermissionsController;
use App\Http\Controllers\Access\GetRoleController;
use App\Http\Controllers\Access\ListEmployeesController;
use App\Http\Controllers\Access\ListRolesController;
use App\Http\Controllers\Access\RemoveRoleAssignmentController;
use App\Http\Controllers\Access\RenameRoleController;
use App\Http\Controllers\Access\SetEmployeePermissionController;
use App\Http\Controllers\Access\SetPinController;
use App\Http\Controllers\Access\SetRolePermissionsController;
use App\Http\Controllers\Access\UpdateEmployeeController;
use App\Http\Middleware\RefusePinInTheUrl;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Middleware\RequireTenantToken;
use Flexipos\Access\Registry\AccessPermissions;
use Illuminate\Support\Facades\Route;

/*
 * The tenant routes of sections C to G, mounted by AccessServiceProvider.
 *
 * The shape is the one `packages/auth/routes/api.php` established: the `api`
 * middleware group, an explicit `api/v1/...` prefix, and an invokable
 * controller for every route - never an inline closure (T-004 AC-13).
 *
 * **Every route carries exactly two middleware, and the pair is the design.**
 * `RequireTenantToken` verifies the tenant token and enters the shop, in one
 * class, so no route can be authenticated without also being gated (T-009
 * AC-45 to AC-47). `RequireTenantPermission` then declares the permission key
 * this route needs. The declaration is a middleware parameter rather than a
 * comment, a route name convention or a list somewhere else, because AC-63's
 * sweep reads it back out of the router: a declaration a machine cannot find
 * is a declaration that goes stale.
 *
 * **Each route declares exactly one key.** AC-63 fails a route with none, a
 * route naming a key the registry does not hold, and a route with two. The
 * alternative marker `RequireTenantPermission::AUTHENTICATED_ONLY` means "a
 * valid tenant token is the whole requirement", and AC-60's "my permissions"
 * is the one route here that carries it. The sweep prints every route that
 * does, so a reviewer sees which routes skip the check and can count them.
 *
 * **The keys are the constants, never the strings.** `AccessPermissions` is
 * where this package's six keys are declared and synced into the tenant's
 * `permissions` table from; a route spelling one of them by hand would be a
 * second home for the same fact, and the typo would be caught only at run
 * time by a 403 nobody could explain.
 *
 * **The PIN route carries a third.** `RefusePinInTheUrl` runs between the two
 * others: after the token, so there is a verified caller, and before the
 * permission check, so a PIN in the query string or the path is refused
 * before the resolver reads anything and before any `access_log` row could
 * be written (AC-48, sink 6).
 *
 * **No route verifies a PIN** (D-16, AC-49). The verify operation is in
 * process only, for the future till task, and `VerifyPinTest` asserts the
 * absence over the printed route list rather than over this file.
 */

Route::middleware(['api', RequireTenantToken::class])
    ->prefix('api/v1/access')
    ->group(function (): void {
        // The caller's own access (AC-60). The one route in this task
        // that declares `authenticated_only` rather than a key: there is
        // no subject but the caller, so there is nothing to be permitted
        // to see. The sweep prints it, which is how a reviewer sees that
        // exactly one route skips the permission check and which one.
        Route::get('/me/permissions', GetMyPermissionsController::class)
            ->middleware(RequireTenantPermission::class.':'.RequireTenantPermission::AUTHENTICATED_ONLY)
            ->name('access.me.permissions');

        // Section C - roles.
        Route::get('/roles', ListRolesController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::ROLES_VIEW)
            ->name('access.roles.list');

        Route::post('/roles', CreateRoleController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::ROLES_MANAGE)
            ->name('access.roles.create');

        Route::get('/roles/{role}', GetRoleController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::ROLES_VIEW)
            ->name('access.roles.get');

        Route::patch('/roles/{role}', RenameRoleController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::ROLES_MANAGE)
            ->name('access.roles.rename');

        Route::put('/roles/{role}/permissions', SetRolePermissionsController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::ROLES_MANAGE)
            ->name('access.roles.permissions');

        Route::delete('/roles/{role}', DeleteRoleController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::ROLES_MANAGE)
            ->name('access.roles.delete');

        // Section D - employees.
        Route::get('/employees', ListEmployeesController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::EMPLOYEES_VIEW)
            ->name('access.employees.list');

        Route::post('/employees', CreateEmployeeController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::EMPLOYEES_MANAGE)
            ->name('access.employees.create');

        Route::get('/employees/{employee}', GetEmployeeController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::EMPLOYEES_VIEW)
            ->name('access.employees.get');

        Route::patch('/employees/{employee}', UpdateEmployeeController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::EMPLOYEES_MANAGE)
            ->name('access.employees.update');

        Route::put('/employees/{employee}/status', ChangeEmployeeStatusController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::EMPLOYEES_MANAGE)
            ->name('access.employees.status');

        Route::delete('/employees/{employee}', DeleteEmployeeController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::EMPLOYEES_MANAGE)
            ->name('access.employees.delete');

        // Section E - assigning access.
        Route::post('/employees/{employee}/roles', AssignRoleController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::ACCESS_ASSIGN)
            ->name('access.assignments.create');

        Route::delete('/employees/{employee}/roles/{role}', RemoveRoleAssignmentController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::ACCESS_ASSIGN)
            ->name('access.assignments.delete');

        Route::put('/employees/{employee}/permissions/{permissionKey}', SetEmployeePermissionController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::ACCESS_ASSIGN)
            ->name('access.permissions.set');

        Route::delete('/employees/{employee}/permissions/{permissionKey}', ClearEmployeePermissionController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::ACCESS_ASSIGN)
            ->name('access.permissions.clear');

        Route::post('/employees/{employee}/branches', AttachBranchController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::ACCESS_ASSIGN)
            ->name('access.branches.attach');

        Route::delete('/employees/{employee}/branches/{branch}', DetachBranchController::class)
            ->middleware(RequireTenantPermission::class.':'.AccessPermissions::ACCESS_ASSIGN)
            ->name('access.branches.detach');

        // Section G - the PIN. The third middleware is the URL channel.
        Route::put('/employees/{employee}/pin', SetPinController::class)
            ->middleware([
                RefusePinInTheUrl::class,
                RequireTenantPermission::class.':'.AccessPermissions::EMPLOYEES_SET_PIN,
            ])
            ->name('access.employees.pin');
    });
