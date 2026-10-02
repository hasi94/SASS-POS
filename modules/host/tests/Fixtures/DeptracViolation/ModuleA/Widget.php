<?php

namespace Tests\Fixtures\DeptracViolation\ModuleA;

/**
 * Stands in for an internal class of one module.
 *
 * Only ever analysed by the fixture Deptrac configuration next to it.
 */
class Widget
{
    public function name(): string
    {
        return 'widget';
    }
}
