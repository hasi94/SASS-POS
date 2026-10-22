<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The page size of a list, clamped (T-014 AC-13, Ruling 10 (g)).
 *
 * An absent or non-positive request is the configured default; a larger one
 * is clamped to the configured maximum rather than refused, as T-010's
 * AC-29 does. Numbers only, from `config/organization.php`.
 */
final class PageSize
{
    public static function resolve(?int $requested, ConfigRepository $config): int
    {
        $default = (int) $config->get('organization.page_size', 50);
        $maximum = (int) $config->get('organization.max_page_size', 100);

        $size = $requested === null || $requested < 1 ? $default : $requested;

        return min($size, $maximum);
    }
}
