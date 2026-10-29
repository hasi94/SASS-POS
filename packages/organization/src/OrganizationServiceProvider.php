<?php

declare(strict_types=1);

namespace Flexipos\Organization;

use Flexipos\Contracts\Access\DeclaresPermissions;
use Flexipos\Contracts\Access\PermissionDeclaration;
use Flexipos\Contracts\Audit\DeclaresAuditActions;
use Flexipos\Contracts\Organization\BranchDirectory;
use Flexipos\Contracts\Organization\TerminalActivation;
use Flexipos\Contracts\Organization\TerminalAuthority;
use Flexipos\Organization\Features\ActivateTerminal\ActivateTerminalHandler;
use Flexipos\Organization\Registry\OrganizationAuditActions;
use Flexipos\Organization\Registry\OrganizationPermissions;
use Flexipos\Organization\Support\DatabaseBranchDirectory;
use Flexipos\Organization\Support\DatabaseTerminalAuthority;
use Illuminate\Support\ServiceProvider;

/**
 * The organization package's entry point into the host application (T-014).
 *
 * Registered explicitly in `modules/host/bootstrap/providers.php`, the way
 * `AccessServiceProvider` is, rather than through Composer package discovery:
 * the host's provider list is the one place that says which packages boot.
 *
 * **A package and not a `modules/*` module**, like `packages/access`
 * (T-010 section 2): every later module reads branches, locations and
 * terminals through a contract, so the tables' owner is shared
 * infrastructure rather than a leaf that other modules may not import.
 *
 * **Bindings arrive with the slices that earn them.** Nothing here is bound
 * to a stub: a contract with no implementation resolves to a binding error,
 * which is a refusal to answer and never a permissive answer.
 */
final class OrganizationServiceProvider extends ServiceProvider implements DeclaresAuditActions, DeclaresPermissions
{
    public function register(): void
    {
        // Step 5. What `packages/access` asks through the published contract.
        // A reader that answers from the tenant database; it has no permissive
        // state, because an unreadable branch is `Unknown`, which refuses.
        $this->app->singleton(BranchDirectory::class, DatabaseBranchDirectory::class);

        // Step 11. What `packages/auth` calls, with a callable that mints the
        // master refresh row as the last act of the tenant transaction. Bound to
        // the one implementation, which refuses before it writes.
        $this->app->singleton(TerminalActivation::class, ActivateTerminalHandler::class);

        // Step 12. The per-request and per-refresh question about a terminal,
        // answered from the tenant database. A reader: a terminal it cannot
        // find is null, which refuses.
        $this->app->singleton(TerminalAuthority::class, DatabaseTerminalAuthority::class);
    }

    /**
     * Mount this package's tenant routes. `loadRoutesFrom()` for the reason
     * `AccessServiceProvider` does it: the file that declares the routes and
     * the provider that decides they exist stay together, and the helper is
     * route-cache aware.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
    }

    /**
     * This package's eight keys (D-12).
     *
     * Declared the way any module declares its own, by implementing the
     * published interface on the provider, so removing the method removes the
     * keys from the registry sweep exactly as it would for a module.
     *
     * @return list<PermissionDeclaration>
     */
    public function permissionDeclarations(): array
    {
        return OrganizationPermissions::declarations();
    }

    /**
     * This package's sixteen declared audit actions (T-017 Ruling 3).
     *
     * @return array<string, list<string>>
     */
    public function auditActions(): array
    {
        return [OrganizationAuditActions::MODULE => OrganizationAuditActions::ALL];
    }
}
