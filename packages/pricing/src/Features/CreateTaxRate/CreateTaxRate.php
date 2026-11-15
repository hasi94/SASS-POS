<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreateTaxRate;

/**
 * Create an effective-dated rate for a tax (T-029 AC-6).
 */
final class CreateTaxRate
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
