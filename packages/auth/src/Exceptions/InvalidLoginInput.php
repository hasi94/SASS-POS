<?php

declare(strict_types=1);

namespace Flexipos\Auth\Exceptions;

use Flexipos\Auth\Features\Login\LoginCredentials;
use RuntimeException;

/**
 * The submitted login body is not something credentials can be read from
 * (AC-9).
 *
 * **Why this is a different refusal from a wrong password, and must stay
 * one.** D-12 makes every *credential* refusal one byte-identical 401, so
 * that an attacker learns nothing from the response. A malformed request is
 * not a credential refusal: nothing was looked up, no hasher ran, no
 * `login_history` row was written and no account's `failed_attempts` moved.
 * Answering it with the generic 401 would be worse than useless - it would
 * put an unbounded number of unparseable requests into the lockout path and
 * the history, and give a client no way to tell a typo in its own JSON from a
 * wrong password. So this is a 422, and the three absences above are what
 * AC-9 asserts.
 *
 * **It is thrown where the criterion is read** - in
 * {@see LoginCredentials::from()}, the first
 * statement of the login slice, before the signing key, before the lookup and
 * before the hasher. The HTTP layer validates the same shape again on its own
 * side (step 12); that is defence in depth, and this is the refusal that
 * holds whether or not a caller came through a controller.
 *
 * **No message here carries the password**, or its length, or any part of it.
 * A refusal is written to a log and a password written to a log is the thing
 * AC-26 forbids. The email is not echoed either - it is already in the
 * request - so a message is a reason and nothing else.
 */
final class InvalidLoginInput extends RuntimeException
{
    public const REASON_EMAIL_MISSING = 'email_missing';

    public const REASON_EMAIL_NOT_STRING = 'email_not_string';

    public const REASON_EMAIL_TOO_LONG = 'email_too_long';

    public const REASON_PASSWORD_MISSING = 'password_missing';

    public const REASON_PASSWORD_NOT_STRING = 'password_not_string';

    public const REASON_PASSWORD_TOO_LONG = 'password_too_long';

    /**
     * Every reason this refusal can carry, derived from the constants above so
     * a seventh is added in one place.
     *
     * @var list<string>
     */
    public const REASONS = [
        self::REASON_EMAIL_MISSING,
        self::REASON_EMAIL_NOT_STRING,
        self::REASON_EMAIL_TOO_LONG,
        self::REASON_PASSWORD_MISSING,
        self::REASON_PASSWORD_NOT_STRING,
        self::REASON_PASSWORD_TOO_LONG,
    ];

    private function __construct(private readonly string $reason)
    {
        parent::__construct(sprintf('the login request cannot be read (%s)', $reason));
    }

    /**
     * Which field was wrong and how - for the test that asserts the mechanism
     * rather than the status code, and for the validation errors the HTTP
     * layer renders.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    public static function emailMissing(): self
    {
        return new self(self::REASON_EMAIL_MISSING);
    }

    public static function emailNotString(): self
    {
        return new self(self::REASON_EMAIL_NOT_STRING);
    }

    public static function emailTooLong(): self
    {
        return new self(self::REASON_EMAIL_TOO_LONG);
    }

    public static function passwordMissing(): self
    {
        return new self(self::REASON_PASSWORD_MISSING);
    }

    public static function passwordNotString(): self
    {
        return new self(self::REASON_PASSWORD_NOT_STRING);
    }

    public static function passwordTooLong(): self
    {
        return new self(self::REASON_PASSWORD_TOO_LONG);
    }
}
