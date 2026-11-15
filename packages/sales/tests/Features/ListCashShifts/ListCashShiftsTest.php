<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\ListCashShifts;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\ListCashShifts\ListCashShifts;
use Flexipos\Sales\Features\ListCashShifts\ListCashShiftsHandler;
use Flexipos\Sales\Support\Page;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 12 - ListCashShifts (D-26)
|--------------------------------------------------------------------------
|
| Keyset-paged, newest first, optionally narrowed by branch, terminal and status.
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

function listCashShifts(?string $branchId = null, ?string $terminalId = null, ?string $status = null, ?int $pageSize = null, ?string $beforeId = null): Page
{
    /** @var ListCashShiftsHandler $handler */
    $handler = app(ListCashShiftsHandler::class);

    return $handler->handle(new ListCashShifts($branchId, $terminalId, $status, $pageSize, $beforeId));
}

it('pages through every shift exactly once, newest first', function () {
    $branch = SalesSeed::branch();
    $terminal = SalesSeed::terminal($branch);
    $cashier = SalesSeed::employee('cashier');

    $ids = [];
    for ($i = 0; $i < 51; $i++) {
        $ids[] = SalesSeed::shift($terminal['id'], $branch, $cashier, sprintf('SH%06d', $i), [
            'id' => strtoupper((string) Str::ulid(now()->addMilliseconds($i))),
            'status' => 'closed',
        ]);
    }

    $first = listCashShifts(null, null, null, 50);
    $second = listCashShifts(null, null, null, 50, $first->nextBeforeId);

    expect($first->rows)->toHaveCount(50)
        ->and($first->nextBeforeId)->not->toBeNull()
        ->and($second->rows)->toHaveCount(1)
        ->and($second->nextBeforeId)->toBeNull()
        ->and(array_merge(array_column($first->rows, 'id'), array_column($second->rows, 'id')))->toBe(array_reverse($ids));
});

it('narrows by branch, terminal and status', function () {
    $branch = SalesSeed::branch();
    $other = SalesSeed::branch();
    $t1 = SalesSeed::terminal($branch, 'T1');
    $t2 = SalesSeed::terminal($branch, 'T2');
    $t3 = SalesSeed::terminal($other, 'T3');
    $cashier = SalesSeed::employee('cashier');

    $open = SalesSeed::shift($t1['id'], $branch, $cashier, 'SH000001');
    SalesSeed::shift($t1['id'], $branch, $cashier, 'SH000002', ['status' => 'closed']);
    SalesSeed::shift($t2['id'], $branch, $cashier, 'SH000003');
    SalesSeed::shift($t3['id'], $other, $cashier, 'SH000004');

    expect(listCashShifts($branch)->rows)->toHaveCount(3)
        ->and(listCashShifts(strtolower($other))->rows)->toHaveCount(1)
        ->and(array_column(listCashShifts($branch, $t1['id'], 'open')->rows, 'id'))->toBe([$open])
        ->and(listCashShifts(null, null, 'closed')->rows)->toHaveCount(1)
        ->and(listCashShifts()->rows)->toHaveCount(4);
});

it('refuses a status that is not one of the four and a malformed branch, terminal or cursor', function () {
    SalesRefusals::expect(SalesRefusal::Validation, fn () => listCashShifts(null, null, 'finished'));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => listCashShifts('nope'));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => listCashShifts(null, 'nope'));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => listCashShifts(null, null, null, null, 'nope'));
});

it('clamps an oversized page and never refuses it', function () {
    $branch = SalesSeed::branch();
    $terminal = SalesSeed::terminal($branch);
    $cashier = SalesSeed::employee('cashier');

    SalesSeed::shift($terminal['id'], $branch, $cashier, 'SH000001', ['status' => 'closed']);
    SalesSeed::shift($terminal['id'], $branch, $cashier, 'SH000002');

    config()->set('sales.max_page_size', 1);

    expect(listCashShifts(null, null, null, 500)->rows)->toHaveCount(1);
});
