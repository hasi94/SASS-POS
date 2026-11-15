<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning\Steps;

use Flexipos\Kernel\Tenancy\Provisioning\TenantMappingWriter;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\ProvisioningStep;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;

/**
 * `write_mapping`, the last of D-20's four real-engine steps (T-026 AC-19).
 *
 * Builds AC-19's row entirely from the three earlier steps' own journalled
 * output - `reserve_identity` (tenant id, code, names), `select_server`
 * (server id) and `create_database` (the charset/collation it actually
 * created) - and hands it to {@see TenantMappingWriter::insert()}, which is
 * itself the idempotent half (a resumed attempt finds its own row). This
 * step's own job is only the second half AC-19 asks for: when a row already
 * exists that is *not* this run's own write (its values differ), refuse
 * rather than silently accept somebody else's row as if it were this run's.
 */
final class WriteMapping implements ProvisioningStep
{
    /**
     * The fields a mismatch is checked against - everything AC-19 names
     * except the identifiers the unique keys (`tenant_id`, `database_identifier`)
     * already guarantee cannot belong to a different reservation.
     *
     * @var list<string>
     */
    private const COMPARED_FIELDS = [
        'server_id',
        'database_identifier',
        'database_name',
        'app_username',
        'app_credential_ref',
        'migrate_username',
        'migrate_credential_ref',
        'charset',
        'collation',
    ];

    public function __construct(
        private readonly TenantMappingWriter $mappingWriter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(RunContext $context, StepJournal $journal): array
    {
        $reserved = $journal->outputOf(StepName::ReserveIdentity);
        $server = $journal->outputOf(StepName::SelectServer);
        $database = $journal->outputOf(StepName::CreateDatabase);
        $credentials = $journal->outputOf(StepName::StoreCredentials);

        $row = [
            'tenant_id' => (string) $reserved['tenant_id'],
            'server_id' => (string) $server['server_id'],
            'database_identifier' => (string) $reserved['code'],
            'database_name' => (string) $reserved['database_name'],
            'app_username' => (string) $reserved['app_username'],
            'app_credential_ref' => (string) $credentials['app_credential_ref'],
            'migrate_username' => (string) $reserved['migrate_username'],
            'migrate_credential_ref' => (string) $credentials['migrate_credential_ref'],
            'charset' => (string) $database['charset'],
            'collation' => (string) $database['collation'],
            'status' => 'provisioning',
            'profile' => 'standard',
        ];

        $existing = $this->mappingWriter->insert($row);

        foreach (self::COMPARED_FIELDS as $field) {
            if ((string) ($existing[$field] ?? '') !== $row[$field]) {
                throw PlatformRefused::validation(
                    "write_mapping found an existing tenant_databases row for this tenant whose [{$field}] ".
                    'differs from this run\'s own value; it was not written by this run and is not adopted.'
                );
            }
        }

        return ['database_identifier' => (string) $existing['database_identifier']];
    }

    public function compensate(RunContext $context): ?string
    {
        // Archiving the mapping (if it exists at all) is abandon's own job
        // (Ruling 10, step 16): TenantMappingWriter::archive() is already a
        // no-op for a run abandoned before this step ever wrote a row.
        return null;
    }
}
