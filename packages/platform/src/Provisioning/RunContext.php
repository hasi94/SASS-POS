<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning;

/**
 * What one step of a run is given to act on: the run's own id, its validated
 * input (the CLI options, AC-1/AC-2 - no secrets ever live here, that is the
 * run row's own "`input` holds no secrets" rule) and who initiated it.
 *
 * This is deliberately a plain, read-only value and not a repository: a step
 * reads another step's output through {@see StepJournal::outputOf()}, never
 * through this object, so "what can a step see" stays answerable from one
 * place.
 */
final class RunContext
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly string $runId,
        public readonly array $input,
        public readonly ?string $initiatedBy,
    ) {}
}
