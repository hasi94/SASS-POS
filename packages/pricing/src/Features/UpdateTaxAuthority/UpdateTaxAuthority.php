<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\UpdateTaxAuthority;

/**
 * Update a tax authority (T-029 AC-4).
 */
final class UpdateTaxAuthority
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $taxAuthorityId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
