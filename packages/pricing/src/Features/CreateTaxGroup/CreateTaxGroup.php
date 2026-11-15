<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreateTaxGroup;

/**
 * Create a tax group (T-029 AC-8).
 */
final class CreateTaxGroup
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
