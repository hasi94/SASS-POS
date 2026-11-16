<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Support;

use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Pricing\Engine\DiscountRule;
use Flexipos\Pricing\Engine\DiscountScope;
use Flexipos\Pricing\Engine\DiscountType;
use Flexipos\Pricing\Engine\LadderAssignment;
use Flexipos\Pricing\Engine\LadderInput;
use Flexipos\Pricing\Engine\LadderItem;
use Flexipos\Pricing\Engine\LadderList;
use Flexipos\Pricing\Engine\ListType;
use Flexipos\Pricing\Engine\RateRow;
use Flexipos\Pricing\Engine\ScopeType;
use Flexipos\Pricing\Engine\TaxApplies;
use Flexipos\Pricing\Engine\TaxMember;
use Flexipos\Pricing\Engine\TaxType;
use Throwable;

/**
 * Small constructors for the pure engine's inputs, so a unit test names only
 * what it varies. Ids are 26-character upper-case ULID-shaped strings built
 * from a short label, the same shape the fixtures use.
 */
final class Build
{
    public const AT = '2025-06-15T09:30:00.000Z';

    public const DATE = '2025-06-01';

    public static function id(string $label): string
    {
        return substr('01'.strtoupper($label).str_repeat('0', 26), 0, 26);
    }

    /**
     * The refusal reason a callable throws, or null when it throws nothing.
     *
     * @throws Throwable anything that is not a PricingRefused
     */
    public static function reasonOf(callable $work): ?string
    {
        try {
            $work();
        } catch (PricingRefused $refused) {
            return $refused->reason->value;
        }

        return null;
    }

    public static function rate(string $rate, string $from = '2020-01-01', ?string $to = null): RateRow
    {
        return new RateRow($rate, $from, $to);
    }

    /**
     * @param  list<RateRow>|null  $rates
     */
    public static function member(
        string $label,
        string $rate = '15.0000',
        int $sequence = 1,
        bool $compound = false,
        TaxType $type = TaxType::Percentage,
        TaxApplies $appliesTo = TaxApplies::Both,
        bool $active = true,
        bool $deleted = false,
        ?array $rates = null,
    ): TaxMember {
        return new TaxMember(
            self::id($label),
            $label,
            null,
            $type,
            $compound,
            $appliesTo,
            $active,
            $deleted,
            $sequence,
            $rates ?? [self::rate($rate)],
        );
    }

    public static function item(?string $unit, string $minQty, string $price, ?string $discountPercent = null): LadderItem
    {
        return new LadderItem($unit, $minQty, $price, $discountPercent);
    }

    /**
     * @param  list<LadderItem>  $items
     * @param  list<LadderAssignment>  $assignments
     */
    public static function list(
        string $code,
        array $items,
        ListType $type = ListType::Standard,
        string $currency = 'USD',
        int $priority = 0,
        ?string $startsAt = null,
        ?string $endsAt = null,
        bool $active = true,
        bool $deleted = false,
        array $assignments = [],
    ): LadderList {
        return new LadderList(self::id('LIST'.$code), $code, $type, $currency, $priority, $startsAt, $endsAt, $active, $deleted, $assignments, $items);
    }

    public static function assignment(ScopeType $scope, string $scopeId, int $priority = 0): LadderAssignment
    {
        return new LadderAssignment($scope, $scopeId, $priority);
    }

    /**
     * @param  list<LadderList>  $lists
     */
    public static function ladder(
        string $unit,
        string $factor,
        string $base,
        array $lists = [],
        ?string $customer = null,
        ?string $group = null,
        ?string $branch = null,
        ?string $branchPrice = null,
        ?string $price = null,
        string $at = self::AT,
        string $currency = 'USD',
    ): LadderInput {
        return new LadderInput($unit, $factor, $base, $at, $currency, $customer, $group, $branch, $lists, $branchPrice, $price);
    }

    /**
     * @param  list<string>  $products
     * @param  list<string>  $categories
     * @param  list<string>  $brands
     */
    public static function discount(
        string $label = 'DISC1',
        DiscountType $type = DiscountType::Percentage,
        string $value = '10.0000',
        DiscountScope $scope = DiscountScope::Item,
        bool $active = true,
        ?string $startsAt = null,
        ?string $endsAt = null,
        ?int $limit = null,
        int $used = 0,
        ?string $minAmount = null,
        ?string $maxDiscount = null,
        array $products = [],
        array $categories = [],
        array $brands = [],
    ): DiscountRule {
        return new DiscountRule(self::id($label), $type, $value, $scope, $active, $startsAt, $endsAt, $limit, $used, $minAmount, $maxDiscount, $products, $categories, $brands);
    }
}
