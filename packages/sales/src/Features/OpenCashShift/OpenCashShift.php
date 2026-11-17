<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\OpenCashShift;

/**
 * Open a cash shift on a terminal (T-030 AC-32).
 */
final class OpenCashShift
{
    /**
     * @param  array<string, mixed>  $fields  `terminal_id` and `opening_cash`; the branch is the terminal's, never the caller's
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
