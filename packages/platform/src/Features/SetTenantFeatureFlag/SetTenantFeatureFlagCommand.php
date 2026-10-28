<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\SetTenantFeatureFlag;

use Flexipos\Platform\Exceptions\PlatformRefused;
use Illuminate\Console\Command;

/**
 * `php artisan platform:set-tenant-feature-flag` (T-016 AC-33, D-21).
 *
 * The console entry point for the write a platform operator makes with no
 * HTTP surface to go through: internal handler plus one command, exactly as
 * D-21 scopes it.
 *
 * Exactly one of `--enable`, `--disable` or `--clear` must be given:
 * `--clear` removes the tenant's override (falling back to the plan or the
 * global default), and naming none or more than one is refused before the
 * handler is ever called.
 */
final class SetTenantFeatureFlagCommand extends Command
{
    protected $signature = 'platform:set-tenant-feature-flag
        {tenant : The tenant id}
        {flag : The feature_flags key}
        {--enable : Set the tenant override to enabled}
        {--disable : Set the tenant override to disabled}
        {--clear : Remove the tenant override}
        {--reason= : Recorded on the override row}
        {--actor= : The acting user id, recorded on the audit row}';

    protected $description = "Set or clear one tenant's feature-flag override";

    public function handle(SetTenantFeatureFlagHandler $handler): int
    {
        $chosen = array_filter([
            'enable' => (bool) $this->option('enable'),
            'disable' => (bool) $this->option('disable'),
            'clear' => (bool) $this->option('clear'),
        ]);

        if (count($chosen) !== 1) {
            $this->components->error('Name exactly one of --enable, --disable or --clear.');

            return self::FAILURE;
        }

        $enabled = match (array_key_first($chosen)) {
            'enable' => true,
            'disable' => false,
            default => null,
        };

        try {
            $handler->handle(new SetTenantFeatureFlag(
                tenantId: (string) $this->argument('tenant'),
                flagKey: (string) $this->argument('flag'),
                enabled: $enabled,
                reason: $this->option('reason') === null ? null : (string) $this->option('reason'),
                actorUserId: $this->option('actor') === null ? null : (string) $this->option('actor'),
            ));
        } catch (PlatformRefused $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info($enabled === null
            ? "The override for [{$this->argument('flag')}] on tenant [{$this->argument('tenant')}] was cleared."
            : "Tenant [{$this->argument('tenant')}] now has [{$this->argument('flag')}] ".($enabled ? 'enabled' : 'disabled').'.');

        return self::SUCCESS;
    }
}
