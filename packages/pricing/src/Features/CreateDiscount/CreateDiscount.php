<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreateDiscount;

/**
 * Create a discount (T-029 AC-19).
 */
final class CreateDiscount
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
