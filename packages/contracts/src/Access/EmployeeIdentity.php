<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

use InvalidArgumentException;

/**
 * The identity facts of one live employee that another package may read
 * (T-038 plan Ruling 3, D-44).
 *
 * Exactly the access-owned fields a directory shows: id, code, names and
 * status. Nothing about a PIN, a user link or a permission version is here,
 * so a package holding one of these cannot leak them.
 */
final readonly class EmployeeIdentity
{
    public function __construct(
        public string $id,
        public string $code,
        public string $firstName,
        public ?string $lastName,
        public string $displayName,
        public string $status,
    ) {
        $required = [
            'id' => $id,
            'code' => $code,
            'firstName' => $firstName,
            'displayName' => $displayName,
            'status' => $status,
        ];

        foreach ($required as $name => $value) {
            if ($value === '') {
                throw new InvalidArgumentException("[{$name}] must not be empty.");
            }
        }

        if ($lastName === '') {
            throw new InvalidArgumentException('[lastName] must be null or not empty.');
        }
    }
}
