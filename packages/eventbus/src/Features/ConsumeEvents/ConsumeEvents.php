<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\ConsumeEvents;

/**
 * One pass for one consumer group (T-018 Ruling 7).
 */
final readonly class ConsumeEvents
{
    public function __construct(
        public string $group,
        public string $consumer,
    ) {}
}
