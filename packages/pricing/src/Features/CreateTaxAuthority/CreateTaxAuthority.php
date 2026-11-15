<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreateTaxAuthority;

/**
 * Create a tax authority (T-029 AC-4).
 */
final class CreateTaxAuthority
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
