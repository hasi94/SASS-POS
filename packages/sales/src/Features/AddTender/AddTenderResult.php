<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\AddTender;

final class AddTenderResult
{
    public function __construct(
        public readonly string $tenderId,
        public readonly bool $replayed,
        public readonly string $amount,
        public readonly ?string $tendered,
        public readonly string $changeGiven,
    ) {}
}
