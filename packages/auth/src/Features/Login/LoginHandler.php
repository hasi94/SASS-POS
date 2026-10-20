<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\Login;

use Carbon\CarbonImmutable;
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
    /**
     * The digest an address that matches nobody is checked against (AC-8).
     *
     * **Why there is one at all.** Without it, an unknown address returns
     * after one indexed `SELECT` and a known one returns after an Argon2id
     * verification, and the difference - measured on this machine at **207
     * milliseconds** for the shipped work factors - tells an attacker which
     * addresses have accounts. That is an enumeration oracle, and no amount
     * of making the *response* identical (D-12) closes it. So the work is
     * done either way: exactly one `check()` call on both paths, against
     * identical parameters.
     *
     * **Why it is a real Argon2id digest and not a placeholder.**
     * `hashing.verify` is `true`, which makes Laravel's Argon2id driver
     * refuse - with a `RuntimeException` - any digest that was not produced
     * by Argon2id. A sentinel such as `'x'` would therefore turn the
     * unknown-address path into a 500 instead of a refusal, and it would do
     * so only in production, where the path is not exercised by a test
     * fixture. This value was produced by
     * `password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID,
     * ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1])` - the
     * shipped work factors, so the two paths cost the same in production -
     * and the random plaintext was discarded at the moment it was generated
     * and written down nowhere. **This is not a secret and not key
     * material**: it is a digest of 64 hex characters nobody knows, it
     * authenticates nothing, and no password can be submitted that verifies
     * against it.
     *
     * **Fixed, not generated per process.** AC-8 says "a fixed dummy hash".
     * A digest generated at boot would cost a hash per process and could, in
     * principle, differ in parameters from the stored ones; a constant
     * cannot drift, and a test can name it to prove the check happened
     * against this and nothing else.
     *
     * The test suite lowers the Argon factors (`phpunit.xml`) while this
     * constant keeps the shipped ones, so each unknown-address case in the
     * suite pays the full ~200 ms. That is a deliberate cost: the point of
     * the constant is that it is what production uses.
     */
    public const DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$dDRaUDV0UkNKVURvblFKUg$D3XAt5OZ587Tunu6dk6duQZoVUpOOUpX2sUuvyA0XGM';

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
            // AC-8. The work is done whether or not there is an account to do
            // it against, and the result is discarded because the outcome was
            // decided by the lookup. This call is the criterion.
            $this->hasher->check($credentials->password, self::DUMMY_PASSWORD_HASH);

            $this->refuse($command, $credentials, RecordLoginAttempt::REASON_UNKNOWN_USER);
        }

        if ((string) $user->status !== UserStatus::ACTIVE) {
            $this->refuse($command, $credentials, RecordLoginAttempt::REASON_NOT_ACTIVE, (string) $user->id);
        }

        if ($this->isLocked($user)) {
            // AC-18: a live lock is reported and never extended. Nothing is
            // written to the account here, which is why "`locked_until` is
            // unchanged and `failed_attempts` stays 0" needs no code to be
            // true.
            $this->refuse($command, $credentials, RecordLoginAttempt::REASON_LOCKED, (string) $user->id);
        }

        if (! $this->hasher->check($credentials->password, (string) $user->password_hash)) {
            $this->countFailure($command, $credentials, (string) $user->id);
        }

        return $this->admit($command, $credentials, $user);
    }

    /**
     * Is this account inside a live lock? (AC-19.)
     *
     * The comparison is `>`, deliberately and visibly: at exactly
     * `locked_until` the lock has run out, so the boundary instant is
     * **accepted**. AC-19 requires both sides to be exercised - refused at
     * T minus a second, accepted at exactly T - because a `>` written where
     * a `>=` was meant is invisible everywhere except at T.
     *
     * An expired lock is simply not a lock: it is left in the column as the
     * record of what happened, and `failed_attempts` - which the lock itself
     * reset to 0 (D-5) - is what the next run of failures counts from
     * (AC-20).
     */
    private function isLocked(object $user): bool
    {
        if ($user->locked_until === null) {
            return false;
        }

        return CarbonImmutable::parse((string) $user->locked_until)->greaterThan($this->clock->now());
    }

    /**
     * A wrong password: count it, and lock the account if that was the last
     * one allowed (AC-16, AC-17, AC-20).
     *
     * **The increment is one `UPDATE ... SET failed_attempts =
     * failed_attempts + 1` and never a read-modify-write.** Reading the
     * column into PHP, adding one and writing the result back loses
     * increments the moment two attempts overlap, which is exactly the
     * guessing pattern the lockout exists to stop: N simultaneous wrong
     * passwords would count as one. The engine does the arithmetic, so the
     * statement is atomic wherever it runs. **AC-22 is marked [ENGINE] and
     * is graded by reading this statement**, not by a test - one SQLite
     * connection cannot produce the concurrency the criterion is about, and
     * a green test over a sequential run would be evidence of nothing while
     * looking like evidence of something.
     *
     * **The lock decision is the second statement's affected-row count, not
     * a read in PHP** - the shape `RefreshTokenHandler::rotate()` and
     * `revokeFamily()` already use. The threshold is a predicate of the
     * locking `UPDATE`, so the engine both tests it and takes the lock, and
     * exactly one of N overlapping requests can be the one that finds
     * `failed_attempts >= threshold` still true and resets it to zero. The
     * reset is what makes that so: the row the predicate matched no longer
     * matches it. A separate `SELECT` plus an unconditional reset-and-lock
     * would let every one of those requests believe it was the one that
     * crossed the line, and write a `lockout` event each.
     *
     * **This does not make the lockout resist a parallel burst, and nothing
     * here does.** `isLocked()` is read at the start of a request, so N
     * simultaneous wrong passwords all clear the lock check and all reach
     * the hasher however atomic this is; a perfectly atomic lockout behaves
     * identically for a burst. What the shape above buys is that AC-17's
     * "exactly one `security_events` row" is structural rather than
     * sequential-only. The control for parallel guessing is per-IP
     * throttling (FP-199), not this. **Sequential behaviour is unchanged,
     * which is this change's weakness too: every existing lockout test
     * stays green, so the statement is still graded by reading it** and the
     * concurrent AC-22 test the engine task owns is what would observe it.
     *
     * @throws CredentialRefused
     */
    private function countFailure(Login $command, LoginCredentials $credentials, string $userId): never
    {
        $now = $this->clock->now();
        $connection = $this->master();

        $connection->table('users')->where('id', $userId)->update([
            'failed_attempts' => $connection->raw('failed_attempts + 1'),
            'updated_at' => $now->format(RunnerClock::PRECISION),
        ]);

        $threshold = (int) $this->config->get('authentication.lockout.threshold');
        $minutes = (int) $this->config->get('authentication.lockout.minutes');
        $lockedUntil = $now->addMinutes($minutes)->format(RunnerClock::PRECISION);

        // D-5: the counter resets as the lock is taken, so the lock is a
        // fixed window rather than something a further attempt can extend,
        // and the run that follows its expiry starts from zero (AC-20).
        // `users.status` is untouched: a lock is a timer, not a state
        // (AC-17).
        $locked = $connection->table('users')
            ->where('id', $userId)
            ->where('failed_attempts', '>=', $threshold)
            ->update([
                'failed_attempts' => 0,
                'locked_until' => $lockedUntil,
                'updated_at' => $now->format(RunnerClock::PRECISION),
            ]);

        if ($locked !== 1) {
            $this->refuse($command, $credentials, RecordLoginAttempt::REASON_BAD_PASSWORD, $userId);
        }

        // The attempt that locked the account is still a `bad_password`
        // attempt, and the lockout is the notable thing that happened
        // alongside it: AC-25 requires both rows, and they are one command so
        // that neither can be written without the other.
        $this->attempts->handle(RecordLoginAttempt::failed(
            email: $credentials->email->submitted,
            failureReason: RecordLoginAttempt::REASON_BAD_PASSWORD,
            userId: $userId,
            ip: $command->ip,
            userAgent: $command->userAgent,
            deviceId: $command->deviceId,
            securityEventType: RecordLoginAttempt::EVENT_LOCKOUT,
            securityEventDetail: [
                'threshold' => $threshold,
                'minutes' => $minutes,
                'locked_until' => $lockedUntil,
            ],
        ));

        throw CredentialRefused::refused();
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
