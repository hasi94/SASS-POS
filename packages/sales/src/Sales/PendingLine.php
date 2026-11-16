<?php

declare(strict_types=1);

namespace Flexipos\Sales\Sales;

/**
 * A sale line that has been priced and is waiting for its locks (T-030 AC-4).
 *
 * Built by {@see LineWriter::price()} **before** the write transaction opens
 * (Ruling 7, item 1), so nothing here depends on a lock. `$columns` are the
 * `sale_items` columns the price decides, and nothing else; the id, the sale,
 * the line number and the product snapshots are added by the write that owns
 * them.
 *
 * `$overridePayload` is the approval binding of a price override
 * (`{product_id, unit_id, unit_price}`) and is null for an ordinary quote;
 * `$discountId` is the discount the line carries.
 */
final readonly class PendingLine
{
    /**
     * @param  array<string, mixed>  $columns
     * @param  array{product_id: string, unit_id: string, unit_price: string}|null  $overridePayload
     */
    public function __construct(
        public array $columns,
        public ?string $discountId,
        public ?array $overridePayload,
    ) {}
}
