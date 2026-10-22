<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\CreateTerminal;

/**
 * What creating a terminal returns: the ids, and the activation code **once**
 * (T-014 AC-26, AC-27). This object is the only place in the process that
 * holds the plaintext after the issuer; nothing persists it.
 */
final class CreateTerminalResult
{
    public function __construct(
        public readonly string $terminalId,
        public readonly string $activationCode,
        public readonly string $activationExpiresAt,
    ) {}
}
