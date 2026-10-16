<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\Login;

use Flexipos\Auth\Exceptions\CredentialRefused;
use Flexipos\Auth\Exceptions\InvalidLoginInput;
use Flexipos\Auth\Exceptions\NoActiveSigningKey;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttempt;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttemptHandler;
use Flexipos\Auth\Support\IpAddress;
use Flexipos\Auth\Support\UserStatus;
use Flexipos\Auth\Tenancy\MembershipStatus;
use Flexipos\Auth\Tenancy\TenantSelectionPolicy;
use Flexipos\Auth\Tokens\SigningKeyring;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Sign a person in against the master, and tell them which shops they may
 * open.
 *
 * **The order of the first three statements is the design.** Validate, then
 * resolve the signing key, then look the account up. Each of the first two
 * is in front of the next for a reason a later reader must not reorder:
 *
 *  1. *Validation first* (AC-9). A request that cannot be read reaches no
 *     hasher, writes no history row and moves no account's
 *     `failed_attempts`, and those three absences are properties of this
 *     position rather than of anything the later code avoids doing.
 *  2. *The signing key second* (AC-31). With no `active` key the whole
 *     operation fails closed, **before** the lookup, the hasher, the
 *     `last_login_at` write and any refresh row. So "no refresh row was
 *     written, `last_login_at` was not updated, and it was not counted
 *     toward lockout" is true because none of those statements has run yet -
 *     not because something undid them afterwards. A cleanup path is a thing
 *     somebody forgets on the sixth branch; an ordering is not.
 *
 * The key is resolved here and **discarded**; {@see IssueTokenPairHandler}
 * resolves it again at the moment it mints, because that handler is the one
 * place a token is signed and it does not take a key from a caller. The cost
 * is one indexed read. If the key were retired in between, issuing throws
 * before it writes, which is the same safe end state.
 *
 * **Which refusal is which.** `InvalidLoginInput` is 422 and means nothing
 * was looked up. `NoActiveSigningKey` is operational, 5xx, and is explicitly
 * *not* a credential failure (AC-31). Everything else is
 * {@see CredentialRefused}: one object, no cause, one byte-identical
 * response (D-12). The cause survives in `login_history.failure_reason`,
 * written before the throw, which is what lets a test say *which* mechanism
 * refused instead of only that something did.
 *
 * **Soft-deleted is absent, not inactive.** The lookup filters
 * `deleted_at is null`, so a deleted account produces `unknown_user` with a
 * null `user_id` in the history row and is indistinguishable from an address
 * that never existed - which is the spec's own rule that soft-deleted rows
 * count as absent everywhere. AC-7 groups it with the status refusals
 * because the *response* is the same; the history row is honest about the
 * difference.
 *
 * **Nothing here opens a tenant database, and nothing here reads
 * `tenant_databases`.** Logging in is a master-only operation: it answers
 * which shops exist for this person and whether they are commercially
 * selectable, and the kernel's serving gate answers the different question of
 * whether a shop may be served, on every later request (D-9).
 */
final class LoginHandler
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly RunnerClock $clock,
        private readonly Hasher $hasher,
        private readonly SigningKeyring $keyring,
        /**
         * The single authority for `tenants.status` (plan section 1),
         * injected rather than constructed, so that the list this login
         * reports follows the policy the application was built with. A
         * handler that called `TenantSelectionPolicy::shipped()` itself could
         * not be handed a different one, and "the selectable set is read from
         * one place" would be back to being a claim about source text
         * (AC-12, AC-47 residual (b)).
         */
        private readonly TenantSelectionPolicy $tenantSelection,
        private readonly RecordLoginAttemptHandler $attempts,
        private readonly IssueTokenPairHandler $tokens,
    ) {}

    /**
     * @throws InvalidLoginInput when the body cannot be read (AC-9)
     * @throws NoActiveSigningKey when no key can sign (AC-31)
     * @throws CredentialRefused for every credential cause (D-12)
     */
    public function handle(Login $command): LoginResult
    {
        $credentials = LoginCredentials::from(
            $command->email,
            $command->password,
            (int) $this->config->get('authentication.limits.email'),
            (int) $this->config->get('authentication.limits.password'),
        );

        // AC-31. Before the lookup, before the hasher, before any write.
        $this->keyring->signingKey();

        $user = $this->findUser($credentials);

        if ($user === null) {
            $this->refuse($command, $credentials, RecordLoginAttempt::REASON_UNKNOWN_USER);
        }

        if ((string) $user->status !== UserStatus::ACTIVE) {
            $this->refuse($command, $credentials, RecordLoginAttempt::REASON_NOT_ACTIVE, (string) $user->id);
        }

        if (! $this->hasher->check($credentials->password, (string) $user->password_hash)) {
            $this->refuse($command, $credentials, RecordLoginAttempt::REASON_BAD_PASSWORD, (string) $user->id);
        }

        return $this->admit($command, $credentials, $user);
    }

    /**
     * The account this address names, or null.
     *
     * The predicate is an indexed equality on `users.email_lower` - the
     * stored generated column of D-26 - which is what makes the lookup
     * case-insensitive **on the stand-in itself** and not by the engine's
     * collation (AC-2).
     */
    private function findUser(LoginCredentials $credentials): ?object
    {
        return $this->master()
            ->table('users')
            ->whereNull('deleted_at')
            ->where('email_lower', $credentials->email->normalised())
            ->first();
    }

    /**
     * Record the cause and refuse with the one refusal.
     *
     * The row is written **before** the throw and outside any transaction of
     * this slice's making, which is the whole of AC-24: the history survives
     * the error response because nothing was open to roll back.
     *
     *
     * @throws CredentialRefused
     */
    private function refuse(Login $command, LoginCredentials $credentials, string $reason, ?string $userId = null): never
    {
        $this->attempts->handle(RecordLoginAttempt::failed(
            email: $credentials->email->submitted,
            failureReason: $reason,
            userId: $userId,
            ip: $command->ip,
            userAgent: $command->userAgent,
            deviceId: $command->deviceId,
        ));

        throw CredentialRefused::refused();
    }

    /**
     * The success path: the account's own columns, the tenant list, the
     * token pair, the history row - in that order.
     *
     * `failed_attempts` and `locked_until` are cleared together (AC-3,
     * AC-16): a successful sign-in ends whatever run of failures preceded it,
     * and leaving an expired `locked_until` behind would make a later read of
     * the column ambiguous about whether the account is in a lock or merely
     * remembers one.
     *
     * The history row is written **after** the token pair so that a row
     * saying `success = true` means a token really was issued; the refresh
     * row and the history row are the two durable facts of a login and
     * neither is inside a transaction (AC-24).
     */
    private function admit(Login $command, LoginCredentials $credentials, object $user): LoginResult
    {
        $userId = (string) $user->id;
        $now = $this->clock->timestamp();

        $this->master()->table('users')->where('id', $userId)->update([
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => $now,
            'last_login_ip' => IpAddress::pack($command->ip),
            'updated_at' => $now,
        ]);

        $tenants = $this->tenantList($userId);

        $pair = $this->tokens->handle(new IssueTokenPair(
            tokenType: TokenClaims::TYPE_PLATFORM,
            userId: $userId,
            ip: IpAddress::pack($command->ip),
            userAgent: $command->userAgent,
        ));

        $this->attempts->handle(RecordLoginAttempt::succeeded(
            email: $credentials->email->submitted,
            userId: $userId,
            ip: $command->ip,
            userAgent: $command->userAgent,
            deviceId: $command->deviceId,
        ));

        return new LoginResult(
            userId: $userId,
            accessToken: $pair->accessToken,
            refreshToken: $pair->refreshToken,
            tenants: $tenants,
            defaultTenantId: $this->defaultTenantId($tenants),
            mustChangePassword: (bool) $user->must_change_password,
        );
    }

    /**
     * The shops this person may see, each with whether they may open it.
     *
     * Four conditions, and each is one of AC-11's cases: the membership is
     * `active` ({@see MembershipStatus}), the membership is not soft-deleted,
     * the tenant is not soft-deleted, and - through the join - the tenant
     * exists at all. `selectable` is the commercial question and is asked of
     * the policy, never restated here (AC-12).
     *
     * The order is by `tenants.code`, so a list is reproducible; no criterion
     * names an order and a client that wants another one has the fields to
     * sort by.
     *
     * @return list<array{id: string, code: string, name: string, status: string, selectable: bool, is_default: bool}>
     */
    private function tenantList(string $userId): array
    {
        $rows = $this->master()
            ->table('tenant_users')
            ->join('tenants', 'tenants.id', '=', 'tenant_users.tenant_id')
            ->where('tenant_users.user_id', $userId)
            ->where('tenant_users.status', MembershipStatus::ACTIVE)
            ->whereNull('tenant_users.deleted_at')
            ->whereNull('tenants.deleted_at')
            ->orderBy('tenants.code')
            ->get([
                'tenants.id as id',
                'tenants.code as code',
                'tenants.name as name',
                'tenants.status as status',
                'tenant_users.is_default as is_default',
            ]);

        return $rows->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'status' => (string) $row->status,
            'selectable' => $this->tenantSelection->permits((string) $row->status),
            'is_default' => (bool) $row->is_default,
        ])->all();
    }

    /**
     * AC-13's three rules, in order: a selectable membership marked default;
     * otherwise the only selectable one; otherwise none.
     *
     * A default that is not selectable falls through rather than being
     * returned - a default is a convenience and selectability is an access
     * decision, and the convenience never overrides the decision.
     *
     * @param  list<array{id: string, code: string, name: string, status: string, selectable: bool, is_default: bool}>  $tenants
     */
    private function defaultTenantId(array $tenants): ?string
    {
        $selectable = array_values(array_filter($tenants, static fn (array $tenant): bool => $tenant['selectable']));

        foreach ($selectable as $tenant) {
            if ($tenant['is_default']) {
                return $tenant['id'];
            }
        }

        return count($selectable) === 1 ? $selectable[0]['id'] : null;
    }

    private function master(): Connection
    {
        return $this->database->connection((string) $this->config->get('tenancy.master_connection', 'master'));
    }
}
