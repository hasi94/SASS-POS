<?php

namespace Flexipos\Finance\Tests\Periods;

use Flexipos\Contracts\Finance\PeriodStatus;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Periods\PeriodRef;
use Flexipos\Finance\Periods\PeriodTransitions;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| PeriodTransitions (T-032 plan step 7; AC-38 to AC-40, AC-42, AC-43)
|--------------------------------------------------------------------------
*/

function financeTransitionRefusal(callable $run): FinanceRefused
{
    try {
        $run();
    } catch (FinanceRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('The state machine allowed what it should have refused.');
}

function financePeriodRef(string $name, PeriodStatus $status): PeriodRef
{
    return new PeriodRef('ID-'.$name, $name, $status);
}

it('allows exactly the four adjacent moves out of the nine pairs (AC-38)', function () {
    $allowed = [];

    foreach (PeriodStatus::cases() as $from) {
        foreach (PeriodStatus::cases() as $to) {
            try {
                PeriodTransitions::transition($from, $to);
                $allowed[] = $from->value.'>'.$to->value;
            } catch (FinanceRefused $refused) {
                expect($refused->reason)->toBe(FinanceRefusal::InvalidTransition);
            }
        }
    }

    expect($allowed)->toBe(['open>soft_closed', 'soft_closed>open', 'soft_closed>closed', 'closed>soft_closed']);
});

it('refuses open to closed and every same-status move', function (PeriodStatus $from, PeriodStatus $to) {
    $refused = financeTransitionRefusal(fn () => PeriodTransitions::transition($from, $to));

    expect($refused->reason)->toBe(FinanceRefusal::InvalidTransition)
        ->and($refused->context)->toBe(['from' => $from->value, 'to' => $to->value]);
})->with([
    'open to closed' => [PeriodStatus::Open, PeriodStatus::Closed],
    'closed to open' => [PeriodStatus::Closed, PeriodStatus::Open],
    'open to open' => [PeriodStatus::Open, PeriodStatus::Open],
    'soft_closed to soft_closed' => [PeriodStatus::SoftClosed, PeriodStatus::SoftClosed],
    'closed to closed' => [PeriodStatus::Closed, PeriodStatus::Closed],
]);

it('tells a reopen from a close', function () {
    expect(PeriodTransitions::isReopen(PeriodStatus::Closed, PeriodStatus::SoftClosed))->toBeTrue()
        ->and(PeriodTransitions::isReopen(PeriodStatus::SoftClosed, PeriodStatus::Open))->toBeTrue()
        ->and(PeriodTransitions::isReopen(PeriodStatus::Open, PeriodStatus::SoftClosed))->toBeFalse()
        ->and(PeriodTransitions::isReopen(PeriodStatus::SoftClosed, PeriodStatus::Closed))->toBeFalse();
});

it('refuses a stale expected status and names the stored one (AC-39)', function () {
    PeriodTransitions::checkExpected(PeriodStatus::Open, PeriodStatus::Open);

    $refused = financeTransitionRefusal(fn () => PeriodTransitions::checkExpected(PeriodStatus::Closed, PeriodStatus::SoftClosed));

    expect($refused->reason)->toBe(FinanceRefusal::StatusChanged)
        ->and($refused->context['status'])->toBe('closed')
        ->and($refused->field)->toBe('expected_status')
        ->and($refused->getMessage())->toContain('closed');
});

it('refuses closing a period while an earlier one is lower, naming the earlier one (AC-40)', function () {
    $refused = financeTransitionRefusal(fn () => PeriodTransitions::checkOrder(
        financePeriodRef('P1', PeriodStatus::Open),
        PeriodStatus::SoftClosed,
        financePeriodRef('P3', PeriodStatus::Open),
    ));

    expect($refused->reason)->toBe(FinanceRefusal::PeriodOrderViolation)
        ->and($refused->context)->toBe(['period_id' => 'ID-P1', 'name' => 'P1']);
});

it('refuses reopening a period while a later one is higher, naming the later one (AC-40)', function () {
    $refused = financeTransitionRefusal(fn () => PeriodTransitions::checkOrder(
        financePeriodRef('P1', PeriodStatus::Closed),
        PeriodStatus::SoftClosed,
        financePeriodRef('P3', PeriodStatus::Closed),
    ));

    expect($refused->reason)->toBe(FinanceRefusal::PeriodOrderViolation)
        ->and($refused->context)->toBe(['period_id' => 'ID-P3', 'name' => 'P3']);

    $first = financeTransitionRefusal(fn () => PeriodTransitions::checkOrder(null, PeriodStatus::Open, financePeriodRef('P2', PeriodStatus::SoftClosed)));

    expect($first->context['name'])->toBe('P2');
});

it('allows a move that keeps the order, including at either end of the year', function () {
    PeriodTransitions::checkOrder(financePeriodRef('P1', PeriodStatus::Closed), PeriodStatus::Closed, financePeriodRef('P3', PeriodStatus::Open));
    PeriodTransitions::checkOrder(financePeriodRef('P1', PeriodStatus::SoftClosed), PeriodStatus::SoftClosed, financePeriodRef('P3', PeriodStatus::SoftClosed));
    PeriodTransitions::checkOrder(null, PeriodStatus::Closed, financePeriodRef('P2', PeriodStatus::Closed));
    PeriodTransitions::checkOrder(financePeriodRef('P11', PeriodStatus::Open), PeriodStatus::Open, null);
    PeriodTransitions::checkOrder(null, PeriodStatus::SoftClosed, null);

    expect(true)->toBeTrue();
});

it('requires a reason of 1 to 500 characters to reopen (AC-42)', function (mixed $reason) {
    $refused = financeTransitionRefusal(fn () => PeriodTransitions::reason($reason, true));

    expect($refused->reason)->toBe(FinanceRefusal::ReasonRequired)
        ->and($refused->field)->toBe('reason');
})->with([
    'none' => [null],
    'empty' => [''],
    'whitespace only' => ["   \u{00A0}"],
    '501 characters' => [str_repeat('r', 501)],
    'not a string' => [7],
]);

it('accepts a reopen reason up to 500 characters, trimmed (AC-42)', function () {
    expect(PeriodTransitions::reason('  Wrong month  ', true))->toBe('Wrong month')
        ->and(PeriodTransitions::reason(str_repeat('r', 500), true))->toBe(str_repeat('r', 500));
});

it('accepts a close with no reason or with one (AC-42)', function () {
    expect(PeriodTransitions::reason(null, false))->toBeNull()
        ->and(PeriodTransitions::reason('Month end', false))->toBe('Month end')
        ->and(financeTransitionRefusal(fn () => PeriodTransitions::reason(str_repeat('r', 501), false))->reason)->toBe(FinanceRefusal::Validation);
});

it('stamps closed_at and closed_by on each move up and clears them on a return to open (AC-43)', function () {
    $now = '2026-11-16 09:30:00.000';

    expect(PeriodTransitions::closure(PeriodStatus::SoftClosed, $now, 'ACTOR'))->toBe(['closed_at' => $now, 'closed_by' => 'ACTOR'])
        ->and(PeriodTransitions::closure(PeriodStatus::Closed, $now, 'ACTOR'))->toBe(['closed_at' => $now, 'closed_by' => 'ACTOR'])
        ->and(PeriodTransitions::closure(PeriodStatus::Open, $now, 'ACTOR'))->toBe(['closed_at' => null, 'closed_by' => null]);
});
