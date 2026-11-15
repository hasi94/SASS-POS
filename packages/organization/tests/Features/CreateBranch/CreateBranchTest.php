<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\CreateBranch;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Features\CreateBranch\CreateBranch;
use Flexipos\Organization\Features\CreateBranch\CreateBranchHandler;
use Flexipos\Organization\Features\CreateBranch\CreateBranchResult;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 8 - creating a branch (AC-10, AC-11, AC-20)
|--------------------------------------------------------------------------
|
| **The two inserts are one unit, and the proof is a fault, not a reading of
| the code.** A trigger on `locations` makes the store's insert fail after
| the branch row is already written; the branch must not be there afterwards,
| nor the audit row. The control is the same input with the trigger gone.
|
| **Every refusal asserts the three counters**: branches, locations and audit
| rows all unchanged. A handler that inserted the branch and then threw on the
| second field would fail the first.
|
| The audit row is compared WHOLE, as an array, so a column that goes
| missing or null fails (T-010 Ruling 11).
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-22 10:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * A valid submission, with overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function createBranchInput(array $overrides = []): array
{
    return array_merge([
        'code' => 'main',
        'name' => 'Main Street',
        'timezone' => 'Asia/Colombo',
        'currency' => 'LKR',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $fields
 */
function createBranch(array $fields, ?string $actorId = null): CreateBranchResult
{
    /** @var CreateBranchHandler $handler */
    $handler = app(CreateBranchHandler::class);

    return $handler->handle(new CreateBranch($fields, $actorId));
}

it('creates the branch, its one store location and the default link, and writes one audit row (AC-10, AC-20)', function () {
    $actor = AccessSeed::employee('creator', ['display_name' => 'Creator Person']);

    $result = createBranch(createBranchInput(['phone' => '0112223344', 'sort_order' => 3]), $actor);

    $branch = (array) OrganizationSeed::connection()->table('branches')->where('id', $result->branchId)->first();
    $locations = OrganizationSeed::connection()->table('locations')->where('branch_id', $result->branchId)->get();

    expect(strlen($result->branchId))->toBe(26)
        ->and($result->branchId)->toBe(strtoupper($result->branchId))
        ->and($result->code)->toBe('MAIN')
        ->and($branch['code'])->toBe('MAIN')
        ->and((bool) $branch['is_active'])->toBeTrue()
        ->and($branch['default_location_id'])->toBe($result->locationId)
        ->and($branch['created_by'])->toBe($actor)
        ->and($branch['updated_by'])->toBe($actor)
        ->and((int) $branch['sort_order'])->toBe(3)
        ->and($branch['created_at'])->toBe('2026-10-22 10:00:00.000')
        ->and($locations)->toHaveCount(1);

    $store = (array) $locations[0];

    // The D-11 defaults, and the branch's own code and name (D-39).
    expect($store['id'])->toBe($result->locationId)
        ->and($store['type'])->toBe('branch_store')
        ->and($store['code'])->toBe('MAIN')
        ->and($store['name'])->toBe('Main Street')
        ->and((bool) $store['is_sellable_from'])->toBeTrue()
        ->and((bool) $store['is_purchasable_to'])->toBeTrue()
        ->and((bool) $store['allow_negative_stock'])->toBeFalse()
        ->and((bool) $store['is_active'])->toBeTrue();

    // One audit row, whole.
    expect(OrganizationSeed::onlyAuditRow())->toBe([
        'actor_type' => 'employee',
        'actor_id' => $actor,
        'actor_name' => 'Creator Person',
        'branch_id' => $result->branchId,
        'module' => 'organization',
        'action' => 'branch_created',
        'subject_type' => 'branch',
        'subject_id' => $result->branchId,
        'before' => null,
        'after' => [
            'id' => $result->branchId,
            'code' => 'MAIN',
            'name' => 'Main Street',
            'address' => null,
            'phone' => '0112223344',
            'email' => null,
            'timezone' => 'Asia/Colombo',
            'currency' => 'LKR',
            'tax_registration_no' => null,
            'business_type' => null,
            'opening_date' => null,
            'default_location_id' => $result->locationId,
            'is_active' => true,
            'sort_order' => 3,
        ],
        // Derived by the one writer: a null that stays null is not a change.
        'changed_fields' => [
            'code', 'currency', 'default_location_id', 'id', 'is_active', 'name', 'phone', 'sort_order', 'timezone',
        ],
        'user_agent' => null,
        'request_id' => null,
    ]);
});

it('accepts the three controls AC-11 names: a ten-character code, a folded lower-case one and an IANA zone with a currency', function (string $submitted, string $stored) {
    $result = createBranch(createBranchInput(['code' => $submitted]));

    expect($result->code)->toBe($stored)
        ->and(OrganizationSeed::connection()->table('branches')->where('code', $stored)->count())->toBe(1);
})->with([
    'ten characters' => ['ABCDE-FGHI', 'ABCDE-FGHI'],
    'lower case folds' => ['a1-b', 'A1-B'],
    'surrounding space is trimmed' => ['  q7  ', 'Q7'],
]);

it('refuses every invalid input with validation, and writes no branch, no location and no audit row (AC-11)', function (array $overrides, array $remove) {
    $input = createBranchInput($overrides);

    foreach ($remove as $key) {
        unset($input[$key]);
    }

    $before = OrganizationSeed::counts();

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => createBranch($input));

    expect(OrganizationSeed::counts())->toBe($before);
})->with([
    'a missing code' => [[], ['code']],
    'a blank code' => [['code' => '   '], []],
    'a missing name' => [[], ['name']],
    'a blank name' => [['name' => ' '], []],
    'a missing timezone' => [[], ['timezone']],
    'a missing currency' => [[], ['currency']],
    'a code over ten characters' => [['code' => 'ABCDEFGHIJK'], []],
    'a code with an invalid character' => [['code' => 'AB_C'], []],
    'a code with a space inside' => [['code' => 'AB C'], []],
    'a name over a hundred characters' => [['name' => str_repeat('n', 101)], []],
    'a timezone that is not IANA' => [['timezone' => 'Mars/Phobos'], []],
    'a lower-case IANA zone' => [['timezone' => 'asia/colombo'], []],
    'a two-letter currency' => [['currency' => 'LK'], []],
    'a numeric currency' => [['currency' => '123'], []],
    'an invalid email' => [['email' => 'not-an-email'], []],
    'a business type outside the set' => [['business_type' => 'wholesale'], []],
    'a non-string code' => [['code' => 12345], []],
    'a non-string name' => [['name' => ['x']], []],
    'an invalid opening date' => [['opening_date' => '2026-13-45'], []],
    'is_active supplied' => [['is_active' => false], []],
    'default_location_id supplied' => [['default_location_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'], []],
    'id supplied' => [['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'], []],
    'deleted_at supplied' => [['deleted_at' => '2026-10-22 00:00:00.000'], []],
]);

it('refuses a code already used by a branch, including a soft-deleted one, and by a location (AC-11, D-39)', function () {
    createBranch(createBranchInput());

    $before = OrganizationSeed::counts();

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => createBranch(createBranchInput(['code' => 'main'])), 'a live branch');

    // A soft-deleted branch (fixture only) still holds its code.
    OrganizationSeed::connection()->table('branches')->where('code', 'MAIN')->update(['deleted_at' => '2026-10-22 00:00:00.000']);

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => createBranch(createBranchInput(['code' => 'MAIN'])), 'a soft-deleted branch');

    // A location holding the code: the store would collide with it.
    OrganizationSeed::location(null, 'WH1');

    $locations = OrganizationSeed::counts()['locations'];

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => createBranch(createBranchInput(['code' => 'wh1'])), 'a location');

    expect(OrganizationSeed::counts()['branches'])->toBe($before['branches'])
        ->and(OrganizationSeed::counts()['locations'])->toBe($locations)
        ->and(OrganizationSeed::counts()['audit_logs'])->toBe($before['audit_logs']);
});

it('leaves no branch and no audit row when the store location cannot be written (AC-10, fault injection)', function () {
    // The control first: the same input succeeds with no fault in place.
    createBranch(createBranchInput(['code' => 'ctl']));

    expect(OrganizationSeed::counts()['branches'])->toBe(1);

    // The fault: every insert into `locations` aborts, AFTER the branch row
    // of the same transaction has been written.
    OrganizationSeed::connection()->unprepared(
        "create trigger fail_store before insert on locations begin select raise(abort, 'store insert failed'); end"
    );

    $before = OrganizationSeed::counts();

    expect(fn () => createBranch(createBranchInput(['code' => 'boom'])))->toThrow(QueryException::class, 'store insert failed');

    expect(OrganizationSeed::counts())->toBe($before)
        ->and(OrganizationSeed::connection()->table('branches')->where('code', 'BOOM')->count())->toBe(0);
});
