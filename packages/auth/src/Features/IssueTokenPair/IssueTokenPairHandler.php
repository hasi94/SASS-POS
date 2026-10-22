<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\IssueTokenPair;

use Firebase\JWT\JWT;
use Flexipos\Auth\Tokens\RefreshTokenValue;
use Flexipos\Auth\Tokens\SigningKeyring;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * The one place a JWT is minted and a refresh-token row is written.
 *
 * **The signing key is resolved first, before anything else happens.** That
 * ordering is what makes AC-31 structurally true rather than a cleanup path:
 * with no active key the handler throws before a value is generated, before a
 * row is built and before any caller has updated `last_login_at`, so "no
 * refresh row was written" is a property of the order of two statements and
 * not of a rollback that someone has to remember to write.
 *
 * **The access token is signed, the refresh token is not a token at all.**
 * One is a JOSE object a verifier reads without the master; the other is 32
 * random bytes whose only meaning is a row. They are issued together because
 * every caller needs both, and for no other reason.
 *
 * **`principal_type` is `user`.** T-009 issues for platform users only;
 * FP-209's terminal tokens add the second principal type, which is why the
 * column exists and why this handler writes a literal rather than assuming.
 *
 * Time is the kernel's `RunnerClock`, injected - one `now()` in the process,
 * one `DATETIME(3)` rendering, and a boundary a test can drive.
 */
final class IssueTokenPairHandler
{
    /**
     * The only principal type T-009 issues for.
     */
    public const PRINCIPAL_TYPE = 'user';

    /**
     * The principal type of a terminal's refresh rows (T-014): written by
     * activation and refresh, read by revocation. `PRINCIPAL_TYPE` stays
     * `user`, because T-009's agreement pins are about login.
     */
    public const PRINCIPAL_TYPE_TERMINAL = 'terminal';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly RunnerClock $clock,
        private readonly SigningKeyring $keyring,
    ) {}

    public function handle(IssueTokenPair $command): IssueTokenPairResult
    {
        // First, and before any value is generated or any row is built.
        $key = $this->keyring->signingKey();

        $now = $this->clock->now();
        $jti = (string) Str::ulid();

        $claims = match ($command->tokenType) {
            TokenClaims::TYPE_TENANT => $this->tenantClaims($command, $jti, $now->getTimestamp()),
            TokenClaims::TYPE_TERMINAL => TokenClaims::terminal(
                $command->userId,
                (string) $command->tenantId,
                (string) $command->branchId,
                $jti,
                $now->getTimestamp(),
                $this->accessTtlMinutes()
            ),
            default => TokenClaims::platform(
                $command->userId,
                $jti,
                $now->getTimestamp(),
                $this->accessTtlMinutes()
            ),
        };

        $accessToken = JWT::encode($claims, $key['private_key'], SigningKeyring::ALGORITHM, $key['kid']);

        $refresh = RefreshTokenValue::generate();
        $refreshTokenId = (string) Str::ulid();
        $familyId = $command->familyId ?? (string) Str::ulid();
        $expiresAt = $now->addDays($this->refreshTtlDays())->format(RunnerClock::PRECISION);

        $this->database
            ->connection((string) $this->config->get('tenancy.master_connection', 'master'))
            ->table('refresh_tokens')
            ->insert([
                'id' => $refreshTokenId,
                'principal_type' => $command->tokenType === TokenClaims::TYPE_TERMINAL
                    ? self::PRINCIPAL_TYPE_TERMINAL
                    : self::PRINCIPAL_TYPE,
                'principal_id' => $command->userId,
                'tenant_id' => $command->tenantId,
                // The hash, never the value (AC-32).
                'token_hash' => $refresh->hash(),
                'family_id' => $familyId,
                'expires_at' => $expiresAt,
                'used_at' => null,
                'revoked_at' => null,
                'ip' => $command->ip,
                'user_agent' => $command->userAgent,
                'created_at' => $now->format(RunnerClock::PRECISION),
                'updated_at' => $now->format(RunnerClock::PRECISION),
            ]);

        return new IssueTokenPairResult(
            accessToken: $accessToken,
            kid: $key['kid'],
            claims: $claims,
            refreshToken: $refresh->plaintext(),
            refreshTokenId: $refreshTokenId,
            familyId: $familyId,
            expiresAt: $expiresAt,
        );
    }

    /**
     * One of the two tenant shapes, chosen by whether the membership named
     * an employee (AC-56, AC-58).
     *
     * @return array<string, mixed>
     */
    private function tenantClaims(IssueTokenPair $command, string $jti, int $issuedAt): array
    {
        $tenantId = (string) $command->tenantId;

        if ($command->employeeClaims === null) {
            return TokenClaims::tenant($command->userId, $tenantId, $jti, $issuedAt, $this->accessTtlMinutes());
        }

        return TokenClaims::tenantWithEmployee(
            $command->userId,
            $tenantId,
            $command->employeeClaims,
            $jti,
            $issuedAt,
            $this->accessTtlMinutes()
        );
    }

    private function accessTtlMinutes(): int
    {
        return (int) $this->config->get('authentication.access_ttl_minutes');
    }

    private function refreshTtlDays(): int
    {
        return (int) $this->config->get('authentication.refresh_ttl_days');
    }
}
