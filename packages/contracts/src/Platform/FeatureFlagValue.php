<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Platform;

/**
 * One flag's resolved answer, and which layer of the ladder produced it
 * (T-016 AC-17 to AC-20).
 *
 * `$source` is `'default'`, `'plan'` or `'tenant'` - informational, so a test
 * can assert which layer actually answered, never read by {@see self::isEnabled()}.
 *
 * {@see self::isEnabled()} is Ruling 13's full table: disabled only for
 * `false`, `null`, `""`, `[]` or `{}` (which PHP's json_decode renders as an
 * empty array too). Anything else - including `0`, a non-empty string, a
 * non-empty array or object - is enabled; a flag's own meaning for its value is
 * that flag's owning module's business, not this class's.
 */
final class FeatureFlagValue
{
    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
        public readonly string $source,
    ) {}

    public function isEnabled(): bool
    {
        if ($this->value === false || $this->value === null || $this->value === '') {
            return false;
        }

        if (is_array($this->value) && $this->value === []) {
            return false;
        }

        return true;
    }
}
