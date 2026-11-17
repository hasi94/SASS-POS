<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * One line of a {@see PostingRequest} (T-033 Ruling 2).
 *
 * Every value is `mixed` on purpose and nothing is validated here: a value
 * object that threw from its constructor would let a refusal escape the fixed
 * order of checks (D-21). The service parses the whole request in one pass.
 * Amounts are decimal strings, never numbers (AC-17).
 *
 * `dimensions` is keyed by {@see JournalDimension} value; an absent key means
 * no dimension.
 */
final readonly class PostingLine
{
    /**
     * @param  array<array-key, mixed>  $dimensions
     */
    public function __construct(
        public mixed $accountId,
        public mixed $debit = '0',
        public mixed $credit = '0',
        public mixed $memo = null,
        public array $dimensions = [],
    ) {}
}
