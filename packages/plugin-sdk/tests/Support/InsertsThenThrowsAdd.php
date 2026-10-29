<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Support;

use Flexipos\Contracts\Plugins\AddHook;
use Flexipos\Contracts\Plugins\HookCall;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes into its own table, then throws - the savepoint control (T-019
 * plan step 10): the invoker's own transaction must roll this insert
 * back while the caller's action, outside this handler's savepoint,
 * keeps its commit.
 */
final class InsertsThenThrowsAdd implements AddHook
{
    public function add(HookCall $call, mixed $result): mixed
    {
        DB::connection((string) config('tenancy.connection', 'tenant'))
            ->table('gateway_savepoint_control')
            ->insert(['value' => 'handler-attempted']);

        throw new RuntimeException('InsertsThenThrowsAdd always fails after writing.');
    }
}
