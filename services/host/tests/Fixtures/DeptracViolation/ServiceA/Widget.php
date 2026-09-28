<?php

namespace Tests\Fixtures\DeptracViolation\ServiceA;

/**
 * Stands in for an internal class of one service package.
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
