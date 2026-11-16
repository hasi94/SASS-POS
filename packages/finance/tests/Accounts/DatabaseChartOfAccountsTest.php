<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Accounts;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\AccountKind;
use Flexipos\Contracts\Finance\AccountRefusal;
use Flexipos\Contracts\Finance\AccountRefused;
use Flexipos\Contracts\Finance\AccountSnapshot;
use Flexipos\Contracts\Finance\AccountSubtype;
use Flexipos\Contracts\Finance\AccountType;
use Flexipos\Contracts\Finance\ChartOfAccounts;
use Flexipos\Contracts\Finance\ControlType;
use Flexipos\Contracts\Finance\NormalBalance;
use Flexipos\Finance\Accounts\DatabaseChartOfAccounts;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use ReflectionProperty;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 12 - the published ChartOfAccounts (AC-55, AC-56, AC-63)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/finance-fixture.php';

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

function chart(): ChartOfAccounts
{
    return app(ChartOfAccounts::class);
}

/**
 * @param  callable(): mixed  $operation
 */
function accountRefusal(AccountRefusal $reason, callable $operation): AccountRefused
{
    try {
        $operation();
    } catch (AccountRefused $refused) {
        Assert::assertSame($reason, $refused->reason, $refused->getMessage());

        return $refused;
    }

    Assert::fail('The operation was expected to be refused and succeeded.');
}

it('resolves the contract from the container to the database implementation', function () {
    expect(chart())->toBeInstanceOf(DatabaseChartOfAccounts::class);
});

it('returns an immutable snapshot by id, by code (case-folded) and by control type (AC-63)', function () {
    $id = FinanceSeed::account('AR1', [
        'name' => 'Receivables', 'subtype' => 'receivable', 'control_type' => 'ar', 'is_system' => true,
    ]);

    $byId = chart()->byId(strtolower($id));

    expect($byId)->toBeInstanceOf(AccountSnapshot::class)
        ->and($byId->id)->toBe($id)
        ->and($byId->code)->toBe('AR1')
        ->and($byId->name)->toBe('Receivables')
        ->and($byId->type)->toBe(AccountType::Asset)
        ->and($byId->subtype)->toBe(AccountSubtype::Receivable)
        ->and($byId->normalBalance)->toBe(NormalBalance::Debit)
        ->and($byId->isPostable)->toBeTrue()
        ->and($byId->isActive)->toBeTrue()
        ->and($byId->controlType)->toBe(ControlType::Ar)
        ->and(chart()->byCode('ar1'))->toEqual($byId)
        ->and(chart()->byControlType(ControlType::Ar))->toEqual([$byId])
        ->and(chart()->byControlType(ControlType::Bank))->toBe([]);

    // Immutable: every property is readonly.
    foreach ((new \ReflectionClass(AccountSnapshot::class))->getProperties() as $property) {
        expect($property->isReadOnly())->toBeTrue();
    }
});

it('refuses by id and by code with the contract reasons (AC-63)', function () {
    $dead = FinanceSeed::account('D1', ['deleted_at' => FinanceSeed::NOW]);

    accountRefusal(AccountRefusal::AccountNotFound, fn () => chart()->byId((string) Str::ulid()));
    accountRefusal(AccountRefusal::AccountNotFound, fn () => chart()->byId('nope'));
    accountRefusal(AccountRefusal::AccountNotLive, fn () => chart()->byId($dead));
    accountRefusal(AccountRefusal::AccountNotFound, fn () => chart()->byCode('ZZ9'));
    accountRefusal(AccountRefusal::AccountNotLive, fn () => chart()->byCode('d1'));
    accountRefusal(AccountRefusal::InvalidCode, fn () => chart()->byCode(' D1'));
});

it('lists only live accounts of a control type, ordered by code', function () {
    $b = FinanceSeed::account('B1', ['subtype' => 'bank', 'control_type' => 'bank']);
    $a = FinanceSeed::account('A1', ['subtype' => 'bank', 'control_type' => 'bank']);
    FinanceSeed::account('C1', ['subtype' => 'bank', 'control_type' => 'bank', 'deleted_at' => FinanceSeed::NOW]);

    expect(array_map(static fn (AccountSnapshot $s): string => $s->id, chart()->byControlType(ControlType::Bank)))->toBe([$a, $b]);
});

it('lets postable() through for a live, active, postable account and refuses the rest in order (AC-63)', function () {
    $ok = FinanceSeed::account('A1');
    $dead = FinanceSeed::account('A2', ['deleted_at' => FinanceSeed::NOW, 'is_active' => false, 'is_postable' => false]);
    $inactive = FinanceSeed::account('A3', ['is_active' => false, 'is_postable' => false]);
    $header = FinanceSeed::header('A4');

    expect(chart()->postable($ok)->id)->toBe($ok);

    accountRefusal(AccountRefusal::AccountNotFound, fn () => chart()->postable((string) Str::ulid()));
    accountRefusal(AccountRefusal::AccountNotLive, fn () => chart()->postable($dead));
    accountRefusal(AccountRefusal::AccountInactive, fn () => chart()->postable($inactive));
    accountRefusal(AccountRefusal::AccountNotPostable, fn () => chart()->postable($header));
});

it('reads under a shared lock when a transaction is open, and plainly when none is (AC-57)', function () {
    $id = FinanceSeed::account('A1');
    $connection = FinanceSeed::connection();

    $locked = static function (callable $operation) use ($connection): array {
        $original = $connection->getQueryGrammar();
        $connection->setQueryGrammar(new MySqlGrammar($connection));

        try {
            return array_column($connection->pretend($operation), 'query');
        } finally {
            $connection->setQueryGrammar($original);
        }
    };

    $outside = $locked(function () use ($id): void {
        try {
            chart()->postable($id);
        } catch (AccountRefused) {
            // pretend() returns no rows; only the statement matters.
        }
    });

    $inside = $locked(function () use ($id, $connection): void {
        $connection->transaction(function () use ($id): void {
            try {
                chart()->postable($id);
            } catch (AccountRefused) {
            }
        });
    });

    $reads = static fn (array $log): array => array_values(array_filter($log, static fn (string $sql): bool => str_contains($sql, 'from `accounts`')));

    expect($reads($outside))->not->toBe([])
        ->and($reads($outside)[0])->not->toContain('lock in share mode')
        ->and($reads($inside)[0])->toEndWith(' lock in share mode');
});

it('refuses assignable() outside a transaction with a LogicException (AC-57)', function () {
    $id = FinanceSeed::account('A1');

    expect(fn () => chart()->assignable('income_account_id', $id, AccountKind::Income))->toThrow(\LogicException::class);
});

it('checks an assignable account in AC-55 order, naming the field, and reads it under a shared lock (AC-55 to AC-57)', function () {
    $income = FinanceSeed::account('R1', ['type' => 'revenue', 'normal_balance' => 'credit', 'subtype' => 'sales']);
    $dead = FinanceSeed::account('R2', ['type' => 'revenue', 'normal_balance' => 'credit', 'deleted_at' => FinanceSeed::NOW]);
    $inactive = FinanceSeed::account('R3', ['type' => 'revenue', 'normal_balance' => 'credit', 'is_active' => false]);
    $header = FinanceSeed::header('R4', ['type' => 'revenue', 'normal_balance' => 'credit']);
    $asset = FinanceSeed::account('A1');

    FinanceSeed::connection()->transaction(function () use ($income, $dead, $inactive, $header, $asset): void {
        expect(chart()->assignable('income_account_id', strtolower($income), AccountKind::Income)->id)->toBe($income);

        $cases = [
            [AccountRefusal::AccountNotFound, (string) Str::ulid()],
            [AccountRefusal::AccountNotFound, 'nope'],
            [AccountRefusal::AccountNotLive, $dead],
            [AccountRefusal::AccountInactive, $inactive],
            [AccountRefusal::AccountNotPostable, $header],
            [AccountRefusal::AccountKindMismatch, $asset],
        ];

        foreach ($cases as [$reason, $id]) {
            $refused = accountRefusal($reason, fn () => chart()->assignable('income_account_id', $id, AccountKind::Income));
            expect($refused->field)->toBe('income_account_id');
        }
    });
});

it('applies the AC-56 kind table through assignable()', function (string $field, AccountKind $kind, array $account, bool $accepted) {
    $id = FinanceSeed::account('K1', $account);

    FinanceSeed::connection()->transaction(function () use ($field, $kind, $id, $accepted): void {
        if ($accepted) {
            expect(chart()->assignable($field, $id, $kind)->id)->toBe($id);

            return;
        }

        accountRefusal(AccountRefusal::AccountKindMismatch, fn () => chart()->assignable($field, $id, $kind));
    });
})->with([
    'income accepts revenue' => ['income_account_id', AccountKind::Income, ['type' => 'revenue', 'normal_balance' => 'credit'], true],
    'income refuses expense' => ['income_account_id', AccountKind::Income, ['type' => 'expense'], false],
    'cogs accepts expense cogs' => ['cogs_account_id', AccountKind::Cogs, ['type' => 'expense', 'subtype' => 'cogs'], true],
    'cogs refuses other expense' => ['cogs_account_id', AccountKind::Cogs, ['type' => 'expense', 'subtype' => 'operating_expense'], false],
    'inventory accepts asset inventory' => ['inventory_account_id', AccountKind::Inventory, ['subtype' => 'inventory'], true],
    'inventory refuses cash' => ['inventory_account_id', AccountKind::Inventory, ['subtype' => 'cash'], false],
    'receivable accepts asset receivable' => ['receivable_account_id', AccountKind::Receivable, ['subtype' => 'receivable'], true],
    'receivable refuses an untyped asset' => ['receivable_account_id', AccountKind::Receivable, [], false],
]);

it('has no property that could be written after construction', function () {
    $snapshot = new AccountSnapshot(
        (string) Str::ulid(), 'A1', 'Cash', AccountType::Asset, null, NormalBalance::Debit, true, true, ControlType::None,
    );

    expect(fn () => (new ReflectionProperty($snapshot, 'name'))->setValue($snapshot, 'Other'))->toThrow(\Error::class);
});
