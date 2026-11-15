<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\AbandonProvisioning;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\ProvisioningServer;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\TenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\TenantMappingWriter;
use Flexipos\Kernel\Tenancy\Secrets\WritableSecretStore;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\ProvisioningRunner;
use Flexipos\Platform\Provisioning\ProvisioningRuns;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Support\MasterConnection;

/**
 * `abandon` (T-026 Ruling 10, AC-9). Compensates a `failed` run, or a stale
 * `running` one, through the same compare-and-set lease `--resume` claims
 * (Ruling 2) - a `completed` run is refused by that one call, with nothing
 * written, exactly as a non-stale `running` run and an unknown id are.
 *
 * **Why none of this is reached through the eleven steps' own `compensate()`.**
 * Every step's `compensate()` returns `null` on purpose (each one's own
 * docblock says so): the three things abandon actually does -
 * `dropUser()` twice, `forget()` twice, and the two archival writes - cross
 * package/connection lines no single step is positioned to own, and D-21
 * forbids a `DROP DATABASE` from existing anywhere outside test
 * infrastructure, so there is nothing a per-step compensate() could safely
 * do with the database itself either. This handler is `abandon`'s own
 * compensation logic, reading every fact it needs from the run's own
 * journal - never from configuration - exactly as the Ruling 3 amendment
 * requires.
 *
 * **Order.** Engine side first (drop the two users, forget the two
 * secrets), then the two master archival writes, then every `done` step is
 * marked `compensated` (reverse sequence order, for the record), then the
 * run itself ends `rolled_back`. Nothing here is compare-and-set, because
 * the lease claim above is: once this handler holds the run, no other
 * process can also be compensating or resuming it.
 */
final class AbandonProvisioningHandler
{
    public function __construct(
        private readonly ProvisioningRunner $runner,
        private readonly ProvisioningRuns $runs,
        private readonly TenantDatabaseProvisioner $provisioner,
        private readonly WritableSecretStore $secrets,
        private readonly TenantMappingWriter $mappings,
        private readonly MasterConnection $master,
        private readonly RunnerClock $clock,
    ) {}

    public function handle(AbandonProvisioning $command): AbandonProvisioningResult
    {
        $run = $this->runner->claim($command->runId, $this->workerId());
        $runId = $command->runId;
        $tenantId = $run['tenant_id'] === null ? null : (string) $run['tenant_id'];

        // Review round 1 W-2: re-read the tenant's own live status before any
        // engine-side work. activate()'s master transaction can commit
        // (mapping active, tenant_count+1, subscription started, tenants.status
        // trial/active) before the runner itself marks the step/run done; a
        // crash in that exact gap leaves a failed/stale-running run that
        // claim() above would otherwise happily hand to this handler, which
        // would then drop a live tenant's database users and secrets.
        if ($tenantId !== null) {
            $this->assertNotYetLive($runId, $tenantId);
        }

        $steps = $this->runs->steps($runId);

        $statusOf = [];

        foreach ($steps as $row) {
            $statusOf[(string) $row['step']] = (string) $row['status'];
        }

        $server = ($statusOf[StepName::SelectServer->value] ?? null) === 'done'
            ? $this->serverFrom($this->runs->stepOutput($runId, StepName::SelectServer->value))
            : null;

        // Engine side: the two users this run itself created, if create_users
        // ever reached its own pre-existence check and found neither one a
        // foreign conflict - whatever that step's own status ended up being
        // (review round 1 W-1, widening from "status done", which missed a
        // crash between that check committing and either createUser() call
        // actually running). dropUser() is `DROP USER IF EXISTS`, so
        // attempting it against a user that check committed to but never
        // actually got created is a safe no-op (Ruling 10's own wording).
        $createUsersOutput = $this->runs->stepOutput($runId, StepName::CreateUsers->value);
        $createUsersChecked = ($createUsersOutput['checked'] ?? false) === true;
        $createUsersPreexisting = ($createUsersOutput['preexisting'] ?? false) === true;

        if ($server !== null && $createUsersChecked && ! $createUsersPreexisting) {
            $reserved = $this->runs->stepOutput($runId, StepName::ReserveIdentity->value);

            $this->provisioner->dropUser($server, (string) $reserved['app_username']);
            $this->provisioner->dropUser($server, (string) $reserved['migrate_username']);
        }

        // The two secret versions this run wrote, whenever store_credentials'
        // own journal actually holds them - regardless of that step's own
        // status (review round 1 W-1; forget() on a reference nothing wrote
        // is a no-op, so there is no harm in asking unconditionally).
        $credentials = $this->runs->stepOutput($runId, StepName::StoreCredentials->value);

        if (isset($credentials['app_credential_ref'])) {
            $this->secrets->forget((string) $credentials['app_credential_ref']);
        }

        if (isset($credentials['migrate_credential_ref'])) {
            $this->secrets->forget((string) $credentials['migrate_credential_ref']);
        }

        // Master side (Ruling 10): archive, never delete or drop. A run
        // that never reached reserve_identity has no tenant row to archive
        // at all. Compare-and-set on status = 'provisioning' (review round
        // 1 W-2): the last line of defence, in case this tenant somehow
        // went live between the re-check above and this write.
        if ($tenantId !== null) {
            $this->master->get()->table('tenants')
                ->where('id', $tenantId)
                ->where('status', 'provisioning')
                ->update([
                    'status' => 'archived',
                    'updated_at' => $this->clock->timestamp(),
                ]);

            // Already a no-op when write_mapping never ran (Ruling 10:
            // "abandon before write_mapping: only the tenant is archived").
            $this->mappings->archive($tenantId);
        }

        $now = $this->clock->timestamp();

        foreach (array_reverse($steps) as $row) {
            if ((string) $row['status'] === 'done') {
                $this->runs->markStepCompensated($runId, (string) $row['step'], null, $now);
            }
        }

        $this->runs->markRunRolledBack($runId, $now);

        $databaseName = $this->databaseNameOf($runId, $statusOf);
        $serverCode = $server?->code;

        return new AbandonProvisioningResult(
            runId: $runId,
            status: 'rolled_back',
            databaseName: $databaseName,
            serverCode: $serverCode,
            message: $databaseName === null
                ? 'This run never reached create_database; there is no database to drop.'
                : "The database itself was not dropped \u{2014} drop [{$databaseName}] on [{$serverCode}] by hand if you are certain nothing else needs it.",
        );
    }

    /**
     * Review round 1 W-2. `tenants.status` leaving `provisioning` (to
     * `trial`/`active`), or `tenant_databases.status` leaving `provisioning`
     * (to `active`), means `activate` already committed for real - abandon
     * must refuse and point the operator at `--resume` instead, which
     * completes safely against an already-active tenant (AC-35).
     *
     * @throws PlatformRefused the tenant is already live
     */
    private function assertNotYetLive(string $runId, string $tenantId): void
    {
        $tenant = $this->master->get()->table('tenants')->where('id', $tenantId)->first();
        $mapping = $this->master->get()->table('tenant_databases')->where('tenant_id', $tenantId)->first();

        $tenantStatus = $tenant === null ? null : (string) $tenant->status;
        $mappingStatus = $mapping === null ? null : (string) $mapping->status;

        if ($tenantStatus !== null && $tenantStatus !== 'provisioning') {
            throw PlatformRefused::validation(
                "abandon refuses: tenant [{$tenantId}] (run [{$runId}]) is already [{$tenantStatus}], not [provisioning] - "
                .'it was activated before this run was marked done. Use --resume instead; it completes safely against an already-active tenant.'
            );
        }

        if ($mappingStatus !== null && $mappingStatus !== 'provisioning') {
            throw PlatformRefused::validation(
                "abandon refuses: tenant [{$tenantId}]'s mapping (run [{$runId}]) is already [{$mappingStatus}], not [provisioning] - "
                .'it was activated before this run was marked done. Use --resume instead; it completes safely against an already-active tenant.'
            );
        }
    }

    /**
     * @param  array<string, string>  $statusOf
     */
    private function databaseNameOf(string $runId, array $statusOf): ?string
    {
        if (($statusOf[StepName::CreateDatabase->value] ?? null) === 'done') {
            $output = $this->runs->stepOutput($runId, StepName::CreateDatabase->value);

            return (string) $output['database_name'];
        }

        if (($statusOf[StepName::ReserveIdentity->value] ?? null) === 'done') {
            $output = $this->runs->stepOutput($runId, StepName::ReserveIdentity->value);

            return (string) $output['database_name'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function serverFrom(array $output): ProvisioningServer
    {
        return new ProvisioningServer(
            (string) $output['server_code'],
            (string) $output['host'],
            (int) $output['port'],
            (string) $output['engine'],
            (string) $output['tls_mode'],
            (string) $output['provisioner_credential_ref'],
        );
    }

    private function workerId(): string
    {
        return 'cli-'.(gethostname() ?: 'unknown').'-'.getmypid();
    }
}
