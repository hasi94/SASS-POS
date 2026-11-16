<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\HoldSale;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\HoldSale\HoldSale;
use Flexipos\Sales\Features\HoldSale\HoldSaleHandler;
use Flexipos\Sales\Features\HoldSale\HoldSaleResult;
use Flexipos\Sales\Tests\Support\LockMarkingGrammar;
use Flexipos\Sales\Tests\Support\SalesQueryLog;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 18 - HoldSale (AC-17, AC-19, AC-51; D-24, D-25; Ruling 4)
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
function hsHold(array $fields, ?string $actorId): HoldSaleResult
{
    /** @var HoldSaleHandler $handler */
    $handler = app(HoldSaleHandler::class);

    return $handler->handle(new HoldSale($fields, $actorId));
}

/**
 * @return array<string, mixed>
 */
function hsState(): array
{
    $c = SalesSeed::connection();

    return [
        'sales' => $c->table('sales')->get()->map(fn ($r) => (array) $r)->all(),
        'holds' => $c->table('sale_holds')->get()->map(fn ($r) => (array) $r)->all(),
        'audit' => SalesSeed::audit(),
    ];
}

it('holds a draft sale, recording what it was held from, where, by whom and when (AC-17, AC-19)', function (string $status) {
    $till = SalesWorld::till();
    $sale = SalesWorld::sale($till, ['status' => $status]);

    $result = hsHold(['sale_id' => strtolower($sale), 'terminal_id' => strtolower($till['terminal'])], strtolower($till['cashier']));

    expect($result->fromStatus)->toBe($status)
        ->and(SalesSeed::row('sales', $sale)['status'])->toBe('held')
        ->and(SalesSeed::row('sale_holds', $result->holdId))->toMatchArray([
            'sale_id' => $sale,
            'action' => 'hold',
            'from_status' => $status,
            'to_status' => 'held',
            'terminal_id' => $till['terminal'],
            'employee_id' => $till['cashier'],
            'created_at' => '2026-11-12 10:00:00.000',
        ]);

    expect(SalesSeed::onlyAuditRow())->toMatchArray(['module' => 'sales', 'action' => 'sales_sale_held', 'subject_type' => 'sale', 'subject_id' => $sale, 'actor_id' => $till['cashier']]);
})->with(['draft', 'pending_payment']);

it('leaves the cashier on the sale as it was, when another employee parks it (D-24)', function () {
    $till = SalesWorld::till();
    $other = SalesSeed::employee('other');
    $sale = SalesWorld::sale($till);

    hsHold(['sale_id' => $sale, 'terminal_id' => $till['terminal']], $other);

    expect(SalesSeed::row('sales', $sale)['employee_id'])->toBe($till['cashier'])
        ->and(SalesSeed::connection()->table('sale_holds')->value('employee_id'))->toBe($other);
});

it('refuses any other status as invalid_transition, naming it, and writes nothing (AC-51)', function (string $status) {
    $till = SalesWorld::till();
    $sale = SalesWorld::sale($till, ['status' => $status]);
    $before = hsState();

    $refused = SalesRefusals::expect(SalesRefusal::InvalidTransition, fn () => hsHold(['sale_id' => $sale, 'terminal_id' => $till['terminal']], $till['cashier']));

    expect($refused->getMessage())->toContain($status)->and(hsState())->toBe($before);
})->with(['held', 'completed', 'voided', 'refunded', 'partially_refunded', 'failed']);

it('refuses a refund document as invalid_transition', function () {
    $till = SalesWorld::till();
    $sale = SalesWorld::sale($till, ['type' => 'refund']);

    SalesRefusals::expect(SalesRefusal::InvalidTransition, fn () => hsHold(['sale_id' => $sale, 'terminal_id' => $till['terminal']], $till['cashier']));
});

it('refuses holding twice: the second call finds the sale held', function () {
    $till = SalesWorld::till();
    $sale = SalesWorld::sale($till);

    hsHold(['sale_id' => $sale, 'terminal_id' => $till['terminal']], $till['cashier']);

    SalesRefusals::expect(SalesRefusal::InvalidTransition, fn () => hsHold(['sale_id' => $sale, 'terminal_id' => $till['terminal']], $till['cashier']));

    expect(SalesSeed::connection()->table('sale_holds')->count())->toBe(1);
});

it('is not_found for a sale that is not there, a terminal that is not active, and a terminal of another branch', function () {
    $till = SalesWorld::till();
    $sale = SalesWorld::sale($till);
    $paused = SalesSeed::terminal($till['branch'], 'T7', 'paused');
    $foreign = SalesSeed::terminal(SalesSeed::branch(), 'T8');
    $before = hsState();

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => hsHold(['sale_id' => (string) Str::ulid(), 'terminal_id' => $till['terminal']], $till['cashier']));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => hsHold(['sale_id' => $sale, 'terminal_id' => (string) Str::ulid()], $till['cashier']));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => hsHold(['sale_id' => $sale, 'terminal_id' => $paused['id']], $till['cashier']));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => hsHold(['sale_id' => $sale, 'terminal_id' => $foreign['id']], $till['cashier']));

    expect(hsState())->toBe($before);
});

it('refuses malformed ids, other fields and a missing or unknown actor', function () {
    $till = SalesWorld::till();
    $sale = SalesWorld::sale($till);

    SalesRefusals::expect(SalesRefusal::Validation, fn () => hsHold(['sale_id' => 'x', 'terminal_id' => $till['terminal']], $till['cashier']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => hsHold(['sale_id' => $sale, 'terminal_id' => 'x'], $till['cashier']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => hsHold(['sale_id' => $sale], $till['cashier']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => hsHold(['sale_id' => $sale, 'terminal_id' => $till['terminal'], 'status' => 'held'], $till['cashier']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => hsHold(['sale_id' => $sale, 'terminal_id' => $till['terminal']], null));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => hsHold(['sale_id' => $sale, 'terminal_id' => $till['terminal']], (string) Str::ulid()));
});

it('takes the sale FOR UPDATE as the first statement inside the transaction', function () {
    $till = SalesWorld::till();
    $sale = SalesWorld::sale($till);

    $inside = SalesQueryLog::inTransaction(SalesQueryLog::during(fn () => hsHold(['sale_id' => $sale, 'terminal_id' => $till['terminal']], $till['cashier'])));

    expect($inside[0])->toContain('"sales"')->and($inside[0])->toContain(LockMarkingGrammar::FOR_UPDATE);
});
