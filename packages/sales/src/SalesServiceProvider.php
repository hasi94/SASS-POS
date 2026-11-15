<?php

declare(strict_types=1);

namespace Flexipos\Sales;

use Flexipos\Contracts\Access\DeclaresPermissions;
use Flexipos\Contracts\Access\PermissionDeclaration;
use Flexipos\Contracts\Audit\DeclaresAuditActions;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Registry\SalesPermissions;
use Illuminate\Support\ServiceProvider;

/**
 * The sales package's entry point into the host application (T-030).
 *
 * Registered explicitly in `modules/host/bootstrap/providers.php`, the way
 * every other `packages/*` provider is: the host's provider list is the one
 * place that says which packages boot.
 *
 * **A package and not a `modules/*` module** (plan Ruling 1), for the reason
 * pricing and customers are: the till, the API task and the stock task all work
 * with its documents, so the tables' owner is shared infrastructure. It
 * publishes **no** contract yet, because nothing outside it calls Sales today
 * (FP-234 adds one when it needs it), and it reaches the rest of the system only
 * through `Flexipos\Contracts`.
 *
 * **Nothing is bound.** Every slice and service autowires from the container.
 * The audit actions and the branch-scoped permission keys arrive with the slices
 * that earn them, in the same change as the registries that hold them, so that a
 * provider never advertises an action no slice writes.
 */
final class SalesServiceProvider extends ServiceProvider implements DeclaresAuditActions, DeclaresPermissions
{
    /**
     * This package's declared audit actions (T-017 Ruling 3).
     *
     * @return array<string, list<string>>
     */
    public function auditActions(): array
    {
        return [SalesAuditActions::MODULE => SalesAuditActions::ALL];
    }

    /**
     * This package's permission keys, for the registry sync.
     *
     * @return list<PermissionDeclaration>
     */
    public function permissionDeclarations(): array
    {
        return SalesPermissions::declarations();
    }
}
