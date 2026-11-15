<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\RemoveTaxGroupTax;

/**
 * Remove a tax from a tax group (T-029 AC-9).
 */
final class RemoveTaxGroupTax
{
    public function __construct(
        public readonly string $taxGroupId,
        public readonly string $taxId,
        public readonly ?string $actorId = null,
    ) {}
}
