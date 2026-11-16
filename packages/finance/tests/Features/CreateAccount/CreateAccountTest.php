<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\CreateAccount;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\AccessDecision;
use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Finance\ControlType;
use Flexipos\Finance\Accounts\AccountRows;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\CreateAccount\CreateAccount;
use Flexipos\Finance\Features\CreateAccount\CreateAccountHandler;
use Flexipos\Finance\Features\CreateAccount\CreateAccountResult;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 9 - creating an account (AC-1 to AC-12, AC-16, AC-17, AC-65)
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
 * @param  array<string, mixed>  $fields
 */
function createAccount(array $fields, ?string $actorId): CreateAccountResult
{
    /** @var CreateAccountHandler $handler */
    $handler = app(CreateAccountHandler::class);

    return $handler->handle(new CreateAccount($fields, $actorId));
}

/**
 * @return array<string, mixed>
 */
function validAccount(string $code = 'ab1'): array
{
    return ['code' => $code, 'name' => '  Petty cash  ', 'type' => 'asset'];
}

it('creates a live account with every default, one audit row, compared whole (AC-1, AC-65)', function () {
    $actor = FinanceSeed::admin();

    $result = createAccount(validAccount(), $actor);

    $row = FinanceSeed::row($result->accountId);

    expect($result->accountId)->toMatch('/\A[0-9A-Z]{26}\z/')
        ->and($row)->toMatchArray([
            'id' => $result->accountId,
            'code' => 'AB1',
            'name' => 'Petty cash',
            'description' => null,
            'type' => 'asset',
            'subtype' => null,
            'parent_id' => null,
            'path' => $result->accountId,
            'depth' => 0,
            'normal_balance' => 'debit',
            'is_postable' => 1,
            'is_system' => 0,
            'control_type' => 'none',
            'is_active' => 1,
            'sort_order' => 0,
            'created_by' => $actor,
            'deleted_at' => null,
            'opening_balance' => null,
            'currency' => null,
        ]);

    expect(FinanceSeed::onlyAuditRow())->toMatchArray([
        'module' => 'finance',
        'actor_id' => $actor,
        'action' => 'account_created',
        'subject_type' => 'account',
        'subject_id' => $result->accountId,
        'before' => null,
        'after' => json_decode((string) json_encode($row), true),
    ]);
});

it('derives a credit normal balance for a liability and takes a supplied subtype', function () {
    $actor = FinanceSeed::admin();

    $id = createAccount(['code' => 'L1', 'name' => 'Loans', 'type' => 'liability', 'subtype' => 'long_term_liability',
        'sort_order' => 7, 'description' => 'Bank loans'], $actor)->accountId;

    expect(FinanceSeed::row($id))->toMatchArray([
        'normal_balance' => 'credit', 'subtype' => 'long_term_liability', 'sort_order' => 7, 'description' => 'Bank loans',
    ]);
});

it('refuses a code that is already in use, live or soft-deleted, by case, naming the holder (AC-3)', function () {
    $actor = FinanceSeed::admin();
    $live = FinanceSeed::account('AB1');
    $dead = FinanceSeed::account('CD2', ['deleted_at' => FinanceSeed::NOW]);

    $refused = FinanceRefusals::expect(FinanceRefusal::CodeInUse, fn () => createAccount(validAccount('ab1'), $actor));
    expect($refused->context)->toBe(['holder' => $live])->and($refused->field)->toBe('code');

    $refused = FinanceRefusals::expect(FinanceRefusal::CodeInUse, fn () => createAccount(validAccount('Cd2'), $actor));
    expect($refused->context)->toBe(['holder' => $dead]);

    expect(FinanceSeed::connection()->table('accounts')->count())->toBe(2)
        ->and(FinanceSeed::audit())->toBe([]);
});

it('maps the unique-index backstop to code_in_use and re-reads the holder (AC-3)', function () {
    $actor = FinanceSeed::admin();
    $holder = FinanceSeed::account('AB1');

    // A pre-check that is blind to the row: the insert reaches the index.
    app()->instance(AccountRows::class, new class(app(TenantConnection::class)) extends AccountRows
    {
        public function byCodeSharedLock(string $code): ?object
        {
            static $calls = 0;

            return $calls++ === 0 ? null : parent::byCodeSharedLock($code);
        }
    });

    $refused = FinanceRefusals::expect(FinanceRefusal::CodeInUse, fn () => createAccount(validAccount('ab1'), $actor));

    expect($refused->context)->toBe(['holder' => $holder])
        ->and(FinanceSeed::connection()->table('accounts')->count())->toBe(1)
        ->and(FinanceSeed::audit())->toBe([]);
});

it('refuses a parent in AC-10 order, one reason each', function (string $case, FinanceRefusal $reason) {
    $actor = FinanceSeed::admin();

    $parentId = match ($case) {
        'not_found' => (string) Str::ulid(),
        'not_live' => FinanceSeed::header('P1', ['deleted_at' => FinanceSeed::NOW]),
        'inactive' => FinanceSeed::header('P1', ['is_active' => false]),
        'not_header' => FinanceSeed::account('P1'),
        'type_mismatch' => FinanceSeed::header('P1', ['type' => 'liability', 'normal_balance' => 'credit']),
        'depth' => FinanceSeed::header('P1', ['depth' => 9]),
        default => throw new \InvalidArgumentException($case),
    };

    $before = FinanceSeed::snapshot();

    $refused = FinanceRefusals::expect($reason, fn () => createAccount(validAccount() + ['parent_id' => $parentId], $actor));

    expect($refused->field)->toBe('parent_id')
        ->and(FinanceSeed::snapshot())->toBe($before)
        ->and(FinanceSeed::audit())->toBe([]);
})->with([
    ['not_found', FinanceRefusal::ParentNotFound],
    ['not_live', FinanceRefusal::ParentNotLive],
    ['inactive', FinanceRefusal::ParentInactive],
    ['not_header', FinanceRefusal::ParentNotHeader],
    ['type_mismatch', FinanceRefusal::ParentTypeMismatch],
    ['depth', FinanceRefusal::DepthExceeded],
]);

it('places a child under its parent with depth and path from the locked parent (AC-11)', function () {
    $actor = FinanceSeed::admin();
    $root = FinanceSeed::header('R1');
    $mid = FinanceSeed::header('R2', ['parent_id' => $root]);

    $id = createAccount(validAccount() + ['parent_id' => strtolower($mid)], $actor)->accountId;

    expect(FinanceSeed::row($id))->toMatchArray([
        'parent_id' => $mid, 'depth' => 2, 'path' => $root.'/'.$mid.'/'.$id,
    ]);
});

it('reads the parent under a shared lock (AC-12; holding it is an engine proof)', function () {
    $actor = FinanceSeed::admin();
    $parent = FinanceSeed::header('R1');

    // Under pretend() every select returns nothing, so the real resolver would
    // refuse the actor before the parent is read. The double allows everyone;
    // authorization has its own tests.
    app()->instance(PermissionResolver::class, new class implements PermissionResolver
    {
        public function decide(string $employeeId, string $permissionKey, ?string $branchId): AccessDecision
        {
            return AccessDecision::allow();
        }
    });

    $connection = FinanceSeed::connection();
    $original = $connection->getQueryGrammar();
    $connection->setQueryGrammar(new MySqlGrammar($connection));

    try {
        $log = $connection->pretend(fn () => FinanceRefusals::capture(
            fn () => createAccount(validAccount() + ['parent_id' => $parent], $actor),
        ));
    } finally {
        $connection->setQueryGrammar($original);
    }

    $reads = array_values(array_filter(
        array_column($log, 'query'),
        static fn (string $sql): bool => str_contains($sql, 'from `accounts`') && str_contains($sql, $parent),
    ));

    expect($reads)->not->toBe([])
        ->and($reads[0])->toEndWith(' lock in share mode');
});

it('refuses a second live single control account, naming the holder, and accepts it once the holder is deleted (AC-17)', function () {
    $actor = FinanceSeed::admin();
    $control = ['name' => 'AR', 'type' => 'asset', 'subtype' => 'receivable', 'control_type' => 'ar'];

    $first = createAccount(['code' => 'AR1'] + $control, $actor)->accountId;

    $refused = FinanceRefusals::expect(FinanceRefusal::ControlAccountExists, fn () => createAccount(['code' => 'AR2'] + $control, $actor));
    expect($refused->context)->toBe(['holder' => $first])->and($refused->field)->toBe('control_type');

    FinanceSeed::connection()->table('accounts')->where('id', $first)->update(['deleted_at' => FinanceSeed::NOW]);

    $second = createAccount(['code' => 'AR2'] + $control, $actor)->accountId;

    expect(FinanceSeed::row($second))->toMatchArray(['control_type' => 'ar', 'is_system' => 0]);
});

it('accepts two bank accounts, a repeatable control type (AC-17)', function () {
    $actor = FinanceSeed::admin();
    $bank = ['name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank', 'control_type' => 'bank'];

    createAccount(['code' => 'BK1'] + $bank, $actor);
    createAccount(['code' => 'BK2'] + $bank, $actor);

    expect(FinanceSeed::connection()->table('accounts')->count())->toBe(2);
});

it('maps the control-marker backstop to control_account_exists when the pre-check is blind (AC-17)', function () {
    $actor = FinanceSeed::admin();
    $holder = FinanceSeed::account('AR1', ['type' => 'asset', 'subtype' => 'receivable', 'control_type' => 'ar']);

    app()->instance(AccountRows::class, new class(app(TenantConnection::class)) extends AccountRows
    {
        public function liveHolderOfControlSharedLock(ControlType $type, ?string $exceptId = null): ?object
        {
            static $calls = 0;

            return $calls++ === 0 ? null : parent::liveHolderOfControlSharedLock($type, $exceptId);
        }
    });

    $refused = FinanceRefusals::expect(FinanceRefusal::ControlAccountExists, fn () => createAccount(
        ['code' => 'AR2', 'name' => 'AR', 'type' => 'asset', 'subtype' => 'receivable', 'control_type' => 'ar'],
        $actor,
    ));

    expect($refused->context)->toBe(['holder' => $holder])
        ->and(FinanceSeed::connection()->table('accounts')->count())->toBe(1);
});

it('refuses a body with a forbidden or unknown key, naming every one, and writes nothing (AC-8, AC-65)', function () {
    $actor = FinanceSeed::admin();

    $refused = FinanceRefusals::expect(FinanceRefusal::Validation, fn () => createAccount(
        validAccount() + ['is_system' => true, 'path' => 'x', 'bogus' => 1, 'opening_balance' => '1.0000'],
        $actor,
    ));

    expect($refused->context['fields'])->toBe(['is_system', 'path', 'bogus', 'opening_balance'])
        ->and(FinanceSeed::connection()->table('accounts')->count())->toBe(0)
        ->and(FinanceSeed::audit())->toBe([]);
});

it('refuses a control type that does not fit the account, and a header control account (AC-16)', function () {
    $actor = FinanceSeed::admin();

    FinanceRefusals::expect(FinanceRefusal::ControlTypeMismatch, fn () => createAccount(
        ['code' => 'X1', 'name' => 'X', 'type' => 'asset', 'subtype' => 'cash', 'control_type' => 'ar'],
        $actor,
    ));
    FinanceRefusals::expect(FinanceRefusal::ControlTypeMismatch, fn () => createAccount(
        ['code' => 'X1', 'name' => 'X', 'type' => 'asset', 'subtype' => 'receivable', 'control_type' => 'ar', 'is_postable' => false],
        $actor,
    ));

    expect(FinanceSeed::connection()->table('accounts')->count())->toBe(0);
});

it('refuses an actor without the manage key before anything is read or written', function () {
    $viewer = FinanceSeed::actor('viewer', 'finance.accounts.view');

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => createAccount(validAccount(), $viewer));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => createAccount(validAccount(), null));

    expect(FinanceSeed::connection()->table('accounts')->count())->toBe(0)
        ->and(FinanceSeed::audit())->toBe([]);
});
