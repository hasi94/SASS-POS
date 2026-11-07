<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning\Steps;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\ProvisioningRuns;
use Flexipos\Platform\Provisioning\ProvisioningStep;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Support\MasterConnection;

/**
 * `select_server` (T-026 AC-13, AC-14).
 *
 * Reads `database_servers` and nothing else: no row here is ever written by
 * this step - `tenant_count` only moves at `activate` (AC-14), so a run that
 * fails after this step but before completing has not reserved capacity
 * nobody can see was ever used.
 *
 * **Automatic selection.** Every row with `accepts_new_tenants = true`,
 * `status = 'active'` and `tenant_count < max_tenants` qualifies; the
 * lowest `tenant_count` wins the tie. When nothing qualifies, the refusal
 * names *why* every candidate was excluded, one reason per excluded server,
 * rather than a single generic "no server available".
 *
 * **`--server=<code>` override.** The named row is selected only if it
 * still satisfies the same three conditions; a failing override refuses
 * outright, with no fallback to automatic selection - the operator asked for
 * a specific server, so a silent substitution would be the wrong kind of
 * helpful.
 *
 * **Resume.** The server, once chosen, is journalled and never
 * re-evaluated: `$journal->read()` is consulted first, so a resumed attempt
 * reuses the same selection even if capacity has since moved elsewhere.
 */
final class SelectServer implements ProvisioningStep
{
    public function __construct(
        private readonly MasterConnection $master,
        private readonly ProvisioningRuns $runs,
        private readonly RunnerClock $clock,
    ) {}

    public function run(RunContext $context, StepJournal $journal): array
    {
        $journalled = $journal->read();

        if (isset($journalled['server_id'])) {
            return $journalled;
        }

        $serverCode = $context->input['server_code'] ?? null;

        $server = $serverCode === null
            ? $this->selectAutomatically()
            : $this->selectOverride((string) $serverCode);

        $output = [
            'server_id' => (string) $server->id,
            'server_code' => (string) $server->code,
            'host' => (string) $server->host,
            'port' => (int) $server->port,
            'engine' => (string) $server->engine,
            'tls_mode' => (string) $server->tls_mode,
            'provisioner_credential_ref' => (string) $server->provisioner_credential_ref,
        ];

        // Journalled before the pointer write below, so a crash between the
        // two leaves a resumed attempt the exact choice already made -
        // Ruling 2's "intent first" - rather than a re-evaluation that could
        // land on a different server once capacity has moved.
        $journal->write($output);

        $this->runs->setTargetServerId($context->runId, $output['server_id'], $this->clock->timestamp());

        return $output;
    }

    public function compensate(RunContext $context): ?string
    {
        // Nothing was ever written to database_servers (AC-14): there is
        // nothing to undo.
        return null;
    }

    private function selectAutomatically(): object
    {
        $servers = $this->master->get()->table('database_servers')->get();

        $qualifying = $servers->filter(fn (object $server): bool => $this->qualifies($server));

        if ($qualifying->isNotEmpty()) {
            /** @var object $winner */
            $winner = $qualifying->sortBy('tenant_count')->first();

            return $winner;
        }

        $reasons = [];

        foreach ($servers as $server) {
            $excluded = $this->exclusionReasons($server);

            if ($excluded !== []) {
                $reasons[] = $server->code.' ('.implode(', ', $excluded).')';
            }
        }

        if ($reasons === []) {
            throw PlatformRefused::notFound('No database server is configured.');
        }

        throw PlatformRefused::notFound(
            'No database server qualifies for a new tenant: '.implode('; ', $reasons).'.'
        );
    }

    private function selectOverride(string $code): object
    {
        $server = $this->master->get()->table('database_servers')->where('code', $code)->first();

        if ($server === null) {
            throw PlatformRefused::notFound("No database server named [{$code}].");
        }

        $excluded = $this->exclusionReasons($server);

        if ($excluded !== []) {
            throw PlatformRefused::validation(
                "The --server={$code} override does not qualify: ".implode(', ', $excluded).'.'
            );
        }

        return $server;
    }

    private function qualifies(object $server): bool
    {
        return $this->exclusionReasons($server) === [];
    }

    /**
     * @return list<string>
     */
    private function exclusionReasons(object $server): array
    {
        $reasons = [];

        if ((bool) $server->accepts_new_tenants === false) {
            $reasons[] = 'accepts_new_tenants = false';
        }

        if ((string) $server->status !== 'active') {
            $reasons[] = "status = '{$server->status}'";
        }

        if ((int) $server->tenant_count >= (int) $server->max_tenants) {
            $reasons[] = "tenant_count {$server->tenant_count} >= max_tenants {$server->max_tenants}";
        }

        return $reasons;
    }
}
