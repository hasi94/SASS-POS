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

        $nowString = $now->format('Y-m-d H:i:s.v');

        foreach ($platformRows as $row) {
            if ((string) $row->status !== LicenceStatus::ACTIVE) {
                continue;
            }

            if ($row->valid_from !== null && (string) $row->valid_from > $nowString) {
                continue;
            }

            if ($row->valid_until !== null && (string) $row->valid_until <= $nowString) {
                continue;
            }

            return self::VALID;
        }

        return self::LAPSED;
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
