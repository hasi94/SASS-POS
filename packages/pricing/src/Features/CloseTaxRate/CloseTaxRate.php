<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CloseTaxRate;

/**
 * Close a tax rate: set `effective_to` and `note` only (T-029 AC-6, AC-7).
 */
final class CloseTaxRate
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $taxRateId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
