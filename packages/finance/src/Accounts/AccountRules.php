<?php

declare(strict_types=1);

namespace Flexipos\Finance\Accounts;

use Flexipos\Contracts\Finance\AccountSubtype;
use Flexipos\Contracts\Finance\AccountType;
use Flexipos\Contracts\Finance\ControlType;
use Flexipos\Contracts\Finance\NormalBalance;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Support\Limits;
use LogicException;

/**
 * The chart-of-accounts rules, pure (T-032 plan Ruling 4; AC-6, AC-7,
 * AC-10 to AC-19, AC-22).
 *
 * No method here reads a database, a clock or the container: each takes the
 * facts it decides on and either returns or throws a {@see FinanceRefused}.
 * The handlers (chunk C) gather the facts under row locks and call these, so
 * a rule is tested once, as a dataset, and the handler tests only prove that
 * the facts are gathered under the right lock.
 *
 * Every decision that has several failing conditions returns the **first** in
 * a fixed order, so a test can pin each one by itself and pin the order.
 */
final class AccountRules
{
    /** Maximum depth: ten levels, depth 0 to 9 (D-12, AC-11). */
    public const MAX_DEPTH = 9;

    /**
     * The fields no one may change on a system account (AC-18), under their
     * request names. `name`, `description` and `sort_order` are not here.
     *
     * @var list<string>
     */
    public const SYSTEM_IMMUTABLE = [
        'code', 'type', 'subtype', 'normal_balance', 'control_type', 'parent_id', 'is_postable',
    ];

    /**
     * The normal balance an account of this type has, checked against the one
     * supplied (AC-6).
     *
     * @throws FinanceRefused normal_balance_mismatch
     */
    public static function derive(AccountType $type, ?NormalBalance $supplied): NormalBalance
    {
        $derived = $type->normalBalance();

        if ($supplied !== null && $supplied !== $derived) {
            throw FinanceRefused::because(
                FinanceRefusal::NormalBalanceMismatch,
                "A {$type->value} account has a {$derived->value} normal balance.",
                'normal_balance',
            );
        }

        return $derived;
    }

    /**
     * A subtype must belong to the type; null is always allowed here (AC-7).
     *
     * @throws FinanceRefused subtype_mismatch
     */
    public static function subtype(AccountType $type, ?AccountSubtype $subtype): ?AccountSubtype
    {
        if ($subtype !== null && ! in_array($subtype, $type->subtypes(), true)) {
            throw FinanceRefused::because(
                FinanceRefusal::SubtypeMismatch,
                "The subtype {$subtype->value} does not belong to the type {$type->value}.",
                'subtype',
            );
        }

        return $subtype;
    }

    /**
     * A control account must be postable and its type and subtype must fit
     * (AC-16). `none` demands nothing.
     *
     * @throws FinanceRefused control_type_mismatch
     */
    public static function control(
        ControlType $control,
        AccountType $type,
        ?AccountSubtype $subtype,
        bool $isPostable,
    ): void {
        $requires = $control->requires();

        if ($requires === null) {
            return;
        }

        if (! $isPostable) {
            throw FinanceRefused::because(
                FinanceRefusal::ControlTypeMismatch,
                "A {$control->value} control account must be postable.",
                'control_type',
            );
        }

        if ($type !== $requires['type'] || $subtype !== $requires['subtype']) {
            throw FinanceRefused::because(
                FinanceRefusal::ControlTypeMismatch,
                "A {$control->value} control account must be {$requires['type']->value}/{$requires['subtype']->value}.",
                'control_type',
            );
        }
    }

    /**
     * Whether a parent can take a child of this type, checked in AC-10's
     * fixed order: not found, not live, inactive, not a header, a different
     * type, too deep (D-25).
     *
     * @throws FinanceRefused parent_not_found, parent_not_live, parent_inactive, parent_not_header, parent_type_mismatch, depth_exceeded
     */
    public static function parent(?AccountState $parent, AccountType $childType): void
    {
        if ($parent === null) {
            throw self::parentRefusal(FinanceRefusal::ParentNotFound, 'The parent account does not exist.');
        }

        if ($parent->isDeleted) {
            throw self::parentRefusal(FinanceRefusal::ParentNotLive, 'The parent account has been deleted.');
        }

        if (! $parent->isActive) {
            throw self::parentRefusal(FinanceRefusal::ParentInactive, 'The parent account is inactive.');
        }

        if ($parent->isPostable) {
            throw self::parentRefusal(FinanceRefusal::ParentNotHeader, 'The parent account is postable, so it cannot have children.');
        }

        if ($parent->type !== $childType) {
            throw self::parentRefusal(
                FinanceRefusal::ParentTypeMismatch,
                "The parent is a {$parent->type->value} account and the child is a {$childType->value} account.",
            );
        }

        if ($parent->depth >= self::MAX_DEPTH) {
            throw self::parentRefusal(FinanceRefusal::DepthExceeded, 'The chart may be at most 10 levels deep.');
        }
    }

    /**
     * The path and depth of a new child (AC-11). A root's path is its own id.
     *
     * @return array{path: string, depth: int}
     *
     * @throws LogicException when the path would not fit its column
     */
    public static function place(?AccountState $parent, string $id): array
    {
        $path = $parent === null ? $id : $parent->path.'/'.$id;

        if (strlen($path) > Limits::PATH) {
            throw new LogicException('An account path of '.strlen($path).' characters does not fit '.Limits::PATH.'.');
        }

        return ['path' => $path, 'depth' => $parent === null ? 0 : $parent->depth + 1];
    }

    /**
     * Whether `$account` may be moved under `$newParent` (AC-13).
     *
     * `cycle` when the new parent is the account or anywhere below it. The
     * test compares a path **segment** (`path` or `path/…`), never a bare
     * string prefix, so an id that merely starts with the account's id is not
     * a descendant. The depth limit is checked on the deepest descendant,
     * not on the moved node alone.
     *
     * `$deepestDescendantDepth` is the **stored** depth of the deepest
     * account in the subtree (the account's own depth when it has no
     * children).
     *
     * @throws FinanceRefused cycle, depth_exceeded
     */
    public static function move(AccountState $account, AccountState $newParent, int $deepestDescendantDepth): void
    {
        if ($newParent->id === $account->id
            || $newParent->path === $account->path
            || str_starts_with($newParent->path, $account->path.'/')) {
            throw FinanceRefused::because(
                FinanceRefusal::Cycle,
                'An account cannot be moved under itself or one of its descendants.',
                'parent_id',
            );
        }

        $newDepth = $newParent->depth + 1;
        $deepestAfter = $deepestDescendantDepth - $account->depth + $newDepth;

        if ($deepestAfter > self::MAX_DEPTH) {
            throw self::parentRefusal(
                FinanceRefusal::DepthExceeded,
                'Moving the account would put its deepest descendant at depth '.$deepestAfter.', and the limit is '.self::MAX_DEPTH.'.',
            );
        }
    }

    /**
     * Whether a system account may be changed in these fields (AC-18).
     *
     * @param  list<string>  $changed  the request names of the fields whose value differs from the stored one
     *
     * @throws FinanceRefused system_account_immutable
     */
    public static function system(bool $isSystem, array $changed): void
    {
        if (! $isSystem) {
            return;
        }

        $locked = array_values(array_intersect(self::SYSTEM_IMMUTABLE, $changed));

        if ($locked !== []) {
            throw FinanceRefused::because(
                FinanceRefusal::SystemAccountImmutable,
                'A system account\'s '.implode(', ', $locked).' cannot be changed.',
                $locked[0],
                ['fields' => $locked],
            );
        }
    }

    /**
     * Deleting or deactivating is not an edit of a field, and a system
     * account allows neither (AC-18).
     *
     * @throws FinanceRefused system_account_immutable
     */
    public static function systemRemoval(bool $isSystem): void
    {
        if ($isSystem) {
            throw FinanceRefused::because(
                FinanceRefusal::SystemAccountImmutable,
                'A system account cannot be deleted or deactivated.',
            );
        }
    }

    /**
     * A type change needs a non-system account with no children, no postings,
     * no referrers, and a type that matches its parent's (AC-15). The first
     * failing condition is named in the refusal's `condition` (D-30).
     *
     * @throws FinanceRefused type_change_refused
     */
    public static function retype(
        bool $isSystem,
        bool $hasChildren,
        bool $hasPostings,
        bool $hasReferrers,
        ?AccountType $parentType,
        AccountType $newType,
    ): void {
        $condition = match (true) {
            $isSystem => 'is_system',
            $hasChildren => 'has_children',
            $hasPostings => 'has_postings',
            $hasReferrers => 'in_use',
            $parentType !== null && $parentType !== $newType => 'parent_type_mismatch',
            default => null,
        };

        if ($condition !== null) {
            throw FinanceRefused::because(
                FinanceRefusal::TypeChangeRefused,
                "The type cannot be changed: {$condition}.",
                'type',
                ['condition' => $condition],
            );
        }
    }

    /**
     * A change to a **different** subtype is guarded like a retype (AC-15a,
     * D-32): a product pointing at a cogs account whose subtype became
     * operating_expense would silently break the owed-account kind rules. The
     * refusal is `type_change_refused`, naming the first failing condition;
     * children and the parent's type do not matter to a subtype. The caller
     * asks only when the subtype really changes, and not when the type changes
     * with it (the retype guard has already run).
     *
     * @throws FinanceRefused type_change_refused
     */
    public static function resubtype(bool $isSystem, bool $hasPostings, bool $hasReferrers): void
    {
        $condition = match (true) {
            $isSystem => 'is_system',
            $hasPostings => 'has_postings',
            $hasReferrers => 'in_use',
            default => null,
        };

        if ($condition !== null) {
            throw FinanceRefused::because(
                FinanceRefusal::TypeChangeRefused,
                "The subtype cannot be changed: {$condition}.",
                'subtype',
                ['condition' => $condition],
            );
        }
    }

    /**
     * A code change is refused on a system account, then once the account has
     * postings (AC-22).
     *
     * @throws FinanceRefused system_account_immutable, code_locked
     */
    public static function recode(bool $isSystem, bool $hasPostings): void
    {
        self::system($isSystem, ['code']);

        if ($hasPostings) {
            throw FinanceRefused::because(
                FinanceRefusal::CodeLocked,
                'The code cannot be changed once the account has postings.',
                'code',
            );
        }
    }

    /**
     * A control-type change is refused on a system account, then once the
     * account has postings or referrers (AC-19). The new value must still
     * pass {@see self::control()} and the single-holder check.
     *
     * @throws FinanceRefused system_account_immutable, control_type_locked
     */
    public static function recontrol(bool $isSystem, bool $hasPostings, bool $hasReferrers): void
    {
        self::system($isSystem, ['control_type']);

        if ($hasPostings || $hasReferrers) {
            throw FinanceRefused::because(
                FinanceRefusal::ControlTypeLocked,
                'The control type cannot be changed once the account has postings or is referenced.',
                'control_type',
            );
        }
    }

    /**
     * A header becoming postable is refused while it has live children (AC-14).
     *
     * @throws FinanceRefused system_account_immutable, has_children
     */
    public static function toPostable(bool $isSystem, bool $hasLiveChildren): void
    {
        self::system($isSystem, ['is_postable']);

        if ($hasLiveChildren) {
            throw FinanceRefused::because(
                FinanceRefusal::HasChildren,
                'An account with children cannot be made postable.',
                'is_postable',
            );
        }
    }

    /**
     * A postable account becoming a header is refused once it has postings or
     * referrers (AC-14).
     *
     * @throws FinanceRefused system_account_immutable, has_postings, in_use
     */
    public static function toHeader(bool $isSystem, bool $hasPostings, bool $hasReferrers): void
    {
        self::system($isSystem, ['is_postable']);

        if ($hasPostings) {
            throw FinanceRefused::because(
                FinanceRefusal::HasPostings,
                'An account with postings cannot become a header.',
                'is_postable',
            );
        }

        if ($hasReferrers) {
            throw FinanceRefused::because(
                FinanceRefusal::InUse,
                'An account that is referenced cannot become a header.',
                'is_postable',
            );
        }
    }

    private static function parentRefusal(FinanceRefusal $reason, string $message): FinanceRefused
    {
        return FinanceRefused::because($reason, $message, 'parent_id');
    }
}
