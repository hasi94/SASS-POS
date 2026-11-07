<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning\Steps;

use Flexipos\Kernel\Tenancy\Provisioning\TenantDatabaseNames;
use Flexipos\Kernel\Tenancy\Secrets\CredentialGenerator;
use Flexipos\Kernel\Tenancy\Secrets\WritableSecretStore;
use Flexipos\Platform\Provisioning\ProvisioningStep;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * `store_credentials`, D-20's second real-engine step - deliberately run
 * before `create_users`, never after (T-026 AC-17, D-20).
 *
 * **Both passwords exist and are durably written before anything needs them.**
 * The two references ({@see TenantDatabaseNames::appCredentialRef()}/
 * `migrateCredentialRef()`) are a pure function of `reserve_identity`'s own
 * journalled `sequence` and the configured secrets root - computed fresh
 * every entry, never journalled as a separate fact, because they are already
 * reproducible from what `reserve_identity` wrote (the same reasoning that
 * keeps `database_name`/`app_username` off this step's own journal too).
 *
 * **AC-17 negative: re-entry neither rotates nor writes a second version.**
 * `exists()` is checked first; a reference already written is left alone -
 * `put()` would be a no-op for the same value anyway, but generating a *new*
 * password and handing it to `put()` would mean handing a different value to
 * an already-written reference, which {@see WritableSecretStore::put()}
 * refuses outright. Checking first is what keeps a resumed attempt from ever
 * making that call.
 */
final class StoreCredentials implements ProvisioningStep
{
    public function __construct(
        private readonly WritableSecretStore $secrets,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(RunContext $context, StepJournal $journal): array
    {
        $reserved = $journal->outputOf(StepName::ReserveIdentity);
        $sequence = (int) $reserved['sequence'];
        $root = (string) $this->config->get('tenancy.secrets.file_root');

        $appRef = TenantDatabaseNames::appCredentialRef($sequence, $root);
        $migrateRef = TenantDatabaseNames::migrateCredentialRef($sequence, $root);

        if (! $this->secrets->exists($appRef)) {
            $this->secrets->put($appRef, CredentialGenerator::generate());
        }

        if (! $this->secrets->exists($migrateRef)) {
            $this->secrets->put($migrateRef, CredentialGenerator::generate());
        }

        return [
            'app_credential_ref' => $appRef,
            'migrate_credential_ref' => $migrateRef,
        ];
    }

    public function compensate(RunContext $context): ?string
    {
        // Forgetting the secrets is abandon's own job (Ruling 10, step 16),
        // run directly against the journalled references rather than through
        // this step's own compensate() - the same deferral ReserveIdentity
        // and SelectServer already use.
        return null;
    }
}
