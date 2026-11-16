<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * Implemented by a package's service provider to declare the
 * {@see AccountReferrers} it provides (T-032 Ruling 2).
 *
 * The same shape as `DeclaresAuditActions` and `DeclaresPermissions`: Finance
 * sweeps the loaded providers rather than listing other packages, so it never
 * names catalog or customers.
 */
interface DeclaresAccountReferrers
{
    /**
     * @return list<class-string<AccountReferrers>>
     */
    public function accountReferrers(): array;
}
