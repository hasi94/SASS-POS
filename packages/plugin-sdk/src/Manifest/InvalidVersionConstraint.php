<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Manifest;

use RuntimeException;

/**
 * A version constraint string this task's own narrow grammar cannot parse
 * (T-019 plan Ruling 3). No `composer/semver` dependency is added; this is
 * the whole of the grammar's refusal surface.
 */
final class InvalidVersionConstraint extends RuntimeException
{
    public static function malformed(string $expression): self
    {
        return new self("Version constraint [{$expression}] is malformed.");
    }
}
