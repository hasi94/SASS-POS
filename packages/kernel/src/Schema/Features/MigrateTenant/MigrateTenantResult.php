<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\MigrateTenant;

/**
 * What one job did, as the runner saw it.
 *
 * `claimRetained` is the field a reader will not expect, and it is the
 * observable behind the completion statement's re-check: when the lease expired
 * while the migration was running and another worker claimed the job, the
 * completing `UPDATE` affects zero rows and this is false. The migration itself
 * still happened, so the mapping mirror is written either way - the database
 * changed, and a bookkeeping race is not a reason to record a version the
 * tenant does not carry.
 *
 * It carries no credential and no credential reference: it is built from the
 * job row, the applied migration names and a sanitised error text.
 */
final readonly class MigrateTenantResult
{
    /**
     * @param  list<string>  $applied  the migrations this call applied, in order
     */
    public function __construct(
        public string $jobId,
        public string $tenantId,
        public string $databaseName,
        public bool $succeeded,
        public int $schemaVersion,
        public array $applied,
        public int $durationMs,
        public bool $claimRetained,
        public ?string $errorClass = null,
        public ?string $errorMessage = null,
        public ?string $errorMigration = null,
    ) {}
}
