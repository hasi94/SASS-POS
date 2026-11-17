<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ReparentAccount;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Finance\Accounts\AccountRefusals;
use Flexipos\Finance\Accounts\AccountRows;
use Flexipos\Finance\Accounts\AccountRules;
use Flexipos\Finance\Accounts\AccountState;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Registry\FinanceAuditActions;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Re-parent an account (T-032 plan Ruling 6, step 11).
 *
 * **The whole chart is locked, `FOR UPDATE`, in id order** (the T-027
 * `product_components` precedent): a subtree move reads every descendant, and
 * a concurrent child create under a descendant would otherwise slip a row
 * past the rewrite. Re-parent is rare and the chart is at most a few
 * thousand rows. Everything below is then decided on the locked values and
 * the paths and depths are rewritten in PHP, which is engine-portable: no
 * recursive SQL and no `LIKE` over paths.
 *
 * Soft-deleted descendants are rewritten too, and they count towards the
 * depth limit: a dead row that kept its old path would point at an ancestor
 * it no longer has, and it must still fit its column.
 *
 * The target is audited once. The descendants' path and depth changes are
 * derived from the move and are not audited row by row (Ruling 11).
 */
final class ReparentAccountHandler
{
    public const ACTION = 'account_reparented';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly Authorize $authorize,
        private readonly AccountRows $rows,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @throws FinanceRefused
     */
    public function handle(ReparentAccount $command): void
    {
        $this->authorize->require($command->actorId, FinancePermissions::ACCOUNTS_MANAGE);

        $id = Input::id($command->accountId, 'account_id');
        $newParentId = $command->newParentId === null ? null : Input::id($command->newParentId, 'parent_id');

        $this->tenant->get()->transaction(function () use ($command, $id, $newParentId): void {
            /** @var array<string, object> $byId */
            $byId = [];

            foreach ($this->rows->allForUpdate() as $row) {
                $byId[(string) $row->id] = $row;
            }

            $row = $byId[$id] ?? null;

            if ($row === null || $row->deleted_at !== null) {
                throw AccountRefusals::notLive($id);
            }

            $account = $this->rows->state($row);

            AccountRules::system($account->isSystem, ['parent_id']);

            $newParent = null;

            if ($newParentId !== null) {
                $parentRow = $byId[$newParentId] ?? null;
                $newParent = $parentRow === null ? null : $this->rows->state($parentRow);

                if ($newParent !== null) {
                    AccountRules::move($account, $newParent, $this->deepestDepth($account, $byId));
                }

                AccountRules::parent($newParent, $account->type);
            }

            $placed = AccountRules::place($newParent, $id);
            $now = $this->clock->timestamp();

            $this->rewriteSubtree($account, $placed['path'], $placed['depth'], $byId);

            $this->rows->update($id, [
                'parent_id' => $newParentId,
                'path' => $placed['path'],
                'depth' => $placed['depth'],
                'updated_at' => $now,
                'updated_by' => $command->actorId,
            ]);

            $after = $this->rows->anyForUpdate($id);

            $this->audit->record(
                FinanceAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'account',
                $id,
                (array) $row,
                $after === null ? null : (array) $after,
            );
        }, 2);
    }

    /**
     * The stored depth of the deepest account at or below `$account`
     * (its own depth when it has no descendants).
     *
     * @param  array<string, object>  $byId
     */
    private function deepestDepth(AccountState $account, array $byId): int
    {
        $deepest = $account->depth;

        foreach ($this->descendants($account, $byId) as $row) {
            $deepest = max($deepest, (int) $row->depth);
        }

        return $deepest;
    }

    /**
     * Every row whose path runs through `$account`, live or soft-deleted.
     *
     * @param  array<string, object>  $byId
     * @return list<object>
     */
    private function descendants(AccountState $account, array $byId): array
    {
        $found = [];

        foreach ($byId as $row) {
            if (str_starts_with((string) $row->path, $account->path.'/')) {
                $found[] = $row;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, object>  $byId
     */
    private function rewriteSubtree(AccountState $account, string $newPath, int $newDepth, array $byId): void
    {
        foreach ($this->descendants($account, $byId) as $row) {
            $path = $newPath.substr((string) $row->path, strlen($account->path));
            $depth = (int) $row->depth - $account->depth + $newDepth;

            // The same guard `place()` applies to a single path: a rewritten
            // descendant must still fit its column.
            AccountRules::place(null, $path);

            $this->rows->update((string) $row->id, ['path' => $path, 'depth' => $depth]);
        }
    }
}
