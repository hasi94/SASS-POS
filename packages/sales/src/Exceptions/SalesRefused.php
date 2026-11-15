<?php

declare(strict_types=1);

namespace Flexipos\Sales\Exceptions;

use BackedEnum;
use RuntimeException;

/**
 * The one exception every sales slice throws when it refuses to write (the
 * `CustomersRefused` shape, plus Ruling 8's amendment: a refusal that came from
 * another package is carried as the {@see self::$cause}).
 *
 * **The cause's enum type is part of the answer.** Several values exist in more
 * than one vocabulary (`unknown_product`, `invalid_amount`, `out_of_range`,
 * `replay_conflict`), so a caller reads `$cause::class` as well as its value.
 *
 * Not constructible without a reason: the constructor is private. The message is
 * operator-facing and may name ids; it never carries a PIN, a hash or a secret.
 */
final class SalesRefused extends RuntimeException
{
    private function __construct(
        public readonly SalesRefusal $reason,
        string $message,
        public readonly ?BackedEnum $cause = null,
    ) {
        parent::__construct($message);
    }

    public static function because(SalesRefusal $reason, string $message, ?BackedEnum $cause = null): self
    {
        return new self($reason, $message, $cause);
    }

    public static function validation(string $message): self
    {
        return new self(SalesRefusal::Validation, $message);
    }

    public static function notFound(string $message): self
    {
        return new self(SalesRefusal::NotFound, $message);
    }

    public static function duplicate(string $message): self
    {
        return new self(SalesRefusal::Duplicate, $message);
    }

    public static function inUse(string $message): self
    {
        return new self(SalesRefusal::InUse, $message);
    }

    public static function outOfRange(string $message): self
    {
        return new self(SalesRefusal::OutOfRange, $message);
    }
}
