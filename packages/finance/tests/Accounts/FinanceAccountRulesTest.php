<?php

namespace Flexipos\Finance\Tests\Accounts;

use Flexipos\Contracts\Finance\AccountSubtype;
use Flexipos\Contracts\Finance\AccountType;
use Flexipos\Contracts\Finance\ControlType;
use Flexipos\Finance\Accounts\AccountRules;
use Flexipos\Finance\Accounts\AccountState;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use LogicException;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| AccountRules (T-032 plan step 6; AC-6, AC-7, AC-10, AC-11, AC-13 to AC-19, AC-22)
|--------------------------------------------------------------------------
|
| Pure decision tables. Each row names the one condition it exercises, and the
| ordering cases put two conditions on one input to pin which is reported.
*/

function financeRulesRefusal(callable $decide): FinanceRefused
{
    try {
        $decide();
    } catch (FinanceRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('The rule allowed what it should have refused.');
}

/**
 * A stored account, defaulting to a live, active, postable, non-system root
 * asset; each case overrides only what it is about.
 *
 * @param  array<string, mixed>  $with
 */
function financeAccountState(array $with = []): AccountState
{
    $id = $with['id'] ?? 'ROOT';

    return new AccountState(
        id: $id,
        type: $with['type'] ?? AccountType::Asset,
        isPostable: $with['isPostable'] ?? true,
        isActive: $with['isActive'] ?? true,
        isDeleted: $with['isDeleted'] ?? false,
        isSystem: $with['isSystem'] ?? false,
        path: $with['path'] ?? $id,
        depth: $with['depth'] ?? 0,
    );
}

// ---- AC-10: the parent, in a fixed order ----------------------------------

it('accepts a live, active header of the same type below depth 9', function () {
    AccountRules::parent(financeAccountState(['isPostable' => false, 'depth' => 8]), AccountType::Asset);

    expect(true)->toBeTrue();
});

it('names each parent failure by itself', function (?array $parent, FinanceRefusal $reason) {
    $state = $parent === null ? null : financeAccountState($parent + ['isPostable' => false]);

    $refused = financeRulesRefusal(fn () => AccountRules::parent($state, AccountType::Asset));

    expect($refused->reason)->toBe($reason)
        ->and($refused->field)->toBe('parent_id');
})->with([
    'no such account' => [null, FinanceRefusal::ParentNotFound],
    'soft-deleted' => [['isDeleted' => true], FinanceRefusal::ParentNotLive],
    'inactive' => [['isActive' => false], FinanceRefusal::ParentInactive],
    'postable' => [['isPostable' => true], FinanceRefusal::ParentNotHeader],
    'another type' => [['type' => AccountType::Liability], FinanceRefusal::ParentTypeMismatch],
    'at depth nine' => [['depth' => 9], FinanceRefusal::DepthExceeded],
]);

it('reports the first of several parent failures in AC-10 order', function () {
    $everythingWrong = financeAccountState([
        'isDeleted' => true, 'isActive' => false, 'isPostable' => true, 'type' => AccountType::Liability, 'depth' => 9,
    ]);

    $reasons = [];

    foreach ([
        FinanceRefusal::ParentNotLive,
        FinanceRefusal::ParentInactive,
        FinanceRefusal::ParentNotHeader,
        FinanceRefusal::ParentTypeMismatch,
        FinanceRefusal::DepthExceeded,
    ] as $expected) {
        $reasons[] = financeRulesRefusal(fn () => AccountRules::parent($everythingWrong, AccountType::Asset))->reason;

        // Repair the failure just reported, so the next one is the only one left in front.
        $everythingWrong = financeAccountState([
            'isDeleted' => $expected === FinanceRefusal::ParentNotLive ? false : $everythingWrong->isDeleted,
            'isActive' => $expected === FinanceRefusal::ParentInactive ? true : $everythingWrong->isActive,
            'isPostable' => $expected === FinanceRefusal::ParentNotHeader ? false : $everythingWrong->isPostable,
            'type' => $expected === FinanceRefusal::ParentTypeMismatch ? AccountType::Asset : $everythingWrong->type,
            'depth' => $everythingWrong->depth,
        ]);
    }

    expect($reasons)->toBe([
        FinanceRefusal::ParentNotLive,
        FinanceRefusal::ParentInactive,
        FinanceRefusal::ParentNotHeader,
        FinanceRefusal::ParentTypeMismatch,
        FinanceRefusal::DepthExceeded,
    ]);
});

// ---- AC-11: depth and path ------------------------------------------------

it('places a root at depth 0 with its own id as path', function () {
    expect(AccountRules::place(null, 'CHILD'))->toBe(['path' => 'CHILD', 'depth' => 0]);
});

it('places a child below a parent at depth 0 and at depth 8', function () {
    $top = financeAccountState(['id' => 'P', 'path' => 'P', 'depth' => 0, 'isPostable' => false]);
    $deep = financeAccountState(['id' => 'Q', 'path' => 'A/B/C/D/E/F/G/Q', 'depth' => 8, 'isPostable' => false]);

    expect(AccountRules::place($top, 'C'))->toBe(['path' => 'P/C', 'depth' => 1])
        ->and(AccountRules::place($deep, 'C'))->toBe(['path' => 'A/B/C/D/E/F/G/Q/C', 'depth' => 9]);
});

it('guards the path width, which depth ten at 26 characters an id can never reach', function () {
    $ulid = str_repeat('A', 26);
    $path = implode('/', array_fill(0, 9, $ulid));

    // Nine ids and eight slashes is 242; one more is 269, the documented maximum.
    expect(strlen(AccountRules::place(financeAccountState(['path' => $path, 'depth' => 8]), $ulid)['path']))->toBe(269);

    $absurd = financeAccountState(['path' => str_repeat('A', 495), 'depth' => 2]);

    expect(fn () => AccountRules::place($absurd, $ulid))->toThrow(LogicException::class);
});

// ---- AC-13: re-parenting --------------------------------------------------

it('refuses a move under itself, a child or a grandchild as a cycle', function (string $parentId, string $parentPath) {
    $account = financeAccountState(['id' => 'A', 'path' => 'X/A', 'depth' => 1]);
    $newParent = financeAccountState(['id' => $parentId, 'path' => $parentPath, 'depth' => 3, 'isPostable' => false]);

    expect(financeRulesRefusal(fn () => AccountRules::move($account, $newParent, 3))->reason)->toBe(FinanceRefusal::Cycle);
})->with([
    'itself' => ['A', 'X/A'],
    'a child' => ['B', 'X/A/B'],
    'a grandchild' => ['C', 'X/A/B/C'],
]);

it('does not mistake an id that merely starts with the account id for a descendant', function () {
    // Sibling roots ABC and ABCD: ABCD's path begins with ABC but not with ABC/.
    $account = financeAccountState(['id' => 'ABC', 'path' => 'ABC', 'depth' => 0]);
    $sibling = financeAccountState(['id' => 'ABCD', 'path' => 'ABCD', 'depth' => 0, 'isPostable' => false]);

    AccountRules::move($account, $sibling, 0);

    expect(true)->toBeTrue();
});

it('checks the depth limit against the deepest descendant, not the moved node alone', function () {
    // An account at depth 1 whose deepest descendant is at depth 7: six levels below it.
    $account = financeAccountState(['id' => 'A', 'path' => 'X/A', 'depth' => 1]);
    $under3 = financeAccountState(['id' => 'T', 'path' => 'R/S/U/T', 'depth' => 3, 'isPostable' => false]);
    $under2 = financeAccountState(['id' => 'U', 'path' => 'R/S/U', 'depth' => 2, 'isPostable' => false]);

    // Moved under depth 3 the account is at 4 and its deepest descendant at 10.
    expect(financeRulesRefusal(fn () => AccountRules::move($account, $under3, 7))->reason)->toBe(FinanceRefusal::DepthExceeded);

    // Moved under depth 2 the deepest descendant lands on 9, the last allowed level.
    AccountRules::move($account, $under2, 7);

    expect(true)->toBeTrue();
});

it('moves a leaf under a depth-8 parent but not under a depth-9 one', function () {
    $leaf = financeAccountState(['id' => 'L', 'path' => 'L', 'depth' => 0]);

    AccountRules::move($leaf, financeAccountState(['id' => 'P', 'path' => 'P', 'depth' => 8, 'isPostable' => false]), 0);

    expect(financeRulesRefusal(fn () => AccountRules::move($leaf, financeAccountState(['id' => 'Q', 'path' => 'Q', 'depth' => 9, 'isPostable' => false]), 0))->reason)
        ->toBe(FinanceRefusal::DepthExceeded);
});

// ---- AC-16: control accounts ----------------------------------------------

it('accepts each control type on exactly its own type and subtype', function () {
    $fits = [
        ['cash', 'asset', 'cash'], ['bank', 'asset', 'bank'], ['inventory', 'asset', 'inventory'],
        ['ar', 'asset', 'receivable'], ['ap', 'liability', 'payable'], ['tax_payable', 'liability', 'tax'],
        ['tax_receivable', 'asset', 'current_asset'], ['grni', 'liability', 'current_liability'],
        ['payroll_payable', 'liability', 'current_liability'], ['retained_earnings', 'equity', 'retained_earnings'],
    ];

    foreach ($fits as [$control, $type, $subtype]) {
        AccountRules::control(ControlType::from($control), AccountType::from($type), AccountSubtype::from($subtype), true);
    }

    expect($fits)->toHaveCount(10);
});

it('refuses a control account that is a header, or of the wrong type or subtype', function (string $control, string $type, ?string $subtype, bool $postable) {
    $refused = financeRulesRefusal(fn () => AccountRules::control(
        ControlType::from($control),
        AccountType::from($type),
        $subtype === null ? null : AccountSubtype::from($subtype),
        $postable,
    ));

    expect($refused->reason)->toBe(FinanceRefusal::ControlTypeMismatch)
        ->and($refused->field)->toBe('control_type');
})->with([
    'a header ar account' => ['ar', 'asset', 'receivable', false],
    'a header cash account' => ['cash', 'asset', 'cash', false],
    'ar on the wrong asset subtype' => ['ar', 'asset', 'cash', true],
    'ar on a liability' => ['ar', 'liability', 'receivable', true],
    'ar with no subtype' => ['ar', 'asset', null, true],
    'ap on the right type and wrong subtype' => ['ap', 'liability', 'tax', true],
    'tax_receivable on a liability' => ['tax_receivable', 'liability', 'current_liability', true],
    'grni on a current asset' => ['grni', 'asset', 'current_asset', true],
    'retained_earnings on capital' => ['retained_earnings', 'equity', 'capital', true],
]);

it('demands nothing of a none control type', function () {
    AccountRules::control(ControlType::None, AccountType::Revenue, null, false);
    AccountRules::control(ControlType::None, AccountType::Asset, AccountSubtype::Cash, true);

    expect(true)->toBeTrue();
});

// ---- AC-18: system accounts -----------------------------------------------

it('refuses a change to each of the seven immutable fields of a system account', function (string $field) {
    $refused = financeRulesRefusal(fn () => AccountRules::system(true, [$field]));

    expect($refused->reason)->toBe(FinanceRefusal::SystemAccountImmutable)
        ->and($refused->field)->toBe($field);
})->with([
    'code' => ['code'],
    'type' => ['type'],
    'subtype' => ['subtype'],
    'normal balance' => ['normal_balance'],
    'control type' => ['control_type'],
    'parent' => ['parent_id'],
    'postable' => ['is_postable'],
]);

it('lets a system account change its name, description and sort order, and any account change nothing', function () {
    AccountRules::system(true, ['name', 'description', 'sort_order']);
    AccountRules::system(true, []);
    AccountRules::system(false, ['code', 'type', 'subtype', 'normal_balance', 'control_type', 'parent_id', 'is_postable']);

    expect(financeRulesRefusal(fn () => AccountRules::system(true, ['name', 'code', 'type']))->context['fields'])->toBe(['code', 'type']);
});

it('refuses to delete or deactivate a system account only', function () {
    AccountRules::systemRemoval(false);

    expect(financeRulesRefusal(fn () => AccountRules::systemRemoval(true))->reason)->toBe(FinanceRefusal::SystemAccountImmutable);
});

// ---- AC-15: retype ---------------------------------------------------------

it('names the one failing condition of a type change', function (array $facts, string $condition) {
    $refused = financeRulesRefusal(fn () => AccountRules::retype(
        $facts['system'] ?? false,
        $facts['children'] ?? false,
        $facts['postings'] ?? false,
        $facts['referrers'] ?? false,
        $facts['parent'] ?? null,
        AccountType::Liability,
    ));

    expect($refused->reason)->toBe(FinanceRefusal::TypeChangeRefused)
        ->and($refused->context['condition'])->toBe($condition)
        ->and($refused->field)->toBe('type');
})->with([
    'a system account' => [['system' => true], 'is_system'],
    'children' => [['children' => true], 'has_children'],
    'postings' => [['postings' => true], 'has_postings'],
    'referrers' => [['referrers' => true], 'in_use'],
    'a parent of another type' => [['parent' => AccountType::Asset], 'parent_type_mismatch'],
    'everything at once, system first' => [['system' => true, 'children' => true, 'postings' => true, 'referrers' => true, 'parent' => AccountType::Asset], 'is_system'],
    'children before postings' => [['children' => true, 'postings' => true, 'referrers' => true], 'has_children'],
    'postings before referrers' => [['postings' => true, 'referrers' => true], 'has_postings'],
    'referrers before the parent' => [['referrers' => true, 'parent' => AccountType::Asset], 'in_use'],
]);

it('allows a type change with no obstacle, and a root or a parent of the new type', function () {
    AccountRules::retype(false, false, false, false, null, AccountType::Liability);
    AccountRules::retype(false, false, false, false, AccountType::Liability, AccountType::Liability);

    expect(true)->toBeTrue();
});

// ---- AC-15a: subtype change ------------------------------------------------

it('names the one failing condition of a subtype change, like a retype but with no children or parent to ask (AC-15a)', function (array $facts, string $condition) {
    $refused = financeRulesRefusal(fn () => AccountRules::resubtype(
        $facts['system'] ?? false,
        $facts['postings'] ?? false,
        $facts['referrers'] ?? false,
    ));

    expect($refused->reason)->toBe(FinanceRefusal::TypeChangeRefused)
        ->and($refused->context['condition'])->toBe($condition)
        ->and($refused->field)->toBe('subtype');
})->with([
    'a system account' => [['system' => true], 'is_system'],
    'postings' => [['postings' => true], 'has_postings'],
    'referrers' => [['referrers' => true], 'in_use'],
    'system before postings' => [['system' => true, 'postings' => true, 'referrers' => true], 'is_system'],
    'postings before referrers' => [['postings' => true, 'referrers' => true], 'has_postings'],
]);

it('allows a subtype change with no obstacle', function () {
    AccountRules::resubtype(false, false, false);

    expect(true)->toBeTrue();
});

// ---- AC-14, AC-19, AC-22: the other decision tables -------------------------

it('refuses a recode on a system account first, then on postings', function () {
    AccountRules::recode(false, false);

    expect(financeRulesRefusal(fn () => AccountRules::recode(true, true))->reason)->toBe(FinanceRefusal::SystemAccountImmutable)
        ->and(financeRulesRefusal(fn () => AccountRules::recode(false, true))->reason)->toBe(FinanceRefusal::CodeLocked);
});

it('refuses a control-type change on a system account first, then on postings or referrers', function () {
    AccountRules::recontrol(false, false, false);

    expect(financeRulesRefusal(fn () => AccountRules::recontrol(true, true, true))->reason)->toBe(FinanceRefusal::SystemAccountImmutable)
        ->and(financeRulesRefusal(fn () => AccountRules::recontrol(false, true, false))->reason)->toBe(FinanceRefusal::ControlTypeLocked)
        ->and(financeRulesRefusal(fn () => AccountRules::recontrol(false, false, true))->reason)->toBe(FinanceRefusal::ControlTypeLocked);
});

it('refuses to make a header with children postable, naming has_children', function () {
    AccountRules::toPostable(false, false);

    expect(financeRulesRefusal(fn () => AccountRules::toPostable(false, true))->reason)->toBe(FinanceRefusal::HasChildren)
        ->and(financeRulesRefusal(fn () => AccountRules::toPostable(true, false))->reason)->toBe(FinanceRefusal::SystemAccountImmutable);
});

it('refuses to make a postable account a header once it has postings or referrers', function () {
    AccountRules::toHeader(false, false, false);

    expect(financeRulesRefusal(fn () => AccountRules::toHeader(false, true, true))->reason)->toBe(FinanceRefusal::HasPostings)
        ->and(financeRulesRefusal(fn () => AccountRules::toHeader(false, false, true))->reason)->toBe(FinanceRefusal::InUse)
        ->and(financeRulesRefusal(fn () => AccountRules::toHeader(true, false, false))->reason)->toBe(FinanceRefusal::SystemAccountImmutable);
});
