<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning\Steps;

use Flexipos\Kernel\Tenancy\Provisioning\Contracts\ProvisioningServer;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\TenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\TenantGrant;
use Flexipos\Kernel\Tenancy\Secrets\CredentialGenerator;
use Flexipos\Kernel\Tenancy\Secrets\SecretResolver;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\ProvisioningStep;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;

/**
 * `create_users`, D-20's third real-engine step - run only after
 * `store_credentials` has durably written both passwords (T-026 AC-5 stand-in
 * half, AC-15, AC-17).
 *
 * **Reads the password back, never generates one.** Both secrets come from
 * `store_credentials`' own journalled references, resolved through the
 * ordinary {@see SecretResolver} - this step never calls
 * {@see CredentialGenerator} itself (AC-15).
 * This is exactly the crash case D-20 exists for: if the process died after
 * `store_credentials` committed but before this step ran, a resumed attempt
 * finds the stored secret already written and proceeds straight to creating
 * the user with it.
 *
 * **"A user that existed before this run" is checked once, on the first
 * attempt only.** `CREATE USER IF NOT EXISTS`/`ALTER USER` are themselves
 * idempotent (Ruling 4), so re-checking existence on every re-entry would
 * wrongly flag a user *this run itself* created on an earlier attempt as a
 * foreign conflict. The check, and its outcome, are journalled before either
 * user is ever created, so a resumed attempt that already passed it does not
 * re-ask - it either proceeds (checked, not preexisting) or refuses again
 * (checked, preexisting), without a second call to the engine.
 */
final class CreateUsers implements ProvisioningStep
{
    public function __construct(
        private readonly TenantDatabaseProvisioner $provisioner,
        private readonly SecretResolver $secrets,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(RunContext $context, StepJournal $journal): array
    {
        $reserved = $journal->outputOf(StepName::ReserveIdentity);
        $server = $this->serverFrom($journal->outputOf(StepName::SelectServer));
        $credentials = $journal->outputOf(StepName::StoreCredentials);
        $database = $journal->outputOf(StepName::CreateDatabase);

        $databaseName = (string) ($database['database_name'] ?? $reserved['database_name']);
        $appUser = (string) $reserved['app_username'];
        $migrateUser = (string) $reserved['migrate_username'];

        $intent = $journal->read();

        if (! ($intent['checked'] ?? false)) {
            foreach ([$appUser, $migrateUser] as $user) {
                if ($this->provisioner->userExists($server, $user)) {
                    $journal->write(['checked' => true, 'preexisting' => true, 'preexisting_user' => $user]);

                    throw $this->preexisting($user);
                }
            }

            $journal->write(['checked' => true]);
        } elseif (($intent['preexisting'] ?? false) === true) {
            throw $this->preexisting((string) $intent['preexisting_user']);
        }

        $appPassword = $this->secrets->resolve((string) $credentials['app_credential_ref']);
        $migratePassword = $this->secrets->resolve((string) $credentials['migrate_credential_ref']);

        $this->provisioner->createUser($server, $appUser, $appPassword, TenantGrant::App, $databaseName);
        $this->provisioner->createUser($server, $migrateUser, $migratePassword, TenantGrant::Migrate, $databaseName);

        return ['app_username' => $appUser, 'migrate_username' => $migrateUser];
    }

    public function compensate(RunContext $context): ?string
    {
        // dropUser() for each created user is abandon's own job (Ruling 10,
        // step 16), not this step's compensate() - the same deferral every
        // earlier step in this sequence already uses.
        return null;
    }

    private function preexisting(string $user): PlatformRefused
    {
        return PlatformRefused::validation(
            "Database user [{$user}] already existed before this run and was not created by it."
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
