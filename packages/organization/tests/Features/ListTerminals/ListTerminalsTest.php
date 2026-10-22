<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\ListTerminals;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\ListTerminals\ListTerminals;
use Flexipos\Organization\Features\ListTerminals\ListTerminalsHandler;
use Flexipos\Organization\Support\ActivationCode;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 10 - listing terminals (AC-42)
|--------------------------------------------------------------------------
|
| Ordered by `code`, filterable by branch and status. An unknown status is
| `validation`, because an empty list would read as "there are none". The
| hash is absent from every item, asserted over the keys and over the stored
| value.
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @return array<string, string>
 */
function listTerminalsWorld(): array
{
    $one = OrganizationSeed::branch();
    $two = OrganizationSeed::branch();
    $oneStore = OrganizationSeed::location($one, 'S1', 'branch_store', ['is_sellable_from' => true]);
    $twoStore = OrganizationSeed::location($two, 'S2', 'branch_store', ['is_sellable_from' => true]);

    return [
        'one' => $one,
        'two' => $two,
        'c' => OrganizationSeed::terminal($one, $oneStore, 'C3', 'active'),
        'a' => OrganizationSeed::terminal($one, $oneStore, 'A1', 'pending', ['activation_code_hash' => ActivationCode::hashOf('ABCD-EFGH'), 'activation_expires_at' => '2026-10-23 10:00:00.000']),
        'b' => OrganizationSeed::terminal($two, $twoStore, 'B2', 'revoked'),
        'gone' => OrganizationSeed::terminal($two, $twoStore, 'G9', 'active', ['deleted_at' => '2026-10-22 00:00:00.000']),
    ];
}

it('lists by code, excludes a soft-deleted terminal, and carries no hash in any item (AC-42)', function () {
    $ids = listTerminalsWorld();

    /** @var ListTerminalsHandler $handler */
    $handler = app(ListTerminalsHandler::class);

    $list = $handler->handle(new ListTerminals);

    expect(array_column($list, 'code'))->toBe(['A1', 'B2', 'C3'])
        ->and(array_column($list, 'id'))->not->toContain($ids['gone'])
        ->and(array_column($list, 'has_pending_code'))->toBe([true, false, false]);

    foreach ($list as $item) {
        expect(array_key_exists('activation_code_hash', $item))->toBeFalse()
            ->and((string) json_encode($item))->not->toContain(ActivationCode::hashOf('ABCD-EFGH'));
    }
});

it('filters by branch and by status, together or apart (AC-42)', function () {
    $ids = listTerminalsWorld();

    /** @var ListTerminalsHandler $handler */
    $handler = app(ListTerminalsHandler::class);

    expect(array_column($handler->handle(new ListTerminals(branchId: strtolower($ids['one']))), 'code'))->toBe(['A1', 'C3'])
        ->and(array_column($handler->handle(new ListTerminals(status: 'revoked')), 'code'))->toBe(['B2'])
        ->and(array_column($handler->handle(new ListTerminals(branchId: $ids['one'], status: 'active')), 'code'))->toBe(['C3'])
        ->and($handler->handle(new ListTerminals(branchId: $ids['two'], status: 'pending')))->toBe([]);
});

it('refuses an unknown status and a malformed branch filter as validation, not as an empty list (AC-42)', function () {
    listTerminalsWorld();

    /** @var ListTerminalsHandler $handler */
    $handler = app(ListTerminalsHandler::class);

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => $handler->handle(new ListTerminals(status: 'paused')));
    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => $handler->handle(new ListTerminals(status: ['active'])));
    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => $handler->handle(new ListTerminals(branchId: 'branch-7')));
});

it('clamps the page to the configured maximum (AC-42)', function () {
    listTerminalsWorld();

    config()->set('organization.page_size', 2);
    config()->set('organization.max_page_size', 3);

    /** @var ListTerminalsHandler $handler */
    $handler = app(ListTerminalsHandler::class);

    expect($handler->handle(new ListTerminals))->toHaveCount(2)
        ->and($handler->handle(new ListTerminals(1)))->toHaveCount(1)
        ->and($handler->handle(new ListTerminals(500)))->toHaveCount(3);
});
