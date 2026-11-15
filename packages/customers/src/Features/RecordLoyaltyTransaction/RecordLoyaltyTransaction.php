<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\RecordLoyaltyTransaction;

/**
 * Record one `loyalty_transactions` row (T-028 AC-27 to AC-33; plan Ruling
 * 6).
 */
final class RecordLoyaltyTransaction
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
