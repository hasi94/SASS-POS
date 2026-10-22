<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ReissueActivationCode;

/**
 * Replace a pending terminal's activation code (T-014 AC-28).
 */
final class ReissueActivationCode
{
    public function __construct(
        public readonly mixed $terminalId,
        public readonly ?string $actorId = null,
    ) {}
}
