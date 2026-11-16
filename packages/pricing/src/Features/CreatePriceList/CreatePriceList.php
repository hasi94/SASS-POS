<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreatePriceList;

/**
 * Create a price list (T-029 AC-12).
 */
final class CreatePriceList
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
