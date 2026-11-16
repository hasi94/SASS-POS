<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\RecallSale;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLine;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLineHandler;
use Flexipos\Sales\Features\HoldSale\HoldSale;
use Flexipos\Sales\Features\HoldSale\HoldSaleHandler;
use Flexipos\Sales\Features\RecallSale\RecallSale;
use Flexipos\Sales\Features\RecallSale\RecallSaleHandler;
use Flexipos\Sales\Features\RecallSale\RecallSaleResult;
use Flexipos\Sales\Tests\Support\LockMarkingGrammar;
use Flexipos\Sales\Tests\Support\SalesQueryLog;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 18 - RecallSale (AC-18, AC-19, AC-51; D-24, D-25, D-39; F-15; R-5)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/sales-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    salesEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @param  array<string, mixed>  $fields
 */
function rcRecall(array $fields, ?string $actorId): RecallSaleResult
{
    /** @var RecallSaleHandler $handler */
    $handler = app(RecallSaleHandler::class);

    return $handler->handle(new RecallSale($fields, $actorId));
}

/**
 * A sale parked at till A, and a second till B in the same branch with its own open shift.
 *
 * @return array<string, mixed>
 */
function rcHeld(string $from = 'draft'): array
{
    $a = SalesWorld::till();
    $b = SalesWorld::secondTill($a);
    $sale = SalesWorld::sale($a, ['status' => $from]);

    /** @var HoldSaleHandler $hold */
    $hold = app(HoldSaleHandler::class);
    $hold->handle(new HoldSale(['sale_id' => $sale, 'terminal_id' => $a['terminal']], $a['cashier']));

    return ['a' => $a, 'b' => $b, 'sale' => $sale, 'bCashier' => SalesSeed::employee('cashierB')];
}

/**
 * @return array<string, mixed>
 */
function rcState(): array
{
    $c = SalesSeed::connection();

    return [
        'sales' => $c->table('sales')->get()->map(fn ($r) => (array) $r)->all(),
        'holds' => $c->table('sale_holds')->get()->map(fn ($r) => (array) $r)->all(),
        'audit' => SalesSeed::audit(),
    ];
}

it('restores exactly the recorded status, from another terminal: terminal and shift move, the cashier does not (AC-18, F-15)', function (string $from) {
    $w = rcHeld($from);

    $result = rcRecall(['sale_id' => strtolower($w['sale']), 'terminal_id' => strtolower($w['b']['terminal'])], $w['bCashier']);

    expect($result->status)->toBe($from)
        ->and($result->shiftId)->toBe($w['b']['shift'])
        ->and(SalesSeed::row('sales', $w['sale']))->toMatchArray([
            'status' => $from,
            'terminal_id' => $w['b']['terminal'],
            'shift_id' => $w['b']['shift'],
            'employee_id' => $w['a']['cashier'],
        ])
        ->and(SalesSeed::row('sale_holds', $result->holdId))->toMatchArray([
            'action' => 'recall',
            'from_status' => 'held',
            'to_status' => $from,
            'terminal_id' => $w['b']['terminal'],
            'employee_id' => $w['bCashier'],
        ]);

    $audit = SalesSeed::audit();

    expect(end($audit)['action'])->toBe('sales_sale_recalled');
})->with(['draft', 'pending_payment']);

it('is no_open_shift at a terminal with none, and writes nothing (D-39)', function () {
    $w = rcHeld();
    $closed = SalesSeed::terminal($w['a']['branch'], 'T9');
    $before = rcState();

    SalesRefusals::expect(SalesRefusal::NoOpenShift, fn () => rcRecall(['sale_id' => $w['sale'], 'terminal_id' => $closed['id']], $w['bCashier']));

    expect(rcState())->toBe($before);
});

it('refuses a sale that is not held as invalid_transition, naming the status (AC-51)', function (string $status) {
    $till = SalesWorld::till();
    $sale = SalesWorld::sale($till, ['status' => $status]);
    $before = rcState();

    $refused = SalesRefusals::expect(SalesRefusal::InvalidTransition, fn () => rcRecall(['sale_id' => $sale, 'terminal_id' => $till['terminal']], $till['cashier']));

    expect($refused->getMessage())->toContain($status)->and(rcState())->toBe($before);
})->with(['draft', 'pending_payment', 'completed', 'voided']);

it('refuses a held sale that has no recorded hold, rather than guessing a status', function () {
    $till = SalesWorld::till();
    $sale = SalesWorld::sale($till, ['status' => 'held']);

    SalesRefusals::expect(SalesRefusal::InvalidTransition, fn () => rcRecall(['sale_id' => $sale, 'terminal_id' => $till['terminal']], $till['cashier']));
});

it('is not_found for a sale of another branch, a terminal that is not active, and a sale that is not there', function () {
    $w = rcHeld();
    $foreign = SalesWorld::till('TF');
    $before = rcState();

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => rcRecall(['sale_id' => $w['sale'], 'terminal_id' => $foreign['terminal']], $foreign['cashier']));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => rcRecall(['sale_id' => $w['sale'], 'terminal_id' => (string) Str::ulid()], $w['bCashier']));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => rcRecall(['sale_id' => (string) Str::ulid(), 'terminal_id' => $w['b']['terminal']], $w['bCashier']));

    expect(rcState())->toBe($before);
});

it('answers who parked it and who picked it up, independently of the audit log: two holds and one recall are three rows (AC-19)', function () {
    $w = rcHeld();
    $clock = app(RunnerClock::class);

    rcRecall(['sale_id' => $w['sale'], 'terminal_id' => $w['b']['terminal']], $w['bCashier']);
    $clock->advanceMinutes(5);

    /** @var HoldSaleHandler $hold */
    $hold = app(HoldSaleHandler::class);
    $hold->handle(new HoldSale(['sale_id' => $w['sale'], 'terminal_id' => $w['b']['terminal']], $w['bCashier']));

    // The audit log is wiped: the trail is the sale_holds table's own.
    SalesSeed::connection()->table('audit_logs')->delete();

    $rows = SalesSeed::connection()->table('sale_holds')->where('sale_id', $w['sale'])->orderBy('created_at')->orderBy('id')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('action')->all())->toBe(['hold', 'recall', 'hold'])
        ->and($rows->pluck('terminal_id')->all())->toBe([$w['a']['terminal'], $w['b']['terminal'], $w['b']['terminal']])
        ->and($rows->pluck('employee_id')->all())->toBe([$w['a']['cashier'], $w['bCashier'], $w['bCashier']])
        ->and($rows->pluck('created_at')->unique()->count())->toBe(2);
});

it('lets the lines be edited after a recall of a sale held from draft, and not after one held from pending_payment (AC-6, F-10)', function (string $from, bool $editable) {
    $w = rcHeld($from);
    $group = SalesWorld::taxGroup('TX10', '10.0000');
    $catalogue = SalesWorld::product('P1', '100.0000', $group);

    rcRecall(['sale_id' => $w['sale'], 'terminal_id' => $w['b']['terminal']], $w['bCashier']);

    /** @var AddSaleLineHandler $add */
    $add = app(AddSaleLineHandler::class);
    $line = fn () => $add->handle(new AddSaleLine(['sale_id' => $w['sale'], 'product_id' => $catalogue['product'], 'unit_id' => $catalogue['unit'], 'quantity' => '1'], $w['bCashier']));

    if ($editable) {
        expect($line()->lineNo)->toBe(1);

        return;
    }

    SalesRefusals::expect(SalesRefusal::SaleNotEditable, $line);
})->with(['from draft' => ['draft', true], 'from pending_payment' => ['pending_payment', false]]);

it('refuses malformed ids, other fields and a missing actor', function () {
    $w = rcHeld();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => rcRecall(['sale_id' => 'x', 'terminal_id' => $w['b']['terminal']], $w['bCashier']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rcRecall(['sale_id' => $w['sale']], $w['bCashier']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rcRecall(['sale_id' => $w['sale'], 'terminal_id' => $w['b']['terminal'], 'shift_id' => $w['b']['shift']], $w['bCashier']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rcRecall(['sale_id' => $w['sale'], 'terminal_id' => $w['b']['terminal']], null));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => rcRecall(['sale_id' => $w['sale'], 'terminal_id' => $w['b']['terminal']], (string) Str::ulid()));
});

it('takes the recalling terminal\'s shift first (shared, by id), then the sale FOR UPDATE (Ruling 7, R-5)', function () {
    $w = rcHeld();

    $inside = SalesQueryLog::inTransaction(SalesQueryLog::during(fn () => rcRecall(['sale_id' => $w['sale'], 'terminal_id' => $w['b']['terminal']], $w['bCashier'])));

    expect($inside[0])->toContain('"cash_shifts"')->and($inside[0])->toContain(LockMarkingGrammar::SHARED)
        ->and($inside[1])->toContain('"sales"')->and($inside[1])->toContain(LockMarkingGrammar::FOR_UPDATE);
});

it('attributes nothing to a shift that closed between the pre-read and the lock (race row)', function () {
    $w = rcHeld();
    $before = rcState();

    // The close commits right after the handler has read the terminal's open shift and before it locks it.
    $closed = false;
    SalesSeed::connection()->listen(static function ($query) use (&$closed, $w): void {
        if (! $closed && str_contains($query->sql, '"cash_shifts" where "terminal_id" = ?')) {
            $closed = true;
            SalesSeed::connection()->table('cash_shifts')->where('id', $w['b']['shift'])->update(['status' => 'closed']);
        }
    });

    SalesRefusals::expect(SalesRefusal::NoOpenShift, fn () => rcRecall(['sale_id' => $w['sale'], 'terminal_id' => $w['b']['terminal']], $w['bCashier']));

    expect($closed)->toBeTrue()
        ->and(SalesSeed::row('sales', $w['sale'])['status'])->toBe('held')
        ->and(SalesSeed::connection()->table('sale_holds')->count())->toBe(count($before['holds']))
        ->and(SalesSeed::row('sales', $w['sale'])['shift_id'])->toBe($w['a']['shift']);
});
