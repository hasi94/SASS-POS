<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\ListApprovalRequests;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\ListApprovalRequests\ListApprovalRequests;
use Flexipos\Sales\Features\ListApprovalRequests\ListApprovalRequestsHandler;
use Flexipos\Sales\Support\Page;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 10 - ListApprovalRequests (D-26)
|--------------------------------------------------------------------------
|
| Keyset-paged, newest first, optionally narrowed by branch and status. The
| cursor reaches row 51 of 51 at page size 50.
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/sales-fixture.php';
require_once __DIR__.'/../../Support/approval-fixture.php';

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

function listApprovals(?string $branchId = null, ?string $status = null, ?int $pageSize = null, ?string $beforeId = null): Page
{
    /** @var ListApprovalRequestsHandler $handler */
    $handler = app(ListApprovalRequestsHandler::class);

    return $handler->handle(new ListApprovalRequests($branchId, $status, $pageSize, $beforeId));
}

it('reaches row 51 of 51 at page size 50, newest first, with no row twice', function () {
    $w = apvWorld();

    $ids = [];
    for ($i = 0; $i < 51; $i++) {
        // Ids are time-ordered ULIDs; a fixed millisecond step keeps them strictly increasing.
        $ids[] = SalesSeed::approval($w['branch'], $w['cashier'], ['id' => strtoupper((string) Str::ulid(now()->addMilliseconds($i)))]);
    }

    $first = listApprovals(null, null, 50);

    expect($first->rows)->toHaveCount(50)
        ->and($first->nextBeforeId)->not->toBeNull();

    $second = listApprovals(null, null, 50, $first->nextBeforeId);

    expect($second->rows)->toHaveCount(1)
        ->and($second->nextBeforeId)->toBeNull();

    $seen = array_merge(array_column($first->rows, 'id'), array_column($second->rows, 'id'));

    expect($seen)->toHaveCount(51)
        ->and(array_unique($seen))->toHaveCount(51)
        ->and($seen)->toBe(array_reverse($ids));
});

it('clamps an oversized page to the configured maximum and never refuses it', function () {
    $w = apvWorld();

    SalesSeed::approval($w['branch'], $w['cashier']);
    config()->set('sales.max_page_size', 1);

    SalesSeed::approval($w['branch'], $w['cashier']);

    expect(listApprovals(null, null, 500)->rows)->toHaveCount(1);
});

it('narrows by branch and by status', function () {
    $w = apvWorld();

    $pendingHere = SalesSeed::approval($w['branch'], $w['cashier']);
    SalesSeed::approval($w['branch'], $w['cashier'], ['status' => 'approved']);
    SalesSeed::approval($w['otherBranch'], $w['cashier']);

    expect(array_column(listApprovals($w['branch'], 'pending')->rows, 'id'))->toBe([$pendingHere])
        ->and(listApprovals($w['branch'])->rows)->toHaveCount(2)
        ->and(listApprovals(strtolower($w['otherBranch']))->rows)->toHaveCount(1)
        ->and(listApprovals(null, 'approved')->rows)->toHaveCount(1);
});

it('refuses a status that is not one of the four, and a malformed branch or cursor', function () {
    SalesRefusals::expect(SalesRefusal::Validation, fn () => listApprovals(null, 'done'));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => listApprovals('nope'));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => listApprovals(null, null, null, 'nope'));
});

it('returns the columns of the row and leaves a payload as stored', function () {
    $w = apvWorld();

    SalesSeed::approval($w['branch'], $w['cashier'], ['type' => 'discount', 'payload' => '{"discount_id":"X"}']);

    $row = listApprovals()->rows[0];

    expect($row['type'])->toBe('discount')
        ->and($row['payload'])->toBe('{"discount_id":"X"}')
        ->and($row)->toHaveKeys(['id', 'branch_id', 'status', 'requested_by', 'consumed_at']);
});
