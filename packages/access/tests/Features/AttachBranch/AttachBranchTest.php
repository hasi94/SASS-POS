<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\AttachBranch;

use Flexipos\Access\Features\AttachBranch\AttachBranch;
use Flexipos\Access\Features\AttachBranch\AttachBranchHandler;
use Flexipos\Access\Tests\Support\AccessRefusals;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 13 - attaching an employee to a branch (AC-35, AC-52)
|--------------------------------------------------------------------------
|
| The one-default rule is asserted as a TRANSITION, not as a count: the
| employee gets a default, then a second branch as default, and the first
| row is read back and found false. "Exactly one row has is_default" would
| also pass against a handler that refused the second request outright,
| which is not what AC-35 asks for.
|
| The second default also proves the demotion happens INSIDE the operation:
| `employee_branches` carries a unique index over a stored generated column
| of `is_default`, so a handler that inserted before demoting would meet
| that index. The test would then fail with an integrity error rather than
| an assertion, which is still red - this note is so the reader of that
| failure knows what it means.
|
| The bump here is the `branch_ids` claim changing, not a permission (AC-58).
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

function attachBranch(string $employeeId, string $branchId, bool $isDefault = false): void
{
    /** @var AttachBranchHandler $handler */
    $handler = app(AttachBranchHandler::class);

    $handler->handle(new AttachBranch($employeeId, $branchId, $isDefault));
}

/**
 * Every attachment of an employee as branch id => is_default.
 *
 * @return array<string, bool>
 */
function attachments(string $employeeId): array
{
    $rows = AccessSeed::connection()->table('employee_branches')
        ->where('employee_id', $employeeId)
        ->orderBy('branch_id')
        ->get();

    $byBranch = [];

    foreach ($rows as $row) {
        /** @var object{branch_id: string, is_default: int|bool} $row */
        $byBranch[(string) $row->branch_id] = (bool) $row->is_default;
    }

    return $byBranch;
}

it('attaches a branch, not as default unless asked, and bumps (AC-35, AC-52)', function () {
    $employeeId = AccessSeed::employee('subject');
    $branchId = AccessSeed::branch();
    $before = AccessSeed::permVersion($employeeId);

    attachBranch($employeeId, $branchId);

    expect(attachments($employeeId))->toBe([$branchId => false])
        ->and(AccessSeed::permVersion($employeeId))->toBe($before + 1);
});

it('makes a requested default the only default, demoting the previous one (AC-35)', function () {
    $employeeId = AccessSeed::employee('subject');
    $first = AccessSeed::branch();
    $second = AccessSeed::branch();

    attachBranch($employeeId, $first, true);
    attachBranch($employeeId, $second, true);

    $attachments = attachments($employeeId);

    expect($attachments[$first])->toBeFalse()
        ->and($attachments[$second])->toBeTrue()
        ->and(count(array_filter($attachments)))->toBe(1);
});

it('refuses to attach the same branch twice, changes nothing and bumps nothing (AC-35)', function () {
    $employeeId = AccessSeed::employee('subject');
    $branchId = AccessSeed::branch();

    attachBranch($employeeId, $branchId, true);

    $before = AccessSeed::permVersion($employeeId);

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => attachBranch($employeeId, $branchId)
    );

    expect(attachments($employeeId))->toBe([$branchId => true])
        ->and(AccessSeed::permVersion($employeeId))->toBe($before);
});

it('refuses a branch id that is not a ULID (AC-31)', function () {
    $employeeId = AccessSeed::employee('subject');

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => attachBranch($employeeId, 'branch-7')
    );

    expect(attachments($employeeId))->toBe([]);
});

it('refuses an employee who was soft-deleted (AC-35)', function () {
    $employeeId = AccessSeed::employee('subject');

    AccessSeed::connection()->table('employees')
        ->where('id', $employeeId)
        ->update(['deleted_at' => now()->format('Y-m-d H:i:s.v')]);

    AccessRefusals::expect(
        RefusalReason::Validation,
        fn () => attachBranch($employeeId, AccessSeed::branch())
    );

    expect(attachments($employeeId))->toBe([]);
});

it('refuses an unknown branch and an inactive one, and folds a lower-case id (AC-52, AC-15, AC-61)', function () {
    $employee = AccessSeed::employee('attach_subject');
    $branch = AccessSeed::branch();
    $inactive = AccessSeed::branch(active: false);

    AccessRefusals::expect(RefusalReason::UnknownBranch, fn () => attachBranch($employee, (string) Str::ulid()));
    AccessRefusals::expect(RefusalReason::BranchInactive, fn () => attachBranch($employee, $inactive));

    expect(attachments($employee))->toBe([]);

    // Control: the active one is accepted, spelled in lower case, and stored
    // upper-case.
    attachBranch($employee, strtolower($branch));

    expect(attachments($employee))->toBe([$branch => false]);
});
