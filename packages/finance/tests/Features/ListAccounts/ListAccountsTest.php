<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\ListAccounts;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\CreateAccount\CreateAccount;
use Flexipos\Finance\Features\CreateAccount\CreateAccountHandler;
use Flexipos\Finance\Features\GetAccount\GetAccount;
use Flexipos\Finance\Features\GetAccount\GetAccountHandler;
use Flexipos\Finance\Features\GetAccountTree\GetAccountTree;
use Flexipos\Finance\Features\GetAccountTree\GetAccountTreeHandler;
use Flexipos\Finance\Features\ListAccounts\ListAccounts;
use Flexipos\Finance\Features\ListAccounts\ListAccountsHandler;
use Flexipos\Finance\Support\Page;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 12 - the account reads (AC-1 read half, AC-61, AC-64)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/finance-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    financeEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @param  array<string, mixed>  $filters
 */
function listAccountsAs(?string $actorId, array $filters = [], mixed $pageSize = null, mixed $afterCode = null, mixed $afterId = null): Page
{
    /** @var ListAccountsHandler $handler */
    $handler = app(ListAccountsHandler::class);

    return $handler->handle(new ListAccounts($filters, $pageSize, $afterCode, $afterId, $actorId));
}

/**
 * @return list<string>
 */
function listedCodes(Page $page): array
{
    return array_map(static fn (array $row): string => (string) $row['code'], $page->rows);
}

function viewer(): string
{
    return FinanceSeed::actor('viewer', 'finance.accounts.view');
}

it('lists live accounts ordered by code then id, excluding soft-deleted rows, 50 to a page by default (AC-61)', function () {
    $actor = viewer();
    FinanceSeed::account('B2');
    FinanceSeed::account('A1');
    FinanceSeed::account('C3', ['deleted_at' => FinanceSeed::NOW]);

    $page = listAccountsAs($actor);

    expect(listedCodes($page))->toBe(['A1', 'B2'])
        ->and($page->nextAfterCode)->toBeNull()
        ->and($page->nextAfterId)->toBeNull()
        ->and($page->rows[0])->toHaveKeys(['id', 'code', 'name', 'type', 'subtype', 'parent_id', 'path', 'depth', 'normal_balance',
            'is_postable', 'is_system', 'control_type', 'is_active', 'sort_order']);
});

it('pages by the (code, id) keyset, three rows at size two (AC-61)', function () {
    $actor = viewer();
    FinanceSeed::account('A1');
    FinanceSeed::account('B2');
    FinanceSeed::account('C3');

    $first = listAccountsAs($actor, [], 2);

    expect(listedCodes($first))->toBe(['A1', 'B2'])
        ->and($first->nextAfterCode)->toBe('B2')
        ->and($first->nextAfterId)->toBe($first->rows[1]['id']);

    $second = listAccountsAs($actor, [], 2, $first->nextAfterCode, $first->nextAfterId);

    expect(listedCodes($second))->toBe(['C3'])->and($second->nextAfterCode)->toBeNull();
});

it('breaks a code tie by id, and never repeats or skips a row across the page edge', function () {
    $actor = viewer();
    $ids = [FinanceSeed::account('A1'), FinanceSeed::account('A2'), FinanceSeed::account('A3')];

    // Same code is impossible (unique), so the tie is exercised on the id
    // half of the cursor: a cursor on (A1, id) must still return A2 and A3.
    $page = listAccountsAs($actor, [], 50, 'A1', $ids[0]);

    expect(listedCodes($page))->toBe(['A2', 'A3']);
});

it('refuses a page size outside 1 to 200, and one that is not an integer (AC-61)', function (mixed $size) {
    FinanceRefusals::expect(FinanceRefusal::PageSizeInvalid, fn () => listAccountsAs(viewer(), [], $size));
})->with([[0], [201], [-1], ['50'], [1.0], [true]]);

it('accepts the page size limits 1 and 200', function () {
    $actor = viewer();
    FinanceSeed::account('A1');

    expect(listAccountsAs($actor, [], 1)->rows)->toHaveCount(1)
        ->and(listAccountsAs($actor, [], 200)->rows)->toHaveCount(1);
});

it('refuses a half cursor and a malformed one (AC-61)', function () {
    $actor = viewer();
    $id = FinanceSeed::account('A1');

    FinanceRefusals::expect(FinanceRefusal::CursorInvalid, fn () => listAccountsAs($actor, [], null, 'A1', null));
    FinanceRefusals::expect(FinanceRefusal::CursorInvalid, fn () => listAccountsAs($actor, [], null, null, $id));
    FinanceRefusals::expect(FinanceRefusal::CursorInvalid, fn () => listAccountsAs($actor, [], null, 'A 1', $id));
    FinanceRefusals::expect(FinanceRefusal::CursorInvalid, fn () => listAccountsAs($actor, [], null, 'A1', 'nope'));
});

it('filters by each documented field, and refuses a case variant or an unknown filter (AC-61, AC-5)', function () {
    $actor = viewer();
    $root = FinanceSeed::header('H1', ['type' => 'liability', 'normal_balance' => 'credit']);
    FinanceSeed::account('L1', ['type' => 'liability', 'normal_balance' => 'credit', 'subtype' => 'payable', 'parent_id' => $root, 'control_type' => 'ap']);
    FinanceSeed::account('A1', ['subtype' => 'cash', 'is_active' => false]);
    FinanceSeed::account('A2', ['subtype' => 'bank']);

    expect(listedCodes(listAccountsAs($actor, ['type' => 'liability'])))->toBe(['H1', 'L1'])
        ->and(listedCodes(listAccountsAs($actor, ['subtype' => 'cash'])))->toBe(['A1'])
        ->and(listedCodes(listAccountsAs($actor, ['parent_id' => strtolower($root)])))->toBe(['L1'])
        ->and(listedCodes(listAccountsAs($actor, ['parent_id' => null])))->toBe(['A1', 'A2', 'H1'])
        ->and(listedCodes(listAccountsAs($actor, ['is_active' => false])))->toBe(['A1'])
        ->and(listedCodes(listAccountsAs($actor, ['is_postable' => false])))->toBe(['H1'])
        ->and(listedCodes(listAccountsAs($actor, ['control_type' => 'ap'])))->toBe(['L1'])
        ->and(listedCodes(listAccountsAs($actor, ['type' => 'asset', 'is_active' => true])))->toBe(['A2']);

    foreach ([['type' => 'Asset'], ['type' => ' asset'], ['is_active' => 'true'], ['control_type' => 'AP'], ['bogus' => 1], ['parent_id' => 'x']] as $bad) {
        FinanceRefusals::expect(FinanceRefusal::Validation, fn () => listAccountsAs($actor, $bad), json_encode($bad) ?: '');
    }
});

it('searches code and name with LIKE wildcards escaped, so a percent matches only a literal percent (AC-61)', function () {
    $actor = viewer();
    FinanceSeed::account('A1', ['name' => '100% cash']);
    FinanceSeed::account('A2', ['name' => 'Petty_cash']);
    FinanceSeed::account('A3', ['name' => 'Petty cash']);
    FinanceSeed::account('XY9', ['name' => 'Other']);

    expect(listedCodes(listAccountsAs($actor, ['q' => '%'])))->toBe(['A1'])
        ->and(listedCodes(listAccountsAs($actor, ['q' => '_'])))->toBe(['A2'])
        ->and(listedCodes(listAccountsAs($actor, ['q' => 'petty'])))->toBe(['A2', 'A3'])
        ->and(listedCodes(listAccountsAs($actor, ['q' => 'xy'])))->toBe(['XY9'])
        ->and(listedCodes(listAccountsAs($actor, ['q' => '!'])))->toBe([]);

    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => listAccountsAs($actor, ['q' => '']));
    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => listAccountsAs($actor, ['q' => str_repeat('a', 101)]));
});

it('reads one account, live only, and refuses an absent or soft-deleted one (AC-20 for reads)', function () {
    $actor = viewer();
    $id = FinanceSeed::account('A1');
    $dead = FinanceSeed::account('A2', ['deleted_at' => FinanceSeed::NOW]);

    /** @var GetAccountHandler $handler */
    $handler = app(GetAccountHandler::class);

    expect($handler->handle(new GetAccount(strtolower($id), $actor)))->toMatchArray(['id' => $id, 'code' => 'A1']);

    FinanceRefusals::expect(FinanceRefusal::AccountNotFound, fn () => $handler->handle(new GetAccount($dead, $actor)));
    FinanceRefusals::expect(FinanceRefusal::AccountNotFound, fn () => $handler->handle(new GetAccount((string) Str::ulid(), $actor)));
    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => $handler->handle(new GetAccount('nope', $actor)));
});

it('returns the whole live chart parents before children, in one query regardless of size (AC-61, NFR)', function () {
    $actor = viewer();

    /** @var GetAccountTreeHandler $handler */
    $handler = app(GetAccountTreeHandler::class);

    $statements = function (int $roots) use ($actor, $handler): int {
        $connection = FinanceSeed::connection();
        $connection->table('accounts')->delete();

        for ($i = 0; $i < $roots; $i++) {
            $root = FinanceSeed::header(sprintf('R%03d', $i));
            $mid = FinanceSeed::header(sprintf('M%03d', $i), ['parent_id' => $root]);
            FinanceSeed::account(sprintf('L%03d', $i), ['parent_id' => $mid]);
        }

        $count = 0;
        $connection->listen(static function () use (&$count): void {
            $count++;
        });

        $tree = $handler->handle(new GetAccountTree($actor));

        expect($tree)->toHaveCount($roots * 3);

        return $count;
    };

    $small = $statements(3);
    $large = $statements(60);

    expect($large)->toBe($small);

    // Parents come before their children.
    $seen = [];

    foreach (app(GetAccountTreeHandler::class)->handle(new GetAccountTree($actor)) as $row) {
        if ($row['parent_id'] !== null) {
            expect($seen)->toHaveKey($row['parent_id']);
        }

        $seen[$row['id']] = true;
    }
});

it('shows a created account in the list, the tree and the single read (AC-1 read half)', function () {
    $admin = FinanceSeed::admin();

    /** @var CreateAccountHandler $create */
    $create = app(CreateAccountHandler::class);
    $id = $create->handle(new CreateAccount(['code' => 'ab1', 'name' => 'Cash', 'type' => 'asset'], $admin))->accountId;

    expect(array_column(listAccountsAs($admin)->rows, 'id'))->toBe([$id])
        ->and(array_column(app(GetAccountTreeHandler::class)->handle(new GetAccountTree($admin)), 'id'))->toBe([$id]);
});

it('refuses every read to an actor holding the other view key or none (AC-64, D-24)', function () {
    $periodsOnly = FinanceSeed::actor('periods-viewer', 'finance.periods.view');
    $id = FinanceSeed::account('A1');

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => listAccountsAs($periodsOnly));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => listAccountsAs(null));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => app(GetAccountHandler::class)->handle(new GetAccount($id, $periodsOnly)));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => app(GetAccountTreeHandler::class)->handle(new GetAccountTree($periodsOnly)));
});
