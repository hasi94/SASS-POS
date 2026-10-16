<?php

declare(strict_types=1);

namespace Flexipos\Auth\Exceptions;

use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttemptHandler;
use RuntimeException;

/**
 * The one refusal every credential failure throws (D-12, AC-6).
 *
 * **It carries no cause, and that is the whole design.** An unknown email, a
 * wrong password, a locked account, an account that is not `active` and a
 * soft-deleted account must be indistinguishable to the caller. The cheapest
 * way for that to go wrong is an exception that knows why it was thrown and a
 * renderer that helpfully includes it, so this class has one maker, no
 * arguments, and one message. There is nothing to leak because there is
 * nothing here.
 *
 * **Where the cause does survive** is `login_history.failure_reason`, written
 * by {@see RecordLoginAttemptHandler}
 * **before** this is thrown. That ordering is AC-24 and it is also what makes
 * every refusal test in this task able to say *which* mechanism refused -
 * asserting the 401 alone would prove only that something did.
 *
 * Contrast the two refusals that are deliberately **not** this one:
 * {@see InvalidLoginInput} (422, nothing was looked up) and
 * {@see NoActiveSigningKey} (5xx, an operational failure that is not a
 * credential failure and is not counted toward lockout, AC-31).
 */
final class CredentialRefused extends RuntimeException
{
    /**
     * The one message, for the one refusal.
     */
    public const MESSAGE = 'the credentials were refused';

    private function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    /**
     * The only way to construct one, so every cause produces the same object.
     */
    public static function refused(): self
    {
        return new self;
    }
}
