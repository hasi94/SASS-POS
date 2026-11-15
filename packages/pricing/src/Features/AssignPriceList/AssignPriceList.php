<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\AssignPriceList;

/**
 * Assign a price list to a branch, customer group or customer (T-029 AC-14).
 */
final class AssignPriceList
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
