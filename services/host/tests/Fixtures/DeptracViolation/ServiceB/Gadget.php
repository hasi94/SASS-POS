<?php

namespace Tests\Fixtures\DeptracViolation\ServiceB;

use Tests\Fixtures\DeptracViolation\ServiceA\Widget;

/**
 * Reaches into another service package's internals on purpose.
 *
 * This is the boundary violation DeptracBoundaryEnforcementTest expects Deptrac
 * to report. It is isolated in its own fixture configuration, so it is never a
 * violation of the shipped deptrac.yaml.
 */
class Gadget
{
    public function borrow(): string
    {
        return (new Widget)->name();
    }
}
