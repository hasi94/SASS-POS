<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\SelectTenant;

use Flexipos\Auth\Exceptions\InvalidTenantCode;
use Flexipos\Auth\Features\Login\LoginCredentials;

/**
 * What a selection body has to contain before anything can be looked up
 * (AC-41).
 *
 * **Validation lives here, in front of the slice, and not only in the
 * controller** - the same reasoning as
 * {@see LoginCredentials}. AC-41 asserts an
 * absence (no `login_history` row), and an absence is a property of *where*
 * the refusal happens. A rule enforced only by an HTTP validator is a rule
 * that disappears the first time a handler is called from a console command,
 * a queue job or a test, which is exactly when the absence would stop
 * holding.
 *
 * **It takes `mixed`, deliberately**: "a non-string value" is one of AC-41's
 * four cases, and a constructor typed `string` cannot express it.
 *
 * **The code is trimmed and the trimmed form is what is looked up.** A code
 * of spaces is a code of nothing, which is why whitespace-only is the same
 * refusal as missing; and a code the user pasted with a trailing space is the
 * code they meant. The trim happens before the emptiness test, not after it.
 *
 * **No length limit is imposed here, and that is deliberate.** `tenants.code`
 * is 30 characters, so a longer value matches no row and is refused as
 * `no_access` - the same answer as any other code that names no shop of
 * theirs. A 422 for a long code would tell a caller that codes are at most 30
 * characters, which is a small disclosure bought for nothing, and it would
 * add a limit to `config/authentication.php` that no criterion asked for.
 */
final class TenantCodeInput
{
    private function __construct(
        /**
         * The code as it will be looked up: trimmed, otherwise exactly as
         * submitted. Case is **not** folded - `tenants.code` is a slug the
         * platform mints, not something a person types from memory, and
         * folding it here would be a lookup rule invented by this class
         * rather than by the schema.
         */
        public readonly string $value,
    ) {}

    /**
     * Narrow a decoded request field to a code, or refuse it.
     *
     * @throws InvalidTenantCode
     */
    public static function from(mixed $code): self
    {
        if ($code === null) {
            throw InvalidTenantCode::codeMissing();
        }

        if (! is_string($code)) {
            throw InvalidTenantCode::codeNotString();
        }

        $trimmed = trim($code);

        if ($trimmed === '') {
            throw InvalidTenantCode::codeMissing();
        }

        return new self($trimmed);
    }
}
