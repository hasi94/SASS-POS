<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Support;

use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use PHPUnit\Framework\Assert;

/**
 * Run an operation that must be refused, and hand back the refusal (the
 * `SalesRefusals` shape).
 *
 * **It fails when nothing is thrown**, which a bare `try`/`catch` keeps
 * forgetting: a catch block alone passes silently when the operation succeeds,
 * the exact outcome a guard test exists to detect.
 */
final class PurchasingRefusals
{
    /**
     * @param  callable(): mixed  $operation
     */
    public static function capture(callable $operation): PurchasingRefused
    {
        try {
            $operation();
        } catch (PurchasingRefused $refused) {
            return $refused;
        }

        Assert::fail('The operation was expected to be refused and succeeded.');
    }

    /**
     * @param  callable(): mixed  $operation
     */
    public static function expect(PurchasingRefusal $reason, callable $operation, ?string $context = null): PurchasingRefused
    {
        $refused = self::capture($operation);

        $where = $context === null ? '' : " [{$context}]";

        Assert::assertSame(
            $reason,
            $refused->reason,
            "Expected [{$reason->value}] and got [{$refused->reason->value}]{$where}: {$refused->getMessage()}"
        );

        return $refused;
    }

    /**
     * Every table a purchasing write can touch, read whole and in a stable
     * order, so "a refusal leaves everything as it was" is one comparison
     * (AC-90): the six purchasing tables, the audit trail, the outbox, the
     * number sequences and the workflow instances.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function state(): array
    {
        $state = [];

        foreach ([
            'suppliers', 'purchase_requests', 'purchase_request_items', 'purchase_orders',
            'purchase_order_items', 'purchase_order_receipt_applications',
            'audit_logs', 'outbox_events', 'number_sequences', 'workflow_instances',
        ] as $table) {
            $state[$table] = PurchasingSeed::connection()->table($table)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        }

        return $state;
    }
}
