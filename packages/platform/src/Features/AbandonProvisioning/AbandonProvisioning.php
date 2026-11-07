<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\AbandonProvisioning;

/**
 * `tenant:provision --abandon=<run id>` (T-026 Ruling 10, AC-9).
 *
 * One field, because abandon asks nothing of the operator beyond which run:
 * everything it needs to compensate is already journalled on that run.
 */
final class AbandonProvisioning
{
    public function __construct(
        public readonly string $runId,
    ) {}
}
