<?php

declare(strict_types=1);

namespace Flexipos\Access;

use Flexipos\Access\Features\ArchiveAuditLogs\ArchiveAuditLogsCommand;
use Flexipos\Access\Features\BootstrapOwner\BootstrapOwnerHandler;
use Flexipos\Access\Features\BuildEmployeeClaims\BuildEmployeeClaimsHandler;
use Flexipos\Access\Features\SearchAuditLog\SearchAuditLogCommand;
use Flexipos\Access\Registry\AccessPermissions;
use Flexipos\Access\Registry\AuditPermissions;
use Flexipos\Access\Registry\PermissionRegistry;
use Flexipos\Access\Resolver\DatabaseEmployeeStalenessCheck;
use Flexipos\Access\Resolver\DatabasePermissionResolver;
use Flexipos\Access\Support\AccessAuditActions;
use Flexipos\Access\Support\AuditWriter;
use Flexipos\Access\Support\ClientIp;
use Flexipos\Access\Support\ClientUserAgent;
use Flexipos\Access\Support\DatabaseEmployeeDirectory;
use Flexipos\Access\Support\DatabaseRoleHolders;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Access\Support\PinPolicy;
use Flexipos\Access\Support\TerminalLoginLogWriter;
use Flexipos\Contracts\Access\DeclaresPermissions;
use Flexipos\Contracts\Access\EmployeeClaimSource;
use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Access\EmployeeStalenessCheck;
use Flexipos\Contracts\Access\PermissionDeclaration;
use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Access\RoleHolders;
use Flexipos\Contracts\Access\TenantAccessBootstrap;
use Flexipos\Contracts\Access\TerminalLoginLog;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Audit\DeclaresAuditActions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * The access package's entry point into the host application.
 *
 * Registered explicitly in `modules/host/bootstrap/providers.php`, the way
 * `AuthServiceProvider` and the kernel's two providers are, rather than
 * through Composer package discovery: every shared package here is a path
 * package the host requires by name, and the host's provider list is the one
 * place that says which of them boot.
 *
 * **Step 1 boots nothing on purpose.** The plan's red-first rule is
 * structural: a guard, a resolver or a policy is never stubbed to allow, not
 * even for a minute, so this provider binds the resolver, the registry, the
 * claim source, the staleness check, the PIN policy and the audit writer only
 * when each of them exists and refuses correctly. Until then the container
 * resolves none of those contracts, and a caller asking for one gets a
 * binding-resolution error - which is a refusal to answer, not a permissive
 * answer.
 *
 * The route file this provider will load (`routes/api.php`) arrives with the
 * HTTP surface in step 20.
 *
 * **Step 6 gives it its first binding and its first declaration.** Neither is
 * a guard: the registry answers "which keys exist", not "may this person do
 * it", so binding it breaks none of the red-first rule above.
 */
final class AccessServiceProvider extends ServiceProvider implements DeclaresAuditActions, DeclaresPermissions
{
    /**
     * Register this package's bindings.
     *
     * The registry is a **singleton** because it is a reader with one
     * question to answer and no state to keep in step - and, more usefully,
     * because every collaborator must see the same registry: a sync that
     * built its own could not be handed one holding a test's scratch
     * declaration, and "the table holds exactly what the application
     * declares" would be a claim about two different registries.
     *
     * It holds no memo of its own (see the class), so a provider registered
     * after this binding is resolved is still seen.
     */
    public function register(): void
    {
        $this->app->singleton(PermissionRegistry::class);

        // The acting set, as one object the whole process shares (D-15). It
        // is the shipped policy and there is no channel through which an
        // operator could widen it; what a test can do is bind a different
        // object, which is a change only an author under review can make.
        $this->app->singleton(EmployeeStatus::class, static fn (): EmployeeStatus => EmployeeStatus::shipped());

        // Step 9's binding, and it is the first one that answers "may this
        // person do it". It is bound to the real resolver on the day the
        // real resolver exists and refuses correctly - never to a stub that
        // allows, not even for a minute (the plan's red-first rule).
        $this->app->singleton(PermissionResolver::class, DatabasePermissionResolver::class);

        // Step 22. What `packages/auth` calls at mint (D-2, D-6). Bound
        // to the real builder, which refuses rather than minting empty
        // rights, so this binding has no permissive state either.
        $this->app->singleton(EmployeeClaimSource::class, BuildEmployeeClaimsHandler::class);

        // Step 21. The per-request staleness question (D-7, AC-66). Bound
        // beside the resolver and for the same reason: it is a reader, it
        // answers from the tenant database, and it refuses by answering
        // `true` - a stale token is refused, never admitted, so there is
        // no permissive state this binding could be in.
        $this->app->singleton(EmployeeStalenessCheck::class, DatabaseEmployeeStalenessCheck::class);

        // Step 17. The PIN rules as one object, over the configured digit
        // bounds (AC-47, D-16). A singleton for `EmployeeStatus`'s reason -
        // every collaborator must judge a PIN against the same list - and
        // built through `shipped()` so that the only way the weak set can
        // differ from the generated one is a test binding a different
        // instance, which is the seam the moved-value proof uses.
        $this->app->singleton(PinPolicy::class, static fn (): PinPolicy => PinPolicy::shipped());

        // The calling client's address, for `audit_logs.ip` (AC-70). A
        // singleton holding a closure, never a captured request: the
        // address is read at write time, so one container serving many
        // requests stamps each row with its own caller. A test that needs
        // a chosen address binds `ClientIp::fixed()` over this.
        $this->app->singleton(ClientIp::class, static fn ($app): ClientIp => ClientIp::fromRequests($app));

        // T-017 Ruling 2. ClientIp's sibling, for `audit_logs.user_agent`,
        // bound beside it for the same reason.
        $this->app->singleton(ClientUserAgent::class, static fn ($app): ClientUserAgent => ClientUserAgent::fromRequests($app));

        // T-014. What organization asks of access through the published
        // contracts: the one audit writer, and whether an employee exists.
        // Both are readers or writers of access's own tables, so organization
        // never queries them (T-010 AC-11's stated limit).
        $this->app->singleton(AuditTrail::class, AuditWriter::class);
        $this->app->singleton(EmployeeDirectory::class, DatabaseEmployeeDirectory::class);
        $this->app->singleton(TerminalLoginLog::class, TerminalLoginLogWriter::class);

        // T-020 Ruling 6a. What the workflow engine asks of access through
        // the published contract, rather than querying employee_roles
        // itself.
        $this->app->singleton(RoleHolders::class, DatabaseRoleHolders::class);

        // T-026 D-23, D-24. The one door packages/platform's create_owner
        // step is allowed to reach into this package through, for the
        // chicken-and-egg first-owner grant. Bound to the real handler,
        // which checks its own precondition rather than trusting a caller.
        $this->app->singleton(TenantAccessBootstrap::class, BootstrapOwnerHandler::class);

        // T-017 step 8/11: console-only entry points, since neither audit
        // search nor archival has an HTTP route (D-7).
        if ($this->app->runningInConsole()) {
            $this->commands([
                ArchiveAuditLogsCommand::class,
                SearchAuditLogCommand::class,
            ]);
        }
    }

    /**
     * Mount this package's tenant routes (step 20).
     *
     * `loadRoutesFrom()` rather than an entry in the host's bootstrap, for
     * `AuthServiceProvider`'s reason: the file that declares the routes and
     * the provider that decides they exist stay together, and a host that
     * did not register this provider gets no access endpoints at all rather
     * than endpoints pointing at a provider that never booted. The helper is
     * route-cache aware, so a cached application does not re-read the file.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        // T-017 Ruling 6, AC-8. The archival job runs daily, over the whole
        // serving fleet, without overlapping a still-running invocation.
        // This inherits FP-319 (no scheduler runs in production yet),
        // exactly as T-016's own scheduled job already does; it is not
        // re-solved here.
        $this->app->booted(function (): void {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);

            $schedule->command(ArchiveAuditLogsCommand::class)
                ->dailyAt('03:00')
                ->withoutOverlapping();
        });
    }

    /**
     * This package's own six keys, plus T-017's `audit.read` (AC-14, D-13).
     *
     * Declared the same way any module declares its own - by implementing
     * the published interface on the provider - so the access package is not
     * a special case in its own registry, and removing this method would
     * remove these keys from the sweep exactly as it would for a module.
     *
     * **`AccessPermissions` keeps its own "six keys" contract.** `AuditPermissions`
     * is a separate class for exactly that reason (Ruling 9); merging here is
     * the only place the two combine.
     *
     * @return list<PermissionDeclaration>
     */
    public function permissionDeclarations(): array
    {
        return array_merge(AccessPermissions::declarations(), AuditPermissions::declarations());
    }

    /**
     * This package's sixteen declared audit actions (T-017 Ruling 3; T-026
     * added `owner_granted`).
     *
     * Declared the same way any module declares its own, by implementing
     * the published interface on the provider, so {@see AuditWriter}'s
     * catalogue enforcement has something to enforce from this process's
     * first boot.
     *
     * @return array<string, list<string>>
     */
    public function auditActions(): array
    {
        return [AccessAuditActions::MODULE => AccessAuditActions::ALL];
    }
}
