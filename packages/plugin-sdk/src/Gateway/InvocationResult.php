<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Gateway;

use Flexipos\Contracts\Plugins\Veto;

/**
 * What {@see HookInvoker::invoke()} returns (T-019 plan Ruling 5).
 *
 * `$status` is one of `ok`, `veto`, `error`, `timeout`, `refused` - the
 * last one is the breaker's "skipped without calling the handler"
 * (AC-31), never written to `plugin_execution_logs` as a call that ran.
 */
final readonly class InvocationResult
{
    public function __construct(
        public string $status,
        public mixed $value = null,
        public ?Veto $veto = null,
        public bool $trippedNow = false,
    ) {}
}
