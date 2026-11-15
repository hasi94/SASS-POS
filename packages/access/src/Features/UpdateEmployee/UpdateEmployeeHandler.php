<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\UpdateEmployee;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\AuditWriter;
use Flexipos\Access\Support\EmployeeLookup;
use Flexipos\Access\Support\EmployeeView;
use Flexipos\Access\Support\PermVersion;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Change an employee's names, and refuse a request that names anything else
 * (AC-26, D-32).
 *
 * **The refusal is the criterion.** A request including `code`, `user_id`,
 * `pin_hash`, `perm_version` or `status` is refused `validation` and
 * **nothing from it is stored** - not even the name change that came with
 * it. `architect`'s reason, recorded because it is the kind of rule a later
 * reader is tempted to soften: a silent ignore is indistinguishable from a
 * field that was never wired, so a client could not tell "protected" from
 * "forgotten", and nor could a test.
 *
 * **Each protected field has its own door and this is not it.** `status` is
 * `ChangeEmployeeStatus`, which knows when to bump; `pin_hash` is `SetPin`,
 * behind its own permission; `code` is immutable (D-14); `user_id` is set at
 * creation and checked at mint (D-6); `perm_version` is only ever raised by
 * {@see PermVersion}, one atomic statement at a
 * time. Letting this slice write any of them would be a second door with
 * none of those guards behind it.
 *
 * **A name change bumps nobody** (AC-52): the version tells live tokens
 * their claims are stale, and a display name is in no claim.
 */
final class UpdateEmployeeHandler
{
    /**
     * The fields an update may not even mention (D-32).
     *
     * Public because AC-26's test enumerates **this** list rather than
     * retyping it: a sixth protected field is then covered the day it is
     * added, and one quietly removed fails the test (D-27).
     *
     * @var list<string>
     */
    public const PROTECTED_FIELDS = ['code', 'user_id', 'pin_hash', 'perm_version', 'status'];

    /**
     * The fields an update may change.
     *
     * @var list<string>
     */
    public const UPDATABLE_FIELDS = ['first_name', 'last_name', 'display_name'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditWriter $audit,
    ) {}

    public function handle(UpdateEmployee $command): EmployeeView
    {
        $named = array_keys($command->fields);

        $protected = array_values(array_intersect($named, self::PROTECTED_FIELDS));

        if ($protected !== []) {
            throw AccessRefused::validation(
                'An employee update may not name ['.implode(', ', $protected).']; '
                .'each of those has its own operation.'
            );
        }

        $unknown = array_values(array_diff($named, self::UPDATABLE_FIELDS));

        if ($unknown !== []) {
            throw AccessRefused::validation('An employee update may not name ['.implode(', ', $unknown).'].');
        }

        if ($named === []) {
            throw AccessRefused::validation('An employee update names no field to change.');
        }

        $values = [];

        foreach (self::UPDATABLE_FIELDS as $field) {
            if (! array_key_exists($field, $command->fields)) {
                continue;
            }

            $value = $command->fields[$field];

            // `last_name` given as null clears it; the other two are
            // required to be strings, because an employee with no first
            // name or no display name is a row nothing can render.
            if ($value === null && $field === 'last_name') {
                $values[$field] = null;

                continue;
            }

            if (! is_string($value)) {
                throw AccessRefused::validation("Field [{$field}] must be a string.");
            }

            $trimmed = trim($value);

            if ($trimmed === '') {
                throw AccessRefused::validation("Field [{$field}] may not be blank.");
            }

            $limit = $field === 'display_name' ? 200 : 100;

            if (mb_strlen($trimmed) > $limit) {
                throw AccessRefused::validation("Field [{$field}] is at most {$limit} characters.");
            }

            $values[$field] = $trimmed;
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $values): EmployeeView {
            $employee = EmployeeLookup::findLive($connection, $command->employeeId);

            // Read from the row, and only the fields this update names: an
            // audit `before` listing fields nobody touched would make
            // `changed_fields` the only honest part of the line.
            $before = [];

            foreach (array_keys($values) as $field) {
                $before[$field] = $employee->{$field} === null ? null : (string) $employee->{$field};
            }

            $connection->table('employees')
                ->where('id', $command->employeeId)
                ->update($values + [
                    'updated_by' => $command->actorId,
                    'updated_at' => $this->clock->timestamp(),
                ]);

            $this->audit->write(
                $connection,
                $command->actorId,
                'employee_updated',
                'employee',
                $command->employeeId,
                $before,
                $values,
            );

            $views = EmployeeLookup::render($connection, [
                EmployeeLookup::findLive($connection, $command->employeeId),
            ]);

            return $views[0];
        });
    }
}
