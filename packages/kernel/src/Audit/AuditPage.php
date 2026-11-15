<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Audit;

/**
 * One page of either audit search's result (T-017 Ruling 4).
 *
 * `nextBeforeId` is null on the last page. Both readers fetch `size + 1`
 * rows to know whether another page exists, then return at most `size` and
 * set this from the row they did not return.
 *
 * **`columns` is the caller's own fixed column list** (`AuditColumns::ALL`
 * on the tenant side; the platform reader's equivalent list), carried on
 * the page rather than read off the first row. A page can hold zero rows
 * and {@see AuditExport}'s CSV format still has to write a real header line
 * naming every column a row would have carried (AC-18's "a header only"
 * empty-page case) - which nothing can derive from an empty row list.
 */
final readonly class AuditPage
{
    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public ?int $nextBeforeId,
    ) {}
}
