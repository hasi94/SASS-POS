<?php

namespace Tests\Fixtures\DeptracViolation\ModuleB;

use Tests\Fixtures\DeptracViolation\ModuleA\Widget;

/**
 * Reaches into another module's internals on purpose.
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
