<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning\Steps;

use Closure;
use Flexipos\Contracts\Access\OwnerSeed;
use Flexipos\Contracts\Access\TenantAccessBootstrap;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Secrets\CredentialGenerator;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\OwnerCredentialAnnouncement;
use Flexipos\Platform\Provisioning\ProvisioningStep;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Support\MasterConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * `create_owner` (T-026 Ruling 7, AC-29 to AC-31, D-26).
 *
 * **Order, exactly Ruling 7's.** (1) the pre-generated `user_id` is
 * journalled before anything else, intent-first; (2) the tenant work -
 * `ensureOwnerEmployee()`, `attachOwnerBranch()`, `grantOwner()` - all inside
 * one {@see Tenancy::run()} call; (3) the master-side write, in one
 * transaction: insert a fresh `users` row, or attach an existing one (D-30),
 * plus the new tenant's `tenant_users` row; (4) `linkUser()`, back into the
 * tenant connection, so `employees.user_id` is back-filled only once the
 * master side is known to have committed.
 *
 * **Every tenant call is idempotent already** ({@see TenantAccessBootstrap}'s
 * own guarantees) - this step journals nothing about the tenant half beyond
 * the one id it must not regenerate, so AC-30's resume ("the master-side
 * write fails after the tenant employees row committed") needs no extra
 * bookkeeping: re-running `ensureOwnerEmployee()`/`attachOwnerBranch()`/
 * `grantOwner()` against a tenant that already has them is a no-op by
 * construction.
 *
 * **Which outcome the master half takes is decided once, before the tenant
 * work runs, and honoured by both halves.** `--owner-name` is ignored for
 * the employee's own `display_name` on an attach (AC-31) - the existing
 * user's own name is immaterial - so the decision ("will this email create or
 * attach") has to be known before {@see OwnerSeed} is built, not only when
 * the master transaction itself runs. The master transaction re-reads the
 * same row under its own lookup rather than trusting the earlier peek, so a
 * resumed attempt is answered from current state either way.
 *
 * **The password is never returned, logged or journalled (AC-18, D-26).**
 * {@see OwnerCredentialAnnouncement} is this step's one side channel for the
 * plaintext, read back by `ProvisionTenantHandler` in the same process and
 * printed by the command - this step's own `run()` return value carries only
 * the employee id, the user id and which outcome happened, which is exactly
 * what {@see ProvisioningRunner} is free to journal.
 */
final class CreateOwner implements ProvisioningStep
{
    /**
     * Test-only. Invoked once, immediately after the tenant-side work
     * commits and before the master transaction is attempted - the window
     * AC-30's crash case occupies - then cleared. Never set outside a test.
     */
    public static ?Closure $masterFailureHook = null;

    public function __construct(
        private readonly MasterConnection $master,
        private readonly Tenancy $tenancy,
        private readonly TenantAccessBootstrap $access,
        private readonly RunnerClock $clock,
        private readonly OwnerCredentialAnnouncement $announcement,
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

        $email = (string) $context->input['owner_email'];
        $ownerName = isset($context->input['owner_name'])
            ? (string) $context->input['owner_name']
            : null;
        $localPart = $this->localPart($email);
        $emailLower = mb_strtolower(trim($email));

        $intent = $journal->read();
        $userId = $intent['user_id'] ?? null;

        if ($userId === null) {
            $userId = (string) Str::ulid();

            // Intent first: journalled before any tenant or master write, so
            // a resumed attempt reuses this exact id rather than minting a
            // second one (Ruling 7 step 1).
            $journal->write(['user_id' => $userId]);
        }

        $userId = (string) $userId;

        // Decided once, up front (this class's own docblock): whether this
        // email will create or attach, so the employee's display name (AC-31)
        // and the master transaction agree.
        $attaching = $this->willAttach($emailLower, $userId);
        $displayName = $attaching ? null : $ownerName;

        $employeeId = $this->tenancy->run(
            $tenantId,
            function () use ($localPart, $displayName, $branchId): string {
                $employeeId = $this->access->ensureOwnerEmployee(new OwnerSeed($localPart, $displayName));
                $this->access->attachOwnerBranch($employeeId, $branchId);
                $this->access->grantOwner($employeeId);

                return $employeeId;
            },
            TenantConnectionPurpose::Migrating
        );

        if (self::$masterFailureHook !== null) {
            $hook = self::$masterFailureHook;
            self::$masterFailureHook = null;
            $hook();
        }

        $outcome = $this->writeMasterSide($emailLower, $email, $localPart, $ownerName, $userId, $tenantId, $employeeId);

        $this->tenancy->run(
            $tenantId,
            function () use ($employeeId, $outcome): void {
                $this->access->linkUser($employeeId, (string) $outcome['user_id']);
            },
            TenantConnectionPurpose::Migrating
        );

        return [
            'employee_id' => $employeeId,
            'user_id' => (string) $outcome['user_id'],
            'mode' => (string) $outcome['mode'],
        ];
    }

    public function compensate(RunContext $context): ?string
    {
        // Nothing this step wrote is undone by abandon (Ruling 10): the
        // employees/users/tenant_users rows are left exactly where an
        // archived tenant leaves everything else (D-21).
        return null;
    }

    /**
     * A read-only peek, taken before any write this attempt makes, at
     * whether `$emailLower` already names somebody other than the id this
     * run itself journalled - the same question the master transaction
     * answers again, under its own lookup, when it actually writes.
     */
    private function willAttach(string $emailLower, string $journalledUserId): bool
    {
        $existing = $this->master->get()->table('users')->where('email_lower', $emailLower)->first();

        if ($existing === null) {
            return false;
        }

        return (string) $existing->id !== $journalledUserId;
    }

    /**
     * @return array{mode: string, user_id: string}
     */
    private function writeMasterSide(
        string $emailLower,
        string $email,
        string $localPart,
        ?string $ownerName,
        string $journalledUserId,
        string $tenantId,
        string $employeeId,
    ): array {
        $master = $this->master->get();

        /** @var array{mode: string, user_id: string} $outcome */
        $outcome = $master->transaction(function () use ($master, $emailLower, $email, $localPart, $ownerName, $journalledUserId, $tenantId, $employeeId): array {
            $existing = $master->table('users')->where('email_lower', $emailLower)->first();

            if ($existing === null) {
                $password = $this->insertUser($master, $journalledUserId, $email, $ownerName ?? $localPart);
                $this->announcement->recordCreated($password);
                $this->insertTenantUserIfAbsent($master, $tenantId, $journalledUserId, $employeeId, isDefault: true);

                return ['mode' => 'created', 'user_id' => $journalledUserId];
            }

            $existingId = (string) $existing->id;

            if ($existingId === $journalledUserId) {
                // An earlier attempt of this run already created it; nothing
                // on the users row is written again (AC-17-style idempotence
                // for the master write).
                $this->announcement->recordAlreadyCreated();
                $this->insertTenantUserIfAbsent($master, $tenantId, $journalledUserId, $employeeId, isDefault: true);

                return ['mode' => 'created', 'user_id' => $journalledUserId];
            }

            // D-30: attach. Nothing on the existing row is modified.
            $this->announcement->recordAttached();
            $this->insertTenantUserIfAbsent($master, $tenantId, $existingId, $employeeId, isDefault: false);

            return ['mode' => 'attached', 'user_id' => $existingId];
        });

        return $outcome;
    }

    /**
     * Inserts the one new `users` row this run's `created` outcome ever
     * writes, and returns its plaintext password - the single in-memory
     * value {@see self::writeMasterSide()} hands straight to
     * {@see OwnerCredentialAnnouncement}, never returned, logged or
     * journalled.
     */
    private function insertUser(Connection $master, string $userId, string $email, string $name): string
    {
        $password = CredentialGenerator::generate()->reveal();
        $now = $this->clock->timestamp();

        $master->table('users')->insert([
            'id' => $userId,
            'name' => $name,
            'email' => $email,
            'password_hash' => Hash::make($password),
            'status' => 'active',
            'must_change_password' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $password;
    }

    private function insertTenantUserIfAbsent(Connection $master, string $tenantId, string $userId, string $employeeId, bool $isDefault): void
    {
        $exists = $master->table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            return;
        }

        $now = $this->clock->timestamp();

        $master->table('tenant_users')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'employee_id' => $employeeId,
            'access_level' => 'owner',
            'status' => 'active',
            'is_default' => $isDefault,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function localPart(string $email): string
    {
        $at = strpos($email, '@');

        return $at === false ? $email : substr($email, 0, $at);
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
                "create_owner refuses: tenant [{$tenantId}]'s mapping status is [{$named}], not [provisioning]."
            );
        }
    }
}
