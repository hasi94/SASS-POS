<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Exceptions;

use RuntimeException;

/**
 * The runner's own loop refused to continue.
 *
 * Both of these say that the runner's model of the world is wrong, which is
 * different from a tenant's migration failing: a failed tenant is recorded and
 * the run goes on (D-13), while these stop the invocation.
 *
 * {@see jobClaimedTwice()} is deliberately an exception and not an iteration
 * limit. A limit would be a guess about how many tenants a run may hold, and it
 * would turn the condition it is hiding - a job that keeps coming back
 * claimable because nothing is completing it - into a run that stopped early
 * and said nothing. The property is asserted instead of the bound.
 */
final class RunnerLoopRefused extends RuntimeException
{
    /**
     * The same job was claimed twice by one worker in one invocation.
     */
    public static function jobClaimedTwice(string $runId, string $jobId): self
    {
        return new self(
            'The job ['.$jobId.'] of run ['.$runId.'] was claimed twice in one invocation, so the'
            .' run is not making progress and the runner stopped rather than looping. The job is'
            .' being left claimable by whatever executed it; look at its status and attempts.'
        );
    }

    /**
     * An invocation named a run that does not exist.
     */
    public static function runNotFound(string $runId): self
    {
        return new self(
            'There is no run ['.$runId.'], so there is nothing to resume. A run is resumed by its'
            .' id; a new run is planned by not naming one.'
        );
    }
}
