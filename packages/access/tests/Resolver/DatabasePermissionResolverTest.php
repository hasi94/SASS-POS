<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Resolver;

use Flexipos\Access\Resolver\DatabasePermissionResolver;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\AccessDecision;
use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 9 - the resolver
|--------------------------------------------------------------------------
|
| D-8 (a) to (f) answered against a real tenant database: AC-38 (nothing, then
| a role), AC-39 (the branch-scoped key and the manager of another branch),
| AC-40 (deny wins, at three shapes of scope), AC-41 (a company-wide key is
| conferred only company-wide), AC-42 (branch_required and unknown_permission
| are each distinguishable from not_permitted), AC-43 (an employee who cannot
| act answers nothing) and AC-44 (the truth table is printed).
|
| Every case runs against step 5's fixture tenant, entered through Tenancy
| with its foreign keys actually enforced, so a fixture that seeds a grant of
| a permission nobody declared fails here instead of silently becoming the
| input to a green test.
|
| Three shapes are deliberate:
|
| - the diverging case for AC-39 is an employee whose ONLY allowing
|   contribution is scoped to branch Y, asked about branch B. An employee
|   holding a null-scope grant cannot discriminate the covering rule, because
|   both the correct reading and "any contribution allows" say allow;
| - the four reasons are asserted as reasons, never as "refused". Three of
|   this package's guards refuse, and a case asserting only `allowed === false`
|   cannot tell which one spoke;
| - the truth table is a list this file prints and counts, not a Pest dataset,
|   so the AC-44 coverage assertions can be made over the whole set in one
|   place rather than row by row.
|
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();

    // enter(), not tenancy()->enter(): the fixture helper turns SQLite's
    // foreign keys on, which the tenant stanza does not.
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/** The branch the questions are asked about. */
const RESOLVER_SCOPED_KEY = 'fixture.scoped';

const RESOLVER_COMPANY_KEY = 'fixture.company';

function resolver(): PermissionResolver
{
    /** @var PermissionResolver $resolver */
    $resolver = app(PermissionResolver::class);

    return $resolver;
}

/**
 * The world every case below asks questions of.
 *
 * One seeding, twelve employees, each of whom exists to make exactly one
 * reading of D-8 fail. The ids are returned by name so the truth table can
 * say which employee a row is about.
 *
 * @return array<string, string>
 */
function resolverWorld(): array
{
    $branchB = AccessSeed::branch();
    $branchY = AccessSeed::branch();

    AccessSeed::permission(RESOLVER_SCOPED_KEY, true);
    AccessSeed::permission(RESOLVER_COMPANY_KEY, false);

    $scopedRole = AccessSeed::role('fixture_scoped_role', [RESOLVER_SCOPED_KEY]);
    $companyRole = AccessSeed::role('fixture_company_role', [RESOLVER_COMPANY_KEY]);
    $deletedRole = AccessSeed::role('fixture_deleted_role', [RESOLVER_SCOPED_KEY], [
        'deleted_at' => now()->format('Y-m-d H:i:s.v'),
    ]);

    $ids = [
        'branchB' => $branchB,
        'branchY' => $branchY,
        'scopedRole' => $scopedRole,
        'companyRole' => $companyRole,
    ];

    // AC-38: nothing at all.
    $ids['bare'] = AccessSeed::employee('bare');

    // AC-38, AC-39: the role at a null scope covers every branch.
    $ids['nullScope'] = AccessSeed::employee('null-scope');
    AccessSeed::assign($ids['nullScope'], $scopedRole);

    // AC-39: the role at branch B only.
    $ids['atB'] = AccessSeed::employee('at-b');
    AccessSeed::assign($ids['atB'], $scopedRole, $branchB);

    // AC-39's diverging case: a manager of Y, asked about B.
    $ids['atY'] = AccessSeed::employee('at-y');
    AccessSeed::assign($ids['atY'], $scopedRole, $branchY);

    // AC-40: a null-scope deny over a null-scope role grant.
    $ids['deniedEverywhere'] = AccessSeed::employee('denied-everywhere');
    AccessSeed::assign($ids['deniedEverywhere'], $scopedRole);
    AccessSeed::personal($ids['deniedEverywhere'], RESOLVER_SCOPED_KEY, false);

    // AC-40: the deny scoped to Y only, over a null-scope role grant.
    $ids['deniedAtY'] = AccessSeed::employee('denied-at-y');
    AccessSeed::assign($ids['deniedAtY'], $scopedRole);
    AccessSeed::personal($ids['deniedAtY'], RESOLVER_SCOPED_KEY, false, $branchY);

    // AC-38: a per-person allow with no role behind it.
    $ids['personalAtB'] = AccessSeed::employee('personal-at-b');
    AccessSeed::personal($ids['personalAtB'], RESOLVER_SCOPED_KEY, true, $branchB);

    // AC-41: a company-wide key conferred by a role assigned at a branch -
    // which it must not be.
    $ids['companyViaBranch'] = AccessSeed::employee('company-via-branch');
    AccessSeed::assign($ids['companyViaBranch'], $companyRole, $branchB);

    // AC-41's control: the same role at a null scope.
    $ids['companyViaNull'] = AccessSeed::employee('company-via-null');
    AccessSeed::assign($ids['companyViaNull'], $companyRole);

    // AC-43: grants, and a status that cannot act.
    $ids['onLeave'] = AccessSeed::employee('on-leave', ['status' => EmployeeStatus::ON_LEAVE]);
    AccessSeed::assign($ids['onLeave'], $scopedRole);

    // AC-43: grants, and soft-deleted.
    $ids['deleted'] = AccessSeed::employee('deleted', ['deleted_at' => now()->format('Y-m-d H:i:s.v')]);
    AccessSeed::assign($ids['deleted'], $scopedRole);

    // AC-43: a soft-deleted role contributes nothing.
    $ids['viaDeletedRole'] = AccessSeed::employee('via-deleted-role');
    AccessSeed::assign($ids['viaDeletedRole'], $deletedRole);

    // AC-43: an employee id naming no row at all.
    $ids['missing'] = (string) Str::ulid();

    return $ids;
}

/**
 * The truth table of AC-38 to AC-43 (AC-44).
 *
 * Each row names the employee fixture, the key, the branch asked about, and
 * the expected answer, reason and finer detail. It is data rather than a Pest
 * dataset so the coverage assertions below can be made over the whole set.
 *
 * @param  array<string, string>  $ids
 * @return list<array{employee: string, key: string, branch: string, allowed: bool, reason: ?string, detail: ?string, why: string}>
 */
function resolverTruthTable(array $ids): array
{
    $rows = [
        ['bare', RESOLVER_SCOPED_KEY, 'branchB', false, 'not_permitted', 'not_granted', 'AC-38 no role, no per-person row'],
        ['nullScope', RESOLVER_SCOPED_KEY, 'branchB', true, null, null, 'AC-38 a null-scope role covers every branch'],
        ['nullScope', RESOLVER_SCOPED_KEY, 'branchY', true, null, null, 'AC-39 the same, at the other branch'],
        ['atB', RESOLVER_SCOPED_KEY, 'branchB', true, null, null, 'AC-39 scope equal to the branch asked'],
        ['atY', RESOLVER_SCOPED_KEY, 'branchB', false, 'not_permitted', 'out_of_scope', 'AC-39 the manager of Y acting in B'],
        ['atY', RESOLVER_SCOPED_KEY, 'branchY', true, null, null, 'AC-39 control: the same employee asked about Y'],
        ['deniedEverywhere', RESOLVER_SCOPED_KEY, 'branchB', false, 'not_permitted', 'explicit_deny', 'AC-40 a null-scope deny wins at B'],
        ['deniedEverywhere', RESOLVER_SCOPED_KEY, 'branchY', false, 'not_permitted', 'explicit_deny', 'AC-40 and at Y'],
        ['deniedAtY', RESOLVER_SCOPED_KEY, 'branchY', false, 'not_permitted', 'explicit_deny', 'AC-40 a deny scoped to Y refuses at Y'],
        ['deniedAtY', RESOLVER_SCOPED_KEY, 'branchB', true, null, null, 'AC-40 and leaves every other branch allowed'],
        ['personalAtB', RESOLVER_SCOPED_KEY, 'branchB', true, null, null, 'AC-38 a per-person allow with no role'],
        ['personalAtB', RESOLVER_SCOPED_KEY, 'branchY', false, 'not_permitted', 'out_of_scope', 'AC-39 that allow does not reach Y'],
        ['companyViaBranch', RESOLVER_COMPANY_KEY, 'branchB', false, 'not_permitted', 'out_of_scope', 'AC-41 a branch assignment confers no company-wide key'],
        ['companyViaNull', RESOLVER_COMPANY_KEY, 'branchB', true, null, null, 'AC-41 control: the branch asked is ignored'],
        ['companyViaNull', RESOLVER_COMPANY_KEY, 'none', true, null, null, 'AC-41 and the same with no branch at all'],
        ['nullScope', RESOLVER_SCOPED_KEY, 'none', false, 'branch_required', null, 'AC-42 a branch-scoped key asked with no branch'],
        ['nullScope', 'fixture.unregistered', 'branchB', false, 'unknown_permission', null, 'AC-42 a key nobody declared'],
        ['onLeave', RESOLVER_SCOPED_KEY, 'branchB', false, 'employee_unavailable', null, 'AC-43 a status that cannot act'],
        ['deleted', RESOLVER_SCOPED_KEY, 'branchB', false, 'employee_unavailable', null, 'AC-43 soft-deleted'],
        ['missing', RESOLVER_SCOPED_KEY, 'branchB', false, 'employee_unavailable', null, 'AC-43 no such employee'],
        ['viaDeletedRole', RESOLVER_SCOPED_KEY, 'branchB', false, 'not_permitted', 'not_granted', 'AC-43 a soft-deleted role contributes nothing'],
    ];

    return array_map(
        static fn (array $row): array => [
            'employee' => $row[0],
            'key' => $row[1],
            'branch' => $row[2],
            'allowed' => $row[3],
            'reason' => $row[4],
            'detail' => $row[5],
            'why' => $row[6],
        ],
        $rows
    );
}

/**
 * Ask one truth-table row.
 *
 * @param  array<string, string>  $ids
 * @param  array{employee: string, key: string, branch: string, allowed: bool, reason: ?string, detail: ?string, why: string}  $row
 */
function askRow(array $ids, array $row): AccessDecision
{
    return resolver()->decide(
        $ids[$row['employee']],
        $row['key'],
        $row['branch'] === 'none' ? null : $ids[$row['branch']],
    );
}

it('answers every row of the truth table, and publishes the table it answered', function () {
    $ids = resolverWorld();
    $table = resolverTruthTable($ids);

    $reasons = [];
    $allowed = 0;
    $refused = 0;
    $lines = [];

    foreach ($table as $row) {
        $decision = askRow($ids, $row);

        $lines[] = sprintf(
            '%-20s %-22s %-8s -> %-9s %-22s %s',
            $row['employee'],
            $row['key'],
            $row['branch'],
            $decision->allowed ? 'allowed' : 'refused',
            ($decision->reason->value ?? '-').'/'.($decision->detail ?? '-'),
            $row['why'],
        );

        expect($decision->allowed)->toBe($row['allowed'], $row['why'])
            ->and($decision->reason?->value)->toBe($row['reason'], $row['why'])
            ->and($decision->detail)->toBe($row['detail'], $row['why']);

        if ($decision->allowed) {
            $allowed++;
        } else {
            $refused++;
            $reasons[$decision->reason->value ?? '-'] = true;
        }
    }

    // The domain this case examined, printed (D-27). A reader sees the rows,
    // not a count of them.
    fwrite(STDOUT, PHP_EOL.'AC-44 resolver truth table ('.count($table).' rows):'.PHP_EOL);
    fwrite(STDOUT, implode(PHP_EOL, $lines).PHP_EOL);

    // AC-44's coverage: a resolver that always refuses, or always allows,
    // fails here even if every row above somehow agreed with it.
    expect($table)->not->toBeEmpty()
        ->and($allowed)->toBeGreaterThan(0)
        ->and($refused)->toBeGreaterThan(0)
        ->and(array_keys($reasons))->toEqualCanonicalizing([
            RefusalReason::NotPermitted->value,
            RefusalReason::BranchRequired->value,
            RefusalReason::UnknownPermission->value,
            RefusalReason::EmployeeUnavailable->value,
        ]);
});

it('flips back when the role that conferred the key is removed (AC-38)', function () {
    $ids = resolverWorld();

    expect(resolver()->decide($ids['nullScope'], RESOLVER_SCOPED_KEY, $ids['branchB'])->allowed)->toBeTrue();

    AccessSeed::connection()->table('employee_roles')
        ->where('employee_id', $ids['nullScope'])
        ->delete();

    $after = resolver()->decide($ids['nullScope'], RESOLVER_SCOPED_KEY, $ids['branchB']);

    expect($after->allowed)->toBeFalse()
        ->and($after->reason)->toBe(RefusalReason::NotPermitted)
        ->and($after->detail)->toBe(AccessDecision::DETAIL_NOT_GRANTED);
});

it('tells branch_required and unknown_permission apart from not_permitted (AC-42)', function () {
    $ids = resolverWorld();

    // The three refusals differ only in their reason: a test asserting
    // "refused" would pass against a resolver that collapsed all three.
    $missingBranch = resolver()->decide($ids['nullScope'], RESOLVER_SCOPED_KEY, null);
    $unknownKey = resolver()->decide($ids['nullScope'], 'fixture.unregistered', $ids['branchB']);
    $notGranted = resolver()->decide($ids['bare'], RESOLVER_SCOPED_KEY, $ids['branchB']);

    expect($missingBranch->reason)->toBe(RefusalReason::BranchRequired)
        ->and($unknownKey->reason)->toBe(RefusalReason::UnknownPermission)
        ->and($notGranted->reason)->toBe(RefusalReason::NotPermitted);

    // The controls AC-42 names: supplying the branch, and registering the
    // key, each change the answer.
    AccessSeed::permission('fixture.unregistered', true);
    AccessSeed::assign($ids['nullScope'], AccessSeed::role('fixture_now_known', ['fixture.unregistered']));

    expect(resolver()->decide($ids['nullScope'], RESOLVER_SCOPED_KEY, $ids['branchB'])->allowed)->toBeTrue()
        ->and(resolver()->decide($ids['nullScope'], 'fixture.unregistered', $ids['branchB'])->allowed)->toBeTrue();
});

it('restores the grants when the employee can act again (AC-43)', function () {
    $ids = resolverWorld();

    expect(resolver()->decide($ids['onLeave'], RESOLVER_SCOPED_KEY, $ids['branchB'])->reason)
        ->toBe(RefusalReason::EmployeeUnavailable);

    AccessSeed::connection()->table('employees')
        ->where('id', $ids['onLeave'])
        ->update(['status' => EmployeeStatus::ACTIVE]);

    expect(resolver()->decide($ids['onLeave'], RESOLVER_SCOPED_KEY, $ids['branchB'])->allowed)->toBeTrue();
});

it('reads the acting set from the one authority, and follows it when it moves (AC-43, D-15)', function () {
    $ids = resolverWorld();

    // Shipped: probation acts, on_leave does not.
    expect(app(EmployeeStatus::class)->acting())->toBe(EmployeeStatus::ACTING)
        ->and(resolver()->decide($ids['onLeave'], RESOLVER_SCOPED_KEY, $ids['branchB'])->reason)
        ->toBe(RefusalReason::EmployeeUnavailable);

    // Moved: the resolver built afterwards answers from the moved set, which
    // is what separates reading the authority from holding a second copy of
    // its list.
    app()->instance(EmployeeStatus::class, EmployeeStatus::withActingStatuses([EmployeeStatus::ON_LEAVE]));
    app()->forgetInstance(PermissionResolver::class);

    expect(resolver()->decide($ids['onLeave'], RESOLVER_SCOPED_KEY, $ids['branchB'])->allowed)->toBeTrue()
        ->and(resolver()->decide($ids['nullScope'], RESOLVER_SCOPED_KEY, $ids['branchB'])->reason)
        ->toBe(RefusalReason::EmployeeUnavailable);

    // The negative control: a third policy built over a third set and handed
    // to nobody changes no answer. Without it, "the resolver followed the
    // move" is equally consistent with the test having moved something that
    // nothing reads and the answers having changed for another reason.
    EmployeeStatus::withActingStatuses([EmployeeStatus::SUSPENDED]);

    expect(resolver()->decide($ids['onLeave'], RESOLVER_SCOPED_KEY, $ids['branchB'])->allowed)->toBeTrue();
});

it('answers holdsEverywhere and decide alike wherever both are defined (Ruling 10)', function () {
    $ids = resolverWorld();

    // **The control the ruling asks for, and the reason it exists.**
    // `holdsEverywhere()` and `decide()` share two contribution readers
    // and `covers()` today - but "they share a helper" is a fact about
    // this morning's code, not a property. If they fork, each slice
    // stays right on its own and AC-36 and AC-39 quietly come to
    // disagree about the same row, which nobody would learn from either.
    //
    // Only a NON-branch-scoped key can be compared: for a branch-scoped
    // one `decide($id, $key, null)` answers `branch_required` by design
    // (Ruling 8), which is the whole reason the second method exists.
    $employees = array_diff_key($ids, array_flip(['branchB', 'branchY', 'scopedRole', 'companyRole']));

    /** @var DatabasePermissionResolver $resolver */
    $resolver = resolver();

    $answers = [];

    foreach ($employees as $name => $employeeId) {
        $everywhere = $resolver->holdsEverywhere($employeeId, RESOLVER_COMPANY_KEY);
        $decided = $resolver->decide($employeeId, RESOLVER_COMPANY_KEY, null)->allowed;

        expect($everywhere)->toBe($decided, "[{$name}] is answered differently by the two methods");

        $answers[$name] = $everywhere;
    }

    // Non-vacuous in both directions: an agreement test over a fixture
    // where every answer is false is satisfied by two methods that both
    // return false always. `companyViaNull` holds the key everywhere and
    // the other eleven do not, so the loop above compared both answers.
    expect($answers)->not->toBe([])
        ->and(array_values(array_unique($answers)))->toEqualCanonicalizing([true, false])
        ->and($answers['companyViaNull'])->toBeTrue();

    // And across an explicit deny, which is the arm a forked `covers()`
    // would get wrong first: a deny that one method reads as covering
    // the null scope and the other does not.
    AccessSeed::personal($ids['companyViaNull'], RESOLVER_COMPANY_KEY, false);

    expect($resolver->holdsEverywhere($ids['companyViaNull'], RESOLVER_COMPANY_KEY))
        ->toBe($resolver->decide($ids['companyViaNull'], RESOLVER_COMPANY_KEY, null)->allowed)
        ->and($resolver->holdsEverywhere($ids['companyViaNull'], RESOLVER_COMPANY_KEY))->toBeFalse();
});
