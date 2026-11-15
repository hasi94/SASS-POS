<?php

declare(strict_types=1);

namespace Flexipos\Auth\Exceptions;

use Flexipos\Auth\Features\SelectTenant\TenantCodeInput;
use RuntimeException;

/**
 * The submitted selection body carries no code a tenant could be looked up by
 * (AC-41).
 *
 * **It is the selection slice's {@see InvalidLoginInput}, and it is a
 * different refusal from every other one here for the same reason.** 422
 * means nothing was looked up: no tenant was read, no membership was judged,
 * no token was issued and - the part AC-41 states - **no `login_history` row
 * is written**. A selection refusal (403 `no_access` / `tenant_not_servable`)
 * records an attempt because a membership was judged; an unreadable request
 * judged nothing, and recording it would put an unbounded number of
 * unparseable bodies into the table that exists to show who tried to reach
 * whose shop.
 *
 * **It is thrown where the criterion is read** - in
 * {@see TenantCodeInput::from()}, the first statement of the slice, before
 * the signing key and before any read - so the absences above are properties
 * of position rather than of anything the later code avoids doing.
 *
 * **The submitted value is not echoed in the message.** A code is not a
 * secret, but a refusal message is written to a log and the rule this task
 * holds to is that a refusal message is a reason and nothing else.
 */
final class InvalidTenantCode extends RuntimeException
{
    public const REASON_CODE_MISSING = 'code_missing';

    public const REASON_CODE_NOT_STRING = 'code_not_string';

    /**
     * Every reason this refusal can carry, derived from the constants above
     * so a third is added in one place.
     *
     * @var list<string>
     */
    public const REASONS = [
        self::REASON_CODE_MISSING,
        self::REASON_CODE_NOT_STRING,
    ];

    private function __construct(private readonly string $reason)
    {
        parent::__construct(sprintf('the tenant selection request cannot be read (%s)', $reason));
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

    /**
     * Absent, empty, or whitespace only: AC-41 makes the three one case,
     * because a code of spaces is a code of nothing.
     */
    public static function codeMissing(): self
    {
        return new self(self::REASON_CODE_MISSING);
    }

    public static function codeNotString(): self
    {
        return new self(self::REASON_CODE_NOT_STRING);
    }
}
