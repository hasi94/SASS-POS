<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\ListPurchaseRequests;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Purchasing\Documents\RequestStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\ListPurchaseRequests\ListPurchaseRequests;
use Flexipos\Purchasing\Features\ListPurchaseRequests\ListPurchaseRequestsHandler;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Page;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 14 - ListPurchaseRequests (AC-35, AC-38, AC-84, AC-86, D-34, D-36)
|--------------------------------------------------------------------------
|
| Only the branches the caller may view; newest first; keyset by before_id; an
| oversize page clamped to 100; bad paging or a bad filter is validation.
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/purchasing-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    puEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function puListRequests(?string $actorId, mixed $status = null, mixed $branchId = null, mixed $number = null, mixed $pageSize = null, mixed $beforeId = null): Page
{
    /** @var ListPurchaseRequestsHandler $handler */
    $handler = app(ListPurchaseRequestsHandler::class);

    return $handler->handle(new ListPurchaseRequests($actorId, $status, $branchId, $number, $pageSize, $beforeId));
}

/**
 * An id that sorts as the number it is built from, so "newest first" is exact.
 */
function puOrderedId(int $n): string
{
    return '01JZ'.str_pad(strtoupper(dechex($n)), 22, '0', STR_PAD_LEFT);
}

/**
 * `$count` requests of one branch with ids `puOrderedId(1..$count)`, in one statement.
 *
 * @param  array<string, string>  $desk
 */
function puSeedRequests(array $desk, int $count): void
{
    $rows = [];

    for ($i = 1; $i <= $count; $i++) {
        $rows[] = [
            'id' => puOrderedId($i),
            'created_at' => PurchasingSeed::NOW,
            'updated_at' => PurchasingSeed::NOW,
            'created_by' => $desk['buyer'],
            'updated_by' => $desk['buyer'],
            'number' => 'PRL'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'branch_id' => $desk['branch'],
            'location_id' => $desk['location'],
            'requested_by' => $desk['buyer'],
            'status' => 'draft',
        ];
    }

    PurchasingSeed::connection()->table('purchase_requests')->insert($rows);
}

/**
 * @return list<string>
 */
function puListedNumbers(Page $page): array
{
    return array_map(static fn (array $row): string => (string) $row['number'], $page->rows);
}

it('AC-35: lists newest first, 25 a page by default, and walks before_id to the end', function () {
    $desk = PurchasingWorld::desk();
    puSeedRequests($desk, 60);

    $first = puListRequests($desk['buyer']);
    $second = puListRequests($desk['buyer'], beforeId: $first->nextCursor);
    $third = puListRequests($desk['buyer'], beforeId: $second->nextCursor);

    expect($first->rows)->toHaveCount(25)
        ->and($first->rows[0]['id'])->toBe(puOrderedId(60))
        ->and($first->nextCursor)->toBe(puOrderedId(36))
        ->and($second->rows[0]['id'])->toBe(puOrderedId(35))
        ->and($second->rows)->toHaveCount(25)
        ->and($third->rows)->toHaveCount(10)
        ->and($third->nextCursor)->toBeNull()
        ->and($third->rows[9]['id'])->toBe(puOrderedId(1));
});

it('AC-35: a page_size of 101 is clamped to 100, never refused', function () {
    $desk = PurchasingWorld::desk();
    puSeedRequests($desk, 105);

    $page = puListRequests($desk['buyer'], pageSize: 101);

    expect($page->rows)->toHaveCount(100)
        ->and($page->nextCursor)->toBe(puOrderedId(6))
        ->and(puListRequests($desk['buyer'], pageSize: 1)->rows)->toHaveCount(1);
});

it('AC-35: a non-positive or non-integer page_size, a malformed cursor, an unknown status and a bad number or branch filter are validation, even for a caller who may see nothing', function (string $field, mixed $value) {
    $desk = PurchasingWorld::desk();
    $nobody = AccessSeed::actorHolding('nobody', []);

    foreach ([$desk['buyer'], $nobody] as $actor) {
        PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => match ($field) {
            'page_size' => puListRequests($actor, pageSize: $value),
            'before_id' => puListRequests($actor, beforeId: $value),
            'status' => puListRequests($actor, status: $value),
            'number' => puListRequests($actor, number: $value),
            default => puListRequests($actor, branchId: $value),
        }, $field);
    }
})->with([
    'page_size 0' => ['page_size', 0],
    'page_size -1' => ['page_size', -1],
    'page_size "x"' => ['page_size', 'x'],
    'page_size 2.5' => ['page_size', 2.5],
    'before_id malformed' => ['before_id', 'nope'],
    'before_id an int' => ['before_id', 5],
    'status unknown' => ['status', 'shipped'],
    'status in the wrong case' => ['status', 'Draft'],
    'status an array' => ['status', ['draft']],
    'number empty' => ['number', ''],
    'number over 30' => ['number', str_repeat('P', 31)],
    'number an int' => ['number', 5],
    'branch malformed' => ['branch_id', 'nope'],
]);

it('AC-35: the status, branch and number filters match and combine, and no match is an empty page', function () {
    $desk = PurchasingWorld::desk();
    PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['status' => 'draft', 'number' => 'PR000001']);
    PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['status' => 'approved', 'number' => 'PR000002']);
    PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['status' => 'approved', 'number' => 'PR000003']);

    $other = PurchasingWorld::desk('o');
    PurchasingSeed::request($other['branch'], $other['location'], $other['buyer'], ['status' => 'approved', 'number' => 'PR000004']);

    $director = AccessSeed::actorHolding('director', [PurchasingPermissions::REQUESTS_VIEW]);

    expect(puListedNumbers(puListRequests($director, status: 'approved')))->toBe(['PR000004', 'PR000003', 'PR000002'])
        ->and(puListedNumbers(puListRequests($director, branchId: $desk['branch'])))->toBe(['PR000003', 'PR000002', 'PR000001'])
        ->and(puListedNumbers(puListRequests($director, status: 'approved', branchId: $desk['branch'])))->toBe(['PR000003', 'PR000002'])
        ->and(puListedNumbers(puListRequests($director, number: 'PR000002')))->toBe(['PR000002'])
        ->and(puListedNumbers(puListRequests($director, status: 'draft', number: 'PR000002')))->toBe([])
        ->and(puListRequests($director, status: 'rejected')->rows)->toBe([]);
});

it('AC-35: every status the enum names is accepted as a filter', function () {
    $desk = PurchasingWorld::desk();

    foreach (RequestStatus::cases() as $status) {
        expect(puListRequests($desk['buyer'], status: $status->value)->rows)->toBe([]);
    }
});

it('AC-35: without a branch filter only the branches where the caller holds the view key appear; a company-wide grant sees every branch', function () {
    $a = PurchasingWorld::desk('a');
    $b = PurchasingWorld::desk('b');
    PurchasingSeed::request($a['branch'], $a['location'], $a['buyer'], ['number' => 'PRA']);
    PurchasingSeed::request($b['branch'], $b['location'], $b['buyer'], ['number' => 'PRB']);

    $atA = AccessSeed::actorHolding('at_a', [PurchasingPermissions::REQUESTS_VIEW], $a['branch']);
    $atBoth = AccessSeed::actorHolding('at_both', [PurchasingPermissions::REQUESTS_VIEW], $a['branch']);
    AccessSeed::assign($atBoth, AccessSeed::role('also_b', [PurchasingPermissions::REQUESTS_VIEW]), $b['branch']);
    $companyWide = AccessSeed::actorHolding('director', [PurchasingPermissions::REQUESTS_VIEW]);
    $managerOnly = AccessSeed::actorHolding('manager_only', [PurchasingPermissions::REQUESTS_MANAGE], $a['branch']);

    expect(puListedNumbers(puListRequests($atA)))->toBe(['PRA'])
        ->and(puListedNumbers(puListRequests($atBoth)))->toBe(['PRB', 'PRA'])
        ->and(puListedNumbers(puListRequests($companyWide)))->toBe(['PRB', 'PRA'])
        ->and(puListRequests($managerOnly)->rows)->toBe([]);
});

it('AC-35: none allowed is an empty page, not an error, and the result of a page count is still keyed', function () {
    $desk = PurchasingWorld::desk();
    puSeedRequests($desk, 3);
    $nobody = AccessSeed::actorHolding('nobody', []);

    $page = puListRequests($nobody);

    expect($page->rows)->toBe([])
        ->and($page->nextCursor)->toBeNull();
});

it('AC-35: with a branch filter the permission is decided once, at that branch, and a refusal is not_authorised carrying the resolver s reason', function () {
    $a = PurchasingWorld::desk('a');
    $b = PurchasingWorld::desk('b');
    PurchasingSeed::request($a['branch'], $a['location'], $a['buyer'], ['number' => 'PRA']);
    PurchasingSeed::request($b['branch'], $b['location'], $b['buyer'], ['number' => 'PRB']);
    $atA = AccessSeed::actorHolding('at_a', [PurchasingPermissions::REQUESTS_VIEW], $a['branch']);

    expect(puListedNumbers(puListRequests($atA, branchId: $a['branch'])))->toBe(['PRA']);

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puListRequests($atA, branchId: $b['branch']));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted);
});

it('AC-35: a company-wide holder still sees the requests of a branch that has since been made inactive or soft-deleted', function () {
    $desk = PurchasingWorld::desk();
    PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['number' => 'PROLD']);
    $director = AccessSeed::actorHolding('director', [PurchasingPermissions::REQUESTS_VIEW]);

    PurchasingSeed::connection()->table('branches')->where('id', $desk['branch'])->update(['is_active' => false, 'deleted_at' => PurchasingSeed::NOW]);

    expect(puListedNumbers(puListRequests($director)))->toBe(['PROLD']);
});

it('AC-86: an employee who cannot act is refused with the resolver s reason, not given an empty page, and a missing actor is validation', function () {
    $desk = PurchasingWorld::desk();
    AccessSeed::connection()->table('employees')->where('id', $desk['buyer'])->update(['status' => 'suspended']);

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puListRequests($desk['buyer']));

    expect($refused->cause)->toBe(RefusalReason::EmployeeUnavailable);

    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puListRequests(null));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puListRequests(null, branchId: $desk['branch']));
});

it('AC-35: an unknown employee is refused, not given an empty page', function () {
    PurchasingWorld::desk();

    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puListRequests((string) Str::ulid()));
});
