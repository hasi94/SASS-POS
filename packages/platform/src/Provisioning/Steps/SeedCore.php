<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning\Steps;

use Flexipos\Contracts\Access\TenantAccessBootstrap;
use Flexipos\Contracts\Organization\BranchSeed;
use Flexipos\Contracts\Organization\DefaultBranch;
use Flexipos\Kernel\Tenancy\Provisioning\SchemaMetaStamp;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\ProvisioningStep;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Support\MasterConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * `seed_core`, scoped to exactly what the shipped tree already has a
 * working, idempotent, per-tenant seed mechanism for (T-026 D-5, AC-25,
 * AC-25a, AC-26, AC-27).
 *
 * **Order, and why it is this order.** The `schema_meta` identity stamp
 * (AC-25a) first, then `seedPermissions()` (AC-25), then
 * {@see DefaultBranch::ensure()} (AC-26). Each later step depends on nothing
 * the earlier ones would refuse to have written, and a refusal from any of
 * them stops this step before the next one runs - a refused permission key
 * means no branch exists, and a refused branch means `create_owner` never
 * runs against a half-seeded tenant (AC-27).
 *
 * **Every write is already idempotent at the layer that does it** - never
 * re-implemented here. `SchemaMetaStamp::stamp()` is a no-op when the row
 * already names this tenant, `SyncPermissionRegistryHandler` behind
 * `seedPermissions()` reconciles rather than inserts blindly, and
 * `DefaultBranch::ensure()` reads the branch by code before ever calling
 * `CreateBranch`. A resumed `seed_core` therefore needs no journal-based
 * de-duplication of its own: one stamp, one branch, however many times this
 * runs.
 *
 * **Ruling 6's pre-check.** Before `Tenancy::run()` is ever called, this
 * tenant's own `tenant_databases.status` is re-read from the master and must
 * still be `provisioning` - the property that keeps provisioning from ever
 * writing into a live tenant, re-checked at every one of the three steps
 * that open the tenant connection rather than assumed once at the top of the
 * run.
 */
final class SeedCore implements ProvisioningStep
{
    public function __construct(
        private readonly MasterConnection $master,
        private readonly Tenancy $tenancy,
        private readonly TenantAccessBootstrap $access,
        private readonly DefaultBranch $branch,
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(RunContext $context, StepJournal $journal): array
    {
        $reserved = $journal->outputOf(StepName::ReserveIdentity);
        $tenantId = (string) $reserved['tenant_id'];

        $this->assertStillProvisioning($tenantId);

        return $this->tenancy->run(
            $tenantId,
            function () use ($context, $tenantId): array {
                SchemaMetaStamp::stamp($this->tenantConnection(), $tenantId);

                $refused = $this->access->seedPermissions();

                if ($refused !== []) {
                    throw PlatformRefused::validation(
                        'seed_core: the permission registry refused these keys: '.implode(', ', $refused)
                    );
                }

                $branchId = $this->branch->ensure(new BranchSeed(
                    tenantName: (string) $context->input['name'],
                    timezone: (string) $context->input['timezone'],
                    currency: (string) $context->input['currency'],
                    businessType: (string) $context->input['business_type'],
                    code: $context->input['branch_code'] ?? null,
                    name: $context->input['branch_name'] ?? null,
                ));

                return ['branch_id' => $branchId];
            },
            TenantConnectionPurpose::Migrating
        );
    }

    public function compensate(RunContext $context): ?string
    {
        // Nothing this step wrote is dropped by abandon: the tenant
        // database itself is never touched that way (D-21), and the rows
        // this step seeded are left exactly where an archived tenant's
        // database leaves everything else.
        return null;
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
                "seed_core refuses: tenant [{$tenantId}]'s mapping status is [{$named}], not [provisioning]."
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
