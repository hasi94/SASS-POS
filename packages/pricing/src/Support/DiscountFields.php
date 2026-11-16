<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Support;

use Brick\Math\BigDecimal;
use Flexipos\Pricing\Engine\DiscountScope;
use Flexipos\Pricing\Engine\DiscountType;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;

/**
 * The `discounts` columns a create and an update both read (AC-19).
 *
 * `used_count` is maintained by the usage-recording path and is never a field
 * here: {@see self::refuseMaintained()} refuses a command that names it before
 * anything is read or written. `sales_discount_account_id` is not built (F-17),
 * so it is refused as an unknown field like any other.
 */
final class DiscountFields
{
    public const FIELDS = [
        'code', 'name', 'type', 'value', 'scope', 'applies_to', 'min_amount', 'max_discount',
        'requires_approval', 'approval_threshold', 'workflow_definition_id', 'starts_at', 'ends_at',
        'usage_limit', 'is_active',
    ];

    private const APPLIES_TO_KEYS = ['products', 'categories', 'brands'];

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws PricingAdminRefused maintained
     */
    public static function refuseMaintained(array $fields): void
    {
        if (Fields::has($fields, 'used_count')) {
            throw PricingAdminRefused::maintained('The used_count is maintained by the system and may not be supplied.');
        }
    }

    /**
     * Each submitted field, normalised, and no field that was not submitted.
     * `workflow_definition_id` is folded but not yet checked for existence.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     *
     * @throws PricingAdminRefused validation
     */
    public static function read(array $fields): array
    {
        $out = [];

        if (Fields::has($fields, 'code')) {
            $out['code'] = Fields::requiredText($fields, 'code', 50);
        }

        if (Fields::has($fields, 'name')) {
            $out['name'] = Fields::requiredText($fields, 'name', 150);
        }

        if (Fields::has($fields, 'type')) {
            $out['type'] = Fields::enum($fields, 'type', array_column(DiscountType::cases(), 'value'), null);
        }

        if (Fields::has($fields, 'scope')) {
            $out['scope'] = Fields::enum($fields, 'scope', array_column(DiscountScope::cases(), 'value'), null);
        }

        if (Fields::has($fields, 'value')) {
            $out['value'] = Fields::money($fields, 'value');
        }

        foreach (['min_amount', 'max_discount', 'approval_threshold'] as $money) {
            if (Fields::has($fields, $money)) {
                $out[$money] = Fields::money($fields, $money);
            }
        }

        if (Fields::has($fields, 'applies_to')) {
            $out['applies_to'] = self::appliesTo($fields['applies_to']);
        }

        if (Fields::has($fields, 'requires_approval')) {
            $out['requires_approval'] = Fields::flag($fields, 'requires_approval', false);
        }

        if (Fields::has($fields, 'is_active')) {
            $out['is_active'] = Fields::flag($fields, 'is_active', true);
        }

        if (Fields::has($fields, 'workflow_definition_id')) {
            $out['workflow_definition_id'] = Identifier::foldNullable($fields['workflow_definition_id'], 'workflow_definition_id');
        }

        if (Fields::has($fields, 'starts_at')) {
            $out['starts_at'] = Fields::instant($fields, 'starts_at');
        }

        if (Fields::has($fields, 'ends_at')) {
            $out['ends_at'] = Fields::instant($fields, 'ends_at');
        }

        if (Fields::has($fields, 'usage_limit')) {
            $out['usage_limit'] = Fields::optionalInteger($fields, 'usage_limit', 4294967295);
        }

        return $out;
    }

    /**
     * The cross-field rules, checked against the row as it will be after the
     * write (the submitted fields over the stored ones, so an update that moves
     * only one half of a pair cannot slip past a rule about the pair).
     *
     * @param  array<string, mixed>  $effective
     *
     * @throws PricingAdminRefused validation, missingTarget
     */
    public static function assertCoherent(array $effective): void
    {
        $value = $effective['value'] ?? null;

        if (($effective['type'] ?? null) === DiscountType::Percentage->value
            && $value !== null
            && BigDecimal::of((string) $value)->compareTo(100) > 0) {
            throw PricingAdminRefused::validation('A percentage discount may not exceed 100.');
        }

        $startsAt = $effective['starts_at'] ?? null;
        $endsAt = $effective['ends_at'] ?? null;

        if ($startsAt !== null && $endsAt !== null && (string) $endsAt < (string) $startsAt) {
            throw PricingAdminRefused::validation('The ends_at may not be before the starts_at.');
        }

        if (($effective['type'] ?? null) === DiscountType::BuyXGetY->value && self::products($effective['applies_to'] ?? null) === []) {
            throw PricingAdminRefused::missingTarget('A buy_x_get_y discount must name at least one product in applies_to.products.');
        }
    }

    /**
     * Validate the `applies_to` shape (D-32, K-11) and return the JSON to store.
     * Shape only: an object whose only keys are `products`, `categories` and
     * `brands`, each, when present, a list of strings. No id is checked for
     * existence. An array, or a string holding a JSON object, is accepted.
     *
     * @throws PricingAdminRefused validation
     */
    private static function appliesTo(mixed $submitted): ?string
    {
        if ($submitted === null) {
            return null;
        }

        if (is_string($submitted)) {
            $decoded = json_decode($submitted, true);

            if (! is_array($decoded)) {
                throw PricingAdminRefused::validation('The applies_to must be a JSON object.');
            }

            $submitted = $decoded;
        }

        if (! is_array($submitted)) {
            throw PricingAdminRefused::validation('The applies_to must be an object with products, categories and brands.');
        }

        foreach ($submitted as $key => $list) {
            if (! in_array($key, self::APPLIES_TO_KEYS, true)) {
                throw PricingAdminRefused::validation('The applies_to may only hold products, categories and brands; found ['.$key.'].');
            }

            if (! is_array($list) || ! array_is_list($list)) {
                throw PricingAdminRefused::validation("The applies_to.{$key} must be a list of strings.");
            }

            foreach ($list as $entry) {
                if (! is_string($entry)) {
                    throw PricingAdminRefused::validation("The applies_to.{$key} must be a list of strings.");
                }
            }
        }

        return (string) json_encode($submitted === [] ? new \stdClass : $submitted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<string>
     */
    private static function products(mixed $storedJson): array
    {
        if (! is_string($storedJson)) {
            return [];
        }

        $decoded = json_decode($storedJson, true);

        if (! is_array($decoded) || ! isset($decoded['products']) || ! is_array($decoded['products'])) {
            return [];
        }

        return array_values(array_filter($decoded['products'], 'is_string'));
    }
}
