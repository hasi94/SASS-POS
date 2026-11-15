<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

use DateTimeInterface;
use Illuminate\Database\Connection;

/**
 * The platform licence verdict (T-016 Ruling 16, AC-24 to AC-27).
 *
 * Reads only `type = 'platform'` rows (D-6): a `module` or `plugin` row is
 * never looked at by this class, which is AC-27's enforcement half.
 */
final class PlatformLicence
{
    public const NONE = 'none';

    public const VALID = 'valid';

    public const LAPSED = 'lapsed';

    /**
     * @param  list<object>  $platformRows  Only rows whose `type` is already `platform`.
     */
    public static function verdict(array $platformRows, DateTimeInterface $now): string
    {
        if ($platformRows === []) {
            return self::NONE;
        }

        foreach ($platformRows as $row) {
            if (self::isCurrent($row, $now)) {
                return self::VALID;
            }
        }

        return self::LAPSED;
    }

    /**
     * Whether one `licences` row is active right now (T-019 plan Ruling 9,
     * extracted unchanged from this method's own loop body): `status`
     * is `active`, `valid_from` is null or not later than now, and
     * `valid_until` is null or later than now.
     *
     * Shared with `Flexipos\Platform\Support\MasterPluginLicences`, so a
     * plugin licence and a platform licence agree on what "current" means
     * without a second authority restating this rule (D-3).
     */
    public static function isCurrent(object $row, DateTimeInterface $now): bool
    {
        if ((string) $row->status !== LicenceStatus::ACTIVE) {
            return false;
        }

        $nowString = $now->format('Y-m-d H:i:s.v');

        if ($row->valid_from !== null && (string) $row->valid_from > $nowString) {
            return false;
        }

        if ($row->valid_until !== null && (string) $row->valid_until <= $nowString) {
            return false;
        }

        return true;
    }

    /**
     * @return list<object>
     */
    public static function platformRowsFor(Connection $master, string $tenantId): array
    {
        return $master->table('licences')
            ->where('tenant_id', $tenantId)
            ->where('type', LicenceStatus::PLATFORM_TYPE)
            ->get()
            ->all();
    }
}
