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
 * **Exactly one of four outcomes per command invocation.** `created` and
 * `rotated` each carry the password the command may print once; `attached`
 * and `alreadyCreated` carry none - the first because nothing was generated,
 * the second because this exact run already printed it on an earlier
 * attempt (D-26's "the initial password was shown when it was created") and
 * the login is provably not recoverable this way (D-31's precondition
 * failed - someone has signed in, or the password was already changed).
 *
 * **`rotated` (review round 1 R-1/D-31).** A resumed `create_owner` found
 * the `users` row this exact run created on an earlier, since-crashed
 * attempt, still provably unused (`must_change_password = true` AND
 * `last_login_at IS NULL`) - the original password was lost with that
 * attempt's process, so a fresh one was generated and written in its place.
 * This never touches a login anyone has ever signed into, and never an
 * existing attached owner (D-30's case is `attached`, untouched either way).
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
     * earlier, since-crashed attempt, but D-31's rotation precondition does
     * not hold (the login has been signed into, or the password already
     * changed): the original password existed once, in that attempt's
     * memory only, and is deliberately not recoverable here - rotating it
     * would be touching a login this run no longer has the right to touch.
     */
    public function recordAlreadyCreated(): void
    {
        $this->mode = 'already_created';
        $this->password = null;
    }

    /**
     * D-31: a resumed attempt found the user this run already created on an
     * earlier, since-crashed attempt, still provably unused - the password
     * was rotated and `$password` is the one and only in-memory copy of the
     * new plaintext.
     */
    public function recordRotated(string $password): void
    {
        $this->mode = 'rotated';
        $this->password = $password;
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
