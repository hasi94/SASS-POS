<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Plugins;

/**
 * Returned by a {@see BeforeHook} to stop a dispatch (T-019 AC-23).
 */
final readonly class Veto
{
    public function __construct(
        public string $reason,
    ) {}
}
