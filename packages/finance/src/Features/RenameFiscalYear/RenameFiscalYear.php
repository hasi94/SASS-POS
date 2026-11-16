<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\RenameFiscalYear;

/**
 * Rename a fiscal year (T-032 AC-34, AC-36). Only `name` may be sent.
 */
final class RenameFiscalYear
{
    /**
     * @param  array<array-key, mixed>  $fields
     */
    public function __construct(
        public readonly string $fiscalYearId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
