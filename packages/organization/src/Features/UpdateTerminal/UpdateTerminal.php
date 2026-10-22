<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\UpdateTerminal;

/**
 * Change a terminal's name or selling location (T-014 AC-42).
 */
final class UpdateTerminal
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly mixed $terminalId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
