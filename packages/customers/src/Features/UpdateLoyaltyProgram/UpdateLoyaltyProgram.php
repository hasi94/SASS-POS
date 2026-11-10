<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\UpdateLoyaltyProgram;

/**
 * Update a loyalty program (T-028 AC-26, AC-28; plan Ruling 5, D-22, D-23).
 */
final class UpdateLoyaltyProgram
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $programId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
