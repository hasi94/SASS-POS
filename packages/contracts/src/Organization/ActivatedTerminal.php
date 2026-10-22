<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Organization;

/**
 * The terminal a successful activation produced: what the token needs and
 * nothing else (T-014 AC-33).
 */
final class ActivatedTerminal
{
    public function __construct(
        public readonly string $terminalId,
        public readonly string $branchId,
    ) {}
}
