<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use InvalidArgumentException;

/**
 * One tax in a tax group as the pure engine sees it: the `tax_group_taxes` row
 * joined to its `taxes` row and that tax's `tax_rates` rows (AC-11).
 *
 * `isActive` and `isDeleted` are separate because the engine is told to skip
 * both (AC-11.1) and a fixture must be able to prove each. A fixed tax cannot
 * compound (refused at write, `fixed_cannot_compound`), so a member claiming
 * both is a caller defect and is refused here.
 */
final readonly class TaxMember
{
    /**
     * @param  list<RateRow>  $rates
     */
    public function __construct(
        public string $taxId,
        public string $code,
        public ?string $taxCode,
        public TaxType $type,
        public bool $isCompound,
        public TaxApplies $appliesTo,
        public bool $isActive,
        public bool $isDeleted,
        public int $sequence,
        public array $rates,
    ) {
        if ($taxId === '' || $code === '') {
            throw new InvalidArgumentException('[taxId] and [code] must not be blank.');
        }

        if ($type === TaxType::Fixed && $isCompound) {
            throw new InvalidArgumentException('[isCompound] a fixed tax cannot compound.');
        }

        if ($sequence < 0 || $sequence > 255) {
            throw new InvalidArgumentException('[sequence] must be 0 to 255.');
        }
    }
}
