<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Flexipos\Access\Registry\PermissionRegistry;
use Flexipos\Contracts\Audit\DeclaresAuditActions;
use Illuminate\Foundation\Application;

/**
 * Every declared (module, action) pair this process's booted providers have
 * ever said they write to `audit_logs` (T-017 Ruling 3).
 *
 * **The same shape as {@see PermissionRegistry},
 * for the same reason.** It asks the container which registered providers
 * implement {@see DeclaresAuditActions}, rather than reading a list kept in
 * this file: a second list here would be the one that goes out of step the
 * day a module is added. It holds **no memo**: the set is recomputed on
 * every call, because a test registering a scratch provider must be seen
 * without restarting the process.
 *
 * **Not bound in the container.** `Illuminate\Foundation\Application` is
 * always self-resolvable, so this class is built by reflection wherever it
 * is asked for - one fewer binding to keep in step with the class.
 */
final class AuditCatalogue
{
    public function __construct(private readonly Application $app) {}

    /**
     * Every module any booted provider declares actions for, sorted.
     *
     * @return list<string>
     */
    public function modules(): array
    {
        $modules = array_keys($this->byModule());

        sort($modules);

        return $modules;
    }

    /**
     * Every declared action, sorted: one module's list when `$module` is
     * given, or the union of every module's list otherwise.
     *
     * @return list<string>
     */
    public function actions(?string $module = null): array
    {
        $byModule = $this->byModule();

        if ($module !== null) {
            return $byModule[$module] ?? [];
        }

        $union = [];

        foreach ($byModule as $actions) {
            $union = array_merge($union, $actions);
        }

        $union = array_values(array_unique($union));
        sort($union);

        return $union;
    }

    /**
     * Whether some booted provider declared this exact (module, action) pair.
     */
    public function knows(string $module, string $action): bool
    {
        return in_array($action, $this->actions($module), true);
    }

    /**
     * Every booted provider's declarations, merged by module.
     *
     * @return array<string, list<string>>
     */
    private function byModule(): array
    {
        $byModule = [];

        foreach ($this->providers() as $provider) {
            foreach ($provider->auditActions() as $module => $actions) {
                $byModule[$module] = array_values(array_unique(array_merge($byModule[$module] ?? [], $actions)));
            }
        }

        foreach (array_keys($byModule) as $module) {
            sort($byModule[$module]);
        }

        return $byModule;
    }

    /**
     * @return list<DeclaresAuditActions>
     */
    private function providers(): array
    {
        $providers = [];

        foreach ($this->app->getProviders(DeclaresAuditActions::class) as $provider) {
            if ($provider instanceof DeclaresAuditActions) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }
}
