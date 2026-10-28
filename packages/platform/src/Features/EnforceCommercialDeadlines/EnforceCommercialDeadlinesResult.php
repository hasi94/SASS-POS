<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\EnforceCommercialDeadlines;

final class EnforceCommercialDeadlinesResult
{
    public function __construct(
        public readonly int $examined,
        public readonly int $changed,
        public readonly int $failed,
    ) {}
}
