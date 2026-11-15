<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\SyncPermissionRegistry;

use Flexipos\Access\Registry\PermissionRegistry;
use Flexipos\Access\Support\PermVersion;
use Flexipos\Access\Support\SystemRole;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * Make the tenant's `permissions` table say what the application declares
 * (AC-15, AC-16).
 *
 * **It never deletes a key.** A key that has disappeared from the code is
 * reported `orphaned` and left where it is, with every grant of it intact
 * (AC-16), because deleting it would cascade through `role_permissions` and
 * `employee_permissions` and silently remove rights - and the commonest
 * cause of a key vanishing is a module not booted in this process, which is
 * a deployment fact rather than a decision to revoke anything.
 *
 * **It never flips `is_branch_scoped` on an existing key.** That flag decides
 * how every stored grant of the key is read (D-8 (c)): turning it on would
 * make every null-scope grant stop conferring it, and turning it off would
 * make every branch-scoped grant start conferring it everywhere. Either is a
 * silent change to rights nobody asked for, so the key is left alone and
 * reported, and the rest of the sync proceeds (AC-15).
 *
 * **One transaction.** Either the whole reconciliation lands or none of it
 * does; a half-synced table is a set of keys whose provenance nobody can
 * state. The declaration set is read **before** the transaction opens, so a
 * set that cannot be reconciled with itself refuses with nothing written at
 * all rather than with a rollback.
 *
 * **It also keeps the `owner` role whole (AC-17, D-12).** `owner` holds every
 * declared key *by data*, never by a bypass in code (D-8 (f)), so a key that
 * nobody grants it is a key the shop's owner cannot use. Seeding and topping
 * up that role is the sync's job because the sync is the moment a new key
 * comes into existence, and doing it anywhere else would leave a window in
 * which the key exists and the owner does not hold it.
 *
 * The top-up **adds and never removes**: an orphaned key is reported, not
 * deleted (AC-16), and deleting `owner`'s grant of it would be removing a
 * right because a module was not booted in this process.
 *
 * Granting `owner` a new key changes what its holders may do, so every holder
 * is bumped **in the same transaction** through {@see PermVersion}, in one
 * atomic statement. A sync that grants nothing bumps nobody - which is a
 * fact about this method having nothing to do, not a guard to be remembered.
 */
final class SyncPermissionRegistryHandler
{
    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
    ) {}

    public function handle(SyncPermissionRegistry $command): SyncPermissionRegistryResult
    {
        // Outside the transaction on purpose: this is the call that refuses a
        // declaration set declaring one key two different ways, and a refusal
        // that never opened a transaction cannot have written a thing.
        $declared = $this->registry->declarations();

        return $this->tenant->get()->transaction(function (Connection $connection) use ($declared): SyncPermissionRegistryResult {
            /** @var array<string, object{key: string, module: string, group: string, description: string, is_branch_scoped: int|bool}> $stored */
            $stored = [];

            foreach ($connection->table('permissions')->get() as $row) {
                /** @var object{key: string, module: string, group: string, description: string, is_branch_scoped: int|bool} $row */
                $stored[(string) $row->key] = $row;
            }

            $inserted = [];
            $updated = [];
            $unchanged = [];
            $refused = [];

            foreach ($declared as $declaration) {
                $key = $declaration->key;

                if (! isset($stored[$key])) {
                    $connection->table('permissions')->insert([
                        'key' => $key,
                        'module' => $declaration->module,
                        'group' => $declaration->group,
                        'description' => $declaration->description,
                        'is_branch_scoped' => $declaration->isBranchScoped,
                    ]);

                    $inserted[] = $key;

                    continue;
                }

                $row = $stored[$key];

                if ((bool) $row->is_branch_scoped !== $declaration->isBranchScoped) {
                    $refused[] = $key;

                    continue;
                }

                if (
                    (string) $row->module === $declaration->module
                    && (string) $row->group === $declaration->group
                    && (string) $row->description === $declaration->description
                ) {
                    $unchanged[] = $key;

                    continue;
                }

                $connection->table('permissions')->where('key', $key)->update([
                    'module' => $declaration->module,
                    'group' => $declaration->group,
                    'description' => $declaration->description,
                ]);

                $updated[] = $key;
            }

            $declaredKeys = array_map(static fn ($declaration): string => $declaration->key, $declared);

            $orphaned = array_values(array_diff(array_keys($stored), $declaredKeys));
            sort($orphaned);

            // The keys owner must hold are the declared ones that are
            // actually stored: a key whose scope flip was refused is still a
            // row and is still granted, and a declared key that was refused
            // insertion does not exist to be granted.
            $grantable = array_values(array_intersect($declaredKeys, array_keys($this->storedKeys($connection))));

            [$ownerCreated, $granted, $bumped, $ownerNotSystem] = $this->keepOwnerWhole($connection, $grantable);

            return new SyncPermissionRegistryResult(
                $inserted,
                $updated,
                $unchanged,
                $orphaned,
                $refused,
                $ownerCreated,
                $granted,
                $bumped,
                $ownerNotSystem,
            );
        });
    }

    /**
     * Seed `owner` if it is missing, grant it every key it does not hold,
     * and bump its holders if anything was granted (AC-17).
     *
     * **Found by the flag as well as the key** (review S-3): every other
     * reader of the owner role reads `is_system`, never the key alone, and
     * this one grants every key there is. A row keyed `owner` that is not a
     * system role is somebody's custom role; it is reported in the result
     * and granted nothing, and no second `owner` can be seeded beside it
     * because `roles.key` is unique. Repairing it is a deliberate act.
     *
     * @param  list<string>  $keys  every key owner should hold
     * @return array{0: bool, 1: list<string>, 2: int, 3: bool}
     */
    private function keepOwnerWhole(Connection $connection, array $keys): array
    {
        $owner = $connection->table('roles')
            ->where('key', SystemRole::OWNER_KEY)
            ->where('is_system', true)
            ->first();

        if ($owner === null && $connection->table('roles')->where('key', SystemRole::OWNER_KEY)->exists()) {
            return [false, [], 0, true];
        }

        $created = $owner === null;

        if ($created) {
            $now = $this->clock->timestamp();
            $ownerId = (string) Str::ulid();

            $connection->table('roles')->insert([
                'id' => $ownerId,
                'key' => SystemRole::OWNER_KEY,
                'name' => SystemRole::OWNER_NAME,
                'description' => SystemRole::OWNER_DESCRIPTION,
                // The flag, not the key, is what every later refusal reads:
                // a shop must not be able to make its own role immune by
                // naming it `owner`.
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            /** @var object{id: string} $owner */
            $ownerId = (string) $owner->id;
        }

        $held = $connection->table('role_permissions')
            ->where('role_id', $ownerId)
            ->pluck('permission_key')
            ->map(static fn ($key): string => (string) $key)
            ->all();

        $missing = array_values(array_diff($keys, $held));
        sort($missing);

        if ($missing === []) {
            // Nothing granted, so nothing changed about anybody's rights, so
            // nothing is bumped. AC-17's "a second sync bumps nothing" is
            // this branch and not a check somewhere else.
            return [$created, [], 0, false];
        }

        $connection->table('role_permissions')->insert(array_map(
            static fn (string $key): array => ['role_id' => $ownerId, 'permission_key' => $key],
            $missing
        ));

        // In the same transaction as the grant, by one atomic statement,
        // selecting the holders inside it.
        $bumped = PermVersion::bumpHoldersOfRole($connection, $ownerId);

        return [$created, $missing, $bumped, false];
    }

    /**
     * The keys `permissions` holds right now, keyed by key.
     *
     * @return array<string, true>
     */
    private function storedKeys(Connection $connection): array
    {
        $keys = [];

        foreach ($connection->table('permissions')->pluck('key') as $key) {
            $keys[(string) $key] = true;
        }

        return $keys;
    }
}
