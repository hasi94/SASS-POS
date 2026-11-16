<?php

declare(strict_types=1);

namespace Flexipos\Purchasing;

use Flexipos\Contracts\Access\DeclaresPermissions;
use Flexipos\Contracts\Access\PermissionDeclaration;
use Flexipos\Contracts\Audit\DeclaresAuditActions;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Illuminate\Support\ServiceProvider;

/**
 * The purchasing package's entry point into the host application (T-034).
 *
 * Registered explicitly in `modules/host/bootstrap/providers.php`, the way
 * every other `packages/*` provider is. It publishes no contract (plan
 * Ruling 1) and reaches the rest of the system only through
 * `Flexipos\Contracts`.
 *
 * Nothing is bound: every slice and service autowires from the container. It
 * declares the audit actions and the permission keys through the two registry
 * contracts (T-017 Ruling 3, AC-95), so the audit catalogue and the permission
 * registry sync pick them up like every other package's.
 */
final class PurchasingServiceProvider extends ServiceProvider implements DeclaresAuditActions, DeclaresPermissions
{
    /**
     * This package's declared audit actions.
     *
     * @return array<string, list<string>>
     */
    public function auditActions(): array
    {
        return [PurchasingAuditActions::MODULE => PurchasingAuditActions::ALL];
    }

    /**
     * This package's permission keys, for the registry sync.
     *
     * @return list<PermissionDeclaration>
     */
    public function permissionDeclarations(): array
    {
        return PurchasingPermissions::declarations();
    }
}
