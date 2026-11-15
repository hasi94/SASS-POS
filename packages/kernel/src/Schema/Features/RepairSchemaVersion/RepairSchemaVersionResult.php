<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\RepairSchemaVersion;

/**
 * What the repair corrected, and what it left alone.
 *
 * `aligned` is reported as well as `corrected`, because "nothing was
 * corrected" and "nothing was looked at" are the same output otherwise, and
 * this is a slice whose whole risk is being pointed at an empty set.
 */
final readonly class RepairSchemaVersionResult
{
    /**
     * @param  array<string, int>  $corrected  tenant id => the version it now mirrors
     * @param  list<string>  $aligned  tenants whose mirror already matched
     */
    public function __construct(
        public array $corrected,
        public array $aligned,
    ) {}
}
