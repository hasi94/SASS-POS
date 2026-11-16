<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\ListCashMovements;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\ListCashMovements\ListCashMovements;
use Flexipos\Sales\Features\ListCashMovements\ListCashMovementsHandler;
use Flexipos\Sales\Support\Page;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 12 - ListCashMovements (D-26, R-17)
|--------------------------------------------------------------------------
|
| A shift's movements, newest first, ordered by `(created_at, id)` and never by
| `id` alone: the ids are the caller's, so an id order is whatever the client
| invented. The cursor is a row id, and it still walks every row once across
| ties on `created_at`.
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

function listCashMovements(string $shiftId, ?int $pageSize = null, ?string $beforeId = null): Page
{
    /** @var ListCashMovementsHandler $handler */
    $handler = app(ListCashMovementsHandler::class);

    return $handler->handle(new ListCashMovements($shiftId, $pageSize, $beforeId));
}

/**
 * @return array{shift: string, cashier: string}
 */
function listedShift(): array
{
    $branch = SalesSeed::branch();
    $terminal = SalesSeed::terminal($branch);
    $cashier = SalesSeed::employee('cashier');

    return ['shift' => SalesSeed::shift($terminal['id'], $branch, $cashier, 'SH000001'), 'cashier' => $cashier];
}

it('orders by created_at, then id, newest first, whatever the ids the caller chose', function () {
    $w = listedShift();

    // The earliest movement has the HIGHEST id, so an id-only order would put it first.
    $earliest = SalesSeed::movement($w['shift'], $w['cashier'], 'cash_in', '1', ['id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'created_at' => '2026-11-12 09:00:00.000']);
    $middle = SalesSeed::movement($w['shift'], $w['cashier'], 'cash_in', '2', ['id' => '01HAAAAAAAAAAAAAAAAAAAAAAA', 'created_at' => '2026-11-12 10:00:00.000']);
    $latest = SalesSeed::movement($w['shift'], $w['cashier'], 'cash_in', '3', ['id' => '01HBBBBBBBBBBBBBBBBBBBBBBB', 'created_at' => '2026-11-12 11:00:00.000']);

    expect(array_column(listCashMovements($w['shift'])->rows, 'id'))->toBe([$latest, $middle, $earliest]);
});

it('breaks a tie on created_at by id, descending, and walks every row once across the tie', function () {
    $w = listedShift();

    $ids = [];
    for ($i = 0; $i < 7; $i++) {
        // Three share an instant; ids are ULID-shaped and chosen out of insertion order.
        $ids[] = SalesSeed::movement($w['shift'], $w['cashier'], 'cash_in', '1', [
            'id' => sprintf('01H%023d', 90 - $i * 3),
            'created_at' => $i < 3 ? '2026-11-12 09:00:00.000' : sprintf('2026-11-12 10:0%d:00.000', $i),
        ]);
    }

    $expected = SalesSeed::connection()->table('cash_movements')->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();

    $seen = [];
    $cursor = null;

    // Bounded: a cursor that does not advance must fail this test, not hang the suite.
    for ($pages = 0; $pages < 10; $pages++) {
        $page = listCashMovements($w['shift'], 2, $cursor);
        $seen = array_merge($seen, array_column($page->rows, 'id'));
        $cursor = $page->nextBeforeId;

        if ($cursor === null) {
            break;
        }
    }

    expect($seen)->toBe($expected)
        ->and(array_unique($seen))->toHaveCount(7);
});

it('reaches row 51 of 51 at page size 50', function () {
    $w = listedShift();

    for ($i = 0; $i < 51; $i++) {
        SalesSeed::movement($w['shift'], $w['cashier'], 'cash_in', '1', ['created_at' => sprintf('2026-11-12 09:%02d:%02d.000', intdiv($i, 60), $i % 60)]);
    }

    $first = listCashMovements($w['shift'], 50);
    $second = listCashMovements($w['shift'], 50, $first->nextBeforeId);

    expect($first->rows)->toHaveCount(50)
        ->and($second->rows)->toHaveCount(1)
        ->and($second->nextBeforeId)->toBeNull();
});

it('lists only the named shift\'s movements', function () {
    $w = listedShift();
    $branch = SalesSeed::row('cash_shifts', $w['shift'])['branch_id'];
    $other = SalesSeed::shift(SalesSeed::terminal($branch, 'T2')['id'], $branch, $w['cashier'], 'SH000002');

    $mine = SalesSeed::movement($w['shift'], $w['cashier'], 'cash_in', '1');
    SalesSeed::movement($other, $w['cashier'], 'cash_in', '1');

    expect(array_column(listCashMovements(strtolower($w['shift']))->rows, 'id'))->toBe([$mine]);
});

it('refuses a malformed shift or cursor, and a cursor that names no row of this table', function () {
    $w = listedShift();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => listCashMovements('nope'));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => listCashMovements($w['shift'], null, 'nope'));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => listCashMovements($w['shift'], null, '01HZZZZZZZZZZZZZZZZZZZZZZZ'));
});

it('is not_found for an unknown shift', function () {
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => listCashMovements('01HZZZZZZZZZZZZZZZZZZZZZZZ'));
});
