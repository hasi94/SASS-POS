<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\RefreshTerminalToken;

use Carbon\CarbonImmutable;
use Flexipos\Auth\Exceptions\CredentialRefused;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttempt;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttemptHandler;
use Flexipos\Auth\Support\IpAddress;
use Flexipos\Auth\Tenancy\TenantSelectionPolicy;
use Flexipos\Auth\Tokens\RefreshTokenValue;
use Flexipos\Auth\Tokens\SigningKeyring;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Contracts\Organization\TerminalAuthority;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * A terminal exchanging its refresh token for the next pair, once (T-014
 * AC-36, D-35, Ruling 5).
 *
 * The order, and what each step leaves untouched when it refuses:
 *
 * 1. The signing key is resolved first, so an operational failure judges
 *    nothing and consumes nothing.
 * 2. The row is looked up **by hash and by principal type `terminal`**. A
 *    user's token presented here is unknown, and a terminal's token presented
 *    to `/auth/refresh` is unknown there (that slice filters on `user`): the
 *    two families never meet. Unknown and revoked are one answer.
 * 3. **A used row is a replay.** The whole family is revoked and a
 *    `token_reuse` security event is written with `user_id` null (a terminal
 *    is not a user; the column has no key), the tenant, and `{family_id,
 *    revoked_rows, terminal_id}`. Never a token or a hash.
 * 4. An expired row is refused.
 * 5. **The tenant is re-checked before anything is consumed**: a tenant that
 *    is gone, may not be selected, or cannot be entered is refused with the
 *    token usable again once the tenant is (T-009 D-24's treatment of a
 *    suspended shop).
 * 6. **The tenant database is asked about the terminal.** Not active and
 *    *paused* (`inactive`): refused, nothing consumed, **the family kept**,
 *    because a paused till refreshes every 15 minutes and would otherwise
 *    lose its session for good (D-35). Not active for any other reason -
 *    revoked, missing, a revoked credential, a revoked or absent state - the
 *    family is revoked and the request refused.
 * 7. `last_seen_at` is stamped on the terminal and its device **before** the
 *    row is consumed, so a failure after consumption cannot strand a client
 *    whose token has already been used.
 * 8. The row is consumed with a conditional update (two requests racing leave
 *    one winner), and the new pair joins the **same family**.
 *
 * The refusals are all {@see CredentialRefused}: the same bytes at the host.
 */
final class RefreshTerminalTokenHandler
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly RunnerClock $clock,
        private readonly SigningKeyring $keyring,
        private readonly TenantSelectionPolicy $tenantSelection,
        private readonly Tenancy $tenancy,
        private readonly TerminalAuthority $authority,
        private readonly RecordLoginAttemptHandler $attempts,
        private readonly IssueTokenPairHandler $tokens,
    ) {}

    /**
     * @throws CredentialRefused every refusal
     */
    public function handle(RefreshTerminalToken $command): RefreshTerminalTokenResult
    {
        $hash = $this->presentedHash($command->token);

        // 1.
        $this->keyring->signingKey();

        // 2.
        $row = $this->master()->table('refresh_tokens')
            ->where('token_hash', $hash)
            ->where('principal_type', IssueTokenPairHandler::PRINCIPAL_TYPE_TERMINAL)
            ->first();

        if ($row === null || $row->revoked_at !== null) {
            throw CredentialRefused::refused();
        }

        // 3.
        if ($row->used_at !== null) {
            $this->refuseReplay($command, $row);
        }

        // 4.
        if (! CarbonImmutable::parse((string) $row->expires_at)->greaterThan($this->clock->now())) {
            throw CredentialRefused::refused();
        }

        $terminalId = (string) $row->principal_id;
        $tenantId = $row->tenant_id === null ? null : (string) $row->tenant_id;

        // 5.
        $tenant = $tenantId === null ? null : $this->master()->table('tenants')->where('id', $tenantId)->first(['status', 'deleted_at']);

        if ($tenantId === null || $tenant === null || $tenant->deleted_at !== null || ! $this->tenantSelection->permits((string) $tenant->status)) {
            throw CredentialRefused::refused();
        }

        try {
            $this->tenancy->enter($tenantId);
        } catch (TenancyException) {
            throw CredentialRefused::refused();
        }

        try {
            // 6.
            $branchId = $this->authority->activeBranchOf($terminalId);

            if ($branchId === null) {
                if (! $this->authority->isPaused($terminalId)) {
                    $this->revokeFamily((string) $row->family_id);
                }

                throw CredentialRefused::refused();
            }

            // 7.
            $this->authority->recordSeen($terminalId);
        } finally {
            $this->tenancy->leave();
        }

        // 8.
        return $this->rotate($command, $row, $terminalId, $tenantId, $branchId);
    }

    private function rotate(RefreshTerminalToken $command, object $row, string $terminalId, string $tenantId, string $branchId): RefreshTerminalTokenResult
    {
        $now = $this->clock->timestamp();

        $consumed = $this->master()->table('refresh_tokens')
            ->where('id', (string) $row->id)
            ->whereNull('used_at')
            ->update(['used_at' => $now, 'updated_at' => $now]);

        if ($consumed !== 1) {
            throw CredentialRefused::refused();
        }

        $pair = $this->tokens->handle(new IssueTokenPair(
            tokenType: TokenClaims::TYPE_TERMINAL,
            userId: $terminalId,
            tenantId: $tenantId,
            familyId: (string) $row->family_id,
            ip: IpAddress::pack($command->ip),
            userAgent: $command->userAgent,
            branchId: $branchId,
        ));

        return new RefreshTerminalTokenResult($terminalId, $branchId, $pair->accessToken, $pair->refreshToken, $pair->familyId);
    }

    /**
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
     * @throws CredentialRefused
     */
    private function refuseReplay(RefreshTerminalToken $command, object $row): never
    {
        $revoked = $this->revokeFamily((string) $row->family_id);

        $this->attempts->handle(RecordLoginAttempt::securityEvent(
            type: RecordLoginAttempt::EVENT_TOKEN_REUSE,
            userId: null,
            tenantId: $row->tenant_id === null ? null : (string) $row->tenant_id,
            ip: $command->ip,
            detail: [
                'family_id' => (string) $row->family_id,
                'revoked_rows' => $revoked,
                'terminal_id' => (string) $row->principal_id,
            ],
        ));

        throw CredentialRefused::refused();
    }

    /**
     * Revoke every row of one family not already revoked, in one statement,
     * and say how many.
     */
    private function revokeFamily(string $familyId): int
    {
        $now = $this->clock->timestamp();

        return $this->master()->table('refresh_tokens')
            ->where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'updated_at' => $now]);
    }

    private function master(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->database->connection((string) $this->config->get('tenancy.master_connection', 'master'));

        return $connection;
    }
}
