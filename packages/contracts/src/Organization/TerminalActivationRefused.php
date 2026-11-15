<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Organization;

use InvalidArgumentException;
use RuntimeException;

/**
 * An activation the tenant database refused (T-014 AC-30).
 *
 * **The reason is for the application log and for nothing else.** The caller
 * sees one byte-identical refusal whatever happened, so a closed reason class
 * is carried here only so an operator can tell an expired code from a
 * fingerprint in use. It is thrown before any write, and it never carries the
 * code, the secret or the fingerprint.
 */
final class TerminalActivationRefused extends RuntimeException
{
    public const UNKNOWN_CODE = 'unknown_code';

    public const EXPIRED = 'expired';

    public const BRANCH_INACTIVE = 'branch_inactive';

    public const DEVICE_IN_USE = 'device_in_use';

    /** @var list<string> */
    public const REASONS = [
        self::UNKNOWN_CODE,
        self::EXPIRED,
        self::BRANCH_INACTIVE,
        self::DEVICE_IN_USE,
    ];

    private function __construct(public readonly string $reasonClass)
    {
        parent::__construct('Terminal activation refused: '.$reasonClass.'.');
    }

    public static function because(string $reasonClass): self
    {
        if (! in_array($reasonClass, self::REASONS, true)) {
            throw new InvalidArgumentException(sprintf('unknown activation refusal reason [%s]', $reasonClass));
        }

        return new self($reasonClass);
    }
}
