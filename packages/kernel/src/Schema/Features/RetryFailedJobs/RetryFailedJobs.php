<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\RetryFailedJobs;

/**
 * Make the failed jobs of one run claimable again (AC-46).
 *
 * One run, never "every failed job everywhere": a retry is a decision about a
 * run whose cause an operator has just removed, and a retry across runs would
 * resurrect jobs from a run somebody abandoned on purpose.
 *
 * `tenantIds` narrows it further, to the tenants the operator named. Null is
 * "every failed job of this run", which is what `--retry-failed` without a
 * tenant means.
 */
final readonly class RetryFailedJobs
{
    /**
     * @param  list<string>|null  $tenantIds  null for every failed job of the run
     */
    public function __construct(
        public string $runId,
        public ?array $tenantIds = null,
    ) {}
}
