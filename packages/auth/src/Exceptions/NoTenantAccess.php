<?php

declare(strict_types=1);

namespace Flexipos\Auth\Exceptions;

use RuntimeException;

/**
 * AC-40(a)'s one refusal: this person may not open this shop, and the
 * response says nothing about why.
 *
 * **It carries no cause, for the same reason {@see CredentialRefused}
 * carries none.** An unknown code, a tenant the person has no membership in,
 * a membership that is `invited` or `suspended`, a soft-deleted membership
 * and a soft-deleted tenant must all produce *one identical* response -
 * otherwise the refusal is an oracle for which shops exist and who belongs to
 * them, which is a disclosure about other people's businesses. One maker, no
 * arguments, one message: there is nothing here to leak because there is
 * nothing here.
 *
 * **Where the cause survives** is `login_history.failure_reason`, written
 * before this is thrown - and even there it is a single `no_access`, because
 * the five shapes above are deliberately not told apart (see
 * `RecordLoginAttempt::REASON_NO_ACCESS`). What the history row *does*
 * distinguish is which tenant was asked for, when the tenant is known to
 * exist (AC-40).
 *
 * **403, not 401** (and contrast {@see CredentialRefused}'s 401). By this
 * point the caller has proved who they are with a platform token; what they
 * have not got is access. Conflating the two would make a client refresh a
 * perfectly good token in response to a permission answer.
 */
final class NoTenantAccess extends RuntimeException
{
    /**
     * The one status code.
     */
    public const STATUS = 403;

    /**
     * The one error token, which is also the `login_history.failure_reason`
     * value - one vocabulary word for one refusal, in the response and in the
     * record.
     */
    public const ERROR = 'no_access';

    /**
     * The one message.
     */
    public const MESSAGE = 'the tenant cannot be selected';

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

    /**
     * The one body.
     *
     * @return array{error: string}
     */
    public static function body(): array
    {
        return ['error' => self::ERROR];
    }
}
