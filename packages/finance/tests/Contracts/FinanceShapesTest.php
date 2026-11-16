<?php

namespace Flexipos\Finance\Tests\Contracts;

use Flexipos\Contracts\Finance\AccountReferrer;
use Flexipos\Contracts\Finance\AccountRefusal;
use Flexipos\Contracts\Finance\AccountRefused;
use Flexipos\Contracts\Finance\AccountSnapshot;
use Flexipos\Contracts\Finance\AccountSubtype;
use Flexipos\Contracts\Finance\AccountType;
use Flexipos\Contracts\Finance\ControlType;
use Flexipos\Contracts\Finance\NormalBalance;
use Flexipos\Contracts\Finance\PeriodBlocker;
use Flexipos\Contracts\Finance\PeriodClearance;
use Flexipos\Contracts\Finance\PeriodGateRefused;
use Flexipos\Contracts\Finance\PeriodRefusal;
use Flexipos\Contracts\Finance\PeriodStatus;

/*
|--------------------------------------------------------------------------
| The published value shapes (T-032 plan step 4, Ruling 2)
|--------------------------------------------------------------------------
|
| Shape only: a malformed id or a negative count is refused by name, and
| nothing is said about whether the values agree with each other.
*/

const FINANCE_SHAPE_ULID = '01jabcdefghjkmnpqrstvwxyz0';

it('accepts a well-formed account snapshot and folds the id to upper case', function () {
    $snapshot = new AccountSnapshot(
        FINANCE_SHAPE_ULID,
        '1100',
        'Cash',
        AccountType::Asset,
        AccountSubtype::Cash,
        NormalBalance::Debit,
        true,
        true,
        ControlType::Cash,
    );

    expect($snapshot->id)->toBe(strtoupper(FINANCE_SHAPE_ULID))
        ->and($snapshot->code)->toBe('1100')
        ->and($snapshot->subtype)->toBe(AccountSubtype::Cash)
        ->and($snapshot->controlType)->toBe(ControlType::Cash);
});

it('refuses a snapshot id that is not a ULID, including one with a trailing newline', function (string $id) {
    expect(fn () => new AccountSnapshot($id, '1100', 'Cash', AccountType::Asset, null, NormalBalance::Debit, true, true, ControlType::None))
        ->toThrow(\InvalidArgumentException::class, '[id]');
})->with([
    'too short' => ['ABC'],
    'empty' => [''],
    'symbol inside' => ['01JABCDEFGHJKMNPQRSTVWXYZ-'],
    'trailing newline' => ["01JABCDEFGHJKMNPQRSTVWXYZ0\n"],
    'twenty-seven characters' => ['01JABCDEFGHJKMNPQRSTVWXYZ00'],
]);

it('accepts a period clearance and refuses a malformed period id', function () {
    $clearance = new PeriodClearance(FINANCE_SHAPE_ULID, PeriodStatus::SoftClosed);

    expect($clearance->periodId)->toBe(strtoupper(FINANCE_SHAPE_ULID))
        ->and($clearance->status)->toBe(PeriodStatus::SoftClosed)
        ->and(fn () => new PeriodClearance('nope', PeriodStatus::Open))
        ->toThrow(\InvalidArgumentException::class, '[periodId]');
});

it('accepts an account referrer and refuses a negative or empty one by name', function () {
    $referrer = new AccountReferrer('products.income_account_id', 2, 1);

    expect($referrer->kind)->toBe('products.income_account_id')
        ->and($referrer->total())->toBe(3)
        ->and(fn () => new AccountReferrer('products.income_account_id', -1, 0))
        ->toThrow(\InvalidArgumentException::class, '[live]')
        ->and(fn () => new AccountReferrer('products.income_account_id', 0, -1))
        ->toThrow(\InvalidArgumentException::class, '[deleted]')
        ->and(fn () => new AccountReferrer('', 0, 0))
        ->toThrow(\InvalidArgumentException::class, '[kind]');
});

it('accepts a period blocker and refuses a negative count or an empty kind by name', function () {
    $blocker = new PeriodBlocker('draft_journal_entries', 3);

    expect($blocker->kind)->toBe('draft_journal_entries')
        ->and($blocker->count)->toBe(3)
        ->and(fn () => new PeriodBlocker('draft_journal_entries', -1))
        ->toThrow(\InvalidArgumentException::class, '[count]')
        ->and(fn () => new PeriodBlocker('', 1))
        ->toThrow(\InvalidArgumentException::class, '[kind]');
});

it('carries one reason and the field on every account refusal', function () {
    $cases = [
        [AccountRefused::notFound('m', 'income_account_id'), AccountRefusal::AccountNotFound],
        [AccountRefused::notLive('m', 'income_account_id'), AccountRefusal::AccountNotLive],
        [AccountRefused::inactive('m', 'income_account_id'), AccountRefusal::AccountInactive],
        [AccountRefused::notPostable('m', 'income_account_id'), AccountRefusal::AccountNotPostable],
        [AccountRefused::kindMismatch('m', 'income_account_id'), AccountRefusal::AccountKindMismatch],
        [AccountRefused::invalidCode('m', 'income_account_id'), AccountRefusal::InvalidCode],
    ];

    foreach ($cases as [$refusal, $reason]) {
        expect($refusal->reason)->toBe($reason)
            ->and($refusal->field)->toBe('income_account_id')
            ->and($refusal->getMessage())->toBe('m');
    }

    expect(AccountRefused::notFound('m')->field)->toBeNull();
});

it('carries one reason on every period gate refusal', function () {
    $cases = [
        [PeriodGateRefused::invalidDate('m'), PeriodRefusal::InvalidDate],
        [PeriodGateRefused::noFiscalPeriod('m'), PeriodRefusal::NoFiscalPeriod],
        [PeriodGateRefused::periodClosed('m'), PeriodRefusal::PeriodClosed],
        [PeriodGateRefused::periodSoftClosed('m'), PeriodRefusal::PeriodSoftClosed],
    ];

    foreach ($cases as [$refusal, $reason]) {
        expect($refusal->reason)->toBe($reason);
    }
});
