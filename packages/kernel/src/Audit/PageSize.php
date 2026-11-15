<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Audit;

/**
 * The one clamp both audit readers use (T-017 Ruling 4, plan section 3).
 *
 * The T-014 D-28 convention: null or less than 1 means the default, and a
 * request above the maximum is clamped to it, never refused. Both figures
 * move with the arguments - there is no literal here - so a caller reading
 * them from `config/audit.php` is the only place either number is written.
 */
final class PageSize
{
    public static function clamp(?int $asked, int $default, int $max): int
    {
        $size = $asked === null || $asked < 1 ? $default : $asked;

        return min($size, $max);
    }
}
