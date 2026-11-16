<?php

declare(strict_types=1);

namespace Flexipos\Finance\Exceptions;

use RuntimeException;

/**
 * The one exception every Finance slice and rule throws when it refuses a
 * request (T-032 plan section 3; the `CustomersRefused` shape).
 *
 * **Not constructible without a cause**: the constructor is private and the
 * only way in is {@see self::because()}, which takes a
 * {@see FinanceRefusal}. `field` names the offending request field when there
 * is one; `context` carries what a caller needs to act on the refusal (the
 * id of the account that holds a code, the stored status after a lost
 * compare-and-set, the offending keys of a body). The message is
 * operator-facing; it never echoes raw input.
 */
final class FinanceRefused extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public readonly FinanceRefusal $reason,
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
        FinanceRefusal $reason,
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
        return new self(FinanceRefusal::Validation, $field, [], $message);
    }
}
