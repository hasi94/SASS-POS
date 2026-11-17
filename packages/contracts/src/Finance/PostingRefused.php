<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

use RuntimeException;
use Throwable;

/**
 * {@see PostingService} refused a request (T-033 Ruling 2).
 *
 * Not constructible without a cause: the constructor is private and every way
 * in is a named constructor. A refusal is whole-request: nothing was written.
 *
 * - `reason` is a {@see PostingRefusal}, or the gate's {@see PeriodRefusal} or
 *   the chart's {@see AccountRefusal} passed through unchanged (AC-23, AC-28);
 *   for those two `getPrevious()` is the original exception.
 * - `field` is the offending field path (`entry_date`, `lines.2.account_id`).
 * - `fields` lists every offending key of an `unknown_field` refusal.
 * - `context` carries the machine-readable detail: both totals for
 *   `entry_unbalanced`, the stored id for `idempotency_conflict` and
 *   `already_reversed`, the path and line index for `unknown_reference`.
 */
final class PostingRefused extends RuntimeException
{
    /**
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public readonly PostingRefusal|PeriodRefusal|AccountRefusal $reason,
        public readonly ?string $field,
        public readonly array $fields,
        public readonly array $context,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function because(
        PostingRefusal $reason,
        string $message,
        ?string $field = null,
        array $context = [],
    ): self {
        return new self($reason, $field, [], $context, $message);
    }

    /**
     * Every key the contract does not define, in one refusal (AC-16).
     *
     * @param  list<string>  $fields
     */
    public static function unknownFields(array $fields, string $message): self
    {
        return new self(PostingRefusal::UnknownField, null, $fields, [], $message);
    }

    /**
     * The period gate refused the entry date; its code passes through (AC-28).
     */
    public static function fromPeriodGate(PeriodGateRefused $refused, string $field): self
    {
        return new self($refused->reason, $field, [], [], $refused->getMessage(), $refused);
    }

    /**
     * The chart refused an account; its code passes through (AC-23).
     *
     * @param  array<string, mixed>  $context
     */
    public static function fromAccount(AccountRefused $refused, string $field, array $context = []): self
    {
        return new self($refused->reason, $field, [], $context, $refused->getMessage(), $refused);
    }
}
