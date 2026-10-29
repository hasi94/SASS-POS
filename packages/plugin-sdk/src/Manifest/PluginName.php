<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Manifest;

/**
 * The one place that computes a plugin's table prefix (T-019 plan
 * Ruling 6, AC-19).
 *
 * Plugin names may not contain `_` (enforced by
 * {@see ManifestValidator}'s name pattern), so this mapping never sends
 * two distinct names to the same prefix: `plg_foo_bar_` could otherwise be
 * read as either `foo-bar` or (impossibly) `foo_bar`.
 */
final class PluginName
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

    public const MAX_LENGTH = 40;

    public static function tablePrefix(string $name): string
    {
        return 'plg_'.str_replace('-', '_', $name).'_';
    }
}
