<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateUnit;

/**
 * Create a unit of measure (T-027 AC-11, plan Ruling 6).
 *
 * The whole submitted body travels in `$fields`, untouched, because the
 * handler refuses fields the operation does not take and can only do so if
 * it sees them.
 */
final class CreateUnit
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
