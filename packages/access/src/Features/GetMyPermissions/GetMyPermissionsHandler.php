<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\GetMyPermissions;

use Flexipos\Access\Support\EmployeeBranches;
use Flexipos\Access\Support\PermHash;
use Flexipos\Access\Support\ScopeKey;
use Flexipos\Access\Support\TenantConnection;

/**
 * The caller's own effective access, read live from the tenant database
 * (AC-60).
 *
 * **Live, and that is the whole point of the endpoint.** The permission
 * list is deliberately not in the token (D-2: size and staleness), so a
 * client that wants to know what it may do asks here and gets the
 * database's answer, not the answer that was true when the token was
 * minted. A client comparing the `perm_hash` returned here with the one
 * in its token learns that it is behind - which is the cheap signal the
 * hash exists for.
 *
 * **The contributions are the hash's own list** (D-23), read through
 * {@see PermHash::contributionsFor()}. It must be: a client that
 * recomputed the hash from this response and got a different value would
 * have no way to tell a format change from a race, and the till has to be
 * able to do exactly that (AC-54).
 *
 * **Contributions, not resolved rights.** What comes back is what was
 * granted, including denials, with the scope each was granted at - not
 * "may I do X at branch Y", which is {@see
 * \Flexipos\Contracts\Access\PermissionResolver}'s question and is
 * answered per question. Resolving every key against every branch has no
 * finite form without a `branches` table, which is the same reason the
 * hash is over contributions.
 *
 * **It reads nothing about anybody else** (AC-65). There is no subject in
 * the command and no join to another employee, so there is no shape of
 * this response that could carry another person's version, hash or PIN
 * state.
 */
final class GetMyPermissionsHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function handle(GetMyPermissions $query): MyPermissionsView
    {
        if ($query->employeeId === null) {
            return MyPermissionsView::none();
        }

        $connection = $this->tenant->get();

        $employee = $connection->table('employees')
            ->where('id', $query->employeeId)
            ->whereNull('deleted_at')
            ->first(['perm_version']);

        if ($employee === null) {
            // The staleness check (D-7) refuses such a token one layer
            // up, so this is unreachable over HTTP; answering "nothing"
            // rather than inventing a version keeps it unreachable
            // *and* harmless if a later caller arrives another way.
            return MyPermissionsView::none();
        }

        /** @var object{perm_version: int|string} $employee */
        $permVersion = (int) $employee->perm_version;
        $contributions = PermHash::contributionsFor($connection, $query->employeeId);

        $rendered = [];

        foreach ($contributions as [$allowed, $key, $scope]) {
            $rendered[] = ['key' => $key, 'scope' => $scope, 'allowed' => $allowed];
        }

        // Sorted, and by the whole row rather than by the key: two
        // identical calls must return the same bytes, and the order rows
        // come back from two joined tables is the engine's business.
        usort(
            $rendered,
            static fn (array $a, array $b): int => [$a['key'], $a['scope'], $a['allowed']]
                <=> [$b['key'], $b['scope'], $b['allowed']]
        );

        return new MyPermissionsView(
            permVersion: $permVersion,
            permHash: PermHash::compute($permVersion, $contributions),
            branchIds: EmployeeBranches::of($connection, $query->employeeId),
            allBranches: ScopeKey::anyAllowsEveryBranch($contributions),
            contributions: array_values(array_unique($rendered, SORT_REGULAR)),
        );
    }
}
