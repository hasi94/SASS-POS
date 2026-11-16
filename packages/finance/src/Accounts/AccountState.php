<?php

declare(strict_types=1);

namespace Flexipos\Finance\Accounts;

use Flexipos\Contracts\Finance\AccountType;

/**
 * What {@see AccountRules} needs to know about a stored account: the facts a
 * decision turns on, and nothing it could not be tested without.
 *
 * Handlers build one from a **locked** row (Ruling 6), so a rule never
 * decides on a value read outside the transaction. `AccountSnapshot` is the
 * published view for other modules and carries neither `path`, `depth` nor
 * `isSystem`, which is why the rules need this.
 */
final readonly class AccountState
{
    public function __construct(
        public string $id,
        public AccountType $type,
        public bool $isPostable,
        public bool $isActive,
        public bool $isDeleted,
        public bool $isSystem,
        public string $path,
        public int $depth,
    ) {}
}
