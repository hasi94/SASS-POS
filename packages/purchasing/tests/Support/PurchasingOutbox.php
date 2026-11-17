<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Support;

/**
 * The outbox rows a purchasing write left, decoded (T-034 AC-97).
 */
final class PurchasingOutbox
{
    /**
     * Every outbox row, oldest first by id, with the payload decoded.
     *
     * @return list<array<string, mixed>>
     */
    public static function events(): array
    {
        return PurchasingSeed::connection()->table('outbox_events')->orderBy('id')->get()
            ->map(static function (object $row): array {
                $a = (array) $row;
                $a['payload'] = $a['payload'] === null ? null : json_decode((string) $a['payload'], true, 512, JSON_THROW_ON_ERROR);

                return $a;
            })
            ->all();
    }

    /**
     * The types written, in order.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return array_map(static fn (array $event): string => (string) $event['type'], self::events());
    }

    /**
     * Forget what a test's set-up wrote, so the operation under test is read alone.
     */
    public static function clear(): void
    {
        PurchasingSeed::connection()->table('outbox_events')->delete();
    }
}
