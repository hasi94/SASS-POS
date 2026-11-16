<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Support;

/**
 * One keyset page of rows (T-034 D-34): `nextCursor` is null on the last page,
 * otherwise the value to send back (`before_id` for documents, `after_code` for
 * suppliers).
 */
final readonly class Page
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $nextCursor,
    ) {}
}
