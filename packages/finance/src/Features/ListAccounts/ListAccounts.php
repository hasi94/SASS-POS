<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ListAccounts;

/**
 * List live accounts, keyset-paged by `(code, id)` (T-032 AC-61).
 *
 * Every value is `mixed`: a caller's page size, cursor and filter values are
 * validated by the handler, never trusted by a type declaration that would
 * turn `"50"` into 50 before it could be refused.
 */
final class ListAccounts
{
    /**
     * @param  array<array-key, mixed>  $filters  `type`, `subtype`, `parent_id`, `is_active`, `is_postable`, `control_type`, `q`
     */
    public function __construct(
        public readonly array $filters = [],
        public readonly mixed $pageSize = null,
        public readonly mixed $afterCode = null,
        public readonly mixed $afterId = null,
        public readonly ?string $actorId = null,
    ) {}
}
