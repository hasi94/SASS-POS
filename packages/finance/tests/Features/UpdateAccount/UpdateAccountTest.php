<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\UpdateAccount;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\AccountReferrer;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\UpdateAccount\UpdateAccount;
use Flexipos\Finance\Features\UpdateAccount\UpdateAccountHandler;
use Flexipos\Finance\Tests\Support\FakeUsagePorts;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 10 - updating an account (AC-14, AC-15, AC-18 to AC-22, AC-25)
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

/**
 * @param  array<array-key, mixed>  $fields
 */
function updateAccountAs(?string $actorId, string $accountId, array $fields): void
{
    /** @var UpdateAccountHandler $handler */
    $handler = app(UpdateAccountHandler::class);

    $handler->handle(new UpdateAccount($accountId, $fields, $actorId));
}

it('updates only the fields named, stamps the actor and writes one audit row, compared whole (AC-21, AC-65)', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('AB1', ['description' => 'Keep me', 'sort_order' => 3]);
    $before = FinanceSeed::row($id);

    updateAccountAs($actor, $id, ['name' => '  Renamed  ']);

    $after = FinanceSeed::row($id);

    expect($after)->toMatchArray([
        'name' => 'Renamed', 'description' => 'Keep me', 'sort_order' => 3, 'code' => 'AB1',
        'updated_by' => $actor, 'updated_at' => '2026-11-10 10:00:00.000',
    ]);

    expect(FinanceSeed::onlyAuditRow())->toMatchArray([
        'module' => 'finance',
        'actor_id' => $actor,
        'action' => 'account_updated',
        'subject_type' => 'account',
        'subject_id' => $id,
        'before' => json_decode((string) json_encode($before), true),
        'after' => json_decode((string) json_encode($after), true),
        'changed_fields' => ['name', 'updated_at', 'updated_by'],
    ]);
});

it('clears a description with an explicit null and leaves an omitted one alone (AC-21)', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('AB1', ['description' => 'Old']);

    updateAccountAs($actor, $id, ['description' => null]);
    expect(FinanceSeed::row($id)['description'])->toBeNull();

    FinanceSeed::connection()->table('accounts')->where('id', $id)->update(['description' => 'Back']);
    updateAccountAs($actor, $id, ['sort_order' => 9]);
    expect(FinanceSeed::row($id))->toMatchArray(['description' => 'Back', 'sort_order' => 9]);
});

it('refuses an empty body and a body naming parent_id or is_active, writing nothing (F-12, AC-8)', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('AB1');
    $before = FinanceSeed::snapshot();

    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => updateAccountAs($actor, $id, []));
    $refused = FinanceRefusals::expect(FinanceRefusal::NotUpdatableHere, fn () => updateAccountAs($actor, $id, ['parent_id' => null, 'is_active' => false]));
    expect($refused->context['fields'])->toBe(['parent_id', 'is_active']);
    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => updateAccountAs($actor, $id, ['name' => 'X', 'is_system' => true]));

    expect(FinanceSeed::snapshot())->toBe($before)->and(FinanceSeed::audit())->toBe([]);
});

it('refuses an unknown or soft-deleted account account_not_live, and a malformed id as validation (AC-20)', function () {
    $actor = FinanceSeed::admin();
    $dead = FinanceSeed::account('AB1', ['deleted_at' => FinanceSeed::NOW]);
    $before = FinanceSeed::snapshot();

    FinanceRefusals::expect(FinanceRefusal::AccountNotLive, fn () => updateAccountAs($actor, $dead, ['name' => 'X']));
    FinanceRefusals::expect(FinanceRefusal::AccountNotLive, fn () => updateAccountAs($actor, (string) Str::ulid(), ['name' => 'X']));
    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => updateAccountAs($actor, 'nope', ['name' => 'X']));

    expect(FinanceSeed::snapshot())->toBe($before)->and(FinanceSeed::audit())->toBe([]);
});

it('refuses a header with live children becoming postable, but not one with only a deleted child (AC-14)', function () {
    $actor = FinanceSeed::admin();
    $header = FinanceSeed::header('H1');
    $child = FinanceSeed::account('H2', ['parent_id' => $header]);

    FinanceRefusals::expect(FinanceRefusal::HasChildren, fn () => updateAccountAs($actor, $header, ['is_postable' => true]));
    expect(FinanceSeed::row($header)['is_postable'])->toBe(0);

    FinanceSeed::connection()->table('accounts')->where('id', $child)->update(['deleted_at' => FinanceSeed::NOW]);

    updateAccountAs($actor, $header, ['is_postable' => true]);
    expect(FinanceSeed::row($header)['is_postable'])->toBe(1);
});

it('lets a postable leaf become a header only without postings or referrers (AC-14, AC-25)', function () {
    $actor = FinanceSeed::admin();
    $plain = FinanceSeed::account('L1');
    $posted = FinanceSeed::account('L2');
    $referenced = FinanceSeed::account('L3');

    FakeUsagePorts::bind();
    FakeUsagePorts::$postings[$posted] = true;
    FakeUsagePorts::$referrers[$referenced] = [new AccountReferrer('products.income_account_id', 0, 1)];

    updateAccountAs($actor, $plain, ['is_postable' => false]);
    expect(FinanceSeed::row($plain)['is_postable'])->toBe(0);

    FinanceRefusals::expect(FinanceRefusal::HasPostings, fn () => updateAccountAs($actor, $posted, ['is_postable' => false]));
    FinanceRefusals::expect(FinanceRefusal::InUse, fn () => updateAccountAs($actor, $referenced, ['is_postable' => false]));

    expect(FinanceSeed::row($posted)['is_postable'])->toBe(1)->and(FinanceSeed::row($referenced)['is_postable'])->toBe(1);
});

it('refuses a type change naming the first failing condition (AC-15, D-30)', function (string $condition) {
    $actor = FinanceSeed::admin();
    FakeUsagePorts::bind();

    $parentId = null;

    if ($condition === 'parent_type_mismatch') {
        $parentId = FinanceSeed::header('P1');
    }

    $id = FinanceSeed::account('T1', ['parent_id' => $parentId, 'is_postable' => $condition !== 'has_children']);

    match ($condition) {
        'has_children' => FinanceSeed::account('T2', ['parent_id' => $id]),
        'has_postings' => FakeUsagePorts::$postings[$id] = true,
        'in_use' => FakeUsagePorts::$referrers[$id] = [new AccountReferrer('customers.receivable_account_id', 1, 0)],
        default => null,
    };

    $before = FinanceSeed::snapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::TypeChangeRefused, fn () => updateAccountAs($actor, $id, ['type' => 'liability', 'subtype' => null]));

    expect($refused->context)->toBe(['condition' => $condition])
        ->and(FinanceSeed::snapshot())->toBe($before)
        ->and(FinanceSeed::audit())->toBe([]);
})->with(['has_children', 'has_postings', 'in_use', 'parent_type_mismatch']);

it('retypes a free account, deriving the normal balance, and needs a subtype that fits (AC-15, AC-6, AC-7)', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('T1', ['subtype' => 'cash']);

    // The stored subtype is a cash subtype: it does not belong to a liability.
    FinanceRefusals::expect(FinanceRefusal::SubtypeMismatch, fn () => updateAccountAs($actor, $id, ['type' => 'liability']));

    updateAccountAs($actor, $id, ['type' => 'liability', 'subtype' => 'payable']);

    expect(FinanceSeed::row($id))->toMatchArray(['type' => 'liability', 'subtype' => 'payable', 'normal_balance' => 'credit']);

    // A supplied balance that disagrees with the new type is refused whole.
    FinanceRefusals::expect(FinanceRefusal::NormalBalanceMismatch, fn () => updateAccountAs($actor, $id, ['type' => 'asset', 'subtype' => null, 'normal_balance' => 'credit']));
});

it('checks a lone normal_balance or subtype against the stored type', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('T1');

    FinanceRefusals::expect(FinanceRefusal::NormalBalanceMismatch, fn () => updateAccountAs($actor, $id, ['normal_balance' => 'credit']));
    FinanceRefusals::expect(FinanceRefusal::SubtypeMismatch, fn () => updateAccountAs($actor, $id, ['subtype' => 'sales']));

    updateAccountAs($actor, $id, ['subtype' => 'bank', 'normal_balance' => 'debit']);
    expect(FinanceSeed::row($id)['subtype'])->toBe('bank');
});

it('keeps a system account\'s code, type, subtype, control type and postability immutable (its balance only moves with its type), and its name, description and sort order editable (AC-18)', function (string $field, mixed $value) {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('ARX', ['type' => 'asset', 'subtype' => 'receivable', 'control_type' => 'ar', 'is_system' => true]);
    $before = FinanceSeed::snapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::SystemAccountImmutable, fn () => updateAccountAs($actor, $id, [$field => $value]));

    expect($refused->context['fields'])->toBe([$field])
        ->and(FinanceSeed::snapshot())->toBe($before)
        ->and(FinanceSeed::audit())->toBe([]);
})->with([
    ['code', 'ARY'],
    ['type', 'liability'],
    ['subtype', 'cash'],
    ['control_type', 'none'],
    ['is_postable', false],
]);

it('lets a system account change its name, description and sort order, and accept an unchanged immutable value (AC-18)', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('ARX', ['type' => 'asset', 'subtype' => 'receivable', 'control_type' => 'ar', 'is_system' => true]);

    updateAccountAs($actor, $id, ['name' => 'Trade debtors', 'description' => 'D', 'sort_order' => 4, 'code' => 'arx', 'type' => 'asset']);

    expect(FinanceSeed::row($id))->toMatchArray(['name' => 'Trade debtors', 'description' => 'D', 'sort_order' => 4, 'is_system' => 1]);
});

it('refuses changing the control type once there are postings or referrers, with the AC-19 reason', function (string $source) {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('C1', ['subtype' => 'cash']);

    FakeUsagePorts::bind();
    $source === 'postings'
        ? FakeUsagePorts::$postings[$id] = true
        : FakeUsagePorts::$referrers[$id] = [new AccountReferrer('products.inventory_account_id', 1, 0)];

    FinanceRefusals::expect(FinanceRefusal::ControlTypeLocked, fn () => updateAccountAs($actor, $id, ['control_type' => 'cash']));

    expect(FinanceSeed::row($id)['control_type'])->toBe('none');
})->with(['postings', 'referrers']);

it('changes the control type under AC-16 and AC-17', function () {
    $actor = FinanceSeed::admin();
    $cash = FinanceSeed::account('C1', ['subtype' => 'cash']);
    $other = FinanceSeed::account('C2', ['subtype' => 'receivable']);
    $holder = FinanceSeed::account('AR1', ['subtype' => 'receivable', 'control_type' => 'ar']);

    updateAccountAs($actor, $cash, ['control_type' => 'cash']);
    expect(FinanceSeed::row($cash)['control_type'])->toBe('cash');

    FinanceRefusals::expect(FinanceRefusal::ControlTypeMismatch, fn () => updateAccountAs($actor, $other, ['control_type' => 'bank']));

    $refused = FinanceRefusals::expect(FinanceRefusal::ControlAccountExists, fn () => updateAccountAs($actor, $other, ['control_type' => 'ar']));
    expect($refused->context)->toBe(['holder' => $holder]);

    // Setting the holder's own control type again is not a second holder.
    updateAccountAs($actor, $holder, ['control_type' => 'ar', 'name' => 'AR']);
});

it('checks a control account against its stored control type when its subtype or postability changes (AC-16)', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('C1', ['subtype' => 'cash', 'control_type' => 'cash']);

    FinanceRefusals::expect(FinanceRefusal::ControlTypeMismatch, fn () => updateAccountAs($actor, $id, ['subtype' => 'bank']));
    FinanceRefusals::expect(FinanceRefusal::ControlTypeMismatch, fn () => updateAccountAs($actor, $id, ['is_postable' => false]));

    expect(FinanceSeed::row($id))->toMatchArray(['subtype' => 'cash', 'is_postable' => 1]);
});

it('recodes under AC-2 and AC-3, and refuses a recode once there are postings (AC-22)', function () {
    $actor = FinanceSeed::admin();
    $id = FinanceSeed::account('AB1');
    $taken = FinanceSeed::account('CD2');
    $dead = FinanceSeed::account('EF3', ['deleted_at' => FinanceSeed::NOW]);

    updateAccountAs($actor, $id, ['code' => 'ab9']);
    expect(FinanceSeed::row($id)['code'])->toBe('AB9');

    $refused = FinanceRefusals::expect(FinanceRefusal::CodeInUse, fn () => updateAccountAs($actor, $id, ['code' => 'cd2']));
    expect($refused->context)->toBe(['holder' => $taken]);
    FinanceRefusals::expect(FinanceRefusal::CodeInUse, fn () => updateAccountAs($actor, $id, ['code' => 'ef3']));
    FinanceRefusals::expect(FinanceRefusal::InvalidCode, fn () => updateAccountAs($actor, $id, ['code' => ' ab9']));

    FakeUsagePorts::bind();
    FakeUsagePorts::$postings[$id] = true;

    FinanceRefusals::expect(FinanceRefusal::CodeLocked, fn () => updateAccountAs($actor, $id, ['code' => 'ZZ1']));

    // Naming the stored code again is not a change, so postings do not matter.
    updateAccountAs($actor, $id, ['code' => 'ab9', 'name' => 'Same code']);

    expect($dead)->not->toBe('');
});

it('refuses an actor without the manage key before reading the target', function () {
    $viewer = FinanceSeed::actor('viewer', 'finance.accounts.view');
    $id = FinanceSeed::account('AB1');

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => updateAccountAs($viewer, $id, ['name' => 'X']));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => updateAccountAs(null, (string) Str::ulid(), ['name' => 'X']));

    expect(FinanceSeed::row($id)['name'])->toBe('Account AB1');
});

it('refuses a change to a different subtype like a retype, naming the failing condition (AC-15a, D-32)', function (string $source, string $condition) {
    $actor = FinanceSeed::admin();
    FakeUsagePorts::bind();
    $id = FinanceSeed::account('S1', ['type' => 'expense', 'subtype' => 'cogs', 'normal_balance' => 'debit']);

    match ($source) {
        'postings' => FakeUsagePorts::$postings[$id] = true,
        'product' => FakeUsagePorts::$referrers[$id] = [new AccountReferrer('products.cogs_account_id', 1, 0)],
        default => FakeUsagePorts::$referrers[$id] = [new AccountReferrer('customers.receivable_account_id', 0, 1)],
    };

    $before = FinanceSeed::snapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::TypeChangeRefused, fn () => updateAccountAs($actor, $id, ['subtype' => 'operating_expense']));

    expect($refused->context)->toBe(['condition' => $condition])
        ->and($refused->field)->toBe('subtype')
        ->and(FinanceSeed::snapshot())->toBe($before)
        ->and(FinanceSeed::audit())->toBe([]);
})->with([
    'postings' => ['postings', 'has_postings'],
    'a product names it' => ['product', 'in_use'],
    'a customer names it' => ['customer', 'in_use'],
]);

it('lets the same subtype through untouched, and a subtype change on an unused account (AC-15a)', function () {
    $actor = FinanceSeed::admin();
    FakeUsagePorts::bind();
    $used = FinanceSeed::account('S1', ['type' => 'expense', 'subtype' => 'cogs', 'normal_balance' => 'debit']);
    $free = FinanceSeed::account('S2', ['type' => 'expense', 'subtype' => 'cogs', 'normal_balance' => 'debit']);
    FakeUsagePorts::$postings[$used] = true;
    FakeUsagePorts::$referrers[$used] = [new AccountReferrer('products.cogs_account_id', 1, 0)];

    // Naming the stored subtype is not a change, however used the account is.
    updateAccountAs($actor, $used, ['subtype' => 'cogs', 'name' => 'Still used']);

    expect(FinanceSeed::row($used))->toMatchArray(['subtype' => 'cogs', 'name' => 'Still used']);

    updateAccountAs($actor, $free, ['subtype' => 'operating_expense']);

    expect(FinanceSeed::row($free)['subtype'])->toBe('operating_expense');
});

it('asks the referrers port once per update however many rules want the answer (S-3)', function () {
    $actor = FinanceSeed::admin();
    FakeUsagePorts::bind();
    $id = FinanceSeed::account('S1', ['type' => 'expense', 'subtype' => 'cogs', 'normal_balance' => 'debit', 'control_type' => 'none']);

    // A header turn and a subtype change both want "any referrers?".
    updateAccountAs($actor, $id, ['subtype' => 'operating_expense', 'is_postable' => false]);

    expect(FakeUsagePorts::$referrerAsks)->toBe(1);
});
