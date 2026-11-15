<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\ConsumeEvents;

/**
 * What one consume pass for one group did (T-018 AC-21 to AC-24, AC-27).
 */
final readonly class ConsumeEventsResult
{
    public function __construct(
        public int $handled,
        public int $acknowledgedNoop,
        public int $deadLettered,
        public int $reclaimed,
    ) {}
}
