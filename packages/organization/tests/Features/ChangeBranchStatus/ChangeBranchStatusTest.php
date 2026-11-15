<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\ChangeBranchStatus;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Features\ChangeBranchStatus\ChangeBranchStatus;
use Flexipos\Organization\Features\ChangeBranchStatus\ChangeBranchStatusHandler;
use Flexipos\Organization\Support\LastBranch;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 8 - deactivating and reactivating a branch (AC-14, AC-20)
|--------------------------------------------------------------------------
|
| Every refusal has its control from AC-14: a branch with only a revoked
| terminal deactivates; with a second active branch present the first
| deactivates; after the terminal is deactivated the branch deactivates. The
| terminal statuses are a table, one branch each, so "pending and active
| block, inactive and revoked do not" is four cases rather than a sentence.
|
| The last-branch guard's concurrency half is [ENGINE] (AC-16) and is NOT
| VERIFIED here; what is asserted is the sequential case and, from the
| captured statements, that the active branches are read `for update`, with
| the stand-in's grammar swapped for MySQL's through `pretend()`.
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

function branchStatus(string $branchId, mixed $isActive, ?string $actorId = null): void
{
    /** @var ChangeBranchStatusHandler $handler */
    $handler = app(ChangeBranchStatusHandler::class);

    $handler->handle(new ChangeBranchStatus($branchId, $isActive, $actorId));
}

function branchIsActive(string $branchId): bool
{
    return (bool) OrganizationSeed::connection()->table('branches')->where('id', $branchId)->value('is_active');
}

/**
 * Two active branches, each with a store.
 *
 * @return array{first: string, second: string, store: string, actor: string}
 */
function branchStatusWorld(): array
{
    $first = OrganizationSeed::branch();
    $second = OrganizationSeed::branch();

    return [
        'first' => $first,
        'second' => $second,
        'store' => OrganizationSeed::location($first, 'S1', 'branch_store', ['is_sellable_from' => true]),
        'actor' => AccessSeed::employee('status_actor', ['display_name' => 'Status Person']),
    ];
}

it('deactivates, then reactivates, with one audit row each, whole, and no employee version change (AC-14, AC-20)', function () {
    $ids = branchStatusWorld();
    $version = AccessSeed::permVersion($ids['actor']);

    branchStatus($ids['first'], false, $ids['actor']);

    expect(branchIsActive($ids['first']))->toBeFalse()
        ->and(OrganizationSeed::onlyAuditRow())->toBe([
            'actor_type' => 'employee',
            'actor_id' => $ids['actor'],
            'actor_name' => 'Status Person',
            'branch_id' => $ids['first'],
            'module' => 'organization',
            'action' => 'branch_deactivated',
            'subject_type' => 'branch',
            'subject_id' => $ids['first'],
            'before' => ['is_active' => true],
            'after' => ['is_active' => false],
            'changed_fields' => ['is_active'],
            'user_agent' => null,
            'request_id' => null,
        ]);

    OrganizationSeed::connection()->table('audit_logs')->delete();

    branchStatus($ids['first'], true, $ids['actor']);

    expect(branchIsActive($ids['first']))->toBeTrue()
        ->and(OrganizationSeed::onlyAuditRow()['action'])->toBe('branch_reactivated')
        ->and(AccessSeed::permVersion($ids['actor']))->toBe($version);
});

it('refuses a state it is already in as validation and writes no audit row, either way (AC-14)', function () {
    $ids = branchStatusWorld();

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => branchStatus($ids['first'], true, $ids['actor']), 'activate an active branch');

    branchStatus($ids['first'], false, $ids['actor']);
    OrganizationSeed::connection()->table('audit_logs')->delete();

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => branchStatus($ids['first'], false, $ids['actor']), 'deactivate an inactive branch');

    expect(OrganizationSeed::audit())->toBe([]);
});

it('refuses a non-boolean state and an unknown branch (AC-14)', function () {
    $ids = branchStatusWorld();

    foreach (['false', 0, null, ['x']] as $bad) {
        OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => branchStatus($ids['first'], $bad, $ids['actor']));
    }

    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => branchStatus('01ARZ3NDEKTSV4RRFFQ69G5FAV', false, $ids['actor']));

    expect(branchIsActive($ids['first']))->toBeTrue();
});

it('is blocked by a pending or active terminal and not by an inactive or revoked one (AC-14)', function (string $status, bool $blocks) {
    $ids = branchStatusWorld();
    OrganizationSeed::terminal($ids['first'], $ids['store'], 'T1', $status);

    if ($blocks) {
        OrganizationSeed::refusal(OrganizationRefusal::HasActiveTerminals, fn () => branchStatus($ids['first'], false, $ids['actor']));

        expect(branchIsActive($ids['first']))->toBeTrue()
            ->and(OrganizationSeed::audit())->toBe([]);

        return;
    }

    branchStatus($ids['first'], false, $ids['actor']);

    expect(branchIsActive($ids['first']))->toBeFalse();
})->with([
    'pending' => ['pending', true],
    'active' => ['active', true],
    'inactive' => ['inactive', false],
    'revoked' => ['revoked', false],
]);

it('deactivates a branch once its only terminal has been paused (AC-14 control)', function () {
    $ids = branchStatusWorld();
    $terminal = OrganizationSeed::terminal($ids['first'], $ids['store'], 'T1', 'active');

    OrganizationSeed::refusal(OrganizationRefusal::HasActiveTerminals, fn () => branchStatus($ids['first'], false, $ids['actor']));

    OrganizationSeed::connection()->table('terminals')->where('id', $terminal)->update(['status' => 'inactive']);

    branchStatus($ids['first'], false, $ids['actor']);

    expect(branchIsActive($ids['first']))->toBeFalse();
});

it('refuses the last active branch, and deactivates one when a second is active (AC-14)', function () {
    $ids = branchStatusWorld();

    // The control first: with a second active branch the first deactivates.
    branchStatus($ids['first'], false, $ids['actor']);

    expect(branchIsActive($ids['first']))->toBeFalse();

    OrganizationSeed::connection()->table('audit_logs')->delete();

    // Now the second is the only active one.
    OrganizationSeed::refusal(OrganizationRefusal::LastBranch, fn () => branchStatus($ids['second'], false, $ids['actor']));

    expect(branchIsActive($ids['second']))->toBeTrue()
        ->and(OrganizationSeed::audit())->toBe([]);

    // An inactive branch does not count as "another one".
    OrganizationSeed::branch(false);

    OrganizationSeed::refusal(OrganizationRefusal::LastBranch, fn () => branchStatus($ids['second'], false, $ids['actor']), 'an inactive third');

    // Reactivating the first makes the second deactivatable again.
    branchStatus($ids['first'], true, $ids['actor']);
    branchStatus($ids['second'], false, $ids['actor']);

    expect(branchIsActive($ids['second']))->toBeFalse();
});

it('reads every active branch for update on the MySQL grammar, the one being deactivated included (AC-16, source half)', function () {
    $ids = branchStatusWorld();

    $connection = OrganizationSeed::connection();

    // The stand-in compiles no lock clause, so the statement is compiled with
    // MySQL's grammar and recorded under pretend(): nothing executes, which is
    // also why the guard sees no rows. What is asserted is the statement it
    // would send the engine.
    $standIn = $connection->getQueryGrammar();
    $connection->setQueryGrammar(new MySqlGrammar($connection));

    try {
        // Pretending returns no rows, so the guard refuses; the refusal is
        // caught inside so the recorded statement survives it.
        $log = $connection->pretend(static function () use ($connection, $ids): void {
            try {
                LastBranch::requireAnother($connection, $ids['first']);
            } catch (OrganizationRefused) {
                // Expected under pretend().
            }
        });
    } finally {
        $connection->setQueryGrammar($standIn);
    }

    $statements = array_map(static fn (array $entry): string => (string) $entry['query'], $log);

    fwrite(STDOUT, "\nLastBranch statements (MySQL grammar):\n".implode("\n", $statements)."\n");

    // One statement, over `branches`, locking, and not excluding the branch
    // being deactivated: excluding it would let two requests lock disjoint rows.
    expect($statements)->toHaveCount(1)
        ->and($statements[0])->toStartWith('select')
        ->and($statements[0])->toContain('`branches`')
        ->and($statements[0])->not->toContain('!=')
        ->and($statements[0])->toEndWith(' for update');

    // The liveness control: on the stand-in's own grammar the same guard
    // answers - two active branches, so it permits.
    LastBranch::requireAnother($connection, $ids['first']);

    expect(branchIsActive($ids['first']))->toBeTrue();
});
