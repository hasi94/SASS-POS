<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Journal;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\AccountRefusal;
use Flexipos\Contracts\Finance\AccountRefused;
use Flexipos\Contracts\Finance\PeriodGateRefused;
use Flexipos\Contracts\Finance\PeriodRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-033 plan step C3 - the period gate and the account checks
|--------------------------------------------------------------------------
|
| AC-23, AC-27 to AC-29, AC-31, and the construction halves of AC-25 (the
| ascending, once-per-account order of the `postable` calls). The two-
| connection proofs of AC-25, AC-26 and AC-30 are `[ENGINE]`, NOT VERIFIED
| under ENGINE_HOLD.
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/journal-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    financeEnterTenant();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 10:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

// ------------------------------------------------------------------ AC-27

it('clears the entry date through the gate once, with the actor, and stores the period it returns (AC-27)', function () {
    $world = jrnWorld();
    $gate = jrnSpyOnGate();

    $entry = jrnPost(jrnBody($world, ['entry_date' => '2026-07-04']), $world['actor']);

    expect($gate->dates)->toBe(['2026-07-04'])
        ->and($entry->periodId)->toBe($world['periods'][7])
        ->and(jrnEntry($entry->id)['period_id'])->toBe($world['periods'][7]);
});

// ------------------------------------------------------------------ AC-28

it('applies the gate for every status and every kind of actor, with the gate\'s code unchanged (AC-28)', function (string $status, string $kind, ?PeriodRefusal $expected) {
    $world = jrnWorld();

    FinanceSeed::connection()->table('fiscal_periods')->where('id', $world['periods'][3])->update(['status' => $status]);

    $actor = match ($kind) {
        'plain' => FinanceSeed::actor('plain', 'finance.accounts.view'),
        'adjuster' => FinanceSeed::actor('adjuster', 'finance.periods.post_adjustment'),
        'null' => null,
        default => throw new \InvalidArgumentException($kind),
    };

    $before = jrnState();
    $refusal = jrnPostRefusal(jrnBody($world), $actor);

    if ($expected === null) {
        expect($refusal)->toBeNull()
            ->and(jrnState()['entries'])->toBe($before['entries'] + 1);

        return;
    }

    expect($refusal)->toBeInstanceOf(PostingRefused::class)
        ->and($refusal->reason)->toBe($expected)
        ->and($refusal->field)->toBe('entry_date')
        ->and($refusal->getPrevious())->toBeInstanceOf(PeriodGateRefused::class)
        ->and(jrnState())->toBe($before);
})->with(function () {
    $cases = [
        'open' => ['plain' => null, 'adjuster' => null, 'null' => null],
        'soft_closed' => ['plain' => PeriodRefusal::PeriodSoftClosed, 'adjuster' => null, 'null' => PeriodRefusal::PeriodSoftClosed],
        'closed' => ['plain' => PeriodRefusal::PeriodClosed, 'adjuster' => PeriodRefusal::PeriodClosed, 'null' => PeriodRefusal::PeriodClosed],
    ];

    foreach ($cases as $status => $kinds) {
        foreach ($kinds as $kind => $expected) {
            yield "{$status} / {$kind}" => [$status, $kind, $expected];
        }
    }
});

// ------------------------------------------------------------------ AC-29

it('resolves the first day, the last day and the day after a period, and refuses a date past the last period (AC-29)', function () {
    $world = jrnWorld();

    expect(jrnPost(jrnBody($world, ['entry_date' => '2026-02-01']))->periodId)->toBe($world['periods'][2])
        ->and(jrnPost(jrnBody($world, ['entry_date' => '2026-02-28']))->periodId)->toBe($world['periods'][2])
        ->and(jrnPost(jrnBody($world, ['entry_date' => '2026-03-01']))->periodId)->toBe($world['periods'][3])
        ->and(jrnPost(jrnBody($world, ['entry_date' => '2026-12-31']))->periodId)->toBe($world['periods'][12]);

    $before = jrnState();

    foreach (['2027-01-01', '2025-12-31'] as $date) {
        $refusal = jrnPostRefusal(jrnBody($world, ['entry_date' => $date]));

        expect($refusal)->toBeInstanceOf(PostingRefused::class)
            ->and($refusal->reason)->toBe(PeriodRefusal::NoFiscalPeriod, $date);
    }

    expect(jrnState())->toBe($before);
});

it('refuses every post in a tenant with no fiscal year (AC-29)', function () {
    $world = jrnWorld();

    FinanceSeed::connection()->table('fiscal_periods')->delete();
    FinanceSeed::connection()->table('fiscal_years')->delete();

    $refusal = jrnPostRefusal(jrnBody($world, ['entry_date' => '2026-03-15']));

    expect($refusal)->toBeInstanceOf(PostingRefused::class)
        ->and($refusal->reason)->toBe(PeriodRefusal::NoFiscalPeriod)
        ->and(jrnState()['entries'])->toBe(0);
});

// ------------------------------------------------------------------ AC-31

it('refuses a document dated in a period that closed meanwhile with the gate\'s code, and never substitutes a date (AC-31)', function () {
    $world = jrnWorld();
    $gate = jrnSpyOnGate();

    FinanceSeed::connection()->table('fiscal_periods')->where('id', $world['periods'][3])->update(['status' => 'closed']);

    $before = jrnState();
    $refusal = jrnPostRefusal(jrnBody($world, ['entry_date' => '2026-03-15']), $world['actor']);

    expect($refusal)->toBeInstanceOf(PostingRefused::class)
        ->and($refusal->reason)->toBe(PeriodRefusal::PeriodClosed)
        ->and($gate->dates)->toBe(['2026-03-15'])
        ->and(jrnState())->toBe($before);
});

// ------------------------------------------------------------------ AC-23

it('refuses a header, an inactive, a deleted and a nonexistent account with the account code and the line index (AC-23)', function (string $kind, AccountRefusal $expected) {
    $world = jrnWorld();

    $bad = match ($kind) {
        'header' => FinanceSeed::header('1900'),
        'inactive' => FinanceSeed::account('1901', ['is_active' => false]),
        'deleted' => FinanceSeed::account('1902', ['deleted_at' => FinanceSeed::NOW]),
        'missing' => (string) Str::ulid(),
        default => throw new \InvalidArgumentException($kind),
    };

    $before = jrnState();

    $refusal = jrnPostRefusal(jrnBody($world, ['lines' => [
        ['account_id' => $world['cash'], 'debit' => '5'],
        ['account_id' => $bad, 'credit' => '5'],
    ]]));

    expect($refusal)->toBeInstanceOf(PostingRefused::class)
        ->and($refusal->reason)->toBe($expected)
        ->and($refusal->field)->toBe('lines.1.account_id')
        ->and($refusal->context)->toBe(['line_index' => 1])
        ->and($refusal->getPrevious())->toBeInstanceOf(AccountRefused::class)
        ->and(jrnState())->toBe($before);
})->with([
    'header' => ['header', AccountRefusal::AccountNotPostable],
    'inactive' => ['inactive', AccountRefusal::AccountInactive],
    'deleted' => ['deleted', AccountRefusal::AccountNotLive],
    'missing' => ['missing', AccountRefusal::AccountNotFound],
]);

it('reports the lowest account id when two accounts are bad, not the first line (AC-23, M9)', function () {
    $world = jrnWorld();

    $low = FinanceSeed::header('1800', ['id' => '01AAAAAAAAAAAAAAAAAAAAAAAA']);
    $high = FinanceSeed::account('1801', ['id' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ', 'is_active' => false]);

    $refusal = jrnPostRefusal(jrnBody($world, ['lines' => [
        ['account_id' => $high, 'debit' => '5'],
        ['account_id' => $low, 'credit' => '5'],
        ['account_id' => $world['cash'], 'debit' => '1'],
        ['account_id' => $world['sales'], 'credit' => '1'],
    ]]));

    expect($refusal)->toBeInstanceOf(PostingRefused::class)
        ->and($refusal->reason)->toBe(AccountRefusal::AccountNotPostable)
        ->and($refusal->field)->toBe('lines.1.account_id');
});

// ------------------------------------------------------------------ AC-25 construction

it('asks the chart once per distinct account, in ascending id order, whatever the line order (AC-25, M9)', function () {
    $world = jrnWorld();
    $chart = jrnSpyOnChart();

    jrnPost(jrnBody($world, ['lines' => [
        ['account_id' => $world['sales'], 'credit' => '3'],
        ['account_id' => $world['cash'], 'debit' => '1'],
        ['account_id' => $world['other'], 'credit' => '1'],
        ['account_id' => $world['sales'], 'credit' => '2'],
        ['account_id' => $world['cash'], 'debit' => '5'],
    ]]));

    $expected = [$world['sales'], $world['cash'], $world['other']];
    sort($expected, SORT_STRING);

    expect($chart->postable)->toBe($expected);

    // The same accounts in the opposite line order give the same call order.
    $chart->postable = [];

    jrnPost(jrnBody($world, ['lines' => [
        ['account_id' => $world['other'], 'credit' => '1'],
        ['account_id' => $world['cash'], 'debit' => '6'],
        ['account_id' => $world['sales'], 'credit' => '5'],
    ]]));

    expect($chart->postable)->toBe($expected);
});
