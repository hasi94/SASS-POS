<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\UpdateBranch;

/**
 * Change the editable fields of a branch (T-014 AC-12).
 */
final class UpdateBranch
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly mixed $branchId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
