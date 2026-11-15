<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Support;

use Flexipos\Contracts\Events\EventConsumer;
use Flexipos\Contracts\Events\EventEnvelope;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * T-018's one proof-of-pipeline example consumer (Ruling 1, AC-34).
 *
 * **Imports nothing under `Flexipos\Eventbus\`** - only the published
 * contract `Flexipos\Contracts\Events\EventConsumer` and a framework
 * facade. That is the whole point: a consumer needs nothing but the
 * contract, and `QaEventbusSourceRulesTest` asserts it structurally.
 *
 * Idempotent by `$event->id` (AC-23): inside one tenant transaction, checks
 * for a marker row keyed by the event id and applies the effect only if it
 * is absent - the recommended shape {@see EventConsumer::handle()}'s own
 * docblock asks for. `$throwTimes` and `$skipIdempotencyCheck` are test
 * controls, not production behaviour.
 */
final class PingedConsumer implements EventConsumer
{
    public const GROUP = 'fixture.pinged';

    public const EVENT_TYPE = 'fixture.pinged';

    public static int $throwTimes = 0;

    public static int $callCount = 0;

    public static int $effectsApplied = 0;

    /**
     * The discriminating control (step 7): true skips the "already applied"
     * check, so a redelivery is handled - and counted - twice.
     */
    public static bool $skipIdempotencyCheck = false;

    public static function reset(): void
    {
        self::$throwTimes = 0;
        self::$callCount = 0;
        self::$effectsApplied = 0;
        self::$skipIdempotencyCheck = false;
    }

    public function group(): string
    {
        return self::GROUP;
    }

    public function eventTypes(): array
    {
        return [self::EVENT_TYPE];
    }

    public function handle(EventEnvelope $event): void
    {
        self::$callCount++;

        if (self::$throwTimes > 0) {
            self::$throwTimes--;

            throw new RuntimeException('PingedConsumer: simulated failure.');
        }

        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));

        $connection->transaction(function () use ($connection, $event): void {
            if (! self::$skipIdempotencyCheck) {
                $exists = $connection->table('fixture_pings')->where('event_id', $event->id)->exists();

                if ($exists) {
                    return;
                }
            }

            self::$effectsApplied++;

            $connection->table('fixture_pings')->insertOrIgnore([
                'event_id' => $event->id,
                'effects_applied' => self::$effectsApplied,
            ]);
        });
    }
}
