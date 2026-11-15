<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Contracts\Access\AccessDecision;
use Flexipos\Contracts\Access\PermissionResolver;

/**
 * A resolver that caches its answers without the employee's `perm_version` -
 * and so answers from before the change that bumped it.
 *
 * **It keeps AC-46 falsifiable, for the reason {@see MisKeyedCacheResolver}
 * keeps AC-45 so.** The shipped resolver reads the tables on every question
 * and holds no memo, so "the answer reflects the change" cannot fail; the
 * criterion is only meaningful beside something that *can* fail it, and this
 * is that something.
 *
 * It keys by tenant as well as by employee, key and scope - everything the
 * mis-keyed double omits is present here - so the staleness this double
 * shows is attributable to the one field it leaves out. That is also why
 * `perm_version` is the right field to omit rather than, say, a timestamp:
 * AC-52 makes the version the one value every qualifying change moves, so a
 * cache that carried it could not serve an answer from before the change.
 *
 * Nothing in `packages/access/src` resolves or references this class.
 */
final class VersionBlindCacheResolver implements PermissionResolver
{
    /** @var array<string, AccessDecision> */
    private array $answers = [];

    public function __construct(
        private readonly PermissionResolver $inner,
        private readonly string $tenant,
    ) {}

    public function decide(string $employeeId, string $permissionKey, ?string $branchId): AccessDecision
    {
        // The defect, in one line: perm_version is not in the key.
        $key = $this->tenant.'|'.$employeeId.'|'.$permissionKey.'|'.($branchId ?? '*');

        return $this->answers[$key] ??= $this->inner->decide($employeeId, $permissionKey, $branchId);
    }
}
