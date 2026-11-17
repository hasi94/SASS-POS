<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\DeleteAccount;

/**
 * Soft-delete an account (T-032 AC-18, AC-20, AC-23).
 */
final class DeleteAccount
{
    public function __construct(
        public readonly string $accountId,
        public readonly ?string $actorId = null,
    ) {}
}
