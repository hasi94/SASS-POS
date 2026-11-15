<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\CreateWarehouse;

/**
 * Create a warehouse, which belongs to a branch or to none (T-014 AC-21).
 *
 * A `branch_store` exists only through `CreateBranch`, and the other four
 * location types are FP-251's (D-11), so this command creates warehouses and
 * its `type` field, when present, must say so.
 */
final class CreateWarehouse
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
