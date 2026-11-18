<?php

declare(strict_types=1);

namespace Flexipos\Hr\Profiles;

/**
 * The one closed classification of every `employee_profiles` column
 * (T-038 plan Ruling 8, D-12, D-45, AC-56).
 *
 * Every column of the table is in **exactly one** of three lists, and the
 * lists are pairwise disjoint:
 *
 * - {@see self::SENSITIVE}: readable only with a second permission, never in
 *   a list, a log, an audit value or an event;
 * - {@see self::DIRECTORY}: what a directory read may show (the AC-50 fields
 *   that live on this table; the code, names and status come from access);
 * - {@see self::INTERNAL}: bookkeeping no HR read ever returns.
 *
 * The directory reads build their `select` from `DIRECTORY` only, never `*`.
 * A column added to the table without a place here fails the AC-56 sweep.
 */
final class ProfileFields
{
    /**
     * The sixteen sensitive fields, in the spec's order (D-12).
     *
     * @var list<string>
     */
    public const SENSITIVE = [
        'dob', 'gender', 'marital_status', 'nationality', 'nic', 'passport_no', 'personal_email', 'phone',
        'emergency_contact', 'address', 'confirmation_date', 'probation_end_date', 'resignation_date',
        'last_working_date', 'exit_reason', 'notes',
    ];

    /**
     * The directory fields stored on `employee_profiles` (AC-50). The primary
     * key `employee_id` is one of them.
     *
     * @var list<string>
     */
    public const DIRECTORY = [
        'employee_id', 'branch_id', 'department_id', 'designation_id', 'reports_to_employee_id',
        'employment_type', 'joining_date', 'work_email',
    ];

    /**
     * The third class (D-45): returned by no HR read.
     *
     * @var list<string>
     */
    public const INTERNAL = ['created_by', 'updated_by', 'created_at', 'updated_at'];

    /**
     * Every classified column.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [...self::DIRECTORY, ...self::SENSITIVE, ...self::INTERNAL];
    }

    public static function isSensitive(string $field): bool
    {
        return in_array($field, self::SENSITIVE, true);
    }
}
