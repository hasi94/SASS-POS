<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\CreateRole;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\RoleKey;
use Flexipos\Access\Support\RoleView;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;

/**
 * Create a custom role (AC-19).
 *
 * **The key is reserved across soft deletion, and the uniqueness check says
 * so in its own `where`.** `roles.key` is unique over a NOT NULL column, so
 * the database already refuses a reused key whether the holder is deleted
 * or not; the check here exists to turn that into a `validation` refusal
 * with a message instead of an integrity error, and it must therefore scan
 * the same rows the index does - every role, deleted or not. A check that
 * filtered out soft-deleted roles would pass this handler and then fail at
 * the database, which is the worst of both.
 *
 * **`is_system` is false and is written as a literal here.** There is no
 * field for it on the command: a shop must not be able to create a role the
 * shop itself cannot delete (AC-21 reads this flag). The only writer of a
 * system role is the registry sync.
 */
final class CreateRoleHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
    ) {}

    public function handle(CreateRole $command): RoleView
    {
        $key = trim($command->key);
        $name = trim($command->name);
        $description = $command->description === null ? null : trim($command->description);

        if ($key === '') {
            throw AccessRefused::validation('A role needs a key.');
        }

        if (! RoleKey::isValid($key)) {
            throw AccessRefused::validation(
                "Role key [{$key}] is not one lower-case identifier of at most ".RoleKey::MAX_LENGTH.' characters.'
            );
        }

        if ($name === '') {
            throw AccessRefused::validation('A role needs a name.');
        }

        if (mb_strlen($name) > 100) {
            throw AccessRefused::validation('A role name is at most 100 characters.');
        }

        if ($description !== null && mb_strlen($description) > 255) {
            throw AccessRefused::validation('A role description is at most 255 characters.');
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $key, $name, $description): RoleView {
            // Every role, including soft-deleted ones: a code or a key is
            // never reused, so audit history stays unambiguous (D-14's rule
            // for employees, and the same reason here).
            if ($connection->table('roles')->where('key', $key)->exists()) {
                throw AccessRefused::validation("Role key [{$key}] is already used.");
            }

            $id = (string) Str::ulid();
            $now = $this->clock->timestamp();

            $connection->table('roles')->insert([
                'id' => $id,
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'is_system' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return new RoleView($id, $key, $name, $description, false, []);
        });
    }
}
