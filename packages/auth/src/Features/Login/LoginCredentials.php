<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\Login;

use Flexipos\Auth\Exceptions\InvalidLoginInput;
use Flexipos\Auth\Support\EmailAddress;

/**
 * What a login body has to contain before anything can be done with it
 * (AC-9).
 *
 * **Validation lives here, in front of the slice, and not only in the
 * controller.** AC-9 asserts three absences - the hasher is not invoked, no
 * `login_history` row is written, no account's `failed_attempts` moves - and
 * all three are properties of *where* the refusal happens. A rule enforced
 * only by an HTTP validator is a rule that disappears the first time a
 * handler is called from a console command, a queue job or a test, which is
 * exactly when the absences would stop holding. The controller validates too
 * (step 12); that is defence in depth, and this is the half that cannot be
 * bypassed.
 *
 * **It takes `mixed`, deliberately.** "A non-string value for either" is one
 * of AC-9's cases, and a constructor typed `string` cannot express it: PHP
 * would refuse the call with a `TypeError` somewhere upstream, in a message
 * that is not a validation reason and may quote the value. So the two fields
 * arrive as whatever was decoded from JSON and are narrowed here, once.
 *
 * **The limits are passed in, not read here.** They are configuration
 * (`authentication.limits.*`, numbers and nothing else) and the handler owns
 * the configuration read, so this class stays a value with no container and
 * no environment behind it - and a test can state a limit instead of editing
 * the environment to reach a boundary.
 *
 * **Both boundaries are accepted, not refused** (AC-9's own control): an
 * email of exactly the limit and a password of exactly the limit are valid
 * input and go on to fail, if they fail, as credentials.
 */
final class LoginCredentials
{
    private function __construct(
        public readonly EmailAddress $email,
        /**
         * As submitted, untrimmed and unaltered: whitespace can be part of a
         * password, and a password is bytes rather than a name.
         */
        public readonly string $password,
    ) {}

    /**
     * Narrow a decoded request body to credentials, or refuse it.
     *
     * @param  int  $emailLimit  the longest email accepted, in characters
     * @param  int  $passwordLimit  the longest password accepted, in characters
     *
     * @throws InvalidLoginInput
     */
    public static function from(mixed $email, mixed $password, int $emailLimit, int $passwordLimit): self
    {
        if ($email === null) {
            throw InvalidLoginInput::emailMissing();
        }

        if (! is_string($email)) {
            throw InvalidLoginInput::emailNotString();
        }

        if ($password === null) {
            throw InvalidLoginInput::passwordMissing();
        }

        if (! is_string($password)) {
            throw InvalidLoginInput::passwordNotString();
        }

        $address = EmailAddress::fromSubmitted($email);

        // Whitespace only is the same as nothing: the trim is AC-2's and it
        // happens before the emptiness test, not after it.
        if ($address->isEmpty()) {
            throw InvalidLoginInput::emailMissing();
        }

        if ($password === '') {
            throw InvalidLoginInput::passwordMissing();
        }

        if ($address->length() > $emailLimit) {
            throw InvalidLoginInput::emailTooLong();
        }

        if (mb_strlen($password) > $passwordLimit) {
            throw InvalidLoginInput::passwordTooLong();
        }

        return new self($address, $password);
    }
}
