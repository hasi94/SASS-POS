<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

/**
 * The closed set `licences.status` holds (T-016 AC-24).
 */
final class LicenceStatus
{
    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    public const SUSPENDED = 'suspended';

    /** @var list<string> */
    public const ALL = [self::ACTIVE, self::EXPIRED, self::REVOKED, self::SUSPENDED];

    /** The one licence type this task enforces (D-6). */
    public const PLATFORM_TYPE = 'platform';

    /** T-019 D-3: the type `MasterPluginLicences` reads. */
    public const PLUGIN_TYPE = 'plugin';
}
