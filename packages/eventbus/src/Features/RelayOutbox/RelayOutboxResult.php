<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\RelayOutbox;

/**
 * What one relay pass did (T-018 AC-14 to AC-19).
 */
final readonly class RelayOutboxResult
{
    public function __construct(
        public int $published,
        public int $retried,
        public int $exhausted,
        public int $skipped,
    ) {}
}
