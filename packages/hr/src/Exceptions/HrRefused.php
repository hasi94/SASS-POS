<?php

declare(strict_types=1);

namespace Flexipos\Hr\Exceptions;

use RuntimeException;

/**
 * The one exception every HR slice and rule throws when it refuses a request
 * (T-038 plan Ruling 9; the `FinanceRefused` shape).
 *
 * **Not constructible without a cause**: the constructor is private and the
 * only way in is {@see self::because()}. `field` names the offending request
 * field when there is one; `context` carries what a caller needs to act on
 * the refusal. **The message names fields and never a submitted value**
 * (plan Ruling 8): no message, whatever the field, echoes what was sent.
 */
final class HrRefused extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public readonly HrRefusal $reason,
        public readonly ?string $field,
        public readonly array $context,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function because(
        HrRefusal $reason,
        string $message,
        ?string $field = null,
        array $context = [],
    ): self {
        return new self($reason, $field, $context, $message);
    }

    /**
     * A malformed or missing field (the common case, `validation`).
     */
    public static function validation(string $field, string $message): self
    {
        return new self(HrRefusal::Validation, $field, [], $message);
    }
}
