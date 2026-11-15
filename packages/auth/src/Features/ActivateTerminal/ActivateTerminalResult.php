<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\ActivateTerminal;

/**
 * What a successful activation returns: the terminal, its branch and its
 * first token pair (T-014 AC-29). It carries no activation code - the code was
 * consumed - and no tenant database name.
 */
final class ActivateTerminalResult
{
    public function __construct(
        public readonly string $terminalId,
        public readonly string $branchId,
        public readonly string $accessToken,
        public readonly string $refreshToken,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toResponse(): array
    {
        return [
            'terminal_id' => $this->terminalId,
            'branch_id' => $this->branchId,
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
        ];
    }
}
