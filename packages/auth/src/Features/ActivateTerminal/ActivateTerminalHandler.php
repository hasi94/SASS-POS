<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\ActivateTerminal;

use Flexipos\Auth\Exceptions\ActivationUnavailable;
use Flexipos\Auth\Exceptions\CredentialRefused;
use Flexipos\Auth\Exceptions\NoActiveSigningKey;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Support\ActivationThrottle;
use Flexipos\Auth\Support\IpAddress;
use Flexipos\Auth\Tenancy\TenantSelectionPolicy;
use Flexipos\Auth\Tokens\SigningKeyring;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Contracts\Organization\ActivatedTerminal;
use Flexipos\Contracts\Organization\ActivationAttempt;
use Flexipos\Contracts\Organization\ActivationCodeFormat;
use Flexipos\Contracts\Organization\TerminalActivation;
use Flexipos\Contracts\Organization\TerminalActivationRefused;
use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Activate a terminal from its one-time code, on a public route with no
 * authentication (T-014 AC-29 to AC-31, AC-33, D-17, D-18, D-41).
 *
 * **One refusal, byte for byte.** A malformed code, an unknown tenant part, an
 * unknown, expired or used code, a revoked or paused terminal, an inactive
 * branch, a tenant that may not be served, a bad fingerprint and a fingerprint
 * that is another terminal's live device all end in
 * {@see CredentialRefused}, which the host renders as the same bytes with no
 * `Retry-After`. What differed goes to the application log as a closed reason
 * class and a tenant id if one was resolved - never the code, never the tenant
 * part the caller typed, never the fingerprint.
 *
 * **The order is the design:**
 *
 * 1. The signing key is resolved first, so an operational failure judges and
 *    counts nothing, and no row is written without a key to mint with.
 * 2. A locked address is refused **before anything else and is not counted**:
 *    a refusal while locked neither counts nor postpones the unlock.
 * 3. The shape of the request. A failure counts the **address** only.
 * 4. The tenant, from the code's tenant part, in the master. An unknown part
 *    counts the address only, so probing made-up tenants consumes no
 *    tenant-keyed state.
 * 5. A locked tenant is refused, uncounted.
 * 6. The tenant's status, by the same selection policy tenant selection uses.
 *    A failure counts both.
 * 7. The tenancy is entered; any tenancy failure is a refusal counting both.
 * 8. The tenant side activates **inside one tenant transaction**, and the mint
 *    of the master refresh row is the callable it runs last (see
 *    {@see TerminalActivation}): a refusal writes nothing, and a mint that
 *    throws takes the tenant writes with it.
 *
 * **Operational failures are not the uniform refusal and are not counted**
 * (Ruling 12): no signing key is the existing 503, and a master write or a
 * tenant commit that fails is {@see ActivationUnavailable}, a 503 too.
 *
 * The one deliberate deviation from the plan's text: step 2 and 3 are in the
 * order above (lock first, then shape), because the plan's order would count a
 * malformed request made while the address is locked, against its own rule
 * that a locked refusal is never counted.
 */
final class ActivateTerminalHandler
{
    /** The device columns' widths (T-014 Ruling 10 (d)); a longer value is refused, never cut. */
    public const DEVICE_LIMITS = ['platform' => 50, 'browser' => 100, 'os' => 100, 'screen' => 30];

    public const FINGERPRINT_PATTERN = '/\A[0-9a-f]{64}\z/';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly SigningKeyring $keyring,
        private readonly TenantSelectionPolicy $tenantSelection,
        private readonly Tenancy $tenancy,
        private readonly TerminalActivation $activation,
        private readonly ActivationThrottle $throttle,
        private readonly IssueTokenPairHandler $tokens,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws NoActiveSigningKey operational, a 503
     * @throws ActivationUnavailable operational, a 503
     * @throws CredentialRefused every refusal
     */
    public function handle(ActivateTerminal $command): ActivateTerminalResult
    {
        // 1. Before anything is read, judged or counted.
        $this->keyring->signingKey();

        $address = $this->throttle->addressKey($command->ip);

        // 2. Locked: refused and not counted.
        if ($this->throttle->isLocked($address)) {
            $this->refuse('address_locked', null, []);
        }

        // 3. The shape of the request.
        $attempt = $this->attemptOf($command);

        if ($attempt === null) {
            $this->refuse('malformed', null, [$address]);
        }

        $parsed = ActivationCodeFormat::parse($command->code);

        if ($parsed === null) {
            $this->refuse('malformed', null, [$address]);
        }

        // 4. The tenant, from the code's own tenant part.
        $tenant = $this->master()->table('tenants')
            ->where('code', $parsed['tenantPart'])
            ->whereNull('deleted_at')
            ->first(['id', 'status']);

        if ($tenant === null) {
            $this->refuse('unknown_tenant', null, [$address]);
        }

        $tenantId = (string) $tenant->id;
        $tenantKey = $this->throttle->tenantKey($tenantId);

        // 5. Locked: refused and not counted.
        if ($this->throttle->isLocked($tenantKey)) {
            $this->refuse('tenant_locked', $tenantId, []);
        }

        // 6. The tenant's status.
        if (! $this->tenantSelection->permits((string) $tenant->status)) {
            $this->refuse('tenant_not_servable', $tenantId, [$address, $tenantKey]);
        }

        // 7. The tenancy.
        try {
            $this->tenancy->enter($tenantId);
        } catch (TenancyException) {
            $this->refuse('tenant_unavailable', $tenantId, [$address, $tenantKey]);
        }

        // 8. The tenant side, with the mint as its last act.
        try {
            $issued = $this->activation->activate(
                new ActivationAttempt(
                    $parsed['secret'],
                    $attempt['fingerprint'],
                    $attempt['platform'],
                    $attempt['browser'],
                    $attempt['os'],
                    $attempt['screen'],
                    $command->ip,
                ),
                fn (ActivatedTerminal $terminal): ActivateTerminalResult => $this->issue($terminal, $tenantId, $command),
            );
        } catch (TerminalActivationRefused $refused) {
            $this->refuse($refused->reasonClass, $tenantId, [$address, $tenantKey]);
        } catch (NoActiveSigningKey $unavailable) {
            throw $unavailable;
        } catch (Throwable $failure) {
            $this->logger->error('Terminal activation failed for an operational reason.', [
                'tenant_id' => $tenantId,
                'exception' => $failure::class,
            ]);

            throw new ActivationUnavailable('Terminal activation could not be completed.', 0, $failure);
        } finally {
            $this->tenancy->leave();
        }

        if (! $issued instanceof ActivateTerminalResult) {
            throw new ActivationUnavailable('Terminal activation returned no token.');
        }

        return $issued;
    }

    /**
     * Mint the pair, on the master, with a **new family**. Called by the
     * tenant side after its last write and inside its transaction.
     */
    private function issue(ActivatedTerminal $terminal, string $tenantId, ActivateTerminal $command): ActivateTerminalResult
    {
        $pair = $this->tokens->handle(new IssueTokenPair(
            tokenType: TokenClaims::TYPE_TERMINAL,
            userId: $terminal->terminalId,
            tenantId: $tenantId,
            ip: IpAddress::pack($command->ip),
            userAgent: $command->userAgent,
            branchId: $terminal->branchId,
        ));

        return new ActivateTerminalResult($terminal->terminalId, $terminal->branchId, $pair->accessToken, $pair->refreshToken);
    }

    /**
     * The fingerprint and device fields, or null when any is malformed.
     *
     * @return array{fingerprint: string, platform: ?string, browser: ?string, os: ?string, screen: ?string}|null
     */
    private function attemptOf(ActivateTerminal $command): ?array
    {
        if (! is_string($command->fingerprint) || preg_match(self::FINGERPRINT_PATTERN, $command->fingerprint) !== 1) {
            return null;
        }

        $device = [];

        foreach (self::DEVICE_LIMITS as $field => $limit) {
            $value = $command->{$field};

            if ($value !== null && (! is_string($value) || mb_strlen($value) > $limit)) {
                return null;
            }

            $device[$field] = $value;
        }

        return [
            'fingerprint' => $command->fingerprint,
            'platform' => $device['platform'],
            'browser' => $device['browser'],
            'os' => $device['os'],
            'screen' => $device['screen'],
        ];
    }

    /**
     * Count the failures, log the closed reason, and refuse.
     *
     * @param  list<string>  $keysToCount
     *
     * @throws CredentialRefused always
     */
    private function refuse(string $reasonClass, ?string $tenantId, array $keysToCount): never
    {
        foreach ($keysToCount as $key) {
            $this->throttle->recordFailure($key);
        }

        $this->logger->info('Terminal activation refused.', ['reason' => $reasonClass, 'tenant_id' => $tenantId]);

        throw CredentialRefused::refused();
    }

    private function master(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->database->connection((string) $this->config->get('tenancy.master_connection', 'master'));

        return $connection;
    }
}
