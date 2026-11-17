<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\UpdateAccount;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Finance\AccountSubtype;
use Flexipos\Contracts\Finance\AccountType;
use Flexipos\Contracts\Finance\AccountUsage;
use Flexipos\Contracts\Finance\ControlType;
use Flexipos\Contracts\Finance\NormalBalance;
use Flexipos\Finance\Accounts\AccountDraft;
use Flexipos\Finance\Accounts\AccountInput;
use Flexipos\Finance\Accounts\AccountRefusals;
use Flexipos\Finance\Accounts\AccountRows;
use Flexipos\Finance\Accounts\AccountRules;
use Flexipos\Finance\Accounts\ReferrerCensus;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Registry\FinanceAuditActions;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Update an account's own fields (T-032 plan Ruling 6, step 10).
 *
 * The target is read **live and `FOR UPDATE`** (AC-20, AC-21) and every rule
 * is evaluated on those locked values. Children are a shared-lock count;
 * postings and referrers come from the ports, the referrers counted inside
 * this transaction.
 *
 * **Effective values.** An update names only some fields (AC-21), so every
 * rule is evaluated against the account as it would be after the change:
 * a named field replaces the stored one, an unnamed one keeps it. That is
 * what makes a type change with no subtype named check the **stored**
 * subtype against the new type, a lone `normal_balance` check against the
 * stored type, and a control account's type and subtype checks against its
 * stored control type (AC-16), none of which the request alone can settle.
 *
 * **Check order**, fixed so a test can pin each reason: system immutability
 * (AC-18), type change (AC-15), subtype change (AC-15a, D-32), code (AC-22,
 * AC-3), postability (AC-14),
 * control type lock (AC-19), subtype and normal balance (AC-6, AC-7), control
 * fit (AC-16), single holder (AC-17).
 *
 * An empty body is refused (nothing to update is not a success), and a body
 * whose values equal the stored ones is accepted and audited like any other
 * update: it is a write that happened to change nothing, and `changed_fields`
 * says so.
 */
final class UpdateAccountHandler
{
    public const ACTION = 'account_updated';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly Authorize $authorize,
        private readonly AccountRows $rows,
        private readonly AccountUsage $usage,
        private readonly ReferrerCensus $referrers,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @throws FinanceRefused
     */
    public function handle(UpdateAccount $command): void
    {
        $this->authorize->require($command->actorId, FinancePermissions::ACCOUNTS_MANAGE);

        $id = Input::id($command->accountId, 'account_id');
        $draft = AccountInput::forUpdate($command->fields);

        if ($draft->supplied === []) {
            throw FinanceRefused::validation('fields', 'At least one field must be supplied.');
        }

        $this->tenant->get()->transaction(function () use ($command, $id, $draft): void {
            $row = $this->rows->liveForUpdate($id);

            if ($row === null) {
                throw AccountRefusals::notLive($id);
            }

            /** @var object{code: string, type: string, subtype: ?string, normal_balance: string, control_type: string, is_postable: int|bool, is_system: int|bool, parent_id: ?string} $row */
            $isSystem = (bool) $row->is_system;
            $storedType = AccountType::from((string) $row->type);
            $storedSubtype = $this->rows->subtypeOf($row);
            $storedBalance = NormalBalance::from((string) $row->normal_balance);
            $storedControl = ControlType::from((string) $row->control_type);
            $storedPostable = (bool) $row->is_postable;

            $type = $draft->has('type') ? ($draft->type ?? $storedType) : $storedType;
            $subtype = $draft->has('subtype') ? $draft->subtype : $storedSubtype;
            $control = $draft->has('control_type') ? ($draft->controlType ?? $storedControl) : $storedControl;
            $postable = $draft->has('is_postable') ? ($draft->isPostable ?? $storedPostable) : $storedPostable;

            $balance = $storedBalance;

            if ($draft->has('type') || $draft->has('normal_balance')) {
                $balance = AccountRules::derive($type, $draft->has('normal_balance') ? $draft->normalBalance : null);
            }

            $typeChanged = $type !== $storedType;
            $subtypeChanged = $subtype !== $storedSubtype;
            $controlChanged = $control !== $storedControl;
            $postableChanged = $postable !== $storedPostable;
            $codeChanged = $draft->has('code') && $draft->code !== (string) $row->code;
            $balanceChanged = $draft->has('normal_balance') && $balance !== $storedBalance;

            $changed = array_keys(array_filter([
                'code' => $codeChanged,
                'type' => $typeChanged,
                'subtype' => $draft->has('subtype') && $subtypeChanged,
                'normal_balance' => $balanceChanged,
                'control_type' => $controlChanged,
                'is_postable' => $postableChanged,
            ]));

            AccountRules::system($isSystem, $changed);

            $postings = null;
            $hasPostings = function () use ($id, &$postings): bool {
                $postings ??= $this->usage->hasPostings($id);

                return $postings;
            };
            $referrerTotal = null;
            $hasReferrers = function () use ($id, &$referrerTotal): bool {
                $referrerTotal ??= $this->referrers->total($id);

                return $referrerTotal > 0;
            };

            if ($typeChanged) {
                // The child's row is already ours (exclusive) and the parent is now taken shared: child before
                // parent, the reverse of a deactivation of the parent. A known pair (D-33), NOT VERIFIED under
                // ENGINE_HOLD, recovered by the transaction retry.
                $parentType = null;

                if ($row->parent_id !== null) {
                    $parent = $this->rows->anySharedLock((string) $row->parent_id);
                    $parentType = $parent === null ? null : AccountType::from((string) $parent->type);
                }

                AccountRules::retype(
                    $isSystem,
                    $this->rows->liveChildCountSharedLock($id) > 0,
                    $hasPostings(),
                    $hasReferrers(),
                    $parentType,
                    $type,
                );
            }

            if ($subtypeChanged && ! $typeChanged) {
                AccountRules::resubtype($isSystem, $hasPostings(), $hasReferrers());
            }

            if ($codeChanged) {
                AccountRules::recode($isSystem, $hasPostings());

                $holder = $this->rows->byCodeSharedLock((string) $draft->code);

                if ($holder !== null && (string) $holder->id !== $id) {
                    throw FinanceRefused::because(
                        FinanceRefusal::CodeInUse,
                        "The code is already used by the account [{$holder->id}].",
                        'code',
                        ['holder' => (string) $holder->id],
                    );
                }
            }

            if ($postableChanged) {
                $postable
                    ? AccountRules::toPostable($isSystem, $this->rows->liveChildCountSharedLock($id) > 0)
                    : AccountRules::toHeader($isSystem, $hasPostings(), $hasReferrers());
            }

            if ($controlChanged) {
                AccountRules::recontrol($isSystem, $hasPostings(), $hasReferrers());
            }

            if ($typeChanged || $draft->has('subtype')) {
                AccountRules::subtype($type, $subtype);
            }

            if ($typeChanged || $subtypeChanged || $controlChanged || $postableChanged) {
                AccountRules::control($control, $type, $subtype, $postable);
            }

            if ($controlChanged && $control->isSingle()) {
                $holder = $this->rows->liveHolderOfControlSharedLock($control, $id);

                if ($holder !== null) {
                    throw FinanceRefused::because(
                        FinanceRefusal::ControlAccountExists,
                        "A live {$control->value} control account already exists: [{$holder->id}].",
                        'control_type',
                        ['holder' => (string) $holder->id],
                    );
                }
            }

            $changes = $this->changes($draft->supplied, $draft, $type, $subtype, $balance, $control, $postable);
            $changes['updated_at'] = $this->clock->timestamp();
            $changes['updated_by'] = $command->actorId;

            try {
                $this->rows->update($id, $changes);
            } catch (UniqueConstraintViolationException) {
                throw $this->backstopRefusal($id, $draft->code, $control);
            }

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
     * The columns to write: exactly the fields the body named, in their final
     * form, plus the normal balance when a type change re-derived it.
     *
     * @param  list<string>  $supplied
     * @return array<string, mixed>
     */
    private function changes(
        array $supplied,
        AccountDraft $draft,
        AccountType $type,
        ?AccountSubtype $subtype,
        NormalBalance $balance,
        ControlType $control,
        bool $postable,
    ): array {
        $changes = [];

        foreach ($supplied as $field) {
            match ($field) {
                'code' => $changes['code'] = $draft->code,
                'name' => $changes['name'] = $draft->name,
                'description' => $changes['description'] = $draft->description,
                'type' => $changes['type'] = $type->value,
                'subtype' => $changes['subtype'] = $subtype?->value,
                'normal_balance' => $changes['normal_balance'] = $balance->value,
                'is_postable' => $changes['is_postable'] = $postable,
                'control_type' => $changes['control_type'] = $control->value,
                'sort_order' => $changes['sort_order'] = $draft->sortOrder,
                default => null,
            };
        }

        if (in_array('type', $supplied, true)) {
            $changes['normal_balance'] = $balance->value;
        }

        return $changes;
    }

    /**
     * A unique index fired between the pre-check and the write: name the
     * collision by asking again, under a lock.
     */
    private function backstopRefusal(string $id, ?string $code, ControlType $control): FinanceRefused
    {
        if ($code !== null) {
            $byCode = $this->rows->byCodeSharedLock($code);

            if ($byCode !== null && (string) $byCode->id !== $id) {
                return FinanceRefused::because(
                    FinanceRefusal::CodeInUse,
                    "The code is already used by the account [{$byCode->id}].",
                    'code',
                    ['holder' => (string) $byCode->id],
                );
            }
        }

        $holder = $control->isSingle() ? $this->rows->liveHolderOfControlSharedLock($control, $id) : null;

        if ($holder !== null) {
            return FinanceRefused::because(
                FinanceRefusal::ControlAccountExists,
                "A live {$control->value} control account already exists: [{$holder->id}].",
                'control_type',
                ['holder' => (string) $holder->id],
            );
        }

        return FinanceRefused::because(
            FinanceRefusal::CodeInUse,
            'The account could not be updated because it collides with another account.',
            'code',
        );
    }
}
