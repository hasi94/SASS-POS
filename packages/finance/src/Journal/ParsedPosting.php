<?php

declare(strict_types=1);

namespace Flexipos\Finance\Journal;

use Flexipos\Contracts\Finance\JournalDimension;
use Flexipos\Contracts\Finance\JournalSourceType;

/**
 * A posting request after every pure check: normalised and typed, ready for
 * the service's database steps (T-033 Ruling 1).
 *
 * Built only by {@see PostingInput::parse}. Each line is in stored form: ids
 * upper case, amounts at four decimals, text trimmed or null, and **all seven
 * dimensions present** in {@see JournalDimension}
 * order, null where the request named none.
 *
 * @phpstan-type ParsedLine array{account_id: string, debit: string, credit: string, memo: ?string, dimensions: array<string, ?string>}
 */
final readonly class ParsedPosting
{
    /**
     * @param  list<ParsedLine>  $lines
     */
    public function __construct(
        public JournalSourceType $sourceType,
        public ?string $sourceId,
        public ?string $sourcePurpose,
        public string $entryDate,
        public ?string $description,
        public ?string $branchId,
        public array $lines,
        public string $totalDebit,
        public string $totalCredit,
        public ?string $actorId,
    ) {}
}
