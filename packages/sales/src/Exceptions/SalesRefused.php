<?php

declare(strict_types=1);

namespace Flexipos\Sales\Exceptions;

use BackedEnum;
use Flexipos\Contracts\Catalog\UnitRefusal;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Customers\CreditLedgerRefused;
use Flexipos\Contracts\Pricing\PricingRefusal;
use Flexipos\Contracts\Pricing\PricingRefused;
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

    /**
     * A pricing refusal, carried through (AC-5, Ruling 8 amendment).
     * `no_price_resolved` is `unresolvable_price`; every other reason is
     * `pricing_refused` with the cause attached, and {@see self::code()} reads
     * the cause's own name off it.
     */
    public static function fromPricing(PricingRefused $refused): self
    {
        $reason = $refused->reason === PricingRefusal::NoPriceResolved ? SalesRefusal::UnresolvablePrice : SalesRefusal::PricingRefused;

        return new self($reason, $refused->getMessage(), $refused->reason);
    }

    /**
     * A unit refusal, carried through: `inherited_unit_mismatch` keeps its name,
     * every other one is `unit_unresolvable` (AC-3) with the cause attached.
     */
    public static function fromUnit(UnitResolutionRefused $refused, ?string $message = null): self
    {
        $reason = $refused->reason === UnitRefusal::InheritedUnitMismatch ? SalesRefusal::InheritedUnitMismatch : SalesRefusal::UnitUnresolvable;

        return new self($reason, $message ?? $refused->getMessage(), $refused->reason);
    }

    /**
     * A credit-ledger refusal, carried through as `credit_refused` (AC-14, AC-49).
     */
    public static function fromCredit(CreditLedgerRefused $refused): self
    {
        return new self(SalesRefusal::CreditRefused, $refused->getMessage(), $refused->reason);
    }

    /**
     * The code a caller of Sales sees: a pricing refusal under its own value
     * (`not_applicable`, `usage_limit_reached`), anything else as its Sales reason.
     */
    public function code(): string
    {
        if ($this->reason === SalesRefusal::PricingRefused && $this->cause !== null) {
            return (string) $this->cause->value;
        }

        return $this->reason->value;
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
