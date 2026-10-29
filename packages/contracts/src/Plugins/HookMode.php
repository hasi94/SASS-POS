<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Plugins;

/**
 * The five modes a hook point may accept (T-019 D-1, plan Ruling 1).
 *
 * `docs/architecture.md` §13: "a hook gateway with named extension points,
 * each declaring its mode: add, before which may veto, after which is
 * queued, filter which transforms, and override which is permitted only on
 * points explicitly marked overridable." The cases below are declared in
 * that exact order, which is also the order one `HookGateway::dispatch()`
 * call runs them in (plan Ruling 4): before, filter, override/action, add,
 * after.
 */
enum HookMode: string
{
    case Add = 'add';
    case Before = 'before';
    case After = 'after';
    case Filter = 'filter';
    case Override = 'override';
}
