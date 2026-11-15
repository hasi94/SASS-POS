<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ChangeBranchStatus;

/**
 * Deactivate or reactivate a branch (T-014 AC-14).
 *
 * `$isActive` is the state asked for, taken as submitted so the handler can
 * refuse a non-boolean as `validation`.
 */
final class ChangeBranchStatus
{
    public function __construct(
        public readonly mixed $branchId,
        public readonly mixed $isActive,
        public readonly ?string $actorId = null,
    ) {}
}
