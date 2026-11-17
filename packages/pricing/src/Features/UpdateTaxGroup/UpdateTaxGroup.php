<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\UpdateTaxGroup;

/**
 * Update a tax group (T-029 AC-8, AC-10).
 */
final class UpdateTaxGroup
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $taxGroupId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
