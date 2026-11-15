<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\RefreshToken;

use Carbon\CarbonImmutable;
use Flexipos\Auth\Exceptions\CredentialRefused;
use Flexipos\Auth\Exceptions\EmployeeUnavailable;
use Flexipos\Auth\Exceptions\NoActiveSigningKey;
use Flexipos\Auth\Exceptions\NoTenantAccess;
use Flexipos\Auth\Exceptions\TenantNotServable;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttempt;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttemptHandler;
use Flexipos\Auth\Support\IpAddress;
use Flexipos\Auth\Support\UserStatus;
use Flexipos\Auth\Tenancy\MembershipStatus;
use Flexipos\Auth\Tenancy\TenantEmployeeClaims;
use Flexipos\Auth\Tenancy\TenantSelectionPolicy;
use Flexipos\Auth\Tokens\RefreshTokenValue;
use Flexipos\Auth\Tokens\SigningKeyring;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Contracts\Access\EmployeeClaims;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Exchange a refresh token for a new pair, once.
 *
 * **Rotation is "the presented row is used and a new row joins its family",
 * and both halves have to be asserted.** `UNIQUE(refresh_tokens.token_hash)`
 * would refuse a duplicated value even if rotation were broken, so the
 * absence of an error proves nothing (plan section 7, mask shape 2). What
 * only rotation produces is `used_at` on the row that was presented **and** a
 * second row carrying the same `family_id`.
 *
 * **A replay revokes the whole family, which is the point of a family.** A
 * token presented twice means either the client retried or somebody else has
 * a copy, and the two are indistinguishable from here. The safe reading is
 * the second: every row of the chain is revoked, so the legitimate holder is
 * signed out too and has to authenticate again. The `token_reuse` event
 * records it, because this is the one thing in the task that is a security
 * event rather than a login outcome (AC-34).
 *
 * **The order is validate, resolve the key, then judge** - the same ruling as
 * `SelectTenant`, and here the "validate" step is only "is there a string at
 * all", because a refresh has no 422. The key is resolved before the token is
 * looked up so that an operational failure judges nothing: no
 * `login_history` row, no `security_events` row, no `used_at`, no revocation.
 * It also makes the two non-consumption cases **mutually exclusive** - a
 * business refusal is reachable only once the key has resolved - so neither
 * can be mistaken for the other.
 *
 * **Two different things leave a presented token unconsumed, and they are
 * not the same case.** A tenant refusal (AC-37, D-24) judged the membership
 * and the tenant and says so in a `login_history` row with a named reason;
 * an operational failure judged nothing and writes no row anywhere. Both end
 * with `used_at` still null and the token still usable, which is exactly why
 * that shared observation cannot tell them apart: the discriminator is the
 * reasoned row against its total absence.
 *
 * **What this slice writes to `login_history`, and what it does not.** Only
 * AC-37's two business refusals, where a membership was judged and AC-40's
 * vocabulary applies. A token that is unknown, expired, revoked, replayed or
 * belongs to an account that is no longer active writes **no** row: AC-35
 * leaves it to `architect` whether to log one, the closed reason set has no
 * word for any of them, and `login_history` means "a credential was judged"
 * (D-36) - nobody typed a credential here. A successful rotation writes no
 * row either, for the same reason: it is the continuation of a login that
 * was already recorded, not a new judgement of anybody.
 *
 * **Nothing here opens a tenant database.** The tenant re-check is two
 * master reads - the membership and the tenant's commercial status - and the
 * serving gate remains the kernel's, on every later request (D-9).
 */
final class RefreshTokenHandler
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly RunnerClock $clock,
        private readonly SigningKeyring $keyring,
        /**
         * The single authority for `tenants.status` (plan section 1), and
         * its third and last shipped consumer: the tenant re-check of AC-37.
         * Injected for the reason the other two are - a handler that built
         * its own policy could not be handed a different one, and "the
         * selectable set is read from one place" would be a claim about
         * source text (AC-47 residual (b)).
         */
        private readonly TenantSelectionPolicy $tenantSelection,
        private readonly RecordLoginAttemptHandler $attempts,
        private readonly IssueTokenPairHandler $tokens,
        /**
         * The employee claims, re-read from the tenant database on every
         * tenant refresh (T-010 AC-62). Shared with the selection slice so
         * the two cannot disagree about entering and leaving the shop.
         */
        private readonly TenantEmployeeClaims $employeeClaims,
    ) {}

    /**
     * @throws NoActiveSigningKey when no key can sign (operational, D-36)
     * @throws CredentialRefused for every token-level refusal (AC-34, AC-35, AC-36)
     * @throws NoTenantAccess when the membership is gone (AC-37)
     * @throws TenantNotServable when the tenant may no longer be selected (AC-37)
     */
    public function handle(RefreshToken $command): RefreshTokenResult
    {
        $hash = $this->presentedHash($command->token);

        // Before the lookup, before any judgement, before any write.
        $this->keyring->signingKey();

        // **Only a user's row is this slice's** (T-014 step 12): a terminal's
        // refresh token presented here is a value this endpoint does not
        // know, refused with nothing revoked. Without the filter it would be
        // judged as a user's, and its family revoked.
        $row = $this->master()->table('refresh_tokens')
            ->where('token_hash', $hash)
            ->where('principal_type', IssueTokenPairHandler::PRINCIPAL_TYPE)
            ->first();

        if ($row === null || $row->revoked_at !== null) {
            // Unknown and revoked are one answer: a value this system does
            // not accept. Telling them apart would say which strings were
            // once real.
            throw CredentialRefused::refused();
        }

        if ($row->used_at !== null) {
            $this->refuseReplay($command, $row);
        }

        if (! CarbonImmutable::parse((string) $row->expires_at)->greaterThan($this->clock->now())) {
            // AC-35's boundary, written so both sides are visible: valid at
            // `expires_at` minus a second, refused at exactly `expires_at`.
            throw CredentialRefused::refused();
        }

        $user = $this->findUser((string) $row->principal_id);

        if ($user === null) {
            $this->revokeFamily((string) $row->family_id);

            throw CredentialRefused::refused();
        }

        // Before `rotate()`, which is what consumes the presented row: a
        // refusal from here must leave the token usable (D-24, AC-62).
        $employeeClaims = $row->tenant_id === null
            ? null
            : $this->recheckTenant($command, $row, (string) $user->email);

        return $this->rotate($command, $row, $employeeClaims);
    }

    /**
     * The hash the presented value would have, or the one refusal.
     *
     * A non-string, an empty string and a value of the wrong shape are all
     * "not a token of ours", and that is the only thing the caller is told.
     *
     * @throws CredentialRefused
     */
    private function presentedHash(mixed $token): string
    {
        if (! is_string($token) || $token === '') {
            throw CredentialRefused::refused();
        }

        return RefreshTokenValue::fromPlaintext($token)->hash();
    }

    /**
     * A token presented a second time: revoke the chain, record the event,
     * refuse (AC-34).
     *
     * **The whole family, including the rows that are still good.** The
     * newest token of the chain is the one an attacker who replayed an old
     * one would want, so leaving it alive would make the revocation
     * ceremonial. Its test asserts exactly that: the newest token worked
     * immediately before the replay and is refused after it.
     *
     * The event carries the family and a count. **No token, no token hash
     * and no credential is in it** (AC-26) - a `family_id` identifies a
     * chain and authenticates nothing.
     *
     * @throws CredentialRefused
     */
    private function refuseReplay(RefreshToken $command, object $row): never
    {
        $revoked = $this->revokeFamily((string) $row->family_id);

        $this->attempts->handle(RecordLoginAttempt::securityEvent(
            type: RecordLoginAttempt::EVENT_TOKEN_REUSE,
            userId: (string) $row->principal_id,
            tenantId: $row->tenant_id === null ? null : (string) $row->tenant_id,
            ip: $command->ip,
            detail: [
                'family_id' => (string) $row->family_id,
                'revoked_rows' => $revoked,
            ],
        ));

        throw CredentialRefused::refused();
    }

    /**
     * Revoke every row of one family that is not already revoked, and say
     * how many.
     *
     * One statement, so a family cannot be half revoked; already-revoked
     * rows keep the instant they were revoked at, which is the honest record
     * of when each one stopped working.
     */
    private function revokeFamily(string $familyId): int
    {
        return $this->master()
            ->table('refresh_tokens')
            ->where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $this->clock->timestamp(), 'updated_at' => $this->clock->timestamp()]);
    }

    /**
     * The account this token belongs to, if it may still hold one.
     *
     * D-13: a session ends when the account stops being `active` or is
     * soft-deleted, and the family is revoked with it - a refresh is the
     * moment a long-lived session is re-examined, which is the only moment
     * T-009 has for this.
     *
     * **A locked account is not cut off** (D-5, AC-36): `users.status` stays
     * `active` through a lockout, so this predicate does not see one.
     * Lockout governs signing in, not a session already established.
     */
    private function findUser(string $userId): ?object
    {
        $user = $this->master()
            ->table('users')
            ->where('id', $userId)
            ->whereNull('deleted_at')
            ->first(['id', 'email', 'status']);

        if ($user === null || (string) $user->status !== UserStatus::ACTIVE) {
            return null;
        }

        return $user;
    }

    /**
     * Re-ask the two questions a tenant token depends on (AC-37).
     *
     * **A refusal here consumes nothing** (D-24): the presented row is
     * untouched, its family is untouched, and no token is issued, so
     * reinstating the membership or the tenant makes the same token work. A
     * shop that is suspended for a week is not a reason to sign its staff
     * out of their session; a replayed token is.
     *
     * **The employee claims are re-read here, never copied from the old
     * token** (AC-62). The presented value is a refresh token and carries
     * no claims at all; the access token it replaces is not read by this
     * slice and could not be trusted if it were. So `perm_version`,
     * `perm_hash`, `branch_ids` and `all_branches` are whatever the tenant
     * database says now, which is the whole point of refreshing - and it
     * is why a refresh is the cure a 401 `permissions_changed` asks for.
     *
     * **A refusal here still consumes nothing.** This runs before
     * {@see self::rotate()}, so an employee who has become non-acting is
     * refused with their refresh token untouched: reinstating them makes
     * the same token work. A token that was never valid also has a null
     * `used_at`, which is why AC-62's control is that the *same* token
     * succeeds afterwards.
     *
     * @throws NoTenantAccess
     * @throws TenantNotServable
     * @throws EmployeeUnavailable
     */
    private function recheckTenant(RefreshToken $command, object $row, string $email): ?EmployeeClaims
    {
        $tenantId = (string) $row->tenant_id;

        $membership = $this->master()
            ->table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('user_id', (string) $row->principal_id)
            ->where('status', MembershipStatus::ACTIVE)
            ->whereNull('deleted_at')
            ->first(['id', 'employee_id']);

        $tenant = $this->master()
            ->table('tenants')
            ->where('id', $tenantId)
            ->first(['status', 'deleted_at']);

        if ($membership === null || $tenant === null || $tenant->deleted_at !== null) {
            $this->record($command, $email, $row, RecordLoginAttempt::REASON_NO_ACCESS);

            throw NoTenantAccess::refused();
        }

        if (! $this->tenantSelection->permits((string) $tenant->status)) {
            $this->record($command, $email, $row, RecordLoginAttempt::REASON_TENANT_NOT_SERVABLE);

            throw TenantNotServable::withStatus((string) $tenant->status);
        }

        try {
            return $this->employeeClaims->forMembership(
                $tenantId,
                $membership->employee_id === null ? null : (string) $membership->employee_id,
                (string) $row->principal_id,
            );
        } catch (EmployeeUnavailable $refusal) {
            $this->record($command, $email, $row, RecordLoginAttempt::REASON_EMPLOYEE_UNAVAILABLE);

            throw $refusal;
        }
    }

    private function record(RefreshToken $command, string $email, object $row, string $reason): void
    {
        $this->attempts->handle(RecordLoginAttempt::failed(
            email: $email,
            failureReason: $reason,
            userId: (string) $row->principal_id,
            tenantId: $row->tenant_id === null ? null : (string) $row->tenant_id,
            ip: $command->ip,
            userAgent: $command->userAgent,
            deviceId: $command->deviceId,
        ));
    }

    /**
     * Consume the presented row and issue the next pair in its family
     * (AC-33).
     *
     * `used_at` is set **before** the new pair is issued, and the update is
     * conditional on the row still being unused: two requests racing with
     * the same token leave exactly one of them with a row to consume, and
     * the loser issues nothing. The engine decides, not a read in PHP - the
     * same reason `failed_attempts` is incremented by the engine (AC-22's
     * shape).
     *
     * The tenant and the family are the **stored row's**, never anything a
     * caller sent (AC-38), because there is nothing a caller could have
     * sent.
     */
    private function rotate(RefreshToken $command, object $row, ?EmployeeClaims $employeeClaims): RefreshTokenResult
    {
        $now = $this->clock->timestamp();

        $consumed = $this->master()
            ->table('refresh_tokens')
            ->where('id', (string) $row->id)
            ->whereNull('used_at')
            ->update(['used_at' => $now, 'updated_at' => $now]);

        if ($consumed !== 1) {
            throw CredentialRefused::refused();
        }

        $tenantId = $row->tenant_id === null ? null : (string) $row->tenant_id;

        $pair = $this->tokens->handle(new IssueTokenPair(
            tokenType: $tenantId === null ? TokenClaims::TYPE_PLATFORM : TokenClaims::TYPE_TENANT,
            userId: (string) $row->principal_id,
            tenantId: $tenantId,
            employeeClaims: $employeeClaims,
            familyId: (string) $row->family_id,
            ip: IpAddress::pack($command->ip),
            userAgent: $command->userAgent,
        ));

        return new RefreshTokenResult(
            accessToken: $pair->accessToken,
            refreshToken: $pair->refreshToken,
            familyId: $pair->familyId,
            tenantId: $tenantId,
        );
    }

    private function master(): Connection
    {
        return $this->database->connection((string) $this->config->get('tenancy.master_connection', 'master'));
    }
}
