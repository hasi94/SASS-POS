<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use Brick\Math\BigDecimal;
use Flexipos\Contracts\Pricing\PriceBasis;
use Flexipos\Contracts\Pricing\PriceSource;
use Flexipos\Contracts\Pricing\PricingRefused;

/**
 * The six-step price ladder, a pure function (AC-15 to AC-17, D-13, D-16,
 * D-17; `docs/database.md` §6.4). Pure: it never reads a table and never
 * resolves a unit factor, it uses the one the caller's `UnitSnapshot` carries
 * (plan Ruling 4).
 *
 * **Steps**, the first that prices the line wins and later steps are never
 * consulted:
 *
 * 1. non-promotion lists assigned to the line's customer;
 * 2. non-promotion lists assigned to its customer group;
 * 3. promotion lists (unassigned ones apply everywhere, assigned ones only
 *    where one assignment matches the customer, group or branch);
 * 4. non-promotion lists assigned to its branch;
 * 5. `product_branches.selling_price`, derived by the unit factor;
 * 6. `products.selling_price`, derived by the unit factor.
 *
 * **Every list** must be active, not deleted, have a window containing the
 * document instant and be in the document's currency (never converted).
 * **Several lists in one step** are tried by assignment priority descending
 * (steps 1, 2 and 4), then list priority descending, then code ascending.
 *
 * **Within a list**, the unit test is outer and the `minQty` test is inner
 * (the owner's ruling, §6.4): items for the line's own unit with `minQty` at or
 * below the **base** quantity, the greatest `minQty`, used **as typed**; only
 * when there are none, items with no unit, the same test, and the price derived
 * as `round(stock price x factor, 4)`. A list that prices nothing is skipped in
 * favour of the next one.
 *
 * **Steps 5 and 6 derive too.** Both selling prices are per **stock** unit, so
 * a case line used as typed would sell a case for the price of a single (D-13).
 *
 * A winning item's `discountPercent` is applied to the resolved price: the
 * outcome keeps the price as `listPrice` and returns `round(listPrice x
 * (100 - percent) / 100, 4)` as `unitPrice` (AC-16a, D-17).
 */
final class PriceLadder
{
    /**
     * @throws PricingRefused
     */
    public static function resolve(LadderInput $input): LadderOutcome
    {
        $lists = array_values(array_filter($input->lists, static fn (LadderList $list): bool => self::qualifies($list, $input)));

        $steps = [
            1 => self::assigned($lists, ScopeType::Customer, $input->customerId),
            2 => self::assigned($lists, ScopeType::CustomerGroup, $input->customerGroupId),
            3 => self::promotions($lists, $input),
            4 => self::assigned($lists, ScopeType::Branch, $input->branchId),
        ];

        foreach ($steps as $step => $ordered) {
            foreach ($ordered as $list) {
                $hit = self::fromList($list, $input);

                if ($hit !== null) {
                    return self::outcome($hit['price'], $hit['discountPercent'], new PriceSource($step, $list->listId, $hit['basis']));
                }
            }
        }

        if ($input->branchSellingPrice !== null) {
            return self::outcome(self::derive($input->branchSellingPrice, $input), null, new PriceSource(5, null, PriceBasis::Product));
        }

        if ($input->sellingPrice !== null) {
            return self::outcome(self::derive($input->sellingPrice, $input), null, new PriceSource(6, null, PriceBasis::Product));
        }

        throw PricingRefused::noPriceResolved('No price list, branch price or product price prices this line.');
    }

    /**
     * The one place a price is multiplied by a unit factor (plan Ruling 4). The
     * factor is always the caller's snapshot, never a column.
     *
     * @throws PricingRefused
     */
    private static function derive(string $stockUnitPrice, LadderInput $input): BigDecimal
    {
        return Decimal::round(Decimal::money($stockUnitPrice, 'price')->multipliedBy(Decimal::factor($input->unitFactor, 'unitFactor')));
    }

    private static function qualifies(LadderList $list, LadderInput $input): bool
    {
        if (! $list->isActive || $list->isDeleted || $list->currency !== $input->currency) {
            return false;
        }

        // Instants are fixed-width UTC strings, so string order is time order.
        if ($list->startsAt !== null && $input->at < $list->startsAt) {
            return false;
        }

        return $list->endsAt === null || $input->at <= $list->endsAt;
    }

    /**
     * The non-promotion lists assigned to one scope, best first (steps 1, 2, 4).
     *
     * @param  list<LadderList>  $lists
     * @return list<LadderList>
     */
    private static function assigned(array $lists, ScopeType $scope, ?string $scopeId): array
    {
        if ($scopeId === null) {
            return [];
        }

        $matches = [];

        foreach ($lists as $list) {
            if ($list->type === ListType::Promotion) {
                continue;
            }

            foreach ($list->assignments as $assignment) {
                if ($assignment->scopeType === $scope && $assignment->scopeId === $scopeId) {
                    $matches[] = ['list' => $list, 'assignmentPriority' => $assignment->priority];

                    break;
                }
            }
        }

        // Assignment priority descending, list priority descending, code
        // ascending, compared bytewise: `<=>` on two numeric-looking codes
        // would compare them as numbers.
        usort($matches, static fn (array $a, array $b): int => ($b['assignmentPriority'] <=> $a['assignmentPriority'])
            ?: ($b['list']->priority <=> $a['list']->priority)
            ?: strcmp($a['list']->code, $b['list']->code));

        return array_map(static fn (array $match): LadderList => $match['list'], $matches);
    }

    /**
     * The promotion lists that apply to this line, best first (step 3).
     *
     * @param  list<LadderList>  $lists
     * @return list<LadderList>
     */
    private static function promotions(array $lists, LadderInput $input): array
    {
        $applicable = array_values(array_filter($lists, static function (LadderList $list) use ($input): bool {
            if ($list->type !== ListType::Promotion) {
                return false;
            }

            if ($list->assignments === []) {
                return true;
            }

            foreach ($list->assignments as $assignment) {
                $line = match ($assignment->scopeType) {
                    ScopeType::Customer => $input->customerId,
                    ScopeType::CustomerGroup => $input->customerGroupId,
                    ScopeType::Branch => $input->branchId,
                };

                if ($line !== null && $line === $assignment->scopeId) {
                    return true;
                }
            }

            return false;
        }));

        usort($applicable, static fn (LadderList $a, LadderList $b): int => ($b->priority <=> $a->priority) ?: strcmp($a->code, $b->code));

        return $applicable;
    }

    /**
     * What one list says about the line, or null when it prices nothing.
     *
     * @return array{price: BigDecimal, discountPercent: ?string, basis: PriceBasis}|null
     *
     * @throws PricingRefused
     */
    private static function fromList(LadderList $list, LadderInput $input): ?array
    {
        $baseQuantity = Decimal::money($input->baseQuantity, 'baseQuantity');

        $unitMatched = self::greatestQualifying($list, $input->unitId, $baseQuantity);

        if ($unitMatched !== null) {
            return [
                'price' => Decimal::money($unitMatched->price, 'price'),
                'discountPercent' => $unitMatched->discountPercent,
                'basis' => PriceBasis::UnitMatched,
            ];
        }

        $stock = self::greatestQualifying($list, null, $baseQuantity);

        if ($stock !== null) {
            return [
                'price' => self::derive($stock->price, $input),
                'discountPercent' => $stock->discountPercent,
                'basis' => PriceBasis::Derived,
            ];
        }

        return null;
    }

    /**
     * Of the items for one unit (null: no unit) whose `minQty` is at or below
     * the base quantity, the one with the greatest `minQty`.
     *
     * Two items at the same `minQty` for one unit cannot exist (it is the unique
     * key), and a duplicate is refused here rather than picked by order.
     *
     * @throws PricingRefused
     */
    private static function greatestQualifying(LadderList $list, ?string $unitId, BigDecimal $baseQuantity): ?LadderItem
    {
        $best = null;
        $bestMin = null;

        foreach ($list->items as $item) {
            if ($item->unitId !== $unitId) {
                continue;
            }

            $min = Decimal::money($item->minQty, 'minQty');

            if ($min->compareTo($baseQuantity) > 0) {
                continue;
            }

            if ($bestMin !== null && $min->compareTo($bestMin) === 0) {
                throw PricingRefused::invalidAmount("Price list [{$list->code}] holds two items at minQty [{$min}] for one unit.");
            }

            if ($bestMin === null || $min->compareTo($bestMin) > 0) {
                $best = $item;
                $bestMin = $min;
            }
        }

        return $best;
    }

    /**
     * @throws PricingRefused
     */
    private static function outcome(BigDecimal $listPrice, ?string $discountPercent, PriceSource $source): LadderOutcome
    {
        $unitPrice = $discountPercent === null
            ? $listPrice
            : Decimal::percentOf($listPrice, BigDecimal::of('100')->minus(Decimal::rate($discountPercent, 'discountPercent')));

        return new LadderOutcome(
            Decimal::text($listPrice),
            $discountPercent,
            Decimal::text($unitPrice),
            $source,
        );
    }
}
