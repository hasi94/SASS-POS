<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreateTaxAuthority;

final class CreateTaxAuthorityResult
{
    public function __construct(
        public readonly string $taxAuthorityId,
    ) {}
}
