<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning;

use RuntimeException;

/**
 * One step of a run failed (AC-3, AC-18). The run and the step rows are
 * already marked `failed` with the sanitised text by the time this is
 * thrown - this exception carries only that same sanitised message, never
 * the original throwable, so a caller that reads `getPrevious()` cannot
 * recover what the sanitiser just redacted.
 */
final class ProvisioningStepFailed extends RuntimeException
{
    public function __construct(
        public readonly StepName $step,
        string $sanitisedMessage,
    ) {
        parent::__construct("Provisioning step [{$step->value}] failed: {$sanitisedMessage}");
    }
}
