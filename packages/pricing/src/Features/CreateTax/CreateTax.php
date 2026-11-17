<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreateTax;

/**
 * Create a tax (T-029 AC-5, F-9).
 */
final class CreateTax
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
