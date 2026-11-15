<?php

declare(strict_types=1);

namespace Flexipos\Auth\Support;

use Flexipos\Auth\Exceptions\CredentialRefused;

/**
 * D-12's single refusal: one status, one body, byte-identical across causes
 * (AC-6).
 *
 * **"Byte-identical" is a property of having one producer, not of five
 * producers agreeing.** An unknown email, a wrong password, a live lock, an
 * account that is not `active` and a soft-deleted account must be
 * indistinguishable from outside. If each refusal built its own body, the
 * criterion would hold until the day somebody added a field to one of them -
 * and the test that compared two of the five would still pass. So the body
 * is a constant function of nothing: there is no argument to this class
 * through which a cause could reach the response, which is the same reason
 * {@see CredentialRefused} carries no cause.
 *
 * **The cause is not lost, it is written down elsewhere.**
 * `login_history.failure_reason` records which mechanism refused, and the
 * NFR says in terms that failure reasons live there and not in responses.
 * That is also what lets this task's refusal tests say *which* mechanism
 * fired, instead of only that something did.
 *
 * **401, not 403.** The caller has not proved who they are; whether they may
 * do something is a different question with a different answer (AC-40's
 * tenant refusals are 403, and they are deliberately a separate shape with a
 * named reason, because by then the caller *is* authenticated).
 *
 * The HTTP layer that renders this arrives in step 12; it renders this and
 * composes nothing of its own.
 */
final class GenericRefusal
{
    /**
     * The one status code.
     */
    public const STATUS = 401;

    /**
     * The one error token. A vocabulary word for the client, not a reason:
     * it says the credentials were not accepted and never which of them, or
     * which of five mechanisms said so.
     */
    public const ERROR = 'invalid_credentials';

    /**
     * The one body.
     *
     * @return array{error: string}
     */
    public static function body(): array
    {
        return ['error' => self::ERROR];
    }

    /**
     * The one body, as the bytes that go on the wire - so "byte-identical"
     * can be asserted as bytes rather than as a structure that happens to
     * encode the same way today.
     */
    public static function json(): string
    {
        return json_encode(self::body(), JSON_THROW_ON_ERROR);
    }
}
