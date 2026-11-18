<?php

namespace Flexipos\Hr\Tests\Profiles;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Profiles\ProfileFields;
use Flexipos\Hr\Profiles\ProfileInput;
use Flexipos\Hr\Support\Fields;
use Flexipos\Hr\Tests\Support\HrRefusals;

/*
|--------------------------------------------------------------------------
| Profile request parsing (T-038 plan step 7; AC-41, AC-42, AC-46, AC-47, AC-51)
|--------------------------------------------------------------------------
|
| Pure: the clock is a date string passed in, the store is never touched.
*/

const HR_TODAY = '2026-11-17';
const HR_BRANCH = '01JABCDEFGHJKMNPQRSTVWXYZ0';
const HR_MANAGER = '01JABCDEFGHJKMNPQRSTVWXYZ1';

/**
 * @return array<string, mixed>
 */
function hrValidProfile(): array
{
    return ['branch_id' => HR_BRANCH, 'employment_type' => 'permanent', 'joining_date' => '2024-02-29'];
}

// ---- create: required fields and shapes (AC-41) -----------------------------

it('parses a minimal create and nulls every optional field', function () {
    $parsed = ProfileInput::create(hrValidProfile(), HR_TODAY);

    expect($parsed)->toHaveCount(count(ProfileInput::WRITABLE))
        ->and($parsed['branch_id'])->toBe(HR_BRANCH)
        ->and($parsed['employment_type'])->toBe('permanent')
        ->and($parsed['joining_date'])->toBe('2024-02-29')
        ->and($parsed['nic'])->toBeNull()
        ->and($parsed['address'])->toBeNull();
});

it('parses a full create with trimmed, shaped and upper-cased values', function () {
    $parsed = ProfileInput::create(hrValidProfile() + [
        'department_id' => '01jabcdefghjkmnpqrstvwxyz2',
        'reports_to_employee_id' => HR_MANAGER,
        'confirmation_date' => '2024-08-29',
        'dob' => '1990-05-05',
        'nic' => ' 901234567V ',
        'work_email' => 'asha@example.com',
        'emergency_contact' => ['name' => 'Kamal', 'phone' => '0771234567'],
        'address' => ['line1' => '12 Main St'],
        'notes' => '  hello  ',
    ], HR_TODAY);

    expect($parsed['department_id'])->toBe('01JABCDEFGHJKMNPQRSTVWXYZ2')
        ->and($parsed['nic'])->toBe('901234567V')
        ->and($parsed['notes'])->toBe('hello')
        ->and($parsed['emergency_contact'])->toBe(['name' => 'Kamal', 'phone' => '0771234567']);
});

it('names every missing required field in one validation refusal', function () {
    $refused = HrRefusals::expect(HrRefusal::Validation, fn () => ProfileInput::create([], HR_TODAY));

    expect($refused->context['fields'])->toBe(['branch_id', 'employment_type', 'joining_date']);
});

it('refuses an employment type outside the six and a non-date joining date', function () {
    $refused = HrRefusals::expect(HrRefusal::Validation, fn () => ProfileInput::create(
        ['employment_type' => 'volunteer', 'joining_date' => 'tomorrow'] + hrValidProfile(),
        HR_TODAY
    ));

    expect($refused->context['fields'])->toBe(['employment_type', 'joining_date']);

    foreach (['Permanent', ' permanent', 'full_time', '', null, 3] as $bad) {
        HrRefusals::expect(HrRefusal::Validation, fn () => ProfileInput::create(['employment_type' => $bad] + hrValidProfile(), HR_TODAY));
    }
});

it('refuses 2025-02-30 and accepts 2024-02-29', function () {
    HrRefusals::expect(HrRefusal::Validation, fn () => ProfileInput::create(['joining_date' => '2025-02-30'] + hrValidProfile(), HR_TODAY));

    expect(ProfileInput::create(['joining_date' => '2024-02-29'] + hrValidProfile(), HR_TODAY)['joining_date'])->toBe('2024-02-29');
});

it('allows a joining date in the future', function () {
    expect(ProfileInput::create(['joining_date' => '2030-01-01'] + hrValidProfile(), HR_TODAY)['joining_date'])->toBe('2030-01-01');
});

it('refuses a date of birth after today and accepts today', function () {
    HrRefusals::expect(HrRefusal::Validation, fn () => ProfileInput::create(['dob' => '2026-11-18'] + hrValidProfile(), HR_TODAY));

    expect(ProfileInput::create(['dob' => HR_TODAY] + hrValidProfile(), HR_TODAY)['dob'])->toBe(HR_TODAY);
});

// ---- create and update: the allowed set (AC-42) ------------------------------

it('refuses every identity field as not_updatable_here and any other unknown field as validation', function () {
    foreach (Fields::IDENTITY as $key) {
        HrRefusals::expect(HrRefusal::NotUpdatableHere, fn () => ProfileInput::create([$key => 'x'] + hrValidProfile(), HR_TODAY), $key);
        HrRefusals::expect(HrRefusal::NotUpdatableHere, fn () => ProfileInput::update([$key => 'x'], [], HR_TODAY), $key);
    }

    foreach (['basic_salary', 'bank_account', 'employee_id', 'created_by', 'photo_path'] as $key) {
        HrRefusals::expect(HrRefusal::Validation, fn () => ProfileInput::create([$key => 'x'] + hrValidProfile(), HR_TODAY), $key);
    }
});

it('accepts the same request once the identity fields are removed', function () {
    HrRefusals::expect(HrRefusal::NotUpdatableHere, fn () => ProfileInput::create(['first_name' => 'A'] + hrValidProfile(), HR_TODAY));
    expect(ProfileInput::create(hrValidProfile(), HR_TODAY))->toBeArray();
});

// ---- date order (AC-46) -----------------------------------------------------

it('refuses each later date one day before joining, and accepts the equal date', function () {
    foreach (['confirmation_date', 'probation_end_date', 'resignation_date', 'last_working_date'] as $field) {
        HrRefusals::expect(HrRefusal::DateOrder, fn () => ProfileInput::create([$field => '2024-02-28'] + hrValidProfile(), HR_TODAY), $field);

        expect(ProfileInput::create([$field => '2024-02-29'] + hrValidProfile(), HR_TODAY)[$field])->toBe('2024-02-29');
    }
});

it('checks update date order on the merged result, not on the request alone', function () {
    $current = hrValidProfile() + ['confirmation_date' => '2024-09-01', 'resignation_date' => null, 'last_working_date' => null];

    // Moving joining_date alone past the stored confirmation date breaks the order.
    $refused = HrRefusals::expect(HrRefusal::DateOrder, fn () => ProfileInput::update(['joining_date' => '2024-10-01'], $current, HR_TODAY));

    expect($refused->context)->toBe(['earlier' => 'joining_date', 'later' => 'confirmation_date']);

    // The control: the same field, moved to a date that keeps the order.
    $ok = ProfileInput::update(['joining_date' => '2024-08-01'], $current, HR_TODAY);

    expect($ok['patch'])->toBe(['joining_date' => '2024-08-01'])
        ->and($ok['merged']['confirmation_date'])->toBe('2024-09-01');
});

it('lets an update clear an optional field and refuses clearing a required one', function () {
    $current = hrValidProfile() + ['nic' => '901234567V', 'department_id' => HR_MANAGER];

    $cleared = ProfileInput::update(['nic' => null, 'department_id' => null, 'notes' => '   '], $current, HR_TODAY);

    expect($cleared['patch'])->toBe(['nic' => null, 'department_id' => null, 'notes' => null])
        ->and($cleared['merged']['nic'])->toBeNull();

    foreach (['branch_id', 'employment_type', 'joining_date'] as $required) {
        HrRefusals::expect(HrRefusal::Validation, fn () => ProfileInput::update([$required => null], $current, HR_TODAY), $required);
    }
});

// ---- lengths (AC-47) --------------------------------------------------------

it('accepts the column widths and refuses one more', function () {
    $widths = ['exit_reason' => 500, 'gender' => 30, 'marital_status' => 30, 'nationality' => 60, 'nic' => 30, 'passport_no' => 30, 'phone' => 30];

    foreach ($widths as $field => $width) {
        expect(strlen((string) ProfileInput::create([$field => str_repeat('x', $width)] + hrValidProfile(), HR_TODAY)[$field]))->toBe($width);

        $refused = HrRefusals::expect(HrRefusal::Validation, fn () => ProfileInput::create([$field => str_repeat('x', $width + 1)] + hrValidProfile(), HR_TODAY), $field);

        expect($refused->context['fields'])->toBe([$field]);
    }
});

it('refuses a blank-after-trim email as absent and a malformed one as validation', function () {
    expect(ProfileInput::create(['personal_email' => '  '] + hrValidProfile(), HR_TODAY)['personal_email'])->toBeNull();

    HrRefusals::expect(HrRefusal::Validation, fn () => ProfileInput::create(['work_email' => 'nope'] + hrValidProfile(), HR_TODAY));
});

// ---- AC-51: filters ---------------------------------------------------------

it('parses the permitted filters and leaves absent ones null', function () {
    $parsed = ProfileInput::filters(
        ['branch_id' => HR_BRANCH, 'employment_type' => 'contract', 'status' => 'active', 'name' => '  asha ', 'page_size' => 10, 'cursor' => 'c'],
        ['active', 'suspended', 'terminated']
    );

    expect($parsed['filters'])->toBe([
        'branch_id' => HR_BRANCH,
        'department_id' => null,
        'designation_id' => null,
        'reports_to_employee_id' => null,
        'employment_type' => 'contract',
        'status' => 'active',
        'name' => 'asha',
    ])->and($parsed['page_size'])->toBe(10)->and($parsed['cursor'])->toBe('c');

    expect(ProfileInput::filters([], ['active'])['page_size'])->toBeNull();
});

it('refuses a filter, sort or search over any sensitive field without echoing the value', function () {
    $sentinel = 'SENTINEL-7741-LL';

    foreach (ProfileFields::SENSITIVE as $field) {
        $refused = HrRefusals::expect(HrRefusal::Validation, fn () => ProfileInput::filters([$field => $sentinel], ['active']), $field);

        expect($refused->context['fields'])->toBe([$field])
            ->and($refused->getMessage())->toContain($field)
            ->and($refused->getMessage())->not->toContain($sentinel);
    }

    foreach (['sort', 'order_by', 'search', 'q'] as $field) {
        HrRefusals::expect(HrRefusal::Validation, fn () => ProfileInput::filters([$field => 'nic'], ['active']), $field);
    }
});

it('refuses a bad filter value, naming the field and not the value', function () {
    $refused = HrRefusals::expect(HrRefusal::Validation, fn () => ProfileInput::filters(
        ['branch_id' => 'not-a-ulid', 'status' => 'on_leave', 'employment_type' => 'x'],
        ['active']
    ));

    expect($refused->context['fields'])->toBe(['branch_id', 'employment_type', 'status'])
        ->and($refused->getMessage())->not->toContain('not-a-ulid')
        ->and($refused->getMessage())->not->toContain('on_leave');
});
