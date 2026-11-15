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
 * journalled `sequence` and the configured secrets root - but **only on the
 * first attempt**. They are journalled here, before either `put()` call, and
 * every later entry reads them back from the journal rather than recomputing
 * them from `tenancy.secrets.file_root` (architect ruling, after step 10's
 * close-out: the root is configuration and can change between a failed
 * attempt and a `--resume`, e.g. a redeploy in between; recomputing on every
 * entry would then split this run's two secrets across two roots, orphaning
 * whichever file the first attempt already wrote, with no record of where it
 * went for `abandon` - step 16 - to find). Reading the journalled reference
 * does not itself bypass root confinement (Ruling 3): confinement is checked
 * against whatever root is configured *now*, so a re-entry behind a root
 * that has genuinely moved fails closed here (`SecretPathEscapesRoot`) rather
 * than silently writing a second file under the new root - no credential is
 * ever split across two roots either way. Once the root is pointed back at
 * where this run's secrets actually live, a resume completes against the
 * same, unchanged references.
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
        $saved = $journal->read();

        if (isset($saved['app_credential_ref'], $saved['migrate_credential_ref'])) {
            $appRef = (string) $saved['app_credential_ref'];
            $migrateRef = (string) $saved['migrate_credential_ref'];
        } else {
            $reserved = $journal->outputOf(StepName::ReserveIdentity);
            $sequence = (int) $reserved['sequence'];
            $root = (string) $this->config->get('tenancy.secrets.file_root');

            $appRef = TenantDatabaseNames::appCredentialRef($sequence, $root);
            $migrateRef = TenantDatabaseNames::migrateCredentialRef($sequence, $root);

            // Intent first: both references are committed before either
            // put() runs, so a resumed attempt - even behind a changed
            // config root - finds and reuses exactly these two references.
            $journal->write([
                'app_credential_ref' => $appRef,
                'migrate_credential_ref' => $migrateRef,
            ]);
        }

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
