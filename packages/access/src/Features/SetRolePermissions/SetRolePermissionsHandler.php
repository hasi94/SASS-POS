<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\SetRolePermissions;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\Escalation;
use Flexipos\Access\Support\PermVersion;
use Flexipos\Access\Support\RoleLookup;
use Flexipos\Access\Support\TenantConnection;

/**
 * Make a role hold exactly these keys, and bump everybody who holds it
 * (AC-20, AC-52).
 *
 * **All or nothing, and the validation happens inside the transaction.** A
 * list containing one unregistered key changes no row at all. The check is
 * one read of `permissions` against the whole requested set rather than a
 * key-at-a-time loop that writes as it goes: the loop's failure leaves the
 * earlier keys written and the later ones not, which is the half-applied
 * state AC-20 refuses.
 *
 * **The bump counts the holders the statement touched.** Every employee
 * holding this role **at any scope** is affected, because what the role
 * confers has changed at every scope it was granted at, and
 * {@see PermVersion::bumpHoldersOfRole()} selects them inside its own
 * `UPDATE` - there is no window in which a holder assigned a moment ago is
 * missed.
 *
 * **An unchanged set still bumps, and that is deliberate.** Setting a role
 * to the keys it already holds raises its holders' versions by one. AC-52's
 * "not bumped" list does not include this operation, and the converse rule
 * (AC-53 (c)) says only that a change implies a bump, never that an
 * unchanged write must not. Detecting "the set is identical" would be a
 * second opinion about what counts as a change, and the cost of being wrong
 * about it is a stale token rather than a spare one.
 *
 * **Step 14 adds AC-36's third clause**: an actor may only add a key they
 * hold company-wide. It is checked for the keys being **added** and not
 * for the whole requested set, because a request that merely re-states a
 * key the role already holds grants nobody anything new - and checking the
 * whole set would stop an actor removing a key from a role while leaving
 * in place one they do not themselves hold.
 */
final class SetRolePermissionsHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly Escalation $escalation,
    ) {}

    public function handle(SetRolePermissions $command): SetRolePermissionsResult
    {
        $keys = array_values(array_unique(array_map(
            static fn (string $key): string => trim($key),
            $command->permissionKeys
        )));

        sort($keys);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $keys): SetRolePermissionsResult {
            $role = RoleLookup::findLive($connection, $command->roleId);

            RoleLookup::requireCustom($role);

            if ($keys !== []) {
                $registered = $connection->table('permissions')
                    ->whereIn('key', $keys)
                    ->pluck('key')
                    ->map(static fn ($key): string => (string) $key)
                    ->all();

                $unregistered = array_values(array_diff($keys, $registered));

                if ($unregistered !== []) {
                    throw AccessRefused::validation(
                        'These permission keys are not registered: ['.implode(', ', $unregistered).'].'
                    );
                }
            }

            // AC-36: adding a key to a role requires the actor to hold that
            // key company-wide. Only the keys being ADDED are checked -
            // taking one away raises nobody - and the set is computed
            // against what the role holds right now, inside the
            // transaction, rather than against what a caller believed.
            $added = array_values(array_diff($keys, $connection->table('role_permissions')
                ->where('role_id', $command->roleId)
                ->pluck('permission_key')
                ->map(static fn ($key): string => (string) $key)
                ->all()));

            foreach ($added as $key) {
                $this->escalation->requireHeld($command->actorId, $key, null);
            }

            // Replace, not merge (AC-20). The delete and the inserts are one
            // statement each and both are inside this transaction, so a
            // reader never sees the role holding nothing.
            $connection->table('role_permissions')->where('role_id', $command->roleId)->delete();

            if ($keys !== []) {
                $connection->table('role_permissions')->insert(array_map(
                    static fn (string $key): array => [
                        'role_id' => $command->roleId,
                        'permission_key' => $key,
                    ],
                    $keys
                ));
            }

            $bumped = PermVersion::bumpHoldersOfRole($connection, $command->roleId);

            return new SetRolePermissionsResult($command->roleId, $keys, $bumped);
        });
    }
}
