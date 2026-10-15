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
    /**
     * Whether the hold is in force. **A constant, and that is the fix for the
     * way it used to be clearable.**
     *
     * It was read from `ENGINE_HOLD` in the environment, with
     * `modules/host/phpunit.xml` declaring the value. MEASURED 2026-10-15 by
     * `qa`: `ENGINE_HOLD=0` in the shell won over that declaration, because
     * PHPUnit's `<env>` does not overwrite a variable that is already set and
     * the reader preferred `$_SERVER` anyway - so `ENGINE_HOLD=0 composer check`
     * opened a PDO to the owner's engine in the gate's first step, which is the
     * same engine the incident below happened on.
     *
     * Adding `force="true"` to the declaration would close that one path. A
     * constant closes the class of path: a hold that any single environment
     * variable can switch off is the same kind of thing as a guard an
     * environment variable can widen, and the reserved-name prefix is a
     * constant for that reason. Clearing this hold is an edit to this line, in
     * a commit, by the owner - which is what "the owner's decision" means.
     *
     * The declaration in phpunit.xml stays, forced on both `<env>` and
     * `<server>`, so that no reader of the variable anywhere - a tool, a child
     * process - can see anything but the held value either.
     */
    public const HELD = true;

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
