<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

use Flexipos\Contracts\Platform\PluginLicences;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * The platform package's implementation of the one question `plugin-sdk`
 * asks before enabling a licensed plugin (T-019 D-3, plan Ruling 9).
 *
 * Reads only `type = 'plugin'` rows, `subject = <plugin name>`: a
 * `platform` or `module` row is never looked at, the same separation
 * {@see PlatformLicence} keeps on its own side (AC-27's enforcement half).
 * `isCurrent()` is the one shared authority for "is this licence row
 * active right now", so a plugin licence and a platform licence can never
 * disagree about what "current" means.
 */
final class MasterPluginLicences implements PluginLicences
{
    public function __construct(
        private readonly MasterConnection $master,
        private readonly RunnerClock $clock,
    ) {}

    public function currentLicenceId(string $tenantId, string $pluginName): ?string
    {
        $rows = $this->master->get()->table('licences')
            ->where('tenant_id', $tenantId)
            ->where('type', LicenceStatus::PLUGIN_TYPE)
            ->where('subject', $pluginName)
            ->orderByRaw('valid_until is not null')
            ->orderByDesc('valid_until')
            ->orderBy('id')
            ->get();

        $now = $this->clock->now();

        foreach ($rows as $row) {
            if (PlatformLicence::isCurrent($row, $now)) {
                return (string) $row->id;
            }
        }

        return null;
    }
}
