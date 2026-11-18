<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Hr;

/**
 * Implemented by a package's service provider to declare the
 * {@see HrReferrers} it provides (T-038 plan Ruling 4).
 *
 * HR sweeps the loaded providers rather than naming other packages. The
 * default "no referrers" is the empty sweep.
 */
interface DeclaresHrReferrers
{
    /**
     * @return list<class-string<HrReferrers>>
     */
    public function hrReferrers(): array;
}
