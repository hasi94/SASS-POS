<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Plugins;

/**
 * An `after` handler (T-019 AC-27, AC-29): never called in-process. The
 * gateway writes one outbox row per registration instead, and this method
 * runs only once that row is consumed, asynchronously, possibly by a
 * different process entirely.
 *
 * **Must be idempotent by `$call->eventId`** (AC-29, inherited from T-018
 * AC-23): redelivery of the same event must never double-apply this
 * handler's effect.
 */
interface AfterHook
{
    public function after(AfterHookCall $call): void;
}
