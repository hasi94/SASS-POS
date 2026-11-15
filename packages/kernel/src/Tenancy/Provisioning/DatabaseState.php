<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Provisioning;

/**
 * What `create_database` finds when it asks (T-026 AC-5, Ruling 4).
 *
 * Three states, not two: `Absent` means create it; `Empty` is the shape a
 * resumed run's own earlier, half-recorded attempt leaves behind, and is
 * adopted; `Occupied` is a database this run did not create - a conflict,
 * refused rather than silently adopted.
 */
enum DatabaseState: string
{
    case Absent = 'absent';
    case Empty = 'empty';
    case Occupied = 'occupied';
}
