<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning\Steps;

use Flexipos\Contracts\Access\TenantAccessBootstrap;
use Flexipos\Contracts\Organization\DefaultBranch;
use Flexipos\Kernel\Schema\Features\MigrateTenant\SchemaMetaIdentity;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\ProvisioningStep;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Support\MasterConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * `verify` (T-026 D-17, AC-32).
 *
 * **Re-checks, never trusts** (D-17): every assertion below reads the tenant
 * connection or the master directly, rather than the `output` any of
 * `seed_core`/`create_owner` already journalled - the same convention
 * `docs/decisions` states outright, so a false-positive two steps back cannot
 * compound into `activate` running against an unverified tenant.
 *
 * **Seven assertions, each named in its own refusal** (AC-32): the migrated
 * schema's `migrations` table is exactly `migrate_remainder`'s own target
 * set, no more and no fewer; the `permissions`/`roles` tables hold `owner`
 * with every declared key ({@see TenantAccessBootstrap::ownerMissingKeys()});
 * `schema_meta`'s `tenant_id` row matches this tenant's own master id
 * ({@see SchemaMetaIdentity::of()}, closing AC-25a's loop); the branch
 * `seed_core` created still exists; the owner employee `create_owner`
 * created is attached to that branch and holds `owner`; and the owner
 * employee ↔ master `tenant_users` link, checked in **both** directions
 * (review round 1 W-8): the tenant-side employee's own `user_id` names
 * `create_owner`'s journalled user, AND the master `tenant_users` row for
 * that exact (tenant, user) pair names that same employee back - closing
 * both ends of the loop rather than only confirming a row with the right
 * user id exists somewhere. Any mismatch fails this step with the specific
 * assertion named, and `activate` never runs - it is the next step in
 * sequence, and this step throwing stops the run exactly as every other
 * step's failure does.
 *
 * **Ruling 6's pre-check**, same as `seed_core`/`create_owner`: this tenant's
 * `tenant_databases.status` must still be `provisioning` before the tenant
 * connection is ever opened.
 */
final class Verify implements ProvisioningStep
{
    public function __construct(
        private readonly MasterConnection $master,
        private readonly Tenancy $tenancy,
        private readonly TenantAccessBootstrap $access,
        private readonly DefaultBranch $branch,
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly Application $app,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(RunContext $context, StepJournal $journal): array
    {
        $reserved = $journal->outputOf(StepName::ReserveIdentity);
        $tenantId = (string) $reserved['tenant_id'];

        $this->assertStillProvisioning($tenantId);

        $seeded = $journal->outputOf(StepName::SeedCore);
        $branchId = (string) $seeded['branch_id'];
        $owner = $journal->outputOf(StepName::CreateOwner);
        $expectedUserId = (string) $owner['user_id'];

        $expectedMigrations = array_map(
            static fn ($entry): string => $entry->migration,
            $this->set()->catalogue()
        );

        $ownerEmployeeId = null;

        $this->tenancy->run(
            $tenantId,
            function () use ($tenantId, $branchId, $expectedMigrations, $expectedUserId, &$ownerEmployeeId): void {
                $connection = $this->tenantConnection();

                $actualMigrations = $connection->table('migrations')
                    ->pluck('migration')
                    ->map(static fn ($name): string => (string) $name)
                    ->all();

                $this->assertSameSet('migrations', $expectedMigrations, $actualMigrations);

                $missingKeys = $this->access->ownerMissingKeys();

                if ($missingKeys !== []) {
                    throw PlatformRefused::validation(
                        'verify: the owner role is missing declared keys: '.implode(', ', $missingKeys)
                    );
                }

                $actualTenantId = SchemaMetaIdentity::of($connection);

                if ($actualTenantId !== $tenantId) {
                    throw PlatformRefused::validation(
                        "verify: schema_meta's tenant_id is [".($actualTenantId ?? 'null')."], expected [{$tenantId}]."
                    );
                }

                if (! $this->branch->exists($branchId)) {
                    throw PlatformRefused::validation("verify: branch [{$branchId}] does not exist.");
                }

                $owner = $this->access->ownerState();

                if ($owner === null) {
                    throw PlatformRefused::validation('verify: no OWNER-coded employee exists.');
                }

                if (! in_array($branchId, $owner['branch_ids'], true)) {
                    throw PlatformRefused::validation(
                        "verify: the owner employee is not attached to branch [{$branchId}]."
                    );
                }

                if (! $owner['holds_owner']) {
                    throw PlatformRefused::validation('verify: the owner employee does not hold the owner role.');
                }

                // Direction 1 (tenant -> master): the employee this tenant
                // actually holds as OWNER must itself be linked to the exact
                // user create_owner journalled - not merely some tenant_users
                // row existing for that user elsewhere (review round 1 W-8).
                if ($owner['user_id'] !== $expectedUserId) {
                    throw PlatformRefused::validation(
                        "verify: the owner employee's own user_id is [".($owner['user_id'] ?? 'null')."], expected [{$expectedUserId}]."
                    );
                }

                $ownerEmployeeId = $owner['employee_id'];
            },
            TenantConnectionPurpose::Migrating
        );

        $link = $this->master->get()->table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $expectedUserId)
            ->first();

        if ($link === null) {
            throw PlatformRefused::validation(
                "verify: no tenant_users row links user [{$expectedUserId}] to tenant [{$tenantId}]."
            );
        }

        // Direction 2 (master -> tenant): that same tenant_users row must
        // name the owner employee back, not some other employee (review
        // round 1 W-8's other half).
        /** @var object{employee_id: ?string} $link */
        if ((string) $link->employee_id !== (string) $ownerEmployeeId) {
            throw PlatformRefused::validation(
                "verify: tenant_users links user [{$expectedUserId}] to employee [".($link->employee_id ?? 'null')."], expected the owner employee [{$ownerEmployeeId}]."
            );
        }

        return [];
    }

    public function compensate(RunContext $context): ?string
    {
        // verify writes nothing of its own; there is nothing to undo.
        return null;
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $actual
     */
    private function assertSameSet(string $label, array $expected, array $actual): void
    {
        sort($expected);
        sort($actual);

        if ($expected !== $actual) {
            throw PlatformRefused::validation(
                "verify: the [{$label}] table does not match migrate_remainder's own target set exactly."
            );
        }
    }

    /**
     * The shipped tenant tree, exactly as `migrate_remainder` built it
     * (Ruling 8.1).
     */
    private function set(): MigrationSet
    {
        return MigrationSet::baseline(
            $this->app->basePath((string) $this->config->get('schema.tenant_migration_path'))
        );
    }

    /**
     * @throws PlatformRefused unless this tenant's mapping is still
     *                         `provisioning` (Ruling 6)
     */
    private function assertStillProvisioning(string $tenantId): void
    {
        $status = $this->master->get()->table('tenant_databases')->where('tenant_id', $tenantId)->value('status');

        if ($status !== 'provisioning') {
            $named = $status === null ? 'unmapped' : (string) $status;

            throw PlatformRefused::validation(
                "verify refuses: tenant [{$tenantId}]'s mapping status is [{$named}], not [provisioning]."
            );
        }
    }

    private function tenantConnection(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->database->connection((string) $this->config->get('tenancy.connection', 'tenant'));

        return $connection;
    }
}
