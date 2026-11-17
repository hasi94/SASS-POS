<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\AddTaxGroupTax;

/**
 * Add a tax to a tax group at a sequence (T-029 AC-9).
 */
final class AddTaxGroupTax
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
