<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ReactivateAccount;

/**
 * Reactivate an account (T-032 AC-20, AC-24).
 */
final class ReactivateAccount
{
    public function __construct(
        public readonly string $accountId,
        public readonly ?string $actorId = null,
    ) {}
}
