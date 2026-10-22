<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\RevokeTerminal;

/**
 * What a revocation reports (T-014 Ruling 11, D-43).
 *
 * `sessionsRevoked` is the statement AC-39 asks for: the tenant side is
 * authoritative and always done by the time this exists, and this flag says
 * whether the second step - the master's refresh families - completed too.
 * False means "repeat the revoke"; it never means the terminal still works.
 */
final class RevokeTerminalResult
{
    public function __construct(
        public readonly string $terminalId,
        public readonly bool $sessionsRevoked,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toResponse(): array
    {
        return ['terminal_id' => $this->terminalId, 'status' => 'revoked', 'sessions_revoked' => $this->sessionsRevoked];
    }
}
