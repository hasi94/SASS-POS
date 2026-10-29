<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Audit;

/**
 * Implemented by a module's service provider to declare the audit actions
 * that module writes (T-017 Ruling 3).
 *
 * **The same shape as `Flexipos\Contracts\Access\DeclaresPermissions`,
 * for the same reason.** "Has this writer ever used this (module, action)
 * pair" is answered by a declared catalogue, not by scanning the table:
 * reading the table empirically would refuse every valid filter against a
 * fresh shop, which is exactly the case
 * `Flexipos\Access\Features\SearchAuditLog\SearchAuditLog` must accept.
 *
 * It lives in `packages/contracts` because a module implementing it must not
 * have to depend on `packages/access` to do so.
 */
interface DeclaresAuditActions
{
    /**
     * Every audit action this provider's package writes, keyed by module.
     *
     * @return array<string, list<string>>
     */
    public function auditActions(): array;
}
