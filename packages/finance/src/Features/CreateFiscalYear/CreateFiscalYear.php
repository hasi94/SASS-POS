<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\CreateFiscalYear;

/**
 * Create a fiscal year and its twelve periods (T-032 AC-31 to AC-35).
 */
final class CreateFiscalYear
{
    /**
     * @param  array<array-key, mixed>  $fields  `name` and `start_date`, nothing else
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
