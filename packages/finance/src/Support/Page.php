<?php

declare(strict_types=1);

namespace Flexipos\Finance\Support;

/**
 * One keyset page of rows (T-032 plan Ruling 10, AC-61).
 *
 * The cursor is `(code, id)` for accounts: pass `nextAfterCode` and
 * `nextAfterId` back together to get the next page. Both are null on the
 * last page. A list ordered by something else (years, by `start_date`) uses
 * `nextAfterCode` for that key and leaves `nextAfterId` null.
 */
final readonly class Page
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $nextAfterCode,
        public ?string $nextAfterId,
    ) {}
}
