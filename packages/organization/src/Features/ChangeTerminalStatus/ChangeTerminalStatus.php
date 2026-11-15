<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ChangeTerminalStatus;

/**
 * Pause or resume a terminal (T-014 AC-37). `$status` is the state asked
 * for, `inactive` or `active`, taken as submitted so anything else is
 * refused as `validation`.
 */
final class ChangeTerminalStatus
{
    public function __construct(
        public readonly mixed $terminalId,
        public readonly mixed $status,
        public readonly ?string $actorId = null,
    ) {}
}
