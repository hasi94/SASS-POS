<?php

declare(strict_types=1);

namespace Flexipos\Testing\Engine;

use RuntimeException;

/**
 * Every connection to the test engine is suspended.
 *
 * Set by `ENGINE_HOLD` in modules/host/phpunit.xml, defaulting to on, after the
 * data-loss incident of 2026-10-14: a permissive stub of the name guard was
 * handed to an engine-wide sweep and sixteen of the owner's databases were
 * dropped, none of them backed up. While any chance of file-level recovery
 * remains, nothing in this suite may write to that server, because every write
 * reuses the disk blocks the dropped files occupied.
 *
 * It throws rather than skips, for the same reason an unreachable engine throws
 * (AC-63): a suspended engine suite must be visible in the gate, not quietly
 * absent from it. Clearing the hold is the owner's decision, not an agent's.
 */
final class EngineOnHold extends RuntimeException
{
    public static function sinceTheIncident(): self
    {
        return new self(
            'The engine test suite is on hold (ENGINE_HOLD). On 2026-10-14 an engine-wide sweep driven '
            .'by a deliberately permissive stub of ReservedDatabaseName dropped sixteen of the owner\'s '
            .'databases on this engine, including dayananda_hidetest, demo_pos and flexhms_glow. There is '
            .'no backup. Until the owner has decided whether file-level recovery will be attempted, no '
            .'test may write to this server: every write can overwrite the blocks the dropped files used. '
            .'Clearing this hold - ENGINE_HOLD=0 in modules/host/phpunit.xml - is the owner\'s call.'
        );
    }
}
