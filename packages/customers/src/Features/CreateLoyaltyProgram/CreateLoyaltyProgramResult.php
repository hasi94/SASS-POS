<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\CreateLoyaltyProgram;

final readonly class CreateLoyaltyProgramResult
{
    public function __construct(
        public string $programId,
    ) {}
}
