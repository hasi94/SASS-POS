<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\DeactivateAccount;

/**
 * Deactivate an account (T-032 AC-18, AC-20, AC-24).
 */
final class DeactivateAccount
{
    public function __construct(
        public readonly string $accountId,
        public readonly ?string $actorId = null,
    ) {}
}
