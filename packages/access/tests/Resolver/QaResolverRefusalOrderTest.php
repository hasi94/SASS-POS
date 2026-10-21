<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Resolver;

use Flexipos\Access\Features\AssignRole\AssignRole;
use Flexipos\Access\Features\AssignRole\AssignRoleHandler;
use Flexipos\Access\Resolver\DatabasePermissionResolver;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Access\Tests\Support\AccessRefusals;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Support\Str;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 QA - the resolver's refusal order, and the log lines D-26 and D-36 name
|--------------------------------------------------------------------------
|
| Written by QA after two mutants survived the dev suite (see the report's
| mutation ledger):
|
| - moving the `employee_unavailable` check AHEAD of the question-level
|   refusals left every resolver test green. AC-44 asks the printed truth
|   table to contain "an unregistered key asked about a non-acting employee
|   returns `unknown_permission`, not `employee_unavailable`" and "a
|   branch-scoped key with no branch asked about a non-acting employee
|   returns `branch_required`". The dev table has neither row, so the
|   ordering of AC-42 / D-33 was asserted nowhere;
| - downgrading the `unknown_permission` log line from error to debug left
|   every test green, although D-26 says "fail closed and log at error
|   level" - the whole reason the order was chosen (D-33) is that this
|   line is the only way a typo'd guard is ever seen.
|
| **The question-level refusal must beat every kind of unavailable subject,
| not one**: non-acting, soft-deleted and absent are three different code
| paths in the resolver, and a reordering that only moved one of them would
| pass a single-row test. Each row also has its control - the same question
| asked of an ACTING employee gives the same question-level reason - so the
| ordering is measured against a subject that cannot be the cause.
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
 * A logger that keeps what it is given, level included.
 */
final class QaOrderRecorder extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param  mixed  $level
     * @param  array<mixed>  $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}

/**
 * Put a recorder where the resolver and the escalation guard will find it.
 */
function qaOrderBindRecorder(): QaOrderRecorder
{
    $recorder = new QaOrderRecorder;

    app()->instance(LoggerInterface::class, $recorder);
    app()->forgetInstance(PermissionResolver::class);
    app()->forgetInstance(DatabasePermissionResolver::class);

    return $recorder;
}

/**
 * @return array<string, string>
 */
function qaOrderWorld(): array
{
    $ids = [
        'scopedKey' => AccessSeed::permission('fixture.scoped', true),
        'companyKey' => AccessSeed::permission('fixture.company', false),
        'branch' => AccessSeed::branch(),
    ];

    $role = AccessSeed::role('fixture_everything', [$ids['scopedKey'], $ids['companyKey']]);

    // Acting, and holding both keys at every scope: the subject that cannot
    // be the cause of a refusal.
    $ids['acting'] = AccessSeed::employee('acting');
    AccessSeed::assign($ids['acting'], $role);

    foreach ([
        'onLeave' => EmployeeStatus::ON_LEAVE,
        'suspended' => EmployeeStatus::SUSPENDED,
        'terminated' => EmployeeStatus::TERMINATED,
    ] as $name => $status) {
        $ids[$name] = AccessSeed::employee($name, ['status' => $status]);
        AccessSeed::assign($ids[$name], $role);
    }

    $ids['deleted'] = AccessSeed::employee('deleted', ['deleted_at' => now()->format('Y-m-d H:i:s.v')]);
    AccessSeed::assign($ids['deleted'], $role);

    $ids['missing'] = (string) Str::ulid();

    return $ids;
}

it('lets a malformed question beat every kind of subject that cannot act (AC-42, AC-44 ordering rows)', function () {
    $ids = qaOrderWorld();

    /** @var PermissionResolver $resolver */
    $resolver = app(PermissionResolver::class);

    $unavailable = ['onLeave', 'suspended', 'terminated', 'deleted', 'missing'];

    $rows = [];

    foreach ($unavailable as $who) {
        // An unregistered key, asked about a subject that cannot act.
        $rows[] = [$who, 'fixture.unregistered', $ids['branch'], RefusalReason::UnknownPermission, 'AC-44 unregistered key beats employee_unavailable'];
        // The same with no branch at all: an unregistered key cannot be
        // branch-scoped, so it is still the key that is wrong.
        $rows[] = [$who, 'fixture.unregistered', null, RefusalReason::UnknownPermission, 'unregistered key with no branch is still unknown_permission'];
        // A branch-scoped key with no branch: the question is missing an input.
        $rows[] = [$who, 'fixture.scoped', null, RefusalReason::BranchRequired, 'AC-44 branch-scoped key with no branch beats employee_unavailable'];
        // The subject-level refusal when the question is well formed: the
        // control that shows the rows above are not "always unavailable".
        $rows[] = [$who, 'fixture.scoped', $ids['branch'], RefusalReason::EmployeeUnavailable, 'well-formed question: the subject decides'];
        $rows[] = [$who, 'fixture.company', null, RefusalReason::EmployeeUnavailable, 'company-wide key: the subject decides'];
    }

    $lines = [];
    $reasons = [];

    foreach ($rows as [$who, $key, $branch, $expected, $why]) {
        $decision = $resolver->decide($ids[$who], $key, $branch);

        $lines[] = sprintf('%-10s %-22s %-8s -> %-22s %s', $who, $key, $branch === null ? 'none' : 'branch', $decision->reason->value, $why);
        $reasons[$expected->value] = true;

        expect($decision->allowed)->toBeFalse($why)
            ->and($decision->reason)->toBe($expected, "[{$who}] {$why}");
    }

    // Control: the ACTING subject gets the same two question-level reasons,
    // so those reasons are the question's, not a side effect of the subject.
    expect($resolver->decide($ids['acting'], 'fixture.unregistered', $ids['branch'])->reason)->toBe(RefusalReason::UnknownPermission)
        ->and($resolver->decide($ids['acting'], 'fixture.scoped', null)->reason)->toBe(RefusalReason::BranchRequired)
        ->and($resolver->decide($ids['acting'], 'fixture.scoped', $ids['branch'])->allowed)->toBeTrue();

    // The domain, printed, and non-empty in the three reasons the ordering is about.
    fwrite(STDOUT, PHP_EOL.'AC-42 / AC-44 ordering rows ('.count($rows).'):'.PHP_EOL.implode(PHP_EOL, $lines).PHP_EOL);

    expect($rows)->not->toBe([])
        ->and(array_keys($reasons))->toEqualCanonicalizing([
            RefusalReason::UnknownPermission->value,
            RefusalReason::BranchRequired->value,
            RefusalReason::EmployeeUnavailable->value,
        ]);
});

it('logs an unregistered key at error level and an ordinary refusal at none (D-26)', function () {
    $ids = qaOrderWorld();
    $log = qaOrderBindRecorder();

    /** @var PermissionResolver $resolver */
    $resolver = app(PermissionResolver::class);

    // An ordinary denial first: nothing at error level, and the recorder is
    // therefore known not to be one that records everything as an error.
    $bare = AccessSeed::employee('bare');

    expect($resolver->decide($bare, 'fixture.company', null)->reason)->toBe(RefusalReason::NotPermitted)
        ->and(array_filter($log->records, static fn (array $r): bool => $r['level'] === 'error'))->toBe([]);

    // The typo'd guard: exactly one error-level record, naming the key.
    expect($resolver->decide($ids['acting'], 'fixture.unregistered', null)->reason)->toBe(RefusalReason::UnknownPermission);

    $errors = array_values(array_filter($log->records, static fn (array $r): bool => $r['level'] === 'error'));

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['context']['permission_key'] ?? null)->toBe('fixture.unregistered');

    // And it is logged for a subject that cannot act, which is the case the
    // ordering exists to protect.
    $resolver->decide($ids['onLeave'], 'fixture.unregistered', null);

    expect(array_values(array_filter($log->records, static fn (array $r): bool => $r['level'] === 'error')))->toHaveCount(2);
});

it('logs an operation with no actor at error level, and the privileged control writes none (D-36)', function () {
    $ids = qaOrderWorld();
    $log = qaOrderBindRecorder();

    $subject = AccessSeed::employee('subject');
    $actor = AccessSeed::actorHolding('privileged', ['fixture.company']);
    $role = AccessSeed::role('company_role', ['fixture.company']);

    $before = AccessSeed::connection()->table('employee_roles')->where('employee_id', $subject)->count();

    AccessRefusals::expect(
        RefusalReason::ActorRequired,
        fn () => app(AssignRoleHandler::class)->handle(new AssignRole($subject, $role, null, null))
    );

    $errors = array_values(array_filter($log->records, static fn (array $r): bool => $r['level'] === 'error'));

    expect($errors)->toHaveCount(1)
        ->and(AccessSeed::connection()->table('employee_roles')->where('employee_id', $subject)->count())->toBe($before);

    // The paired control: the identical request with an actor who holds the
    // key succeeds, and logs no further error.
    app(AssignRoleHandler::class)->handle(new AssignRole($subject, $role, null, $actor));

    expect(AccessSeed::connection()->table('employee_roles')->where('employee_id', $subject)->count())->toBe($before + 1)
        ->and(array_values(array_filter($log->records, static fn (array $r): bool => $r['level'] === 'error')))->toHaveCount(1)
        ->and($ids)->not->toBe([]);
});
