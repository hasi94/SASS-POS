<?php

namespace Flexipos\Finance\Tests\Contracts;

use Flexipos\Contracts\Finance\AccountKind;
use Flexipos\Contracts\Finance\AccountRefusal;
use Flexipos\Contracts\Finance\AccountSnapshot;
use Flexipos\Contracts\Finance\AccountSubtype;
use Flexipos\Contracts\Finance\AccountType;
use Flexipos\Contracts\Finance\ControlType;
use Flexipos\Contracts\Finance\FiscalYearStatus;
use Flexipos\Contracts\Finance\NormalBalance;
use Flexipos\Contracts\Finance\PeriodRefusal;
use Flexipos\Contracts\Finance\PeriodStatus;

/*
|--------------------------------------------------------------------------
| The closed vocabularies (T-032 plan step 4, Ruling 2)
|--------------------------------------------------------------------------
|
| Pure: no database, no container. Values are referenced as `Enum::Case`
| and compared as strings, never used as dataset keys (Ruling 13).
*/

/**
 * @param  list<\BackedEnum>  $cases
 * @return list<string>
 */
function financeEnumValues(array $cases): array
{
    return array_map(static fn (\BackedEnum $case): string => (string) $case->value, $cases);
}

function financeSnapshotOf(AccountType $type, ?AccountSubtype $subtype): AccountSnapshot
{
    return new AccountSnapshot(
        '01JABCDEFGHJKMNPQRSTVWXYZ0',
        '1000',
        'Account',
        $type,
        $subtype,
        $type->normalBalance(),
        true,
        true,
        ControlType::None,
    );
}

it('has exactly the five account types', function () {
    expect(financeEnumValues(AccountType::cases()))->toBe(['asset', 'liability', 'equity', 'revenue', 'expense']);
});

it('has exactly the eighteen account subtypes', function () {
    expect(financeEnumValues(AccountSubtype::cases()))->toBe([
        'current_asset', 'fixed_asset', 'inventory', 'receivable', 'cash', 'bank',
        'current_liability', 'payable', 'tax', 'long_term_liability',
        'capital', 'retained_earnings',
        'sales', 'other_income',
        'cogs', 'operating_expense', 'payroll_expense', 'other_expense',
    ]);
});

it('has exactly the two normal balances', function () {
    expect(financeEnumValues(NormalBalance::cases()))->toBe(['debit', 'credit']);
});

it('has exactly the eleven control types', function () {
    expect(financeEnumValues(ControlType::cases()))->toBe([
        'none', 'cash', 'bank', 'inventory', 'ar', 'ap', 'tax_payable', 'tax_receivable',
        'grni', 'payroll_payable', 'retained_earnings',
    ]);
});

it('has exactly the three period statuses and the three year statuses', function () {
    expect(financeEnumValues(PeriodStatus::cases()))->toBe(['open', 'soft_closed', 'closed'])
        ->and(financeEnumValues(FiscalYearStatus::cases()))->toBe(['open', 'closing', 'closed']);
});

it('has exactly the four account kinds', function () {
    expect(financeEnumValues(AccountKind::cases()))->toBe(['income', 'cogs', 'inventory', 'receivable']);
});

it('has exactly the six account refusal reasons', function () {
    expect(financeEnumValues(AccountRefusal::cases()))->toBe([
        'account_not_found', 'account_not_live', 'account_inactive', 'account_not_postable',
        'account_kind_mismatch', 'invalid_code',
    ]);
});

it('has exactly the four period refusal reasons', function () {
    expect(financeEnumValues(PeriodRefusal::cases()))->toBe([
        'invalid_date', 'no_fiscal_period', 'period_closed', 'period_soft_closed',
    ]);
});

it('gives each account type its normal balance (AC-6)', function () {
    expect(AccountType::Asset->normalBalance())->toBe(NormalBalance::Debit)
        ->and(AccountType::Expense->normalBalance())->toBe(NormalBalance::Debit)
        ->and(AccountType::Liability->normalBalance())->toBe(NormalBalance::Credit)
        ->and(AccountType::Equity->normalBalance())->toBe(NormalBalance::Credit)
        ->and(AccountType::Revenue->normalBalance())->toBe(NormalBalance::Credit);
});

it('lists the subtypes each type allows (AC-7)', function () {
    $expected = [
        'asset' => ['current_asset', 'fixed_asset', 'inventory', 'receivable', 'cash', 'bank'],
        'liability' => ['current_liability', 'payable', 'tax', 'long_term_liability'],
        'equity' => ['capital', 'retained_earnings'],
        'revenue' => ['sales', 'other_income'],
        'expense' => ['cogs', 'operating_expense', 'payroll_expense', 'other_expense'],
    ];

    foreach (AccountType::cases() as $type) {
        expect(financeEnumValues($type->subtypes()))->toBe($expected[$type->value]);
    }
});

it('places every subtype under exactly one type', function () {
    $seen = [];

    foreach (AccountType::cases() as $type) {
        foreach ($type->subtypes() as $subtype) {
            $seen[] = $subtype->value;
        }
    }

    expect($seen)->toHaveCount(count(AccountSubtype::cases()))
        ->and(array_unique($seen))->toHaveCount(count($seen));
});

it('states what each control type requires (AC-16)', function () {
    $expected = [
        'none' => null,
        'cash' => ['asset', 'cash'],
        'bank' => ['asset', 'bank'],
        'inventory' => ['asset', 'inventory'],
        'ar' => ['asset', 'receivable'],
        'ap' => ['liability', 'payable'],
        'tax_payable' => ['liability', 'tax'],
        'tax_receivable' => ['asset', 'current_asset'],
        'grni' => ['liability', 'current_liability'],
        'payroll_payable' => ['liability', 'current_liability'],
        'retained_earnings' => ['equity', 'retained_earnings'],
    ];

    foreach (ControlType::cases() as $control) {
        $requires = $control->requires();

        expect($requires === null ? null : [$requires['type']->value, $requires['subtype']->value])
            ->toBe($expected[$control->value]);
    }
});

it('requires a subtype that belongs to the required type, for every control type', function () {
    foreach (ControlType::cases() as $control) {
        $requires = $control->requires();

        if ($requires !== null) {
            expect($requires['type']->subtypes())->toContain($requires['subtype']);
        }
    }
});

it('names the five single control types (D-3)', function () {
    $single = array_values(array_filter(
        ControlType::cases(),
        static fn (ControlType $control): bool => $control->isSingle(),
    ));

    expect(financeEnumValues($single))->toBe(['ar', 'ap', 'grni', 'payroll_payable', 'retained_earnings'])
        ->and(financeEnumValues(ControlType::singles()))->toBe(financeEnumValues($single));
});

it('ranks the period statuses open, soft_closed, closed (AC-40)', function () {
    expect(PeriodStatus::Open->rank())->toBe(0)
        ->and(PeriodStatus::SoftClosed->rank())->toBe(1)
        ->and(PeriodStatus::Closed->rank())->toBe(2);
});

it('accepts an account for a kind only on the right type and subtype (AC-56)', function () {
    $accepts = static function (AccountKind $kind): array {
        $accepted = [];

        foreach (AccountType::cases() as $type) {
            foreach ([null, ...$type->subtypes()] as $subtype) {
                if ($kind->accepts(financeSnapshotOf($type, $subtype))) {
                    $accepted[] = $type->value.'/'.($subtype === null ? '-' : $subtype->value);
                }
            }
        }

        return $accepted;
    };

    // Income takes any revenue account, whatever its subtype.
    expect($accepts(AccountKind::Income))->toBe(['revenue/-', 'revenue/sales', 'revenue/other_income'])
        ->and($accepts(AccountKind::Cogs))->toBe(['expense/cogs'])
        ->and($accepts(AccountKind::Inventory))->toBe(['asset/inventory'])
        ->and($accepts(AccountKind::Receivable))->toBe(['asset/receivable']);
});
