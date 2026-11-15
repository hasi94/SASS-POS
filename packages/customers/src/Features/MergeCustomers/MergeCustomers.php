<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\MergeCustomers;

/**
 * Merge a source customer into a target customer (T-028 AC-13, AC-14; plan
 * Ruling 9, D-20).
 */
final class MergeCustomers
{
    public function __construct(
        public readonly string $sourceId,
        public readonly string $targetId,
        public readonly ?string $actorId = null,
    ) {}
}
