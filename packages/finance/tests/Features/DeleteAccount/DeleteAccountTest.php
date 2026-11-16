<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\DeleteAccount;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\AccountReferrer;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\CreateAccount\CreateAccount;
use Flexipos\Finance\Features\CreateAccount\CreateAccountHandler;
use Flexipos\Finance\Features\DeleteAccount\DeleteAccount;
use Flexipos\Finance\Features\DeleteAccount\DeleteAccountHandler;
use Flexipos\Finance\Tests\Support\FakeUsagePorts;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 11 - deleting an account (AC-18, AC-20, AC-23, AC-25)
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

function deleteAccountAs(?string $actorId, string $accountId): void
{
    /** @var DeleteAccountHandler $handler */
    $handler = app(DeleteAccountHandler::class);

    $handler->handle(new DeleteAccount($accountId, $actorId));
}

it('soft-deletes an account, keeps its code reserved and writes one audit row (AC-23)', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('AB1');
    $before = FinanceSeed::row($id);

    deleteAccountAs($actor, $id);

    $after = FinanceSeed::row($id);

    expect($after['deleted_at'])->toBe('2026-11-10 10:00:00.000')
        ->and($after['code'])->toBe('AB1')
        ->and($after['updated_by'])->toBe($actor);

    expect(FinanceSeed::onlyAuditRow())->toMatchArray([
        'action' => 'account_deleted',
        'subject_type' => 'account',
        'subject_id' => $id,
        'before' => json_decode((string) json_encode($before), true),
        'after' => json_decode((string) json_encode($after), true),
    ]);

    // The code stays reserved: a create of the same code is refused.
    /** @var CreateAccountHandler $create */
    $create = app(CreateAccountHandler::class);

    FinanceRefusals::expect(
        FinanceRefusal::CodeInUse,
        fn () => $create->handle(new CreateAccount(['code' => 'ab1', 'name' => 'Again', 'type' => 'asset'], $actor)),
    );
});

it('refuses a second delete account_not_live and writes no second audit row (AC-23)', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('AB1');

    deleteAccountAs($actor, $id);
    FinanceRefusals::expect(FinanceRefusal::AccountNotLive, fn () => deleteAccountAs($actor, $id));
    FinanceRefusals::expect(FinanceRefusal::AccountNotLive, fn () => deleteAccountAs($actor, (string) Str::ulid()));

    expect(FinanceSeed::audit())->toHaveCount(1);
});

it('refuses a system account (AC-18)', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('SYS', ['is_system' => true]);

    FinanceRefusals::expect(FinanceRefusal::SystemAccountImmutable, fn () => deleteAccountAs($actor, $id));

    expect(FinanceSeed::row($id)['deleted_at'])->toBeNull()->and(FinanceSeed::audit())->toBe([]);
});

it('refuses an account with live children, but not one with only deleted children (AC-23)', function () {
    $actor = FinanceSeed::admin();
    $header = FinanceSeed::header('H1');
    $child = FinanceSeed::account('H2', ['parent_id' => $header]);

    FinanceRefusals::expect(FinanceRefusal::HasChildren, fn () => deleteAccountAs($actor, $header));

    FinanceSeed::connection()->table('accounts')->where('id', $child)->update(['deleted_at' => FinanceSeed::NOW]);

    deleteAccountAs($actor, $header);
    expect(FinanceSeed::row($header)['deleted_at'])->not->toBeNull();
});

it('refuses an account with postings, through a bound port (AC-23, AC-25)', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('AB1');

    FakeUsagePorts::bind();
    FakeUsagePorts::$postings[$id] = true;

    FinanceRefusals::expect(FinanceRefusal::HasPostings, fn () => deleteAccountAs($actor, $id));

    expect(FinanceSeed::row($id)['deleted_at'])->toBeNull();
});

it('refuses an account any record names, live or soft-deleted, naming the kinds and counts (AC-23)', function (int $live, int $deleted) {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('AB1');

    FakeUsagePorts::bind();
    FakeUsagePorts::$referrers[$id] = [new AccountReferrer('products.income_account_id', $live, $deleted)];

    $refused = FinanceRefusals::expect(FinanceRefusal::InUse, fn () => deleteAccountAs($actor, $id));

    expect($refused->context['referrers'])->toBe(['products.income_account_id' => ['live' => $live, 'deleted' => $deleted]])
        ->and(FinanceSeed::row($id)['deleted_at'])->toBeNull()
        ->and(FinanceSeed::audit())->toBe([]);
})->with([[2, 0], [0, 3]]);

it('refuses an actor without the manage key', function () {
    $viewer = FinanceSeed::actor('viewer', 'finance.accounts.view');
    $id = FinanceSeed::account('AB1');

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => deleteAccountAs($viewer, $id));

    expect(FinanceSeed::row($id)['deleted_at'])->toBeNull();
});
