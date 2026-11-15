<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\RetryFailedJobs;

/**
 * Which jobs a retry made claimable, and which it refused to.
 *
 * `exhausted` is reported rather than silently skipped: a job at the
 * configured attempt maximum is the one an operator most wants to be told
 * about, because it is the one the retry they just asked for will not touch
 * (D-20, AC-46).
 */
final readonly class RetryFailedJobsResult
{
    /**
     * @param  list<string>  $reset  job ids made `pending` again
     * @param  list<string>  $exhausted  job ids left `failed` at the maximum
     */
    public function __construct(
        public string $runId,
        public array $reset,
        public array $exhausted,
    ) {}
}
