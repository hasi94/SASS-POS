<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\CreateTerminal;

/**
 * Create a terminal and its one-time activation code (T-014 AC-26).
 */
final class CreateTerminal
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
