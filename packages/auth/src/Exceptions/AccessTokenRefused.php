<?php

declare(strict_types=1);

namespace Flexipos\Auth\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An access token did not verify. Thrown in one place,
 * `VerifyAccessTokenHandler::handle()`, which is the only code that decides
 * whether a token is good.
 *
 * **The reason is for us, never for the caller.** `reason()` is what a log or
 * a `login_history` row records; the HTTP surface renders the generic refusal
 * of D-12, because a verifier that told a client *why* its token failed would
 * be an oracle. The reasons are:
 *
 * - `malformed_token` - not three segments, an undecodable header, or no
 *   `kid`. Ours, decided before the library is given anything.
 * - `unknown_kid` - the header names a key `signing_keys` does not hold.
 *   Ours.
 * - `retired_key` - the key exists and its status is not one that verifies.
 *   Ours, and the case the per-process cache must never be able to answer
 *   stale.
 * - `invalid_token` - the library refused it: a bad signature, an altered
 *   payload, `alg: none`, or an `alg` that is not the key's. The library's
 *   own exception is kept as `getPrevious()` so a test can name which
 *   mechanism refused rather than only that something did.
 * - `expired` - `exp` reached, refused at exactly `exp`.
 * - `token_type` - a platform token where a tenant one is required, or the
 *   reverse. Ours, and checked *before* the claim set so that the set
 *   comparison cannot absorb it.
 * - `claim_set` - a validly signed token whose claim names are not exactly
 *   the set its type allows (AC-28).
 *
 * **No message here carries the token, a segment of it or a claim value.** A
 * refusal is written to a log; a token written to a log is the thing AC-26
 * forbids.
 */
final class AccessTokenRefused extends RuntimeException
{
    /**
     * The one reason the HTTP surface distinguishes, and therefore the one
     * reason that is a named value rather than a literal (step 12).
     *
     * AC-42 and AC-46 both answer **403** to a token of the wrong type and
     * 401 to everything else, because the caller holds a token this system
     * issued and the remedy is to exchange it, not to authenticate again.
     * That is the only branch the middleware takes on a reason, so it is the
     * only reason the middleware may name - and it names this constant, so
     * that the word exists once. Every other reason reaches the client as
     * the same generic refusal and is told apart only in a log.
     */
    public const REASON_WRONG_TOKEN_TYPE = 'token_type';

    private function __construct(private readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('the access token was refused (%s)', $reason), 0, $previous);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public static function malformed(): self
    {
        return new self('malformed_token');
    }

    public static function unknownKid(): self
    {
        return new self('unknown_kid');
    }

    public static function retiredKey(): self
    {
        return new self('retired_key');
    }

    public static function rejectedByLibrary(Throwable $previous): self
    {
        return new self('invalid_token', $previous);
    }

    public static function expired(Throwable $previous): self
    {
        return new self('expired', $previous);
    }

    public static function wrongTokenType(): self
    {
        return new self(self::REASON_WRONG_TOKEN_TYPE);
    }

    public static function claimSet(): self
    {
        return new self('claim_set');
    }
}
