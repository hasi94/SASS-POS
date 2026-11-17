<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\RenameFiscalPeriod;

/**
 * Rename a fiscal period (T-032 AC-34, AC-36). Only `name` may be sent.
 */
final class RenameFiscalPeriod
{
    /**
     * @param  array<array-key, mixed>  $fields
     */
    public function __construct(
        public readonly string $periodId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
