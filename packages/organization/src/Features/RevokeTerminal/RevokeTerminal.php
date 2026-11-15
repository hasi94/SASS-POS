<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\RevokeTerminal;

/**
 * Revoke a terminal for good (T-014 AC-38 to AC-40).
 */
final class RevokeTerminal
{
    public function __construct(
        public readonly mixed $terminalId,
        public readonly ?string $actorId = null,
    ) {}
}
