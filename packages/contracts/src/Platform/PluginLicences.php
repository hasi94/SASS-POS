<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Platform;

/**
 * The one question `plugin-sdk` asks platform before enabling a licensed
 * plugin (T-019 D-3, plan Ruling 9).
 *
 * Deliberately not `Flexipos\Contracts\Plugins\*`: this is a platform
 * question ("is this licence row current?"), answered once in
 * `Flexipos\Platform\Support\PlatformLicence::isCurrent()` and reused here,
 * never a second licensing authority duplicating T-016's.
 */
interface PluginLicences
{
    /**
     * The current licence id for this tenant and plugin, or null when none
     * is active, unexpired, un-revoked and un-suspended. One named error
     * covers every reason a reader gets null (AC-13, AC-16).
     */
    public function currentLicenceId(string $tenantId, string $pluginName): ?string;
}
