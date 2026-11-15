<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\UpdateTax;

/**
 * Update a tax (T-029 AC-5, F-9).
 */
final class UpdateTax
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $taxId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
