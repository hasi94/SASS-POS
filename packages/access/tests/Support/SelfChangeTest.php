<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Features\AssignRole\AssignRole;
use Flexipos\Access\Features\AssignRole\AssignRoleHandler;
use Flexipos\Access\Features\AttachBranch\AttachBranch;
use Flexipos\Access\Features\AttachBranch\AttachBranchHandler;
use Flexipos\Access\Features\ClearEmployeePermission\ClearEmployeePermission;
use Flexipos\Access\Features\ClearEmployeePermission\ClearEmployeePermissionHandler;
use Flexipos\Access\Features\DetachBranch\DetachBranch;
use Flexipos\Access\Features\DetachBranch\DetachBranchHandler;
use Flexipos\Access\Features\RemoveRoleAssignment\RemoveRoleAssignment;
use Flexipos\Access\Features\RemoveRoleAssignment\RemoveRoleAssignmentHandler;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermission;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermissionHandler;
use Flexipos\Contracts\Access\RefusalReason;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 14 - nobody changes their own access (AC-34)
|--------------------------------------------------------------------------
|
| Every one of the six operations is exercised as a PAIR: the identical
| request, with the identical fixture, is refused when the actor is the
| subject and succeeds when it is somebody else. That pairing is the whole
| test. `self_change` shares a status with `not_attached`, `no_escalation`
| and three more, and this step alone adds three guards that can refuse the
| same call - so a case asserting that something was refused proves only
| that one of them fired, and a case asserting a refusal without its
| control cannot tell a working guard from a broken operation.
|
| The reason is asserted too, not merely the fact of refusal.
|
| The last case is a STATIC sweep and it closes the gap a list of six
| cannot: it reads which handlers actually call the guard and compares that
| against the operations covered here, so a seventh operation arriving with
| no rule - or with a rule and no test - is red rather than silent.
|
| Setting one's own PIN is deliberately NOT one of these (AC-47). It is a
| credential, not a grant. `SetPin` is step 15's successor's work and the
| absence of a case here is the decision, recorded.
|
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
 * One fixture per case: a key, a role holding it, a privileged other
 * actor, and a subject with a grant of each kind already in place.
 *
 * @return array<string, string>
 */
function selfChangeWorld(string $suffix): array
{
    $key = AccessSeed::permission("fixture.key_{$suffix}");

    $ids = [
        'key' => $key,
        'role' => AccessSeed::role("role_{$suffix}", [$key]),
        'branch' => AccessSeed::branch(),
        'spare' => AccessSeed::branch(),
    ];

    $ids['subject'] = AccessSeed::employee("subject-{$suffix}");
    $ids['other'] = AccessSeed::actorHolding("other-{$suffix}", [$key]);

    AccessSeed::attach($ids['subject'], $ids['branch']);

    return $ids;
}

/**
 * The six operations AC-34 names, each as "do this to that employee".
 *
 * Keyed by the handler that carries the rule, because the sweep at the
 * bottom of this file compares these keys against the handlers that
 * actually call it.
 *
 * @return array<class-string, callable(array<string, string>, string, ?string): void>
 */
function selfChangeOperations(): array
{
    return [
        AssignRoleHandler::class => static function (array $ids, string $subject, ?string $actor): void {
            /** @var AssignRoleHandler $handler */
            $handler = app(AssignRoleHandler::class);
            $handler->handle(new AssignRole($subject, $ids['role'], null, $actor));
        },
        RemoveRoleAssignmentHandler::class => static function (array $ids, string $subject, ?string $actor): void {
            /** @var RemoveRoleAssignmentHandler $handler */
            $handler = app(RemoveRoleAssignmentHandler::class);
            $handler->handle(new RemoveRoleAssignment($subject, $ids['seededRole'], null, $actor));
        },
        SetEmployeePermissionHandler::class => static function (array $ids, string $subject, ?string $actor): void {
            /** @var SetEmployeePermissionHandler $handler */
            $handler = app(SetEmployeePermissionHandler::class);
            $handler->handle(new SetEmployeePermission($subject, $ids['key'], true, null, $actor));
        },
        ClearEmployeePermissionHandler::class => static function (array $ids, string $subject, ?string $actor): void {
            /** @var ClearEmployeePermissionHandler $handler */
            $handler = app(ClearEmployeePermissionHandler::class);
            $handler->handle(new ClearEmployeePermission($subject, $ids['key'], null, $actor));
        },
        AttachBranchHandler::class => static function (array $ids, string $subject, ?string $actor): void {
            /** @var AttachBranchHandler $handler */
            $handler = app(AttachBranchHandler::class);
            $handler->handle(new AttachBranch($subject, $ids['spare'], false, $actor));
        },
        DetachBranchHandler::class => static function (array $ids, string $subject, ?string $actor): void {
            /** @var DetachBranchHandler $handler */
            $handler = app(DetachBranchHandler::class);
            $handler->handle(new DetachBranch($subject, $ids['branch'], $actor));
        },
    ];
}

/**
 * What each operation needs already in place before it can succeed.
 *
 * @return array<class-string, callable(array<string, string>, string): void>
 */
function selfChangePreconditions(): array
{
    return [
        RemoveRoleAssignmentHandler::class => static function (array $ids, string $subject): void {
            AccessSeed::assign($subject, $ids['seededRole']);
        },
        // `ClearEmployeePermission` needs a row to clear and the loop seeds
        // one for every case already, so it has no entry here: a second
        // insert would meet the null-safe unique key.
    ];
}

it('refuses every section E operation on the actor own record, and allows the same change by another actor (AC-34)', function () {
    $operations = selfChangeOperations();
    $preconditions = selfChangePreconditions();

    expect($operations)->not->toBe([]);

    $index = 0;

    foreach ($operations as $handler => $run) {
        $suffix = (string) $index++;
        $ids = selfChangeWorld($suffix);

        // The subject needs rights of their own, because the self case must
        // fail on AC-34 rather than on AC-36: an actor who holds nothing
        // would be refused `no_escalation` first and the case would pass
        // for the wrong reason.
        $ids['seededRole'] = AccessSeed::role("seeded_{$suffix}", [$ids['key']]);
        AccessSeed::personal($ids['subject'], $ids['key'], true);

        if (isset($preconditions[$handler])) {
            $preconditions[$handler]($ids, $ids['subject']);
        }

        $refused = AccessRefusals::expect(
            RefusalReason::SelfChange,
            static fn () => $run($ids, $ids['subject'], $ids['subject'])
        );

        expect($refused->getMessage())->toContain($ids['subject']);

        // The control, and it is the half that makes the refusal mean
        // something: the identical request, the identical fixture, a
        // different actor.
        $run($ids, $ids['subject'], $ids['other']);
    }

    expect($index)->toBe(count($operations));
});

it('is not triggered when there is no actor at all, because absence is not identity (AC-34)', function () {
    $ids = selfChangeWorld('no-actor');

    AccessSeed::attach($ids['subject'], $ids['spare']);

    /** @var DetachBranchHandler $handler */
    $handler = app(DetachBranchHandler::class);

    // A detach needs no actor's rights, so it is the operation that can
    // show this cleanly: with no actor the self-change rule has nothing to
    // compare and stands aside. The dangerous half of a missing actor -
    // handing out rights nobody holds - is `Escalation`'s, and its own test
    // asserts that absence is refused there.
    $handler->handle(new DetachBranch($ids['subject'], $ids['spare'], null));

    expect(AccessSeed::connection()->table('employee_branches')
        ->where('employee_id', $ids['subject'])
        ->where('branch_id', $ids['spare'])
        ->exists())->toBeFalse();
});

it('covers exactly the handlers that carry the rule, read from their source (D-27)', function () {
    $featureRoot = dirname(__DIR__, 2).'/src/Features';
    $carriers = [];

    /** @var iterable<\SplFileInfo> $files */
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($featureRoot));

    foreach ($files as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (! str_contains($source, 'SelfChange::refuse')) {
            continue;
        }

        $slice = basename(dirname($file->getPathname()));
        $carriers[] = 'Flexipos\\Access\\Features\\'.$slice.'\\'.$file->getBasename('.php');
    }

    sort($carriers);

    $covered = array_keys(selfChangeOperations());
    sort($covered);

    // Printed, so the report says what was examined rather than that
    // something was.
    expect($carriers)->not->toBe([])
        ->and($carriers)->toBe($covered, 'handlers calling SelfChange::refuse: '.implode(', ', $carriers));
});
