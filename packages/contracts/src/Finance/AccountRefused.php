<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

use RuntimeException;

/**
 * {@see ChartOfAccounts} refused a lookup or an assignment (T-032 Ruling 2).
 *
 * Not constructible without a cause: the constructor is private and every way
 * in is a named constructor carrying one {@see AccountRefusal}. `field` names
 * the request field being validated when there is one (for example
 * `income_account_id`), so a caller can attach the refusal to its input.
 */
final class AccountRefused extends RuntimeException
{
    private function __construct(
        public readonly AccountRefusal $reason,
        public readonly ?string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message, ?string $field = null): self
    {
        return new self(AccountRefusal::AccountNotFound, $field, $message);
    }

    public static function notLive(string $message, ?string $field = null): self
    {
        return new self(AccountRefusal::AccountNotLive, $field, $message);
    }

    public static function inactive(string $message, ?string $field = null): self
    {
        return new self(AccountRefusal::AccountInactive, $field, $message);
    }

    public static function notPostable(string $message, ?string $field = null): self
    {
        return new self(AccountRefusal::AccountNotPostable, $field, $message);
    }

    public static function kindMismatch(string $message, ?string $field = null): self
    {
        return new self(AccountRefusal::AccountKindMismatch, $field, $message);
    }

    public static function invalidCode(string $message, ?string $field = null): self
    {
        return new self(AccountRefusal::InvalidCode, $field, $message);
    }
}
