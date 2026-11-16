<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\ReparentAccount;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\ReparentAccount\ReparentAccount;
use Flexipos\Finance\Features\ReparentAccount\ReparentAccountHandler;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 11 - re-parenting an account (AC-10, AC-13, AC-18, AC-20)
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

function reparentAccountAs(?string $actorId, string $accountId, ?string $newParentId): void
{
    /** @var ReparentAccountHandler $handler */
    $handler = app(ReparentAccountHandler::class);

    $handler->handle(new ReparentAccount($accountId, $newParentId, $actorId));
}

/**
 * Two roots (A and B) and, under A, a three-level subtree: M, its child N and
 * N's child O.
 *
 * @return array{a: string, b: string, m: string, n: string, o: string}
 */
function reparentChart(): array
{
    $a = FinanceSeed::header('A');
    $b = FinanceSeed::header('B');
    $m = FinanceSeed::header('M', ['parent_id' => $a]);
    $n = FinanceSeed::header('N', ['parent_id' => $m]);
    $o = FinanceSeed::account('O', ['parent_id' => $n]);

    return compact('a', 'b', 'm', 'n', 'o');
}

it('moves a subtree and rewrites every descendant\'s path and depth in one transaction (AC-13)', function () {
    $actor = FinanceSeed::admin();
    $c = reparentChart();

    reparentAccountAs($actor, $c['m'], $c['b']);

    expect(FinanceSeed::row($c['m']))->toMatchArray([
        'parent_id' => $c['b'], 'depth' => 1, 'path' => $c['b'].'/'.$c['m'], 'updated_by' => $actor,
    ])->and(FinanceSeed::row($c['n']))->toMatchArray([
        'parent_id' => $c['m'], 'depth' => 2, 'path' => $c['b'].'/'.$c['m'].'/'.$c['n'],
    ])->and(FinanceSeed::row($c['o']))->toMatchArray([
        'parent_id' => $c['n'], 'depth' => 3, 'path' => $c['b'].'/'.$c['m'].'/'.$c['n'].'/'.$c['o'],
    ])->and(FinanceSeed::row($c['a']))->toMatchArray(['depth' => 0, 'path' => $c['a']]);

    $audit = FinanceSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['action' => 'account_reparented', 'subject_id' => $c['m'], 'actor_id' => $actor])
        ->and($audit['before']['parent_id'])->toBe($c['a'])
        ->and($audit['after']['parent_id'])->toBe($c['b']);
});

it('moves an account to the root with a null parent and rewrites its subtree upward', function () {
    $actor = FinanceSeed::admin();
    $c = reparentChart();

    reparentAccountAs($actor, $c['n'], null);

    expect(FinanceSeed::row($c['n']))->toMatchArray(['parent_id' => null, 'depth' => 0, 'path' => $c['n']])
        ->and(FinanceSeed::row($c['o']))->toMatchArray(['depth' => 1, 'path' => $c['n'].'/'.$c['o']]);
});

it('rewrites soft-deleted descendants too, so no path is left pointing at the old parent', function () {
    $actor = FinanceSeed::admin();
    $c = reparentChart();
    FinanceSeed::connection()->table('accounts')->where('id', $c['o'])->update(['deleted_at' => FinanceSeed::NOW]);

    reparentAccountAs($actor, $c['m'], $c['b']);

    expect(FinanceSeed::row($c['o'])['path'])->toBe($c['b'].'/'.$c['m'].'/'.$c['n'].'/'.$c['o']);
});

it('refuses a cycle: under itself, a child or a grandchild (AC-13)', function (string $target) {
    $actor = FinanceSeed::admin();
    $c = reparentChart();
    FinanceSeed::connection()->table('accounts')->where('id', $c['o'])->update(['is_postable' => false]);
    $before = FinanceSeed::snapshot();

    FinanceRefusals::expect(FinanceRefusal::Cycle, fn () => reparentAccountAs($actor, $c['m'], $c[$target]));

    expect(FinanceSeed::snapshot())->toBe($before)->and(FinanceSeed::audit())->toBe([]);
})->with(['m', 'n', 'o']);

it('refuses a move that would push the deepest descendant past the limit, and a parent at depth 9 (AC-11, AC-13)', function () {
    $actor = FinanceSeed::admin();
    $c = reparentChart();

    // Subtree M-N-O has height 2; a parent at depth 8 gives O depth 11.
    $deep = FinanceSeed::header('D8', ['depth' => 8]);
    FinanceRefusals::expect(FinanceRefusal::DepthExceeded, fn () => reparentAccountAs($actor, $c['m'], $deep));

    // A single leaf fits under depth 8 but not under depth 9.
    $leaf = FinanceSeed::account('LEAF');
    $deep9 = FinanceSeed::header('D9', ['depth' => 9]);

    reparentAccountAs($actor, $leaf, $deep);
    expect(FinanceSeed::row($leaf)['depth'])->toBe(9);

    FinanceRefusals::expect(FinanceRefusal::DepthExceeded, fn () => reparentAccountAs($actor, $leaf, $deep9));
});

it('checks the new parent in AC-10 order', function (string $case, FinanceRefusal $reason) {
    $actor = FinanceSeed::admin();
    $c = reparentChart();

    $parent = match ($case) {
        'not_found' => (string) Str::ulid(),
        'not_live' => FinanceSeed::header('P1', ['deleted_at' => FinanceSeed::NOW]),
        'inactive' => FinanceSeed::header('P1', ['is_active' => false]),
        'not_header' => FinanceSeed::account('P1'),
        'type_mismatch' => FinanceSeed::header('P1', ['type' => 'liability', 'normal_balance' => 'credit']),
        default => throw new \InvalidArgumentException($case),
    };

    $before = FinanceSeed::snapshot();

    FinanceRefusals::expect($reason, fn () => reparentAccountAs($actor, $c['m'], $parent));

    expect(FinanceSeed::snapshot())->toBe($before)->and(FinanceSeed::audit())->toBe([]);
})->with([
    ['not_found', FinanceRefusal::ParentNotFound],
    ['not_live', FinanceRefusal::ParentNotLive],
    ['inactive', FinanceRefusal::ParentInactive],
    ['not_header', FinanceRefusal::ParentNotHeader],
    ['type_mismatch', FinanceRefusal::ParentTypeMismatch],
]);

it('refuses to re-parent a system account (AC-18)', function () {
    $actor = FinanceSeed::admin();
    $a = FinanceSeed::header('A');
    $b = FinanceSeed::header('B');
    $system = FinanceSeed::account('SYS', ['parent_id' => $a, 'is_system' => true]);

    $refused = FinanceRefusals::expect(FinanceRefusal::SystemAccountImmutable, fn () => reparentAccountAs($actor, $system, $b));

    expect($refused->context['fields'])->toBe(['parent_id'])
        ->and(FinanceSeed::row($system)['parent_id'])->toBe($a);
});

it('refuses an unknown or soft-deleted account account_not_live (AC-20)', function () {
    $actor = FinanceSeed::admin();
    $b = FinanceSeed::header('B');
    $dead = FinanceSeed::account('DEAD', ['deleted_at' => FinanceSeed::NOW]);

    FinanceRefusals::expect(FinanceRefusal::AccountNotLive, fn () => reparentAccountAs($actor, $dead, $b));
    FinanceRefusals::expect(FinanceRefusal::AccountNotLive, fn () => reparentAccountAs($actor, (string) Str::ulid(), $b));
    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => reparentAccountAs($actor, 'nope', $b));
    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => reparentAccountAs($actor, $b, 'nope'));
});

it('refuses an actor without the manage key', function () {
    $viewer = FinanceSeed::actor('viewer', 'finance.accounts.view');
    $c = reparentChart();

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => reparentAccountAs($viewer, $c['m'], $c['b']));

    expect(FinanceSeed::row($c['m'])['parent_id'])->toBe($c['a']);
});
