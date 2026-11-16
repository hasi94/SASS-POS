<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Organization;

use RuntimeException;

/**
 * `DocumentNumbers` refused to allocate a number (T-030 AC-40, AC-50).
 *
 * **Not constructible without a cause**, the `UnitResolutionRefused` shape: the
 * constructor is private and every way in is a named constructor carrying
 * exactly one {@see DocumentNumbersRefusal}. The message is operator-facing and
 * may name a document type and a length; it never carries a secret, because
 * there is none to carry here. Thrown before anything is written, so a refused
 * allocation leaves `next_number` where it was.
 */
final class DocumentNumbersRefused extends RuntimeException
{
    private function __construct(
        public readonly DocumentNumbersRefusal $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unsupportedResetPeriod(string $message): self
    {
        return new self(DocumentNumbersRefusal::UnsupportedResetPeriod, $message);
    }

    public static function numberTooLong(string $message): self
    {
        return new self(DocumentNumbersRefusal::NumberTooLong, $message);
    }
}
