<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Contracts\Access\AccessDecision;
use Flexipos\Contracts\Access\PermissionResolver;

/**
 * A resolver that caches its answers on the employee id alone - and so hands
 * one tenant another tenant's answer.
 *
 * **It exists to keep AC-45 falsifiable, and it is a statement about the
 * criterion rather than about the shipped resolver.** The shipped resolver
 * has no cache (Ruling 5), so "each answer is that tenant's own" is
 * satisfied structurally: there is nothing to mis-key. A criterion whose
 * only evidence is the absence of a mechanism is one nobody can tell from a
 * criterion nobody tested, so this double supplies the failing case the
 * criterion is written against - the same question, the same worker, two
 * tenants, and an answer that belongs to the first of them.
 *
 * Nothing in `packages/access/src` resolves or references this class, and
 * nothing may: it is the wrong answer, written down on purpose.
 *
 * The key omits exactly one field, the tenant, so that what the test
 * demonstrates is attributable to that omission and not to a double that is
 * broken in some other way.
 */
final class MisKeyedCacheResolver implements PermissionResolver
{
    /** @var array<string, AccessDecision> */
    private array $answers = [];

    public function __construct(private readonly PermissionResolver $inner) {}

    public function decide(string $employeeId, string $permissionKey, ?string $branchId): AccessDecision
    {
        // The defect, in one line: the tenant is not in the key.
        $key = $employeeId.'|'.$permissionKey.'|'.($branchId ?? '*');

        return $this->answers[$key] ??= $this->inner->decide($employeeId, $permissionKey, $branchId);
    }
}
