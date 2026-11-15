<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Engine;

/**
 * What a step's votes currently amount to (T-020 Ruling 7).
 */
enum StepState
{
    case Open;
    case Satisfied;
    case Rejected;
}
