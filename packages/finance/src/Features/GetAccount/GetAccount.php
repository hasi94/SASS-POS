<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\GetAccount;

/**
 * Read one live account (T-032 AC-61, AC-64).
 */
final class GetAccount
{
    public function __construct(
        public readonly string $accountId,
        public readonly ?string $actorId = null,
    ) {}
}
