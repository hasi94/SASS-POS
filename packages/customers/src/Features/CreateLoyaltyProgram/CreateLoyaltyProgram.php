<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\CreateLoyaltyProgram;

/**
 * Create a loyalty program (T-028 AC-26; plan Ruling 5, D-22).
 */
final class CreateLoyaltyProgram
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
