<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\RefreshTerminalToken;

/**
 * A terminal's next token pair, in the same family as the one it presented
 * (T-014 AC-36).
 */
final class RefreshTerminalTokenResult
{
    public function __construct(
        public readonly string $terminalId,
        public readonly string $branchId,
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly string $familyId,
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
