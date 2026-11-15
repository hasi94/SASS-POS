<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\UpdateEmployee;

/**
 * One request to change an employee's names (AC-26, D-32).
 *
 * **It carries the fields as a map, and that is the design rather than
 * laziness.** D-32 requires a request *mentioning* `code`, `user_id`,
 * `pin_hash`, `perm_version` or `status` to be **refused**, and a command
 * with typed nullable properties cannot express "mentioned" at all: a null
 * `code` property is indistinguishable from an absent one, so the handler
 * could only ignore the field silently - the exact behaviour D-32 rejects,
 * because a silent ignore cannot be told from a field that was never wired.
 *
 * The map is therefore the request's own key set, and
 * {@see UpdateEmployeeHandler::PROTECTED_FIELDS} is the list it is checked
 * against.
 */
final class UpdateEmployee
{
    /**
     * @param  array<string, mixed>  $fields  exactly the fields the request named
     */
    public function __construct(
        public readonly string $employeeId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
