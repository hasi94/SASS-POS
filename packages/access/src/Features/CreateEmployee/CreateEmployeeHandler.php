<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\CreateEmployee;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\AuditWriter;
use Flexipos\Access\Support\EmployeeSeats;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Access\Support\EmployeeView;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Contracts\Platform\LimitKey;
use Flexipos\Contracts\Platform\PlanLimits;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;

/**
 * Create an employee (AC-24, AC-25).
 *
 * **The code is checked against every row, deleted or not.** `employees.code`
 * is unique over a NOT NULL column so the index already covers soft-deleted
 * rows; this check exists to make that a `validation` refusal rather than an
 * integrity error, and it has to scan the same rows the index does. A code is
 * never reused because payroll and audit history would otherwise be ambiguous
 * (D-14).
 *
 * **`user_id` is checked for shape and for uniqueness, and for nothing
 * else.** It names `users.id` in the **master** database and a foreign key
 * cannot cross databases, so there is nothing to check existence against from
 * here - and the mint-time check (D-6) is where a wrong link is caught, on the
 * connection that can see both facts. Two employees with a null `user_id` are
 * both accepted (AC-7): a cashier with a PIN and no web login is the normal
 * case.
 *
 * **Whitespace inside a code is refused, around it is trimmed.** A code is
 * compared exactly as stored (D-14), so ` C001 ` and `C001` must not be able
 * to become two employees; and `C 001` is refused outright rather than
 * silently collapsed, because inventing a normalisation would make two codes
 * a shop considers different into one.
 */
final class CreateEmployeeHandler
{
    /** `employees.code` is VARCHAR(20). */
    public const MAX_CODE_LENGTH = 20;

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditWriter $audit,
        private readonly PlanLimits $planLimits,
    ) {}

    public function handle(CreateEmployee $command): EmployeeView
    {
        $code = trim($command->code);
        $firstName = trim($command->firstName);
        $lastName = $command->lastName === null ? null : trim($command->lastName);
        $displayName = $command->displayName === null ? null : trim($command->displayName);
        $userId = $command->userId === null ? null : trim($command->userId);
        $status = $command->status === null ? EmployeeStatus::ACTIVE : trim($command->status);

        if ($code === '') {
            throw AccessRefused::validation('An employee needs a code.');
        }

        if (mb_strlen($code) > self::MAX_CODE_LENGTH) {
            throw AccessRefused::validation('An employee code is at most '.self::MAX_CODE_LENGTH.' characters.');
        }

        if (preg_match('/\s/', $code) === 1) {
            throw AccessRefused::validation("Employee code [{$code}] contains whitespace.");
        }

        if ($firstName === '') {
            throw AccessRefused::validation('An employee needs a first name.');
        }

        foreach (['first name' => $firstName, 'last name' => $lastName] as $what => $value) {
            if ($value !== null && mb_strlen($value) > 100) {
                throw AccessRefused::validation("An employee {$what} is at most 100 characters.");
            }
        }

        if ($displayName !== null && mb_strlen($displayName) > 200) {
            throw AccessRefused::validation('An employee display name is at most 200 characters.');
        }

        if ($userId !== null && ! Str::isUlid($userId)) {
            throw AccessRefused::validation("User id [{$userId}] is not a ULID.");
        }

        if (! EmployeeStatus::exists($status)) {
            throw AccessRefused::validation("Employee status [{$status}] is not one of the declared statuses.");
        }

        // AC-24: the trimmed first and last names joined by exactly one
        // space, and the first name alone when there is no last one.
        $displayName = $displayName === null || $displayName === ''
            ? trim($firstName.' '.($lastName ?? ''))
            : $displayName;

        // T-016 AC-1: resolved before the transaction, asserted first inside
        // it, and only when the new status holds a seat (Ruling 7) - creating
        // a resigned or terminated employee spends no quota.
        $employeeLimit = EmployeeSeats::holdsSeat($status)
            ? $this->planLimits->forCurrentTenant(LimitKey::Employees)
            : null;

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $code, $firstName, $lastName, $displayName, $userId, $status, $command, $employeeLimit): EmployeeView {
            if ($employeeLimit !== null) {
                $employeeLimit->assertRoomFor(EmployeeSeats::count($connection));
            }

            if ($connection->table('employees')->where('code', $code)->exists()) {
                throw AccessRefused::validation("Employee code [{$code}] is already used.");
            }

            if ($userId !== null && $connection->table('employees')->where('user_id', $userId)->exists()) {
                throw AccessRefused::validation("User id [{$userId}] already belongs to another employee.");
            }

            $id = (string) Str::ulid();
            $now = $this->clock->timestamp();

            $connection->table('employees')->insert([
                'id' => $id,
                'code' => $code,
                'user_id' => $userId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'display_name' => $displayName,
                'status' => $status,
                // pin_hash, pin_failed_attempts, pin_locked_until and
                // perm_version are left to the column defaults: the initial
                // version is the schema's fact, not this handler's.
                'created_by' => $command->actorId,
                'updated_by' => $command->actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->audit->write(
                $connection,
                $command->actorId,
                'employee_created',
                'employee',
                $id,
                null,
                [
                    'code' => $code,
                    'user_id' => $userId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'display_name' => $displayName,
                    'status' => $status,
                ],
            );

            return new EmployeeView($id, $code, $firstName, $lastName, $displayName, $status, false, []);
        });
    }
}
