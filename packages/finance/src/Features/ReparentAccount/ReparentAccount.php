<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ReparentAccount;

/**
 * Move an account, with its whole subtree, under another parent or to the
 * root (T-032 AC-10, AC-13, AC-18, AC-20). A null `newParentId` makes it a
 * root.
 */
final class ReparentAccount
{
    public function __construct(
        public readonly string $accountId,
        public readonly ?string $newParentId,
        public readonly ?string $actorId = null,
    ) {}
}
