<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Fixtures\FixtureEcho;

use Flexipos\Contracts\Plugins\AfterHook;
use Flexipos\Contracts\Plugins\AfterHookCall;
use Illuminate\Support\Facades\DB;

/**
 * The proof-of-pipeline fixture's `after` handler (T-019 AC-27, AC-29,
 * AC-44). **Idempotent by `$call->eventId`**, the same shape
 * `Flexipos\Eventbus\Tests\Support\PingedConsumer` uses: a marker row in
 * its own `plg_fixture_echo_after_effects`, checked for inside the
 * handler's own savepoint before the effect is applied.
 *
 * `$applyAttempts` counts every time execution reaches past the
 * app-level idempotency check, win or lose - unlike the effect table's
 * row count, it is not something the table's `event_id` primary key can
 * explain by itself, so it is what AC-29's control case asserts on to
 * prove the app-level check (not the PK) is what suppressed the second
 * call.
 */
final class EchoAfter implements AfterHook
{
    public static bool $skipIdempotencyCheck = false;

    public static int $applyAttempts = 0;

    public static function reset(): void
    {
        self::$skipIdempotencyCheck = false;
        self::$applyAttempts = 0;
    }

    public function after(AfterHookCall $call): void
    {
        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));

        $connection->transaction(function () use ($connection, $call): void {
            if (! self::$skipIdempotencyCheck) {
                $exists = $connection->table('plg_fixture_echo_after_effects')
                    ->where('event_id', $call->eventId)
                    ->exists();

                if ($exists) {
                    return;
                }
            }

            self::$applyAttempts++;

            $connection->table('plg_fixture_echo_after_effects')->insertOrIgnore([
                'event_id' => $call->eventId,
                'point' => $call->point,
            ]);
        });
    }
}
