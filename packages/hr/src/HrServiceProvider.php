<?php

declare(strict_types=1);

namespace Flexipos\Hr;

use Illuminate\Support\ServiceProvider;

/**
 * The HR package's entry point into the host application (T-038).
 *
 * Registered explicitly in `modules/host/bootstrap/providers.php`, the way
 * the finance provider is (plan Ruling 1). It is empty at this step: the
 * permission and audit-action declarations, the org-chart binding and the
 * employee-delete census arrive with the steps that build what they declare.
 *
 * It declares no route, no command and no job (D-38).
 */
final class HrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
}
