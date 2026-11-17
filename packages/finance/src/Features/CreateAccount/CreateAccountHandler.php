<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\CreateAccount;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Finance\ControlType;
use Flexipos\Finance\Accounts\AccountDraft;
use Flexipos\Finance\Accounts\AccountInput;
use Flexipos\Finance\Accounts\AccountRows;
use Flexipos\Finance\Accounts\AccountRules;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Registry\FinanceAuditActions;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create an account (T-032 plan Ruling 6, steps 8 and 9).
 *
 * Order: authorize, parse (every rejection that needs no lock), then one
 * transaction. Inside it, the parent is read under a **shared lock** and
 * AC-10 is re-validated on the locked values, so a parent deleted,
 * deactivated or turned postable by a concurrent request cannot gain a child
 * (AC-12). The code and single-holder checks are locking reads too.
 *
 * **Two backstops sit behind the pre-checks**: the unique index on `code`
 * and the unique index on the generated `control_marker`. A violation is
 * caught and mapped to the same refusal the pre-check would have given, with
 * the holder re-read **under a lock** (W-1: a plain read under REPEATABLE
 * READ would see the snapshot from before the winner committed and find
 * nothing).
 */
final class CreateAccountHandler
{
    public const ACTION = 'account_created';

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
    public function handle(CreateAccount $command): CreateAccountResult
    {
        $this->authorize->require($command->actorId, FinancePermissions::ACCOUNTS_MANAGE);

        $draft = AccountInput::forCreate($command->fields);

        $id = strtoupper((string) Str::ulid());

        $this->tenant->get()->transaction(function () use ($command, $draft, $id): void {
            $parent = null;

            if ($draft->parentId !== null) {
                $row = $this->rows->anySharedLock($draft->parentId);
                $parent = $row === null ? null : $this->rows->state($row);

                AccountRules::parent($parent, $draft->type ?? throw new \LogicException('A create draft carries a type.'));
            }

            $this->assertCodeFree((string) $draft->code);
            $this->assertControlFree($draft);

            $placed = AccountRules::place($parent, $id);
            $now = $this->clock->timestamp();

            try {
                $this->rows->insert([
                    'id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                    'created_by' => $command->actorId,
                    'updated_by' => null,
                    'code' => $draft->code,
                    'name' => $draft->name,
                    'description' => $draft->description,
                    'type' => $draft->type->value,
                    'subtype' => $draft->subtype?->value,
                    'parent_id' => $draft->parentId,
                    'path' => $placed['path'],
                    'depth' => $placed['depth'],
                    'normal_balance' => $draft->normalBalance?->value,
                    'is_postable' => $draft->isPostable,
                    'is_system' => false,
                    'control_type' => $draft->controlType?->value,
                    'currency' => null,
                    'is_active' => $draft->isActive,
                    'sort_order' => $draft->sortOrder,
                    'opening_balance' => null,
                    'opening_balance_date' => null,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->backstopRefusal($draft);
            }

            $after = $this->rows->anyForUpdate($id);

            $this->audit->record(
                FinanceAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'account',
                $id,
                null,
                $after === null ? null : (array) $after,
            );
        }, 2);

        return new CreateAccountResult($id);
    }

    /**
     * A code held by any account, live or soft-deleted, is in use (AC-3).
     */
    private function assertCodeFree(string $code): void
    {
        $holder = $this->rows->byCodeSharedLock($code);

        if ($holder !== null) {
            throw $this->codeInUse((string) $holder->id);
        }
    }

    private function assertControlFree(AccountDraft $draft): void
    {
        $control = $draft->controlType;

        if ($control === null || ! $control->isSingle()) {
            return;
        }

        $holder = $this->rows->liveHolderOfControlSharedLock($control);

        if ($holder !== null) {
            throw $this->controlExists($control, (string) $holder->id);
        }
    }

    /**
     * The unique index fired: work out which one by asking, under a lock.
     */
    private function backstopRefusal(AccountDraft $draft): FinanceRefused
    {
        $byCode = $this->rows->byCodeSharedLock((string) $draft->code);

        if ($byCode !== null) {
            return $this->codeInUse((string) $byCode->id);
        }

        $control = $draft->controlType;
        $holder = $control !== null && $control->isSingle()
            ? $this->rows->liveHolderOfControlSharedLock($control)
            : null;

        if ($control !== null && $holder !== null) {
            return $this->controlExists($control, (string) $holder->id);
        }

        return FinanceRefused::because(
            FinanceRefusal::CodeInUse,
            'The account could not be created because it collides with another account.',
            'code',
        );
    }

    private function codeInUse(string $holderId): FinanceRefused
    {
        return FinanceRefused::because(
            FinanceRefusal::CodeInUse,
            "The code is already used by the account [{$holderId}].",
            'code',
            ['holder' => $holderId],
        );
    }

    private function controlExists(ControlType $control, string $holderId): FinanceRefused
    {
        return FinanceRefused::because(
            FinanceRefusal::ControlAccountExists,
            "A live {$control->value} control account already exists: [{$holderId}].",
            'control_type',
            ['holder' => $holderId],
        );
    }
}
