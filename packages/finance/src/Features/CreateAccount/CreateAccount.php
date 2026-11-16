<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\CreateAccount;

/**
 * Create an account (T-032 AC-1 to AC-12, AC-16, AC-17, AC-65).
 */
final class CreateAccount
{
    /**
     * @param  array<array-key, mixed>  $fields  the request body, validated by `AccountInput::forCreate`
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
