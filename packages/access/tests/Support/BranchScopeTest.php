<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Support\BranchScope;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Contracts\Organization\BranchDirectory;
use Flexipos\Contracts\Organization\BranchState;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 5 - what a branch id is, and what an operation may do with one
| (AC-61, AC-52)
|--------------------------------------------------------------------------
|
| **The fold is shown by what happens without it.** A lower-case id must equal
| the upper-case one after `requireWellFormed()`; and the control is a scratch
| table with a unique key over the id, into which the un-folded spellings make
| TWO rows and the folded ones make one. A scratch table, not
| `employee_branches`: that table gains a foreign key in step 6, which would
| refuse the lower-case spelling for a different reason and turn the control
| into a different measurement.
|
| `requireUsable()` is run both ways against a double that answers each state
| and against the real directory over the fixture tenant, so a contract
| implementation that returned the wrong state fails the second half.
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

it('folds a lower-case ULID to upper case and passes null and the upper-case spelling through (AC-61)', function () {
    $upper = (string) Str::ulid();

    expect(BranchScope::requireWellFormed(strtolower($upper)))->toBe($upper)
        ->and(BranchScope::requireWellFormed($upper))->toBe($upper)
        ->and(BranchScope::requireWellFormed(null))->toBeNull()
        ->and(BranchScope::requireBranch(strtolower($upper)))->toBe($upper);
});

it('refuses a string that is not a 26-character ULID in either case (AC-61)', function (string $submitted) {
    AccessRefusals::expect(RefusalReason::Validation, fn () => BranchScope::requireWellFormed($submitted));
})->with([
    'too short' => ['01ABC'],
    'too long' => ['01ARZ3NDEKTSV4RRFFQ69G5FAVX'],
    'a character outside the alphabet' => ['01ARZ3NDEKTSV4RRFFQ69G5FAU'],
    'not an id at all' => ['branch-7'],
    'empty' => [''],
]);

it('makes two rows of one id when the fold is skipped and one when it is applied (AC-61 control)', function () {
    $schema = AccessSeed::connection()->getSchemaBuilder();

    $schema->create('qa_fold_probe', static function (Blueprint $table): void {
        $table->string('branch_id', 26)->unique();
    });

    $upper = (string) Str::ulid();
    $table = AccessSeed::connection()->table('qa_fold_probe');

    // Skipping the fold: the same branch, two spellings, two rows. This is
    // the defect the fold exists to prevent, and the test reports it.
    $table->insert(['branch_id' => $upper]);
    $table->insert(['branch_id' => strtolower($upper)]);

    expect(AccessSeed::connection()->table('qa_fold_probe')->count())->toBe(2);

    AccessSeed::connection()->table('qa_fold_probe')->delete();

    // Applying it: the second spelling collides with the first.
    foreach ([$upper, strtolower($upper)] as $spelling) {
        $folded = (string) BranchScope::requireWellFormed($spelling);

        AccessSeed::connection()->table('qa_fold_probe')->updateOrInsert(['branch_id' => $folded], ['branch_id' => $folded]);
    }

    expect(AccessSeed::connection()->table('qa_fold_probe')->count())->toBe(1);

    $schema->drop('qa_fold_probe');
});

it('lets null pass requireUsable and refuses unknown and inactive, from a double (AC-52)', function () {
    $directory = new class implements BranchDirectory
    {
        public int $asked = 0;

        public function state(string $branchId): BranchState
        {
            $this->asked++;

            return match ($branchId) {
                'ACTIVE' => BranchState::Active,
                'INACTIVE' => BranchState::Inactive,
                default => BranchState::Unknown,
            };
        }
    };

    BranchScope::requireUsable($directory, null);

    expect($directory->asked)->toBe(0);

    BranchScope::requireUsable($directory, 'ACTIVE');

    AccessRefusals::expect(RefusalReason::UnknownBranch, fn () => BranchScope::requireUsable($directory, 'NOSUCH'));
    AccessRefusals::expect(RefusalReason::BranchInactive, fn () => BranchScope::requireUsable($directory, 'INACTIVE'));

    // Null never asked; the other three each asked once.
    expect($directory->asked)->toBe(3);
});

it('answers requireUsable from the real directory over the tenant database (AC-52)', function () {
    $active = AccessSeed::branch();
    $inactive = AccessSeed::branch(active: false);
    $directory = app(BranchDirectory::class);

    expect($directory->state($active))->toBe(BranchState::Active)
        ->and($directory->state($inactive))->toBe(BranchState::Inactive)
        ->and($directory->state((string) Str::ulid()))->toBe(BranchState::Unknown);

    BranchScope::requireUsable($directory, $active);

    AccessRefusals::expect(RefusalReason::BranchInactive, fn () => BranchScope::requireUsable($directory, $inactive));
    AccessRefusals::expect(RefusalReason::UnknownBranch, fn () => BranchScope::requireUsable($directory, (string) Str::ulid()));

    // A soft-deleted branch is absent.
    AccessSeed::connection()->table('branches')->where('id', $active)->update(['deleted_at' => '2026-10-22 00:00:00.000']);

    expect($directory->state($active))->toBe(BranchState::Unknown);
});
