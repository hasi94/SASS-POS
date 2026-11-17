<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\TransitionFiscalPeriod;

use ArrayObject;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\PeriodBlocker;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\TransitionFiscalPeriod\TransitionFiscalPeriod;
use Flexipos\Finance\Features\TransitionFiscalPeriod\TransitionFiscalPeriodHandler;
use Flexipos\Finance\Periods\PeriodRows;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Finance\Tests\Support\FakeUsagePorts;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 14 - moving a period (AC-38 to AC-44, AC-46)
|--------------------------------------------------------------------------
|
| Every refusal writes nothing: the calendar snapshot (years, periods and the
| audit count) is compared before and after. The contention clauses of AC-44
| are `[ENGINE]`; here the lock calls are observed in their documented order
| on the rows seam.
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/finance-fixture.php';

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

/**
 * @param  array<string, mixed>  $extra
 */
function transitionAs(?string $actorId, string $periodId, mixed $to, mixed $expected, mixed $reason = null, array $extra = []): void
{
    $fields = ['status' => $to, 'expected_status' => $expected] + $extra;

    if ($reason !== null) {
        $fields['reason'] = $reason;
    }

    app(TransitionFiscalPeriodHandler::class)->handle(new TransitionFiscalPeriod($periodId, $fields, $actorId));
}

function closerActor(): string
{
    return FinanceSeed::actor('closer', 'finance.periods.close');
}

function reopenerActor(): string
{
    return FinanceSeed::actor('reopener', 'finance.periods.reopen');
}

it('moves a period up and down one step, stamping closed_at and closed_by and clearing them on open (AC-43)', function () {
    $closer = closerActor();
    $reopener = reopenerActor();
    $year = FinanceSeed::fiscalYear('2026-01-01');
    $p1 = $year['periods'][1];

    transitionAs($closer, $p1, 'soft_closed', 'open');

    expect(FinanceSeed::periodRow($p1))->toMatchArray(['status' => 'soft_closed', 'closed_at' => '2026-11-10 10:00:00.000', 'closed_by' => $closer]);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-11 08:30:00.000'));
    transitionAs($closer, $p1, 'closed', 'soft_closed');

    expect(FinanceSeed::periodRow($p1))->toMatchArray(['status' => 'closed', 'closed_at' => '2026-11-11 08:30:00.000', 'closed_by' => $closer]);

    transitionAs($reopener, $p1, 'soft_closed', 'closed', 'Late invoice found');

    expect(FinanceSeed::periodRow($p1))->toMatchArray(['status' => 'soft_closed', 'closed_by' => $reopener]);

    transitionAs($reopener, $p1, 'open', 'soft_closed', 'Reopening fully');

    expect(FinanceSeed::periodRow($p1))->toMatchArray(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);
});

it('writes one audit row per move, named by the move, with the reason in after (AC-65, AC-42)', function () {
    $closer = closerActor();
    $reopener = reopenerActor();
    $p1 = FinanceSeed::fiscalYear('2026-01-01')['periods'][1];
    $moves = [
        [$closer, 'soft_closed', 'open', null, 'fiscal_period_soft_closed'],
        [$closer, 'closed', 'soft_closed', 'Month done', 'fiscal_period_closed'],
        [$reopener, 'soft_closed', 'closed', 'Correction', 'fiscal_period_reopened'],
        [$reopener, 'open', 'soft_closed', 'Redo', 'fiscal_period_reopened'],
    ];

    foreach ($moves as [$actor, $to, $from, $reason, $action]) {
        FinanceSeed::connection()->table('audit_logs')->delete();
        $before = FinanceSeed::periodRow($p1);

        transitionAs($actor, $p1, $to, $from, $reason);

        $row = FinanceSeed::onlyAuditRow();

        expect($row)->toMatchArray(['action' => $action, 'subject_id' => $p1, 'actor_id' => $actor, 'before' => $before])
            ->and($row['after'])->toBe([...FinanceSeed::periodRow($p1), 'reason' => $reason]);
    }
});

it('refuses every pair that is not an adjacent move, before authorization can say anything (AC-38)', function (string $from, string $to) {
    $nobody = FinanceSeed::nobody();
    $p1 = FinanceSeed::fiscalYear('2026-01-01')['periods'][1];
    $before = FinanceSeed::calendarSnapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::InvalidTransition, fn () => transitionAs($nobody, $p1, $to, $from, 'because'));

    expect($refused->context)->toBe(['from' => $from, 'to' => $to])
        ->and(FinanceSeed::calendarSnapshot())->toBe($before);
})->with([
    'open to open' => ['open', 'open'],
    'soft to soft' => ['soft_closed', 'soft_closed'],
    'closed to closed' => ['closed', 'closed'],
    'open to closed' => ['open', 'closed'],
    'closed to open' => ['closed', 'open'],
]);

it('refuses a status that is not exactly one of the three lower-case values (AC-5 for periods)', function () {
    $closer = closerActor();
    $p1 = FinanceSeed::fiscalYear('2026-01-01')['periods'][1];
    $before = FinanceSeed::calendarSnapshot();

    foreach (['Soft_Closed', 'SOFT_CLOSED', ' soft_closed', 'closing', '', null, 1] as $bad) {
        FinanceRefusals::expect(FinanceRefusal::Validation, fn () => transitionAs($closer, $p1, $bad, 'open'), var_export($bad, true));
        FinanceRefusals::expect(FinanceRefusal::Validation, fn () => transitionAs($closer, $p1, 'soft_closed', $bad), var_export($bad, true));
    }

    expect(FinanceSeed::calendarSnapshot())->toBe($before);
});

it('refuses a replayed close with the old expected status, writing nothing and no second audit row (AC-39, M16)', function () {
    $closer = closerActor();
    $p1 = FinanceSeed::fiscalYear('2026-01-01')['periods'][1];

    transitionAs($closer, $p1, 'soft_closed', 'open');

    $after = FinanceSeed::calendarSnapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::StatusChanged, fn () => transitionAs($closer, $p1, 'soft_closed', 'open'));

    expect($refused->context)->toBe(['status' => 'soft_closed'])
        ->and($refused->field)->toBe('expected_status')
        ->and(FinanceSeed::calendarSnapshot())->toBe($after)
        ->and(FinanceSeed::connection()->table('audit_logs')->count())->toBe(1);
});

it('requires an earlier period to be closed before a later one, and a later one reopened first (AC-40)', function () {
    $closer = closerActor();
    $reopener = reopenerActor();
    $year = FinanceSeed::fiscalYear('2026-01-01');
    [$p1, $p2, $p3] = [$year['periods'][1], $year['periods'][2], $year['periods'][3]];
    $before = FinanceSeed::calendarSnapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::PeriodOrderViolation, fn () => transitionAs($closer, $p2, 'soft_closed', 'open'));
    expect($refused->context['period_id'])->toBe($p1)
        ->and(FinanceSeed::calendarSnapshot())->toBe($before);

    transitionAs($closer, $p1, 'soft_closed', 'open');
    transitionAs($closer, $p2, 'soft_closed', 'open');

    // P1 is soft_closed, P2 soft_closed: closing P2 first would put a closed period after a soft-closed one.
    $refused = FinanceRefusals::expect(FinanceRefusal::PeriodOrderViolation, fn () => transitionAs($closer, $p2, 'closed', 'soft_closed'));
    expect($refused->context['period_id'])->toBe($p1);

    transitionAs($closer, $p1, 'closed', 'soft_closed');
    transitionAs($closer, $p2, 'closed', 'soft_closed');

    // Reopening P1 while P2 is closed would put an open period before a closed one.
    $refused = FinanceRefusals::expect(FinanceRefusal::PeriodOrderViolation, fn () => transitionAs($reopener, $p1, 'soft_closed', 'closed', 'redo'));
    expect($refused->context['period_id'])->toBe($p2)->and($refused->context['name'])->toBe('2026 P02');

    transitionAs($reopener, $p2, 'soft_closed', 'closed', 'redo');
    transitionAs($reopener, $p1, 'soft_closed', 'closed', 'redo');

    expect(FinanceSeed::periodRow($p3)['status'])->toBe('open');
});

it('applies the order rule across a year boundary (AC-40)', function () {
    $closer = closerActor();
    $reopener = reopenerActor();
    $a = FinanceSeed::fiscalYear('2026-01-01', null, [], array_fill(1, 11, 'closed') + [12 => 'open']);
    $b = FinanceSeed::fiscalYear('2027-01-01');
    [$last, $first] = [$a['periods'][12], $b['periods'][1]];

    $refused = FinanceRefusals::expect(FinanceRefusal::PeriodOrderViolation, fn () => transitionAs($closer, $first, 'soft_closed', 'open'));
    expect($refused->context['period_id'])->toBe($last);

    transitionAs($closer, $last, 'soft_closed', 'open');
    transitionAs($closer, $first, 'soft_closed', 'open');

    $refused = FinanceRefusals::expect(FinanceRefusal::PeriodOrderViolation, fn () => transitionAs($reopener, $last, 'open', 'soft_closed', 'redo'));
    expect($refused->context['period_id'])->toBe($first);
});

it('lists the blockers a close finds and never asks about a reopen (AC-41)', function () {
    $closer = closerActor();
    $reopener = reopenerActor();
    $year = FinanceSeed::fiscalYear('2026-01-01', null, [], [1 => 'soft_closed']);
    [$p1, $p2] = [$year['periods'][1], $year['periods'][2]];

    FakeUsagePorts::bind();
    FakeUsagePorts::$blockers[$p1] = [new PeriodBlocker('draft_journal_entries', 3), new PeriodBlocker('draft_journal_entries', 2), new PeriodBlocker('open_till_sessions', 1)];
    FakeUsagePorts::$blockers[$p2] = [new PeriodBlocker('draft_journal_entries', 1)];
    $before = FinanceSeed::calendarSnapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::CloseBlocked, fn () => transitionAs($closer, $p1, 'closed', 'soft_closed'));
    expect($refused->context)->toBe(['blockers' => ['draft_journal_entries' => 5, 'open_till_sessions' => 1]]);

    FinanceRefusals::expect(FinanceRefusal::CloseBlocked, fn () => transitionAs($closer, $p2, 'soft_closed', 'open'));

    expect(FinanceSeed::calendarSnapshot())->toBe($before);

    // The reopen is never blocked this way, though the port would refuse a close.
    transitionAs($reopener, $p1, 'open', 'soft_closed', 'reopen despite blockers');

    expect(FinanceSeed::periodRow($p1)['status'])->toBe('open');
});

it('requires a reason of 1 to 500 characters on a reopen only (AC-42)', function () {
    $closer = closerActor();
    $reopener = reopenerActor();
    $p1 = FinanceSeed::fiscalYear('2026-01-01', null, [], [1 => 'closed'])['periods'][1];
    $before = FinanceSeed::calendarSnapshot();

    foreach ([null, '', '   ', 5, str_repeat('x', 501)] as $reason) {
        FinanceRefusals::expect(FinanceRefusal::ReasonRequired, fn () => transitionAs($reopener, $p1, 'soft_closed', 'closed', $reason), var_export($reason, true));
    }

    expect(FinanceSeed::calendarSnapshot())->toBe($before);

    transitionAs($reopener, $p1, 'soft_closed', 'closed', str_repeat('é', 500));

    expect(FinanceSeed::onlyAuditRow()['after']['reason'])->toBe(str_repeat('é', 500));

    // A close takes an optional reason, and refuses an over-long one.
    $p2 = FinanceSeed::fiscalYear('2027-01-01')['periods'][1];
    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => transitionAs($closer, $p2, 'soft_closed', 'open', str_repeat('x', 501)));
});

it('refuses a client-supplied closed_at, closed_by or any other key, whole-request (AC-43, AC-36)', function (string $key) {
    $closer = closerActor();
    $p1 = FinanceSeed::fiscalYear('2026-01-01')['periods'][1];
    $before = FinanceSeed::calendarSnapshot();

    $refused = FinanceRefusals::expect(
        FinanceRefusal::Validation,
        fn () => transitionAs($closer, $p1, 'soft_closed', 'open', null, [$key => '2020-01-01 00:00:00.000']),
    );

    expect($refused->context['fields'])->toBe([$key])
        ->and(FinanceSeed::calendarSnapshot())->toBe($before);
})->with(['closed_at', 'closed_by', 'start_date', 'name', 'id']);

it('refuses to move a period of a closed year, in both directions, before anything else (AC-46, M17)', function () {
    $closer = closerActor();
    $reopener = reopenerActor();
    $year = FinanceSeed::fiscalYear('2026-01-01', null, ['status' => 'closed'], array_fill(1, 12, 'closed'));
    $before = FinanceSeed::calendarSnapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::FiscalYearClosed, fn () => transitionAs($reopener, $year['periods'][12], 'soft_closed', 'closed', 'redo'));
    expect($refused->context)->toBe(['fiscal_year' => $year['id']]);

    // The year check is first: a stale expected status (`open` against a stored
    // `closed`) is `fiscal_year_closed`, not `status_changed`.
    FinanceRefusals::expect(FinanceRefusal::FiscalYearClosed, fn () => transitionAs($closer, $year['periods'][12], 'soft_closed', 'open'));

    expect(FinanceSeed::calendarSnapshot())->toBe($before);
});

it('keeps the keys apart: close moves need close, reopen moves need reopen (AC-64)', function () {
    $closer = closerActor();
    $reopener = reopenerActor();
    $others = FinanceSeed::actor('others', 'finance.periods.manage', 'finance.periods.view', 'finance.accounts.manage');
    $year = FinanceSeed::fiscalYear('2026-01-01', null, [], [1 => 'closed', 2 => 'soft_closed']);
    $before = FinanceSeed::calendarSnapshot();

    $closeMove = ['soft_closed', 'open'];
    $reopenMove = ['soft_closed', 'closed'];

    foreach ([$reopener, $others, null] as $actor) {
        $refused = FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => transitionAs($actor, $year['periods'][3], ...$closeMove));
        expect($refused->context)->toBe(['permission' => 'finance.periods.close']);
    }

    foreach ([$closer, $others, null] as $actor) {
        $refused = FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => transitionAs($actor, $year['periods'][1], ...[...$reopenMove, 'redo']));
        expect($refused->context)->toBe(['permission' => 'finance.periods.reopen']);
    }

    expect(FinanceSeed::calendarSnapshot())->toBe($before);
});

it('observes the locks in the documented order: the year, then the periods ascending (AC-44)', function () {
    $closer = closerActor();
    $year = FinanceSeed::fiscalYear('2026-01-01', null, [], [1 => 'closed']);

    $calls = new ArrayObject;
    app()->instance(PeriodRows::class, new class(app(TenantConnection::class), $calls) extends PeriodRows
    {
        /** @param ArrayObject<int, string> $calls */
        public function __construct(TenantConnection $tenant, private readonly ArrayObject $calls)
        {
            parent::__construct($tenant);
        }

        public function yearForUpdate(string $id): ?object
        {
            $this->calls->append('year');

            return parent::yearForUpdate($id);
        }

        public function periodsBetweenForUpdate(string $from, string $to): array
        {
            $this->calls->append("periods {$from}..{$to}");

            return parent::periodsBetweenForUpdate($from, $to);
        }
    });

    transitionAs($closer, $year['periods'][2], 'soft_closed', 'open');

    expect($calls->getArrayCopy())->toBe(['year', 'periods 2026-01-01..2026-03-01']);
});

it('refuses an unknown period, a malformed id and a missing expected status (AC-39)', function () {
    $closer = closerActor();
    $p1 = FinanceSeed::fiscalYear('2026-01-01')['periods'][1];

    FinanceRefusals::expect(FinanceRefusal::PeriodNotFound, fn () => transitionAs($closer, (string) Str::ulid(), 'soft_closed', 'open'));
    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => transitionAs($closer, 'nope', 'soft_closed', 'open'));

    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => app(TransitionFiscalPeriodHandler::class)
        ->handle(new TransitionFiscalPeriod($p1, ['status' => 'soft_closed'], $closer)));

    expect(FinanceSeed::audit())->toBe([]);
});
