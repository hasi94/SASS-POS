<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Support;

use Flexipos\Contracts\Auth\TerminalSessions;
use RuntimeException;

/**
 * A `TerminalSessions` double for the slice tests (T-014 step 10).
 *
 * The slices ask auth for two facts and the slice tests are about neither, so
 * the double answers a fixed tenant part and records the revocations it was
 * asked for. **It is bound over the contract, never over a concrete class**,
 * and the real implementation (`MasterTerminalSessions`) is proved against the
 * master in step 13; nothing here stands in for that proof.
 *
 * `$failing` makes `revokeAll()` throw, which is the fault injection AC-39
 * needs.
 */
final class FixedTerminalSessions implements TerminalSessions
{
    /** @var list<array{string, string}> */
    public array $revoked = [];

    public function __construct(
        public string $tenantPart = 'TEN-000123',
        public bool $failing = false,
        public bool $missingTenant = false,
    ) {}

    public function tenantPartFor(string $tenantId): string
    {
        if ($this->missingTenant) {
            throw new RuntimeException("No live tenant has id [{$tenantId}].");
        }

        return $this->tenantPart;
    }

    public function revokeAll(string $tenantId, string $terminalId): int
    {
        if ($this->failing) {
            throw new RuntimeException('The master is unavailable.');
        }

        $this->revoked[] = [$tenantId, $terminalId];

        return 1;
    }
}
