<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning;

/**
 * The one in-memory channel `create_owner` uses to hand the console command
 * the owner's freshly-generated password, once (T-026 D-26, Ruling 7 step 7).
 *
 * **Why this exists at all.** {@see ProvisioningStep::run()}'s return value is
 * journalled verbatim by {@see ProvisioningRunner} - written to
 * `tenant_provisioning_steps.output` as JSON - so a step's return array can
 * never carry the plaintext password without violating AC-18. This object is
 * a plain, non-persisted holder, bound as a container singleton
 * (`PlatformServiceProvider`): `create_owner` records onto it mid-run, and
 * `ProvisionTenantHandler` reads it straight back after the run finishes, in
 * the same process, with nothing written anywhere in between.
 *
 * **Exactly one of three outcomes per command invocation.** `created`
 * carries the password the command may print once; `attached` and
 * `alreadyCreated` carry none - the first because nothing was generated, the
 * second because this exact run already printed it on an earlier attempt
 * (D-26's "the initial password was shown when it was created").
 */
final class OwnerCredentialAnnouncement
{
    private ?string $mode = null;

    private ?string $password = null;

    /**
     * This attempt inserted a brand-new `users` row: `$password` is the one
     * and only in-memory copy of its initial plaintext.
     */
    public function recordCreated(string $password): void
    {
        $this->mode = 'created';
        $this->password = $password;
    }

    /**
     * This attempt attached an existing `users` row (D-30): nothing about it
     * was generated or changed, so there is nothing to print but the fact.
     */
    public function recordAttached(): void
    {
        $this->mode = 'attached';
        $this->password = null;
    }

    /**
     * A resumed attempt found the user this run already created on an
     * earlier, since-crashed attempt: the password existed once, in that
     * attempt's memory only, and is not recoverable here.
     */
    public function recordAlreadyCreated(): void
    {
        $this->mode = 'already_created';
        $this->password = null;
    }

    public function mode(): ?string
    {
        return $this->mode;
    }

    public function password(): ?string
    {
        return $this->password;
    }
}
