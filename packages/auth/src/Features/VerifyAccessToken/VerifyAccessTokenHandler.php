<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\VerifyAccessToken;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Flexipos\Auth\Exceptions\AccessTokenRefused;
use Flexipos\Auth\Tokens\SigningKeyring;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Throwable;

/**
 * The one place a JWT is verified. AC-29's eight refusals end here.
 *
 * **Which half is ours, and the distinction is the point.** Four of the eight
 * are the library's by contract and this class deliberately does not re-derive
 * them: a payload altered after signing and a signature from a foreign key
 * raise `SignatureInvalidException`; `alg: none` is absent from
 * `JWT::$supported_algs`; an `alg` that is not the key's is refused by
 * `constantTimeEquals($key->getAlgorithm(), $header->alg)`; and `exp` is
 * refused at exactly `exp`. **This handler never reads `alg` from the
 * header** - the algorithm is `SigningKeyring::ALGORITHM`, named by us and
 * handed to the library - which is what makes alg-confusion structurally
 * impossible here rather than guarded against. A second copy of those checks
 * would be a second place for them to disagree.
 *
 * The other four are ours: a malformed token, an unknown `kid`, the `kid` of a
 * key that no longer verifies, and the exact claim set.
 *
 * **`kid` is read from an unverified header, and that is correct.** It names
 * which key to try; it grants nothing. A token naming a key it was not signed
 * with fails the signature check a moment later.
 *
 * **The token-type check runs before the claim-set comparison, and it is not
 * redundant - measured.** Deleting it and running the suite: a platform token
 * presented where a tenant one is required is still refused, because the two
 * shapes differ by the `tenant` claim and the set comparison catches it - but
 * under the reason `claim_set`, which names the wrong cause in the one place
 * the cause survives. And a token whose names are exactly the platform set
 * while its `token_type` says `tenant` is then **accepted as a platform
 * token**: the set comparison has nothing to object to. So the ordering keeps
 * the two refusals distinguishable, and the check itself is what stands
 * between a mislabelled token and a session. Both measurements are pinned by
 * cases in VerifyAccessTokenTest.
 *
 * **Time comes from the injected clock, through the library's own seam.**
 * `JWT::$timestamp` is a public static; it is set from `RunnerClock` for the
 * duration of one decode and restored afterwards in a `finally`, so a test
 * that stopped the clock cannot leave the process pinned to its instant.
 */
final class VerifyAccessTokenHandler
{
    public function __construct(
        private readonly SigningKeyring $keyring,
        private readonly RunnerClock $clock,
    ) {}

    /**
     * The verified claims.
     *
     * @return array<string, mixed>
     */
    public function handle(VerifyAccessToken $command): array
    {
        $kid = $this->kidOf($command->token);

        $publicKey = $this->keyring->verificationKey($kid);

        if ($publicKey === null) {
            // Two refusals, one gate: the keyring decided, and the second read
            // only names which of the two it was for the record.
            throw $this->keyring->find($kid) === null
                ? AccessTokenRefused::unknownKid()
                : AccessTokenRefused::retiredKey();
        }

        $claims = $this->decode($command->token, $publicKey);

        if (($claims['token_type'] ?? null) !== $command->requiredTokenType) {
            throw AccessTokenRefused::wrongTokenType();
        }

        if (! TokenClaims::isExactly($claims, $command->requiredTokenType)) {
            throw AccessTokenRefused::claimSet();
        }

        return $claims;
    }

    /**
     * The `kid` of an unverified header, or a refusal.
     */
    private function kidOf(string $token): string
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw AccessTokenRefused::malformed();
        }

        $decoded = base64_decode(strtr($segments[0], '-_', '+/'), true);

        if ($decoded === false) {
            throw AccessTokenRefused::malformed();
        }

        $header = json_decode($decoded, true);

        if (! is_array($header) || ! is_string($header['kid'] ?? null) || $header['kid'] === '') {
            throw AccessTokenRefused::malformed();
        }

        return $header['kid'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $token, string $publicKey): array
    {
        $restore = JWT::$timestamp;

        JWT::$timestamp = $this->clock->now()->getTimestamp();

        try {
            return (array) JWT::decode($token, new Key($publicKey, SigningKeyring::ALGORITHM));
        } catch (ExpiredException $expired) {
            throw AccessTokenRefused::expired($expired);
        } catch (Throwable $refused) {
            throw AccessTokenRefused::rejectedByLibrary($refused);
        } finally {
            JWT::$timestamp = $restore;
        }
    }
}
