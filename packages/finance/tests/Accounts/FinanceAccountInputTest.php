<?php

namespace Flexipos\Finance\Tests\Accounts;

use Flexipos\Contracts\Finance\AccountSubtype;
use Flexipos\Contracts\Finance\AccountType;
use Flexipos\Contracts\Finance\ControlType;
use Flexipos\Contracts\Finance\NormalBalance;
use Flexipos\Finance\Accounts\AccountInput;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| Account request parsing (T-032 plan step 5; AC-1, AC-5 to AC-9)
|--------------------------------------------------------------------------
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function financeAccountBody(array $overrides = []): array
{
    return array_replace(['code' => 'ab1', 'name' => ' Cash drawer ', 'type' => 'asset'], $overrides);
}

function financeAccountRefusalOf(callable $parse): FinanceRefused
{
    try {
        $parse();
    } catch (FinanceRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('The body was accepted but should have been refused.');
}

it('builds a create draft with every default resolved (AC-1)', function () {
    $draft = AccountInput::forCreate(financeAccountBody());

    expect($draft->code)->toBe('AB1')
        ->and($draft->name)->toBe('Cash drawer')
        ->and($draft->description)->toBeNull()
        ->and($draft->type)->toBe(AccountType::Asset)
        ->and($draft->subtype)->toBeNull()
        ->and($draft->normalBalance)->toBe(NormalBalance::Debit)
        ->and($draft->parentId)->toBeNull()
        ->and($draft->isPostable)->toBeTrue()
        ->and($draft->isActive)->toBeTrue()
        ->and($draft->controlType)->toBe(ControlType::None)
        ->and($draft->sortOrder)->toBe(0)
        ->and($draft->has('subtype'))->toBeTrue();
});

it('derives the normal balance from the type and refuses a different one (AC-6)', function () {
    $expected = ['asset' => 'debit', 'expense' => 'debit', 'liability' => 'credit', 'equity' => 'credit', 'revenue' => 'credit'];

    foreach ($expected as $type => $balance) {
        $other = $balance === 'debit' ? 'credit' : 'debit';

        expect(AccountInput::forCreate(financeAccountBody(['type' => $type]))->normalBalance?->value)->toBe($balance)
            ->and(AccountInput::forCreate(financeAccountBody(['type' => $type, 'normal_balance' => $balance]))->normalBalance?->value)->toBe($balance)
            ->and(financeAccountRefusalOf(fn () => AccountInput::forCreate(financeAccountBody(['type' => $type, 'normal_balance' => $other])))->reason)
            ->toBe(FinanceRefusal::NormalBalanceMismatch);
    }
});

it('accepts exactly the subtypes AC-7 lists for each type', function () {
    // Typed out from the spec, not derived from the enum under test.
    $allowed = [
        'asset' => ['current_asset', 'fixed_asset', 'inventory', 'receivable', 'cash', 'bank'],
        'liability' => ['current_liability', 'payable', 'tax', 'long_term_liability'],
        'equity' => ['capital', 'retained_earnings'],
        'revenue' => ['sales', 'other_income'],
        'expense' => ['cogs', 'operating_expense', 'payroll_expense', 'other_expense'],
    ];
    $every = array_merge(...array_values($allowed));

    foreach ($allowed as $type => $subtypes) {
        foreach ($every as $subtype) {
            $body = financeAccountBody(['type' => $type, 'subtype' => $subtype]);

            if (in_array($subtype, $subtypes, true)) {
                expect(AccountInput::forCreate($body)->subtype?->value)->toBe($subtype);
            } else {
                expect(financeAccountRefusalOf(fn () => AccountInput::forCreate($body))->reason)->toBe(FinanceRefusal::SubtypeMismatch);
            }
        }
    }
});

it('refuses an enum value that differs in case or padding on each of the four enum fields (AC-5)', function (string $field, string $valid) {
    $body = ['type' => 'asset', 'subtype' => 'cash', 'normal_balance' => 'debit', 'control_type' => 'none'];
    $accepted = AccountInput::forCreate(financeAccountBody(array_replace($body, [$field => $valid])));

    expect($accepted)->not->toBeNull();

    foreach ([ucfirst($valid), strtoupper($valid), ' '.$valid, $valid.' ', 'unknown'] as $bad) {
        $refused = financeAccountRefusalOf(fn () => AccountInput::forCreate(financeAccountBody(array_replace($body, [$field => $bad]))));

        expect($refused->reason)->toBe(FinanceRefusal::Validation)
            ->and($refused->field)->toBe($field);
    }
})->with([
    'type' => ['type', 'asset'],
    'subtype' => ['subtype', 'cash'],
    'normal balance' => ['normal_balance', 'debit'],
    'control type' => ['control_type', 'none'],
]);

it('refuses a missing or malformed code, name and type', function (array $body, FinanceRefusal $reason, string $field) {
    $refused = financeAccountRefusalOf(fn () => AccountInput::forCreate($body));

    expect($refused->reason)->toBe($reason)
        ->and($refused->field)->toBe($field);
})->with([
    'no code' => [['name' => 'A', 'type' => 'asset'], FinanceRefusal::InvalidCode, 'code'],
    'padded code' => [['code' => ' AB1', 'name' => 'A', 'type' => 'asset'], FinanceRefusal::InvalidCode, 'code'],
    'no name' => [['code' => 'A', 'type' => 'asset'], FinanceRefusal::Validation, 'name'],
    'blank name' => [['code' => 'A', 'name' => '  ', 'type' => 'asset'], FinanceRefusal::Validation, 'name'],
    'no type' => [['code' => 'A', 'name' => 'A'], FinanceRefusal::Validation, 'type'],
    'over-long description' => [['code' => 'A', 'name' => 'A', 'type' => 'asset', 'description' => str_repeat('d', 501)], FinanceRefusal::Validation, 'description'],
]);

it('accepts a null description and a 500-character one (AC-4)', function () {
    expect(AccountInput::forCreate(financeAccountBody(['description' => null]))->description)->toBeNull()
        ->and(AccountInput::forCreate(financeAccountBody(['description' => str_repeat('d', 500)]))->description)->toBe(str_repeat('d', 500));
});

it('names every forbidden and unknown key in one message (AC-8)', function () {
    $forbidden = ['id', 'is_system', 'path', 'depth', 'deleted_at', 'created_by', 'updated_by', 'opening_balance', 'opening_balance_date'];

    foreach ($forbidden as $key) {
        $refused = financeAccountRefusalOf(fn () => AccountInput::forCreate(financeAccountBody([$key => 'x'])));

        expect($refused->reason)->toBe(FinanceRefusal::Validation)
            ->and($refused->context['fields'])->toBe([$key]);
    }

    $all = financeAccountBody(array_fill_keys($forbidden, 'x') + ['colour' => 'red', 'currency' => 'LKR']);
    $refused = financeAccountRefusalOf(fn () => AccountInput::forCreate($all));

    expect($refused->context['fields'])->toBe([...$forbidden, 'colour', 'currency']);

    foreach ([...$forbidden, 'colour', 'currency'] as $key) {
        expect($refused->getMessage())->toContain($key);
    }
});

it('accepts a null currency and refuses a non-null one (AC-8)', function () {
    expect(AccountInput::forCreate(financeAccountBody(['currency' => null]))->code)->toBe('AB1')
        ->and(financeAccountRefusalOf(fn () => AccountInput::forCreate(financeAccountBody(['currency' => 'LKR'])))->context['fields'])->toBe(['currency']);
});

it('refuses a non-boolean flag and an out-of-range sort order (AC-9)', function () {
    foreach (['is_postable', 'is_active'] as $flag) {
        foreach (['true', 1, 'yes', null] as $bad) {
            expect(financeAccountRefusalOf(fn () => AccountInput::forCreate(financeAccountBody([$flag => $bad])))->field)->toBe($flag);
        }
    }

    foreach ([-1, 65536, '5', 1.0] as $bad) {
        expect(financeAccountRefusalOf(fn () => AccountInput::forCreate(financeAccountBody(['sort_order' => $bad])))->field)->toBe('sort_order');
    }

    expect(AccountInput::forCreate(financeAccountBody(['sort_order' => 65535]))->sortOrder)->toBe(65535)
        ->and(AccountInput::forCreate(financeAccountBody(['is_postable' => false]))->isPostable)->toBeFalse();
});

it('refuses a control account that is not postable or does not fit (AC-16)', function () {
    $fits = financeAccountBody(['type' => 'asset', 'subtype' => 'receivable', 'control_type' => 'ar']);

    expect(AccountInput::forCreate($fits)->controlType)->toBe(ControlType::Ar)
        ->and(financeAccountRefusalOf(fn () => AccountInput::forCreate([...$fits, 'is_postable' => false]))->reason)->toBe(FinanceRefusal::ControlTypeMismatch)
        ->and(financeAccountRefusalOf(fn () => AccountInput::forCreate([...$fits, 'subtype' => 'cash']))->reason)->toBe(FinanceRefusal::ControlTypeMismatch)
        ->and(financeAccountRefusalOf(fn () => AccountInput::forCreate([...$fits, 'type' => 'liability', 'subtype' => 'payable']))->reason)->toBe(FinanceRefusal::ControlTypeMismatch)
        ->and(financeAccountRefusalOf(fn () => AccountInput::forCreate([...$fits, 'subtype' => null]))->reason)->toBe(FinanceRefusal::ControlTypeMismatch);
});

// ---- update ---------------------------------------------------------------

it('builds an update draft of only the fields named, keeping an explicit null (AC-21)', function () {
    $draft = AccountInput::forUpdate(['name' => ' New ', 'description' => null, 'subtype' => null, 'sort_order' => 3]);

    expect($draft->supplied)->toBe(['name', 'description', 'subtype', 'sort_order'])
        ->and($draft->has('description'))->toBeTrue()
        ->and($draft->has('code'))->toBeFalse()
        ->and($draft->name)->toBe('New')
        ->and($draft->description)->toBeNull()
        ->and($draft->sortOrder)->toBe(3)
        ->and(AccountInput::forUpdate([])->supplied)->toBe([]);
});

it('refuses parent_id and is_active on an update as not_updatable_here (AC-8)', function () {
    foreach (['parent_id' => null, 'is_active' => false] as $key => $value) {
        $refused = financeAccountRefusalOf(fn () => AccountInput::forUpdate([$key => $value]));

        expect($refused->reason)->toBe(FinanceRefusal::NotUpdatableHere)
            ->and($refused->field)->toBe($key);
    }

    $both = financeAccountRefusalOf(fn () => AccountInput::forUpdate(['parent_id' => null, 'is_active' => true]));

    expect($both->context['fields'])->toBe(['parent_id', 'is_active']);
});

it('refuses a forbidden key on an update before it looks at not_updatable_here', function () {
    $refused = financeAccountRefusalOf(fn () => AccountInput::forUpdate(['is_system' => true, 'parent_id' => null]));

    expect($refused->reason)->toBe(FinanceRefusal::Validation)
        ->and($refused->context['fields'])->toBe(['is_system']);
});

it('checks the pairings an update body settles by itself', function () {
    expect(financeAccountRefusalOf(fn () => AccountInput::forUpdate(['type' => 'asset', 'normal_balance' => 'credit']))->reason)
        ->toBe(FinanceRefusal::NormalBalanceMismatch)
        ->and(financeAccountRefusalOf(fn () => AccountInput::forUpdate(['type' => 'asset', 'subtype' => 'sales']))->reason)
        ->toBe(FinanceRefusal::SubtypeMismatch)
        ->and(AccountInput::forUpdate(['type' => 'revenue', 'subtype' => 'sales', 'normal_balance' => 'credit'])->subtype)
        ->toBe(AccountSubtype::Sales);
});
