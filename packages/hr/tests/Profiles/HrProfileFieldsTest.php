<?php

namespace Flexipos\Hr\Tests\Profiles;

use Flexipos\Hr\Profiles\EmploymentType;
use Flexipos\Hr\Profiles\ProfileFields;

/*
|--------------------------------------------------------------------------
| The one closed classification (T-038 plan step 7; AC-56 pure half, D-45)
|--------------------------------------------------------------------------
|
| Pure. The live half of AC-56 (every column of the table is classified,
| and no read returns an internal one) needs the schema and belongs to the
| sweep chunk.
*/

it('holds exactly the spec\'s sixteen sensitive fields', function () {
    // The spec's "Closed value sets" list, in its order, written out here on
    // purpose: moving a field between classes must be a decision, not a drift.
    $spec = [
        'dob', 'gender', 'marital_status', 'nationality', 'nic', 'passport_no', 'personal_email', 'phone',
        'emergency_contact', 'address', 'confirmation_date', 'probation_end_date', 'resignation_date',
        'last_working_date', 'exit_reason', 'notes',
    ];

    expect(ProfileFields::SENSITIVE)->toBe($spec)
        ->and(ProfileFields::SENSITIVE)->toHaveCount(16);
});

it('holds exactly the directory fields that live on the profile table', function () {
    expect(ProfileFields::DIRECTORY)->toBe([
        'employee_id', 'branch_id', 'department_id', 'designation_id', 'reports_to_employee_id',
        'employment_type', 'joining_date', 'work_email',
    ]);
});

it('holds exactly the four internal fields', function () {
    expect(ProfileFields::INTERNAL)->toBe(['created_by', 'updated_by', 'created_at', 'updated_at']);
});

it('keeps the three classes pairwise disjoint, so a column is in exactly one', function () {
    $all = ProfileFields::all();

    expect(array_values(array_intersect(ProfileFields::SENSITIVE, ProfileFields::DIRECTORY)))->toBe([])
        ->and(array_values(array_intersect(ProfileFields::SENSITIVE, ProfileFields::INTERNAL)))->toBe([])
        ->and(array_values(array_intersect(ProfileFields::DIRECTORY, ProfileFields::INTERNAL)))->toBe([])
        ->and(count($all))->toBe(count(array_unique($all)))
        ->and($all)->toHaveCount(28);
});

it('answers whether a field is sensitive', function () {
    expect(ProfileFields::isSensitive('nic'))->toBeTrue()
        ->and(ProfileFields::isSensitive('work_email'))->toBeFalse()
        ->and(ProfileFields::isSensitive('created_by'))->toBeFalse()
        ->and(ProfileFields::isSensitive('code'))->toBeFalse();
});

it('has exactly the six employment types', function () {
    expect(array_map(static fn (EmploymentType $type): string => $type->value, EmploymentType::cases()))
        ->toBe(['permanent', 'contract', 'probation', 'part_time', 'casual', 'intern']);
});
