<?php

declare(strict_types=1);

namespace Flexipos\Hr\Profiles;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Exceptions\HrRefused;
use Flexipos\Hr\Support\Fields;
use Flexipos\Hr\Support\Input;
use Flexipos\Hr\Support\Limits;

/**
 * Pure parsing of the profile requests (T-038 plan step 7; AC-41, AC-42,
 * AC-46, AC-47, AC-51).
 *
 * No database, no clock of its own: `$today` is the injectable clock's UTC
 * date, passed in. Existence and liveness of the branch, department,
 * designation and manager are the handlers' (they need the store); this
 * class settles shape, closed sets, text rules and date order.
 *
 * Failures are collected across fields, so one `validation` refusal names
 * every failing field. **No message echoes a value**, sensitive or not.
 */
final class ProfileInput
{
    /** @var list<string> the fields a create or update body may carry */
    public const WRITABLE = [
        'branch_id', 'department_id', 'designation_id', 'reports_to_employee_id', 'employment_type',
        'joining_date', 'confirmation_date', 'probation_end_date', 'resignation_date', 'last_working_date',
        'exit_reason', 'dob', 'gender', 'marital_status', 'nationality', 'nic', 'passport_no',
        'personal_email', 'work_email', 'phone', 'emergency_contact', 'address', 'notes',
    ];

    /** @var list<string> a create must carry these; an update may never clear them */
    public const REQUIRED = ['branch_id', 'employment_type', 'joining_date'];

    /** @var list<string> the filter keys of the profile list (AC-51), besides paging */
    public const FILTERS = ['branch_id', 'department_id', 'designation_id', 'reports_to_employee_id', 'employment_type', 'status', 'name'];

    /**
     * A create body: every writable field normalised, absent optionals null.
     *
     * @param  array<array-key, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws HrRefused not_updatable_here, validation or date_order
     */
    public static function create(array $body, string $today): array
    {
        Fields::only($body, self::WRITABLE, Fields::IDENTITY);

        $missing = array_values(array_filter(self::REQUIRED, static fn (string $field): bool => ! array_key_exists($field, $body)));

        $parsed = self::parse($body, $missing);

        $result = [];

        foreach (self::WRITABLE as $field) {
            $result[$field] = $parsed[$field] ?? null;
        }

        self::assertDates($result, $today);

        return $result;
    }

    /**
     * An update body against the stored profile.
     *
     * Returns the normalised `patch` (only the keys the request carried; null
     * clears an optional field) and the `merged` result the date rules ran
     * on: `$current` with the patch laid over it (M4: the order check is on
     * the merged result, never the request alone).
     *
     * @param  array<array-key, mixed>  $body
     * @param  array<string, mixed>  $current  the stored profile's writable fields
     * @return array{patch: array<string, mixed>, merged: array<string, mixed>}
     *
     * @throws HrRefused not_updatable_here, validation or date_order
     */
    public static function update(array $body, array $current, string $today): array
    {
        Fields::only($body, self::WRITABLE, Fields::IDENTITY);

        $patch = self::parse($body, []);
        $merged = array_merge($current, $patch);

        self::assertDates($merged, $today);

        return ['patch' => $patch, 'merged' => $merged];
    }

    /**
     * The filters of the profile list (AC-51): a closed set of keys plus
     * paging. A sensitive field, a sort, a search over anything but the name
     * is an unknown key and so `validation`, before any state is read, naming
     * the key and never the value.
     *
     * @param  array<array-key, mixed>  $query
     * @param  list<string>  $statuses  the employee statuses access publishes
     * @return array{filters: array<string, ?string>, page_size: mixed, cursor: mixed}
     *
     * @throws HrRefused validation
     */
    public static function filters(array $query, array $statuses): array
    {
        Fields::only($query, [...self::FILTERS, 'page_size', 'cursor']);

        $filters = [];
        $failed = [];

        foreach (self::FILTERS as $field) {
            if (! array_key_exists($field, $query)) {
                $filters[$field] = null;

                continue;
            }

            try {
                $filters[$field] = self::filterValue($field, $query[$field], $statuses);
            } catch (HrRefused $refused) {
                $failed[] = $field;
            }
        }

        if ($failed !== []) {
            throw self::failed($failed);
        }

        return ['filters' => $filters, 'page_size' => $query['page_size'] ?? null, 'cursor' => $query['cursor'] ?? null];
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @param  list<string>  $missing  required fields a create did not carry
     * @return array<string, mixed>
     */
    private static function parse(array $body, array $missing): array
    {
        $parsed = [];
        $failed = $missing;

        foreach ($body as $field => $value) {
            $field = (string) $field;

            try {
                $parsed[$field] = self::fieldValue($field, $value);
            } catch (HrRefused $refused) {
                $failed[] = $field;
            }
        }

        if ($failed !== []) {
            throw self::failed($failed);
        }

        return $parsed;
    }

    /**
     * @throws HrRefused validation
     */
    private static function fieldValue(string $field, mixed $value): mixed
    {
        if ($value === null && in_array($field, self::REQUIRED, true)) {
            throw HrRefused::validation($field, "The {$field} is required.");
        }

        return match ($field) {
            'branch_id' => Input::id($value, $field),
            'department_id', 'designation_id', 'reports_to_employee_id' => $value === null ? null : Input::id($value, $field),
            'employment_type' => Input::enum($value, EmploymentType::class, $field)->value,
            'joining_date' => Input::date($value, $field),
            'confirmation_date', 'probation_end_date', 'resignation_date', 'last_working_date', 'dob' => $value === null ? null : Input::date($value, $field),
            'exit_reason' => Input::text($value, Limits::EXIT_REASON, false, $field),
            'gender', 'marital_status' => Input::text($value, Limits::ENUM, false, $field),
            'nationality' => Input::text($value, Limits::NATIONALITY, false, $field),
            'nic', 'passport_no' => Input::text($value, Limits::IDENTITY_NUMBER, false, $field),
            'phone' => Input::text($value, Limits::PHONE, false, $field),
            'notes' => Input::text($value, Limits::PROFILE_NOTES, false, $field),
            'personal_email', 'work_email' => ShapeRules::email($value, $field),
            'emergency_contact' => ShapeRules::emergencyContact($value),
            'address' => ShapeRules::address($value),
            default => throw HrRefused::validation($field, "The {$field} is not accepted here."),
        };
    }

    /**
     * @param  list<string>  $statuses
     *
     * @throws HrRefused validation
     */
    private static function filterValue(string $field, mixed $value, array $statuses): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($field) {
            'employment_type' => Input::enum($value, EmploymentType::class, $field)->value,
            'status' => is_string($value) && in_array($value, $statuses, true)
                ? $value
                : throw HrRefused::validation($field, "The {$field} is not one of the accepted values."),
            'name' => Input::text($value, Limits::NAME, false, $field),
            default => Input::id($value, $field),
        };
    }

    /**
     * Date order on a normalised result, and the date of birth against today.
     *
     * @param  array<string, mixed>  $values
     */
    private static function assertDates(array $values, string $today): void
    {
        $dob = $values['dob'] ?? null;

        if (is_string($dob)) {
            DateRules::assertNotFuture($dob, $today);
        }

        DateRules::assertOrder($values);
    }

    /**
     * @param  list<string>  $fields
     */
    private static function failed(array $fields): HrRefused
    {
        $fields = array_values(array_unique($fields));

        return HrRefused::because(
            HrRefusal::Validation,
            'These fields are invalid: '.implode(', ', $fields).'.',
            $fields[0],
            ['fields' => $fields],
        );
    }
}
