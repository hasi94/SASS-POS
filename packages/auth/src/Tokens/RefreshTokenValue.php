<?php

declare(strict_types=1);

namespace Flexipos\Auth\Tokens;

use InvalidArgumentException;

/**
 * A refresh token value: 32 CSPRNG bytes as base64url, and its SHA-256 hex.
 *
 * **The plaintext is a return value and nothing else.** It lives in this
 * object, which is handed to the caller that renders the response, and it is
 * never a property of anything persisted: `refresh_tokens.token_hash` gets
 * `hash()` and no column anywhere gets `plaintext()` (AC-32). The runtime
 * proof that it reaches no column of any master table is AC-32's and QA's;
 * what this class guarantees is that there is nothing else to write.
 *
 * **32 bytes, because AC-32 asks for at least 256 bits measured as a length
 * and not as a statistical test.** `random_bytes()` is the platform CSPRNG and
 * throws rather than degrading, which is the correct failure for a credential.
 *
 * **base64url with no padding**, so the value survives a JSON body, a header
 * and a form field unescaped. It is never put in a URL (the security NFR), but
 * a value that would need escaping is a value somebody eventually escapes
 * twice.
 *
 * **The hash is SHA-256 and not a password hash, on purpose.** This is a
 * 256-bit random value, not a human-chosen secret: there is nothing for a
 * work factor to defend against, and the lookup on `token_hash` has to be an
 * indexed equality.
 */
final class RefreshTokenValue
{
    /**
     * Bytes of randomness behind the value.
     */
    public const BYTES = 32;

    /**
     * Characters of SHA-256 hex, which is `refresh_tokens.token_hash`'s width.
     */
    public const HASH_LENGTH = 64;

    private function __construct(private readonly string $plaintext) {}

    /**
     * A new value from the platform CSPRNG.
     */
    public static function generate(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '='));
    }

    /**
     * A value presented by a client, so it can be hashed and looked up.
     *
     * An empty string is refused rather than hashed: the SHA-256 of nothing is
     * a perfectly valid-looking 64 characters that would match any row written
     * by the same mistake, and a refusal is the only answer that cannot be
     * confused with a lookup miss.
     */
    public static function fromPlaintext(string $plaintext): self
    {
        if ($plaintext === '') {
            throw new InvalidArgumentException('a refresh token value cannot be empty');
        }

        return new self($plaintext);
    }

    /**
     * The value handed to the client. Never written to a column, a log or an
     * exception message.
     */
    public function plaintext(): string
    {
        return $this->plaintext;
    }

    /**
     * What `refresh_tokens.token_hash` stores.
     */
    public function hash(): string
    {
        return hash('sha256', $this->plaintext);
    }
}
