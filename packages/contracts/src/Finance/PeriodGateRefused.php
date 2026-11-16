<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

use RuntimeException;

/**
 * {@see PeriodGate} would not let a posting into a date (T-032 AC-47).
 *
 * Not constructible without a cause: private constructor, one named
 * constructor per {@see PeriodRefusal}.
 */
final class PeriodGateRefused extends RuntimeException
{
    private function __construct(
        public readonly PeriodRefusal $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalidDate(string $message): self
    {
        return new self(PeriodRefusal::InvalidDate, $message);
    }

    public static function noFiscalPeriod(string $message): self
    {
        return new self(PeriodRefusal::NoFiscalPeriod, $message);
    }

    public static function periodClosed(string $message): self
    {
        return new self(PeriodRefusal::PeriodClosed, $message);
    }

    public static function periodSoftClosed(string $message): self
    {
        return new self(PeriodRefusal::PeriodSoftClosed, $message);
    }
}
