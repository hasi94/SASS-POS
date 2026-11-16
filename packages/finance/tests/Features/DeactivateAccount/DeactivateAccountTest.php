<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\DeactivateAccount;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\AccountReferrer;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\DeactivateAccount\DeactivateAccount;
use Flexipos\Finance\Features\DeactivateAccount\DeactivateAccountHandler;
use Flexipos\Finance\Features\ReactivateAccount\ReactivateAccount;
use Flexipos\Finance\Features\ReactivateAccount\ReactivateAccountHandler;
use Flexipos\Finance\Tests\Support\FakeUsagePorts;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 11 - deactivating and reactivating an account (AC-18,
| AC-20, AC-24)
|--------------------------------------------------------------------------
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

function deactivateAccountAs(?string $actorId, string $accountId): void
{
    /** @var DeactivateAccountHandler $handler */
    $handler = app(DeactivateAccountHandler::class);

    $handler->handle(new DeactivateAccount($accountId, $actorId));
}

function reactivateAccountAs(?string $actorId, string $accountId): void
{
    /** @var ReactivateAccountHandler $handler */
    $handler = app(ReactivateAccountHandler::class);

    $handler->handle(new ReactivateAccount($accountId, $actorId));
}

it('deactivates and reactivates an account, one audit row each (AC-24)', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('AB1');
    $before = FinanceSeed::row($id);

    deactivateAccountAs($actor, $id);

    $inactive = FinanceSeed::row($id);

    expect($inactive)->toMatchArray(['is_active' => 0, 'updated_by' => $actor]);
    expect(FinanceSeed::onlyAuditRow())->toMatchArray([
        'action' => 'account_deactivated',
        'subject_id' => $id,
        'before' => json_decode((string) json_encode($before), true),
        'after' => json_decode((string) json_encode($inactive), true),
    ]);

    FinanceSeed::connection()->table('audit_logs')->delete();

    reactivateAccountAs($actor, $id);

    expect(FinanceSeed::row($id)['is_active'])->toBe(1)
        ->and(FinanceSeed::onlyAuditRow()['action'])->toBe('account_reactivated');
});

it('refuses deactivating twice and reactivating an active account (AC-24)', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('AB1');

    FinanceRefusals::expect(FinanceRefusal::AlreadyActive, fn () => reactivateAccountAs($actor, $id));

    deactivateAccountAs($actor, $id);

    FinanceRefusals::expect(FinanceRefusal::AlreadyInactive, fn () => deactivateAccountAs($actor, $id));

    expect(FinanceSeed::audit())->toHaveCount(1);
});

it('refuses a system account (AC-18)', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('SYS', ['is_system' => true]);

    FinanceRefusals::expect(FinanceRefusal::SystemAccountImmutable, fn () => deactivateAccountAs($actor, $id));

    expect(FinanceSeed::row($id)['is_active'])->toBe(1);
});

it('refuses an account with an active child, but not one whose children are all inactive or deleted (AC-24)', function () {
    $actor = FinanceSeed::admin();
    $header = FinanceSeed::header('H1');
    $active = FinanceSeed::account('H2', ['parent_id' => $header]);
    $inactive = FinanceSeed::account('H3', ['parent_id' => $header, 'is_active' => false]);
    $dead = FinanceSeed::account('H4', ['parent_id' => $header, 'deleted_at' => FinanceSeed::NOW]);

    FinanceRefusals::expect(FinanceRefusal::HasChildren, fn () => deactivateAccountAs($actor, $header));

    FinanceSeed::connection()->table('accounts')->where('id', $active)->update(['is_active' => false]);

    deactivateAccountAs($actor, $header);

    expect(FinanceSeed::row($header)['is_active'])->toBe(0)
        ->and([$inactive, $dead])->not->toBe([]);
});

it('refuses an account a live record names, but not one only a deleted record names (AC-24)', function () {
    $actor = FinanceSeed::admin();
    $live = FinanceSeed::account('AB1');
    $deletedOnly = FinanceSeed::account('AB2');

    FakeUsagePorts::bind();
    FakeUsagePorts::$referrers[$live] = [new AccountReferrer('customers.receivable_account_id', 1, 0)];
    FakeUsagePorts::$referrers[$deletedOnly] = [new AccountReferrer('customers.receivable_account_id', 0, 4)];

    $refused = FinanceRefusals::expect(FinanceRefusal::InUse, fn () => deactivateAccountAs($actor, $live));
    expect($refused->context['referrers'])->toBe(['customers.receivable_account_id' => ['live' => 1, 'deleted' => 0]]);

    deactivateAccountAs($actor, $deletedOnly);

    expect(FinanceSeed::row($live)['is_active'])->toBe(1)->and(FinanceSeed::row($deletedOnly)['is_active'])->toBe(0);
});

it('refuses reactivating under an inactive or deleted parent (AC-24)', function () {
    $actor = FinanceSeed::admin();
    $inactiveParent = FinanceSeed::header('P1', ['is_active' => false]);
    $deletedParent = FinanceSeed::header('P2', ['deleted_at' => FinanceSeed::NOW]);
    $a = FinanceSeed::account('C1', ['parent_id' => $inactiveParent, 'is_active' => false]);
    $b = FinanceSeed::account('C2', ['parent_id' => $deletedParent, 'is_active' => false]);

    FinanceRefusals::expect(FinanceRefusal::ParentInactive, fn () => reactivateAccountAs($actor, $a));
    FinanceRefusals::expect(FinanceRefusal::ParentNotLive, fn () => reactivateAccountAs($actor, $b));

    FinanceSeed::connection()->table('accounts')->where('id', $inactiveParent)->update(['is_active' => true]);
    reactivateAccountAs($actor, $a);

    expect(FinanceSeed::row($a)['is_active'])->toBe(1)->and(FinanceSeed::row($b)['is_active'])->toBe(0);
});

it('refuses an unknown or soft-deleted account account_not_live (AC-20)', function () {
    $actor = FinanceSeed::admin();
    $dead = FinanceSeed::account('AB1', ['deleted_at' => FinanceSeed::NOW, 'is_active' => false]);

    FinanceRefusals::expect(FinanceRefusal::AccountNotLive, fn () => deactivateAccountAs($actor, $dead));
    FinanceRefusals::expect(FinanceRefusal::AccountNotLive, fn () => reactivateAccountAs($actor, $dead));
    FinanceRefusals::expect(FinanceRefusal::AccountNotLive, fn () => deactivateAccountAs($actor, (string) Str::ulid()));
    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => reactivateAccountAs($actor, 'nope'));
});

it('refuses an actor without the manage key', function () {
    $viewer = FinanceSeed::actor('viewer', 'finance.accounts.view');
    $id = FinanceSeed::account('AB1');

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => deactivateAccountAs($viewer, $id));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => reactivateAccountAs(null, $id));

    expect(FinanceSeed::row($id)['is_active'])->toBe(1);
});
