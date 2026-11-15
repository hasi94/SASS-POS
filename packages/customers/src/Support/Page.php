<?php

declare(strict_types=1);

namespace Flexipos\Customers\Support;

/**
 * One keyset page of rows (T-028 plan Ruling 11): newest-first by the ULID
 * `id`, `nextBeforeId` null on the last page.
 */
final readonly class Page
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $nextBeforeId,
    ) {}
}
