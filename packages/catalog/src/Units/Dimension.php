<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Units;

/**
 * `units.dimension` (T-027 AC-11; `docs/database.md` §6.3): the one thing
 * `UnitLadder`'s pre-check compares before it ever looks at a conversion
 * row or the base-unit chain (AC-10).
 */
enum Dimension: string
{
    case Count = 'count';
    case Mass = 'mass';
    case Volume = 'volume';
    case Length = 'length';
    case Area = 'area';
}
