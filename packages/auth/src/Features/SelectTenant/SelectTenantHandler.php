<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\SelectTenant;

use Flexipos\Auth\Exceptions\InvalidTenantCode;
use Flexipos\Auth\Exceptions\NoActiveSigningKey;
use Flexipos\Auth\Exceptions\NoTenantAccess;
use Flexipos\Auth\Exceptions\TenantNotServable;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttempt;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttemptHandler;
use Flexipos\Auth\Support\IpAddress;
use Flexipos\Auth\Tenancy\MembershipStatus;
use Flexipos\Auth\Tenancy\TenantSelectionPolicy;
use Flexipos\Auth\Tokens\SigningKeyring;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Open one shop for somebody a platform token has already identified.
 *
 * **No tenant database is opened and `tenant_databases` is not read** (D-9,
 * AC-39, AC-52). Every statement this handler issues is against the master,
 * and the two facts it establishes are commercial and relational: does this
 * person hold an active membership, and is the shop in a status that may be
 * selected. Whether the shop may be *served* - the mapping's status, the
 * schema floor - is the kernel's `Tenancy::enter()` decision and it runs on
 * every later request, which is why a second copy of it here would be a
 * second place for the rule to live. Nothing in this file writes
 * `database.connections.tenant`, and nothing reads a mapping.
 *
 * **The order of the first two statements is the design, the same as
 * `LoginHandler`'s and for the same reasons, with one that is new here.**
 *
 *  1. *Validation first* (AC-41). An unreadable code is a 422 and writes no
 *     `login_history` row, and that absence is a property of this position.
 *  2. *The signing key second*, before the tenant is read and before any
 *     membership is judged. For `Login` the reason was AC-31's three
 *     absences; here the reason is sharper. **This slice can only record an
 *     attempt with one of `RecordLoginAttempt`'s six reasons, and an
 *     operational failure is none of them.** If the membership were judged
 *     first and signing failed afterwards, AC-40 would owe a refusal row the
 *     reason set cannot express - and because `RecordLoginAttempt` refuses an
 *     unrecognised reason at construction, the gap would surface as a crash
 *     *inside the refusal path*. Resolving the key first makes "nothing was
 *     judged, so nothing is recorded" (D-36) structurally true rather than a
 *     cleanup path. The cost is one indexed read on `signing_keys.status`
 *     ahead of cheaper work, on a path that is about to mint a token anyway.
 *
 * **What an operational failure leaves behind is nothing but a log line.**
 * No `login_history` row (D-36), no `security_events` row (`type` is a closed
 * enum in `docs/database.md` section 4.1 with no operational value, and a
 * schema change is not T-009's), no `last_accessed_at` stamp and no token.
 * The single trace is the error-level entry {@see SigningKeyring} writes at
 * the throw site; FP-201 carries the readiness check and the alert that are
 * not here.
 *
 * **The three refusals are deliberately not one shape.**
 * {@see InvalidTenantCode} is 422 and judged nothing. {@see NoTenantAccess}
 * is 403 and identical across its five causes, because telling them apart
 * would disclose whose shops exist. {@see TenantNotServable} is 403 and
 * *does* name the status, because by then the caller has proved they belong
 * to this shop and "your shop is suspended" is the only way they can be told
 * to contact their provider.
 *
 * **Mask shape 4, and the remedy is in the code rather than in a test.** The
 * read decides; the `last_accessed_at` update is then written
 * **unconditionally on the row the read returned**, keyed by the membership's
 * own identifier and re-filtering on no status of any kind. An update that
 * repeated the read's terms would write nothing whenever the read was wrong,
 * and a broken policy read would be invisible - the refusal path right for
 * the wrong reason. The proof is in `SelectTenantTest`: a policy that permits
 * `closed` makes a closed tenant selectable *and* stamps it, which only a
 * read-decides-once shape can do.
 */
final class SelectTenantHandler
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly RunnerClock $clock,
        private readonly SigningKeyring $keyring,
        /**
         * The single authority for `tenants.status` (plan section 1),
         * injected rather than constructed - the second of its three shipped
         * consumers, after the login tenant list and before the refresh
         * re-check. A handler that called `TenantSelectionPolicy::shipped()`
         * itself could not be handed a different one, and "the selectable set
         * is read from one place" would be back to being a claim about source
         * text (AC-12, AC-47 residual (b)).
         */
        private readonly TenantSelectionPolicy $tenantSelection,
        private readonly RecordLoginAttemptHandler $attempts,
        private readonly IssueTokenPairHandler $tokens,
    ) {}

    /**
     * @throws InvalidTenantCode when the code cannot be read (AC-41)
     * @throws NoActiveSigningKey when no key can sign (operational, D-36)
     * @throws NoTenantAccess for every shape of AC-40(a)
     * @throws TenantNotServable for AC-40(b)
     */
    public function handle(SelectTenant $command): SelectTenantResult
    {
        $code = TenantCodeInput::from($command->code);

        // Before the tenant read, before the membership, before any write.
        $this->keyring->signingKey();

        $email = $this->actorEmail($command->userId);

        $tenant = $this->findTenant($code);

        if ($tenant === null) {
            // The code names no shop at all, so there is no tenant to record
            // the attempt against (AC-40: `tenant_id` is set only when the
            // tenant is known to exist).
            $this->refuseAccess($command, $email, null);
        }

        $tenantId = (string) $tenant->id;
        $membership = $this->findMembership($tenantId, $command->userId);

        if ($tenant->deleted_at !== null || $membership === null) {
            $this->refuseAccess($command, $email, $tenantId);
        }

        if (! $this->tenantSelection->permits((string) $tenant->status)) {
            $this->refuseNotServable($command, $email, $tenantId, (string) $tenant->status);
        }

        return $this->admit($command, $email, $code, $tenantId, $membership);
    }

    /**
     * The address the attempt is recorded under.
     *
     * `login_history.email` is not nullable and AC-23 wants the address a
     * person is known by, but a selection submits no address: the caller is
     * identified by a token. So it is read from the account the token's `sub`
     * names, in the display form `users.email` stores (AC-2 keeps the
     * lower-cased form in `email_lower`, which is a lookup key and not
     * evidence).
     *
     * **The soft-delete filter is the predicate's, as it is in the login
     * slice's `findUser()` and the refresh slice's.** The spec's Assumptions say a
     * soft-deleted row counts as absent everywhere, and this read is the one
     * place it did not: without `whereNull('deleted_at')` a deleted account
     * holding an unexpired platform token could still select a tenant and
     * leave a `login_history` row under its own address (W-3). Being absent
     * here means the null branch below, which already refuses - so the
     * filter adds a case to an existing refusal rather than a new branch.
     *
     * **A `sub` naming no row is refused here, with no history row, and that
     * is a declared narrowing of AC-40.** It is not one of AC-40's five
     * cases - all of those are about the tenant or the membership. It is
     * reachable, for one access-token lifetime, by exactly the soft deletion
     * above; the earlier version of this note argued it was unreachable and
     * considered only hard deletion, which nothing does. Recording it is
     * impossible rather than undesirable: there is no address to put in a
     * non-nullable column, and inventing one would put a fictional value
     * into the table investigations are run from. The refusal is the
     * ordinary `no_access`, so the caller learns nothing from it either way.
     *
     * Status is deliberately **not** re-checked here: AC-48 accepts a
     * disabled account's access token until `exp`, and the status rule for a
     * selection is the membership's, which TenantSelectionPolicy applies.
     *
     * @throws NoTenantAccess
     */
    private function actorEmail(string $userId): string
    {
        $email = $this->master()
            ->table('users')
            ->where('id', $userId)
            ->whereNull('deleted_at')
            ->value('email');

        if ($email === null) {
            throw NoTenantAccess::refused();
        }

        return (string) $email;
    }

    /**
     * The shop this code names, soft-deleted or not.
     *
     * The soft-delete filter is deliberately **not** in this query: AC-40
     * wants `tenant_id` on the history row whenever the tenant is known to
     * exist, and a soft-deleted shop exists - it is simply not selectable by
     * anybody. Filtering here would turn it into an unknown code and lose the
     * one fact the row is worth writing for. The refusal is identical either
     * way, which is the criterion.
     */
    private function findTenant(TenantCodeInput $code): ?object
    {
        return $this->master()
            ->table('tenants')
            ->where('code', $code->value)
            ->first(['id', 'code', 'status', 'deleted_at']);
    }

    /**
     * This person's live membership of this shop, or null.
     *
     * `invited`, `suspended` and a soft-deleted row are all simply "not
     * active" ({@see MembershipStatus}), which is why none of them is
     * enumerated: a status this code does not name is refused by default.
     */
    private function findMembership(string $tenantId, string $userId): ?object
    {
        return $this->master()
            ->table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('status', MembershipStatus::ACTIVE)
            ->whereNull('deleted_at')
            ->first(['id', 'employee_id']);
    }

    /**
     * Record the attempt and refuse with AC-40(a)'s one refusal.
     *
     * Written before the throw and outside any transaction of this slice's
     * making, which is AC-24's shape: the row survives the error response
     * because nothing was open to roll back.
     *
     * @throws NoTenantAccess
     */
    private function refuseAccess(SelectTenant $command, string $email, ?string $tenantId): never
    {
        $this->record($command, $email, RecordLoginAttempt::REASON_NO_ACCESS, $tenantId);

        throw NoTenantAccess::refused();
    }

    /**
     * @throws TenantNotServable
     */
    private function refuseNotServable(SelectTenant $command, string $email, string $tenantId, string $status): never
    {
        $this->record($command, $email, RecordLoginAttempt::REASON_TENANT_NOT_SERVABLE, $tenantId);

        throw TenantNotServable::withStatus($status);
    }

    private function record(SelectTenant $command, string $email, string $reason, ?string $tenantId): void
    {
        $this->attempts->handle(RecordLoginAttempt::failed(
            email: $email,
            failureReason: $reason,
            userId: $command->userId,
            tenantId: $tenantId,
            ip: $command->ip,
            userAgent: $command->userAgent,
            deviceId: $command->deviceId,
        ));
    }

    /**
     * The success path: stamp the membership, mint the pair, record the
     * attempt - in that order.
     *
     * **The stamp is unconditional on the row the read returned.** Its only
     * predicate is `tenant_users.id`, the membership's own key; no status of
     * the membership and no status of the tenant appears in it. See the class
     * docblock for why re-filtering here would hide a broken read.
     *
     * **A new family every time** (AC-30): `IssueTokenPair` is given no
     * `familyId`, which is what "start a new chain" means, so selecting A
     * makes F1 and selecting B makes F2 while the platform family F0 is
     * neither used nor revoked. Nothing here touches another family's rows,
     * which is why AC-44's "the A token is unchanged and still verifies"
     * needs no code to be true.
     *
     * The history row is written **after** the pair so that a row saying
     * `success = true` means a token really was issued.
     */
    private function admit(
        SelectTenant $command,
        string $email,
        TenantCodeInput $code,
        string $tenantId,
        object $membership
    ): SelectTenantResult {
        $now = $this->clock->timestamp();

        $this->master()->table('tenant_users')->where('id', (string) $membership->id)->update([
            'last_accessed_at' => $now,
            'updated_at' => $now,
        ]);

        $pair = $this->tokens->handle(new IssueTokenPair(
            tokenType: TokenClaims::TYPE_TENANT,
            userId: $command->userId,
            tenantId: $tenantId,
            // AC-39: a membership with a null `employee_id` yields a token
            // with **no** `employee` claim, never one carrying a null.
            employeeId: $membership->employee_id === null ? null : (string) $membership->employee_id,
            ip: IpAddress::pack($command->ip),
            userAgent: $command->userAgent,
        ));

        $this->attempts->handle(RecordLoginAttempt::succeeded(
            email: $email,
            userId: $command->userId,
            tenantId: $tenantId,
            ip: $command->ip,
            userAgent: $command->userAgent,
            deviceId: $command->deviceId,
        ));

        return new SelectTenantResult(
            tenantId: $tenantId,
            tenantCode: $code->value,
            accessToken: $pair->accessToken,
            refreshToken: $pair->refreshToken,
            familyId: $pair->familyId,
        );
    }

    private function master(): Connection
    {
        return $this->database->connection((string) $this->config->get('tenancy.master_connection', 'master'));
    }
}
