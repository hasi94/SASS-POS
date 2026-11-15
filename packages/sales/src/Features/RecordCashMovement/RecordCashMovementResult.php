<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RecordCashMovement;

final class RecordCashMovementResult
{
    public function __construct(
        public readonly string $movementId,
        public readonly bool $replayed,
    ) {}
}
