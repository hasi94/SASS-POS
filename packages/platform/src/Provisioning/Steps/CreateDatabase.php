<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning\Steps;

use Flexipos\Kernel\Tenancy\Provisioning\Contracts\ProvisioningServer;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\TenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\DatabaseState;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\ProvisioningStep;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * `create_database`, the first of D-20's three real-engine steps (T-026
 * AC-5 stand-in half, AC-15, AC-39).
 *
 * **Reads, never recomputes.** `database_name` comes from `reserve_identity`'s
 * own journalled output (AC-12); the server comes from `select_server`'s. This
 * step derives nothing of its own beyond the configured charset/collation.
 *
 * **AC-5's three-state fencing, exactly as {@see DatabaseState} describes.**
 * On the very first attempt (no "about to create" marker journalled yet):
 * `Absent` creates it; `Empty`/`Occupied` both mean a database this run did
 * not make is already there, refused as pre-existing, and that refusal is
 * journalled (`preexisting: true`) so a resumed attempt refuses again without
 * re-asking the engine. Once the marker *is* journalled - "about to create"
 * was committed, but this attempt's own `done` never was (a crash between the
 * two) - a re-entry re-reads the state: `Empty` is this run's own half-made
 * effect, adopted; `Absent` means the create itself never landed, retried;
 * `Occupied` means something else wrote into it in between, refused.
 *
 * **Intent first (Ruling 2).** The marker is written before `createDatabase()`
 * is ever called, for the same reason `reserve_identity` journals its chosen
 * identity before inserting: a crash between the two must leave the resumed
 * attempt something to tell "about to create" apart from "already existed".
 */
final class CreateDatabase implements ProvisioningStep
{
    public function __construct(
        private readonly TenantDatabaseProvisioner $provisioner,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(RunContext $context, StepJournal $journal): array
    {
        $reserved = $journal->outputOf(StepName::ReserveIdentity);
        $server = $this->serverFrom($journal->outputOf(StepName::SelectServer));
        $name = (string) $reserved['database_name'];
        $charset = (string) $this->config->get('tenancy.provisioning.charset', 'utf8mb4');
        $collation = (string) $this->config->get('tenancy.provisioning.collation', 'utf8mb4_unicode_ci');

        $intent = $journal->read();

        if (($intent['preexisting'] ?? false) === true) {
            // Already refused once; a resumed attempt refuses again rather
            // than re-asking an engine that will give the same answer.
            throw $this->preexisting($name);
        }

        if (! ($intent['attempted'] ?? false)) {
            $state = $this->provisioner->databaseState($server, $name);

            if ($state !== DatabaseState::Absent) {
                $journal->write(['attempted' => true, 'preexisting' => true]);

                throw $this->preexisting($name);
            }

            $journal->write(['attempted' => true]);
            $this->provisioner->createDatabase($server, $name, $charset, $collation);

            return ['database_name' => $name, 'charset' => $charset, 'collation' => $collation];
        }

        // Re-entry after "about to create" was journalled, but this
        // attempt's own completion never was: Empty is this run's own
        // half-made effect (adopted), Absent means retry the create itself,
        // Occupied means a foreign write landed in between.
        $state = $this->provisioner->databaseState($server, $name);

        if ($state === DatabaseState::Occupied) {
            throw PlatformRefused::validation(
                "create_database found [{$name}] occupied by something this run did not create, after already ".
                'journalling its own creation attempt.'
            );
        }

        if ($state === DatabaseState::Absent) {
            $this->provisioner->createDatabase($server, $name, $charset, $collation);
        }

        return ['database_name' => $name, 'charset' => $charset, 'collation' => $collation];
    }

    public function compensate(RunContext $context): ?string
    {
        // Abandon never drops a database (D-21, AC-9, AC-39): nothing this
        // step's own compensate() could undo without issuing exactly the
        // statement the gate forbids outside test code.
        return null;
    }

    private function preexisting(string $name): PlatformRefused
    {
        return PlatformRefused::validation(
            "Database [{$name}] already exists and was not created by this run."
        );
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
}
