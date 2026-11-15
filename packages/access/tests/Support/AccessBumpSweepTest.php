<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Features\ArchiveAuditLogs\ArchiveAuditLogs;
use Flexipos\Access\Features\ArchiveAuditLogs\ArchiveAuditLogsHandler;
use Flexipos\Access\Features\AssignRole\AssignRole;
use Flexipos\Access\Features\AssignRole\AssignRoleHandler;
use Flexipos\Access\Features\AttachBranch\AttachBranch;
use Flexipos\Access\Features\AttachBranch\AttachBranchHandler;
use Flexipos\Access\Features\BootstrapOwner\BootstrapOwnerHandler;
use Flexipos\Access\Features\BuildEmployeeClaims\BuildEmployeeClaims;
use Flexipos\Access\Features\BuildEmployeeClaims\BuildEmployeeClaimsHandler;
use Flexipos\Access\Features\ChangeEmployeeStatus\ChangeEmployeeStatus;
use Flexipos\Access\Features\ChangeEmployeeStatus\ChangeEmployeeStatusHandler;
use Flexipos\Access\Features\ClearEmployeePermission\ClearEmployeePermission;
use Flexipos\Access\Features\ClearEmployeePermission\ClearEmployeePermissionHandler;
use Flexipos\Access\Features\CreateEmployee\CreateEmployee;
use Flexipos\Access\Features\CreateEmployee\CreateEmployeeHandler;
use Flexipos\Access\Features\CreateRole\CreateRole;
use Flexipos\Access\Features\CreateRole\CreateRoleHandler;
use Flexipos\Access\Features\DeleteEmployee\DeleteEmployee;
use Flexipos\Access\Features\DeleteEmployee\DeleteEmployeeHandler;
use Flexipos\Access\Features\DeleteRole\DeleteRole;
use Flexipos\Access\Features\DeleteRole\DeleteRoleHandler;
use Flexipos\Access\Features\DetachBranch\DetachBranch;
use Flexipos\Access\Features\DetachBranch\DetachBranchHandler;
use Flexipos\Access\Features\GetEmployee\GetEmployee;
use Flexipos\Access\Features\GetEmployee\GetEmployeeHandler;
use Flexipos\Access\Features\GetMyPermissions\GetMyPermissions;
use Flexipos\Access\Features\GetMyPermissions\GetMyPermissionsHandler;
use Flexipos\Access\Features\GetRole\GetRole;
use Flexipos\Access\Features\GetRole\GetRoleHandler;
use Flexipos\Access\Features\ListEmployees\ListEmployees;
use Flexipos\Access\Features\ListEmployees\ListEmployeesHandler;
use Flexipos\Access\Features\ListRoles\ListRoles;
use Flexipos\Access\Features\ListRoles\ListRolesHandler;
use Flexipos\Access\Features\RemoveRoleAssignment\RemoveRoleAssignment;
use Flexipos\Access\Features\RemoveRoleAssignment\RemoveRoleAssignmentHandler;
use Flexipos\Access\Features\RenameRole\RenameRole;
use Flexipos\Access\Features\RenameRole\RenameRoleHandler;
use Flexipos\Access\Features\SearchAuditLog\SearchAuditLog;
use Flexipos\Access\Features\SearchAuditLog\SearchAuditLogHandler;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermission;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermissionHandler;
use Flexipos\Access\Features\SetPin\SetPin;
use Flexipos\Access\Features\SetPin\SetPinHandler;
use Flexipos\Access\Features\SetRolePermissions\SetRolePermissions;
use Flexipos\Access\Features\SetRolePermissions\SetRolePermissionsHandler;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Features\UpdateEmployee\UpdateEmployee;
use Flexipos\Access\Features\UpdateEmployee\UpdateEmployeeHandler;
use Flexipos\Access\Features\VerifyPin\VerifyPin;
use Flexipos\Access\Features\VerifyPin\VerifyPinHandler;
use Flexipos\Access\Registry\PermissionRegistry;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Contracts\Access\PermissionResolver;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 24 - the bump sweep (AC-52, AC-53)
|--------------------------------------------------------------------------
|
| The closing sweep of the task: for every operation it ships, run it and
| compare the resolver's answer for every (employee, key, scope) cell
| before and after. Where an answer moved - or where an employee's
| `branch_ids` or `all_branches` moved - `perm_version` must have moved
| too.
|
| **The domain is the `Features/` directory and nothing else.** A list of
| operations written here would be a second declaration of what this
| package does, and the day a twenty-fourth slice arrives the list is
| what makes it invisible. So the operations are enumerated from the
| directory, the table below is checked against that enumeration, and a
| slice with no entry fails by name.
|
| **Every slice, not only the writing ones, and that is a simplification
| rather than a widening.** Classifying slices into "writes one of the
| seven tables" and "does not" would need a second instrument - a source
| scan - with its own false negatives: a handler that writes through a
| `Support/` collaborator mentions no table name at all. Running all of
| them needs no classification: a read changes no cell, so it owes no
| bump, and the rule stays "a change implies a bump".
|
| **The rule is one-directional and deliberately so** (AC-53 (c)). A bump
| with no change is not a defect - D-35 settled that setting a role's
| permissions to the set it already holds still bumps - and asserting the
| converse would turn a safe answer into a red test. What is forbidden is
| the other direction: an answer that moved under a version that did not,
| which is a token that keeps working after the rights behind it changed.
|
| **AC-52's "single atomic statement" half is carried by `PermVersion`'s
| own test and by diff review**, not here: this sweep sees the value
| before and after, which cannot distinguish one statement from a
| read-then-write. The concurrency half is `[ENGINE]`.
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
 * Every slice this package ships, read from the directory.
 *
 * @return list<string>
 */
function bumpSweepSlices(?string $root = null): array
{
    $root = $root ?? dirname(__DIR__, 2).'/src/Features';

    $slices = array_map(
        static fn (string $path): string => basename($path),
        glob($root.'/*', GLOB_ONLYDIR) ?: []
    );

    sort($slices);

    return $slices;
}

/**
 * The world every operation below acts on.
 *
 * Three employees, because the sweep has to see an operation that moves
 * one of them and leaves the others alone: a single-employee fixture
 * cannot tell "this employee's version moved" from "every version moved".
 *
 * @return array<string, string>
 */
function bumpSweepWorld(): array
{
    /** @var SyncPermissionRegistryHandler $sync */
    $sync = app(SyncPermissionRegistryHandler::class);
    $sync->handle(new SyncPermissionRegistry);

    $ids = [
        // Three fixture keys, and each exists because one operation
        // could otherwise move nothing. `fixture.a` is the per-person
        // grant's subject; `fixture.scoped` is branch-scoped, so a role
        // assigned AT a branch confers something (a company-wide key
        // assigned at a branch confers nothing, D-8 (c), and the first
        // version of this fixture used one - with the result that
        // AssignRole and RemoveRoleAssignment moved no cell at all and
        // the sweep would have stayed green if either stopped bumping);
        // `fixture.standing` is held permanently, so a status change has
        // something to take away.
        'key' => AccessSeed::permission('fixture.a'),
        'scopedKey' => AccessSeed::permission('fixture.scoped', true),
        'standingKey' => AccessSeed::permission('fixture.standing'),
        'branch' => AccessSeed::branch(),
    ];

    $ids['role'] = AccessSeed::role('shift_lead', [$ids['key'], $ids['scopedKey']]);
    $ids['subject'] = AccessSeed::employee('subject');
    // Coded OWNER (review round 1 W-4): BootstrapOwnerHandler::grantOwner()
    // now refuses any target that is not the live, OWNER-coded employee -
    // this fixture's own 'BootstrapOwner' operation below needs a target
    // that clears that gate, and nothing else here depends on this
    // employee's code being literally 'other'.
    $ids['other'] = AccessSeed::employee('OWNER', ['user_id' => '01JBUSER000000000000000009']);
    $ids['actor'] = AccessSeed::actorHolding(
        'actor',
        array_merge(bumpSweepKeys(), [$ids['key'], $ids['scopedKey']])
    );

    // The standing grant, seeded directly rather than through a slice:
    // it has to survive every operation below, including the ones that
    // remove the subject's other rights.
    AccessSeed::assign($ids['subject'], AccessSeed::role('standing', [$ids['standingKey']]));

    return $ids;
}

/**
 * Every permission key the matrix asks about.
 *
 * The registry's own keys plus this file's fixture key, so the matrix
 * covers both what the application declares and what a shop can be given
 * by hand.
 *
 * @return list<string>
 */
function bumpSweepKeys(): array
{
    $keys = array_map(
        static fn ($declaration): string => $declaration->key,
        app(PermissionRegistry::class)->declarations(),
    );

    sort($keys);

    return $keys;
}

/**
 * The resolver's answer for every (employee, key, scope) cell, plus the
 * two claim-shaped facts that are not answers but change with rights.
 *
 * @param  array<string, string>  $ids
 * @return array<string, array<string, mixed>> employee id => cell key => value
 */
function bumpSweepMatrix(array $ids): array
{
    /** @var PermissionResolver $resolver */
    $resolver = app(PermissionResolver::class);

    $keys = array_merge(bumpSweepKeys(), [$ids['key'], $ids['scopedKey'], $ids['standingKey']]);
    $employees = [$ids['actor'], $ids['subject'], $ids['other']];

    $matrix = [];

    foreach ($employees as $employeeId) {
        $cells = [];

        foreach ($keys as $key) {
            foreach ([null, $ids['branch']] as $scope) {
                $decision = $resolver->decide($employeeId, $key, $scope);

                $cells[$key.'@'.($scope ?? '*')] = $decision->allowed;
            }
        }

        $cells['branch_ids'] = implode(',', AccessSeed::connection()
            ->table('employee_branches')
            ->where('employee_id', $employeeId)
            ->orderBy('branch_id')
            ->pluck('branch_id')
            ->all());

        $matrix[$employeeId] = $cells;
    }

    return $matrix;
}

/**
 * Every employee's stored `perm_version`.
 *
 * @param  array<string, string>  $ids
 * @return array<string, int>
 */
function bumpSweepVersions(array $ids): array
{
    $versions = [];

    foreach ([$ids['actor'], $ids['subject'], $ids['other']] as $employeeId) {
        $versions[$employeeId] = AccessSeed::permVersion($employeeId);
    }

    return $versions;
}

/**
 * One callable per slice, keyed by the slice's directory name.
 *
 * The order is the one each operation's preconditions allow: a branch is
 * attached before a role is assigned at it, and the two deletions are
 * last.
 *
 * @param  array<string, string>  $ids
 * @return array<string, callable(): void>
 */
function bumpSweepOperations(array $ids): array
{
    $created = [];

    return [
        // A sync that changes nothing. It is here because the directory
        // says it is a slice, and it is also AC-53 (c)'s shape: an
        // operation the sweep must not demand a bump from.
        'SyncPermissionRegistry' => static function (): void {
            app(SyncPermissionRegistryHandler::class)->handle(new SyncPermissionRegistry);
        },
        // T-017: neither of the audit slices below touches employee_roles,
        // employee_permissions or employee_branches, so neither one can
        // move a resolver answer - the same AC-53 (c) shape as the sync
        // above, measured rather than assumed.
        'ArchiveAuditLogs' => static function (): void {
            app(ArchiveAuditLogsHandler::class)->handle(new ArchiveAuditLogs);
        },
        'SearchAuditLog' => static function () use ($ids): void {
            // bumpSweepKeys() includes every registry key, audit.read among
            // them, and the actor is granted all of them - see
            // bumpSweepWorld().
            app(SearchAuditLogHandler::class)->handle(new SearchAuditLog($ids['actor']));
        },
        'CreateRole' => static function () use ($ids, &$created): void {
            $created['role'] = app(CreateRoleHandler::class)
                ->handle(new CreateRole('tidy', 'Tidy', null, $ids['actor']))->id;
        },
        'RenameRole' => static function () use ($ids, &$created): void {
            app(RenameRoleHandler::class)
                ->handle(new RenameRole($created['role'], 'Tidier', 'A description.', $ids['actor']));
        },
        'SetRolePermissions' => static function () use ($ids, &$created): void {
            app(SetRolePermissionsHandler::class)
                ->handle(new SetRolePermissions($created['role'], [$ids['key']], $ids['actor']));
        },
        'CreateEmployee' => static function () use ($ids, &$created): void {
            $created['employee'] = app(CreateEmployeeHandler::class)->handle(new CreateEmployee(
                code: 'new-emp',
                firstName: 'New',
                actorId: $ids['actor'],
            ))->id;
        },
        'UpdateEmployee' => static function () use ($ids, &$created): void {
            app(UpdateEmployeeHandler::class)
                ->handle(new UpdateEmployee($created['employee'], ['first_name' => 'Renamed'], $ids['actor']));
        },
        'AttachBranch' => static function () use ($ids): void {
            app(AttachBranchHandler::class)
                ->handle(new AttachBranch($ids['subject'], $ids['branch'], false, $ids['actor']));
        },
        'AssignRole' => static function () use ($ids): void {
            app(AssignRoleHandler::class)
                ->handle(new AssignRole($ids['subject'], $ids['role'], $ids['branch'], $ids['actor']));
        },
        'SetEmployeePermission' => static function () use ($ids): void {
            app(SetEmployeePermissionHandler::class)
                ->handle(new SetEmployeePermission($ids['subject'], $ids['key'], true, null, $ids['actor']));
        },
        'ClearEmployeePermission' => static function () use ($ids): void {
            app(ClearEmployeePermissionHandler::class)
                ->handle(new ClearEmployeePermission($ids['subject'], $ids['key'], null, $ids['actor']));
        },
        'RemoveRoleAssignment' => static function () use ($ids): void {
            app(RemoveRoleAssignmentHandler::class)
                ->handle(new RemoveRoleAssignment($ids['subject'], $ids['role'], $ids['branch'], $ids['actor']));
        },
        'DetachBranch' => static function () use ($ids): void {
            app(DetachBranchHandler::class)->handle(new DetachBranch($ids['subject'], $ids['branch'], $ids['actor']));
        },
        'SetPin' => static function () use ($ids): void {
            app(SetPinHandler::class)->handle(new SetPin($ids['subject'], '7391', $ids['actor']));
        },
        'VerifyPin' => static function (): void {
            app(VerifyPinHandler::class)->handle(new VerifyPin('subject', '7391'));
        },
        'ListRoles' => static function (): void {
            app(ListRolesHandler::class)->handle(new ListRoles);
        },
        'GetRole' => static function () use ($ids): void {
            app(GetRoleHandler::class)->handle(new GetRole($ids['role']));
        },
        'ListEmployees' => static function (): void {
            app(ListEmployeesHandler::class)->handle(new ListEmployees);
        },
        'GetEmployee' => static function () use ($ids): void {
            app(GetEmployeeHandler::class)->handle(new GetEmployee($ids['subject']));
        },
        'GetMyPermissions' => static function () use ($ids): void {
            app(GetMyPermissionsHandler::class)->handle(new GetMyPermissions($ids['subject']));
        },
        'BuildEmployeeClaims' => static function () use ($ids): void {
            app(BuildEmployeeClaimsHandler::class)
                ->handle(new BuildEmployeeClaims($ids['other'], '01JBUSER000000000000000009'));
        },
        // T-026 D-23. The `owner` role already exists ({@see bumpSweepWorld()}'s
        // sync), so this grants it to 'other' and moves every (key, scope)
        // cell of theirs that owner adds - the same shape AssignRole proves,
        // through this slice's own gated operation instead.
        'BootstrapOwner' => static function () use ($ids): void {
            app(BootstrapOwnerHandler::class)->grantOwner($ids['other']);
        },
        'ChangeEmployeeStatus' => static function () use ($ids): void {
            app(ChangeEmployeeStatusHandler::class)
                ->handle(new ChangeEmployeeStatus($ids['subject'], EmployeeStatus::ON_LEAVE, $ids['actor']));
        },
        'DeleteEmployee' => static function () use ($ids, &$created): void {
            app(DeleteEmployeeHandler::class)->handle(new DeleteEmployee($created['employee'], $ids['actor']));
        },
        'DeleteRole' => static function () use ($ids, &$created): void {
            app(DeleteRoleHandler::class)->handle(new DeleteRole($created['role'], $ids['actor']));
        },
    ];
}

/**
 * The sweep itself, extracted so it can be run twice - once against the
 * shipped tree, and once against an enumeration carrying a slice nobody
 * listed.
 *
 * @param  list<string>  $slices  the enumerated domain
 * @return array{operations: list<string>, cells: int, moved: list<string>}
 */
function assertEveryChangeIsBumped(array $slices): array
{
    $ids = bumpSweepWorld();
    $operations = bumpSweepOperations($ids);

    // The domain check, and it is where an unlisted slice fails: the
    // keys of the table above are compared with the directory, not with
    // themselves.
    expect(array_keys($operations))->toEqualCanonicalizing(
        $slices,
        'every slice in packages/access/src/Features must be exercised by this sweep'
    );

    $cells = 0;
    $examined = [];
    $moved = [];

    foreach ($operations as $name => $run) {
        $before = bumpSweepMatrix($ids);
        $versionsBefore = bumpSweepVersions($ids);

        $run();

        $after = bumpSweepMatrix($ids);
        $versionsAfter = bumpSweepVersions($ids);

        foreach ($before as $employeeId => $cellsBefore) {
            $cells += count($cellsBefore);

            if ($cellsBefore === $after[$employeeId]) {
                continue;
            }

            $changed = array_keys(array_diff_assoc(
                array_map(static fn (mixed $v): string => var_export($v, true), $cellsBefore),
                array_map(static fn (mixed $v): string => var_export($v, true), $after[$employeeId]),
            ));

            $moved[] = $name.' moved '.substr($employeeId, -6).' at '.implode(', ', $changed);

            expect($versionsAfter[$employeeId])->toBeGreaterThan(
                $versionsBefore[$employeeId],
                "[{$name}] changed what [{$employeeId}] may do (".implode(', ', $changed)
                .') and did not raise perm_version'
            );
        }

        $examined[] = $name;
    }

    return ['operations' => $examined, 'cells' => $cells, 'moved' => $moved];
}

it('raises perm_version for every operation that changes what somebody may do (AC-52, AC-53)', function () {
    $report = assertEveryChangeIsBumped(bumpSweepSlices());

    // The domain, printed (D-27): a reader sees which operations ran and
    // how many cells were compared, not a count of assertions.
    fwrite(STDOUT, PHP_EOL.'AC-53 bump sweep: '.count($report['operations']).' operations, '
        .$report['cells'].' (employee, key, scope) cells compared'.PHP_EOL
        .'  operations: '.implode(', ', $report['operations']).PHP_EOL
        .'  changes seen: '.PHP_EOL.'    '.implode(PHP_EOL.'    ', $report['moved']).PHP_EOL);

    // Non-empty in both senses: operations ran, and some of them really
    // moved the answers. A sweep over a fixture where nothing ever
    // changed would satisfy "a change implies a bump" vacuously, which
    // is the shape this assertion exists to refuse.
    expect($report['operations'])->not->toBe([])
        ->and($report['cells'])->toBeGreaterThan(0)
        ->and($report['moved'])->not->toBe([]);
});

it('fails when a slice exists that the sweep never runs (AC-53, mutation control b)', function () {
    // The enumeration's own answer, plus one slice nobody listed. This
    // is what a twenty-fourth operation looks like to the sweep on the
    // day it is added: found by the directory walk, absent from the
    // table, and red by name.
    $withStranger = array_merge(bumpSweepSlices(), ['RevokeEverything']);

    expect(fn () => assertEveryChangeIsBumped($withStranger))->toThrow(AssertionFailedError::class);
});

it('reads its domain from a directory rather than from a list (AC-53, mutation control b)', function () {
    // The other half of the same control: the enumerator is pointed at a
    // scratch tree and reports what is in it. Together with the case
    // above - the assertion reddens on a slice it does not run - this is
    // "an unlisted slice is found and fails", in two measurable halves.
    //
    // The scratch tree is under storage/app and not inside
    // `src/Features`, deliberately: a PHP file planted in an analysed
    // path survives a killed run and aborts the next `composer check` in
    // static analysis, before any test hook could remove it. The
    // property under test is that the domain is a directory listing, and
    // that is the same wherever the directory is.
    $root = storage_path('app/bump-sweep-probe');

    @mkdir($root.'/RevokeEverything', 0777, true);

    try {
        expect(bumpSweepSlices($root))->toBe(['RevokeEverything']);
    } finally {
        @rmdir($root.'/RevokeEverything');
        @rmdir($root);
    }
});

it('does not require a bump from an operation that changed nothing (AC-53, control c)', function () {
    $ids = bumpSweepWorld();

    $before = bumpSweepMatrix($ids);
    $versionBefore = AccessSeed::permVersion($ids['subject']);

    // A read, and a write that changes no answer: the role is given the
    // permission set it already holds, which D-35 says still bumps. The
    // sweep must accept both, because its rule is change-implies-bump
    // and not its converse.
    app(ListRolesHandler::class)->handle(new ListRoles);
    app(SetRolePermissionsHandler::class)
        ->handle(new SetRolePermissions($ids['role'], [$ids['key']], $ids['actor']));

    expect(bumpSweepMatrix($ids))->toBe($before)
        ->and(AccessSeed::permVersion($ids['subject']))->toBe($versionBefore);
});
