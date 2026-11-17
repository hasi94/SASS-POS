<?php

declare(strict_types=1);

namespace Flexipos\Sales\Support;

/**
 * One keyset page of rows (T-030 D-26): `nextBeforeId` is null on the last page.
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
