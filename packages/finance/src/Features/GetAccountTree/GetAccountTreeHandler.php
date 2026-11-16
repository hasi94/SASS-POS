<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\GetAccountTree;

use Flexipos\Finance\Accounts\AccountRows;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;

/**
 * The whole live chart in one query, `ORDER BY depth, code, id` (T-032 plan
 * Ruling 10, AC-61, NFR).
 *
 * A parent is always at a smaller depth than its child, so ordering by depth
 * puts every parent before its children with no per-node query and no
 * recursion. The result is a flat list: a client that wants nesting groups
 * it by `parent_id` in one pass.
 */
final class GetAccountTreeHandler
{
    public function __construct(
        private readonly Authorize $authorize,
        private readonly AccountRows $rows,
    ) {}

    /**
     * @return list<array<string, mixed>>
     *
     * @throws FinanceRefused
     */
    public function handle(GetAccountTree $query): array
    {
        $this->authorize->require($query->actorId, FinancePermissions::ACCOUNTS_VIEW);

        return array_map(static fn (object $row): array => (array) $row, $this->rows->liveTreeUnlocked());
    }
}
