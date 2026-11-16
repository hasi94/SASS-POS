<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Periods;

use ArrayObject;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\PeriodClearance;
use Flexipos\Contracts\Finance\PeriodGate;
use Flexipos\Contracts\Finance\PeriodGateRefused;
use Flexipos\Contracts\Finance\PeriodRefusal;
use Flexipos\Contracts\Finance\PeriodStatus;
use Flexipos\Finance\Periods\DatabasePeriodGate;
use Flexipos\Finance\Periods\PeriodRows;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 15 - the period gate (AC-47 to AC-51)
|--------------------------------------------------------------------------
|
| The gate runs inside the posting transaction, so every call here opens one
| on the fixture connection. The two-connection proof of AC-50 (a close waits
| for an in-flight posting) is `[ENGINE]`; here the shared-lock call is
| observed on the rows seam.
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/finance-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    financeEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * Ask the gate inside a transaction, the way the posting service does.
 */
function gateClear(mixed $date, ?string $actorId): PeriodClearance
{
    return FinanceSeed::connection()->transaction(fn (): PeriodClearance => app(PeriodGate::class)->clear($date, $actorId));
}

/**
 * @return array{0: ?PeriodRefusal, 1: ?PeriodClearance}
 */
function gateOutcome(mixed $date, ?string $actorId): array
{
    try {
        return [null, gateClear($date, $actorId)];
    } catch (PeriodGateRefused $refused) {
        return [$refused->reason, null];
    }
}

it('is bound to the database gate (AC-47)', function () {
    expect(app(PeriodGate::class))->toBeInstanceOf(DatabasePeriodGate::class);
});

it('decides every status for every kind of actor (AC-47, M18)', function (string $status, string $kind, ?PeriodRefusal $expected) {
    $year = FinanceSeed::fiscalYear('2026-01-01', null, [], [3 => $status]);

    $actor = match ($kind) {
        'plain' => FinanceSeed::actor('plain', 'finance.accounts.view'),
        'adjuster' => FinanceSeed::actor('adjuster', 'finance.periods.post_adjustment'),
        'every other key' => FinanceSeed::actor('others', ...array_values(array_diff(
            FinancePermissions::KEYS,
            ['finance.periods.post_adjustment'],
        ))),
        'finance admin' => FinanceSeed::admin(),
        'null' => null,
        default => throw new \InvalidArgumentException($kind),
    };

    [$refusal, $clearance] = gateOutcome('2026-03-15', $actor);

    expect($refusal)->toBe($expected);

    if ($expected === null) {
        expect($clearance)->toBeInstanceOf(PeriodClearance::class)
            ->and($clearance->periodId)->toBe($year['periods'][3])
            ->and($clearance->status)->toBe(PeriodStatus::from($status));
    }
})->with(function () {
    $cases = [
        'open' => ['plain' => null, 'adjuster' => null, 'every other key' => null, 'finance admin' => null, 'null' => null],
        'soft_closed' => [
            'plain' => PeriodRefusal::PeriodSoftClosed, 'adjuster' => null, 'every other key' => PeriodRefusal::PeriodSoftClosed,
            'finance admin' => null, 'null' => PeriodRefusal::PeriodSoftClosed,
        ],
        'closed' => [
            'plain' => PeriodRefusal::PeriodClosed, 'adjuster' => PeriodRefusal::PeriodClosed, 'every other key' => PeriodRefusal::PeriodClosed,
            'finance admin' => PeriodRefusal::PeriodClosed, 'null' => PeriodRefusal::PeriodClosed,
        ],
    ];

    foreach ($cases as $status => $kinds) {
        foreach ($kinds as $kind => $expected) {
            yield "{$status} / {$kind}" => [$status, $kind, $expected];
        }
    }
});

it('refuses every date that is not a real strict Y-m-d, and never defaults to allowed or today (AC-48, M19)', function () {
    $adjuster = FinanceSeed::admin();
    FinanceSeed::fiscalYear(gmdate('Y').'-01-01');

    foreach ([null, '', '   ', '2026-02-30', '2026-13-01', '26-1-1', '2026-1-1', '2026-02-01 00:00:00', '2026-02-01T00:00:00Z', "2026-02-01\n", 20260201, 2026.0, true, [], 'today'] as $date) {
        [$refusal, $clearance] = gateOutcome($date, $adjuster);

        expect($refusal)->toBe(PeriodRefusal::InvalidDate, var_export($date, true))
            ->and($clearance)->toBeNull();
    }
});

it('fails closed in a tenant with no fiscal year, for today and for any date (AC-48)', function () {
    $admin = FinanceSeed::admin();

    foreach ([gmdate('Y-m-d'), '2026-06-15', '2000-01-01'] as $date) {
        expect(gateOutcome($date, $admin)[0])->toBe(PeriodRefusal::NoFiscalPeriod, $date);
    }
});

it('resolves the first day, the last day and the day after (AC-49)', function () {
    $year = FinanceSeed::fiscalYear('2026-01-01');
    FinanceSeed::fiscalYear('2027-01-01');
    $actor = FinanceSeed::admin();

    expect(gateClear('2026-02-01', $actor)->periodId)->toBe($year['periods'][2])
        ->and(gateClear('2026-02-28', $actor)->periodId)->toBe($year['periods'][2])
        ->and(gateClear('2026-03-01', $actor)->periodId)->toBe($year['periods'][3])
        ->and(gateClear('2026-12-31', $actor)->periodId)->toBe($year['periods'][12]);

    // The day after the last year's last day, and the day before the first: no period.
    expect(gateOutcome('2027-12-31', $actor)[1]?->status)->toBe(PeriodStatus::Open)
        ->and(gateOutcome('2028-01-01', $actor)[0])->toBe(PeriodRefusal::NoFiscalPeriod)
        ->and(gateOutcome('2025-12-31', $actor)[0])->toBe(PeriodRefusal::NoFiscalPeriod);
});

it('throws when called outside a transaction, and never reads (AC-50)', function () {
    FinanceSeed::fiscalYear('2026-01-01');

    $statements = [];
    FinanceSeed::connection()->listen(static function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    expect(fn () => app(PeriodGate::class)->clear('2026-02-01', null))
        ->toThrow(LogicException::class, 'inside the caller');

    expect(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'fiscal_periods')))->toBe([]);
});

it('takes a shared lock on the period row, and only that, inside the transaction (AC-50)', function () {
    FinanceSeed::fiscalYear('2026-01-01');

    $calls = new ArrayObject;
    app()->instance(PeriodRows::class, new class(app(TenantConnection::class), $calls) extends PeriodRows
    {
        /** @param ArrayObject<int, string> $calls */
        public function __construct(TenantConnection $tenant, private readonly ArrayObject $calls)
        {
            parent::__construct($tenant);
        }

        public function periodCoveringSharedLock(string $date): ?object
        {
            $this->calls->append("shared {$date}");

            return parent::periodCoveringSharedLock($date);
        }

        public function periodCoveringUnlocked(string $date): ?object
        {
            $this->calls->append("unlocked {$date}");

            return parent::periodCoveringUnlocked($date);
        }
    });

    gateClear('2026-02-10', null);

    expect($calls->getArrayCopy())->toBe(['shared 2026-02-10']);
});

it('exposes only published types: the contract and its implementation answer in Contracts\\Finance terms (AC-51)', function () {
    $published = 'Flexipos\\Contracts\\Finance\\';

    foreach ([PeriodGate::class, DatabasePeriodGate::class] as $class) {
        $return = (string) (new ReflectionMethod($class, 'clear'))->getReturnType();

        expect($return)->toBe(PeriodClearance::class)
            ->and($return)->toStartWith($published);
    }

    // The clearance carries an id and a status, nothing else.
    $properties = array_map(static fn ($p): string => $p->getName(), (new ReflectionClass(PeriodClearance::class))->getProperties());
    expect($properties)->toBe(['periodId', 'status']);

    // Every exception the implementation throws is published, or the programmer-error LogicException.
    $source = (string) file_get_contents((string) (new ReflectionClass(DatabasePeriodGate::class))->getFileName());
    preg_match_all('/throw\s+(?:new\s+)?\\\\?(\w+)/', $source, $thrown);

    expect(array_values(array_unique($thrown[1])))->toEqualCanonicalizing(['LogicException', 'PeriodGateRefused']);

    foreach (['LogicException' => LogicException::class, 'PeriodGateRefused' => PeriodGateRefused::class] as $short => $full) {
        expect($short === 'LogicException' || str_starts_with($full, $published))->toBeTrue();
    }
});
