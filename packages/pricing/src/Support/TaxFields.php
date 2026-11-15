<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Support;

use Flexipos\Pricing\Engine\TaxApplies;
use Flexipos\Pricing\Engine\TaxType;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;

/**
 * The `taxes` columns a create and an update both read (AC-5, F-9). The enum
 * fields are validated with the pure engine's own enums, so the strings stored
 * and the cases the engine reads cannot drift (K-4).
 */
final class TaxFields
{
    public const UPDATE_FIELDS = ['code', 'name', 'tax_code', 'type', 'is_compound', 'applies_to', 'is_recoverable', 'is_withholding', 'is_active'];

    public const CREATE_FIELDS = ['authority_id', ...self::UPDATE_FIELDS];

    /**
     * Each submitted field, normalised, and no field that was not submitted.
     * `authority_id` is returned folded but is never an update field.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, string|bool|null>
     *
     * @throws PricingAdminRefused validation
     */
    public static function read(array $fields): array
    {
        $out = [];

        if (Fields::has($fields, 'authority_id')) {
            $out['authority_id'] = Identifier::fold($fields['authority_id'], 'authority_id');
        }

        if (Fields::has($fields, 'code')) {
            $out['code'] = Fields::requiredText($fields, 'code', 50);
        }

        if (Fields::has($fields, 'name')) {
            $out['name'] = Fields::requiredText($fields, 'name', 150);
        }

        if (Fields::has($fields, 'tax_code')) {
            $out['tax_code'] = Fields::optionalText($fields, 'tax_code', 20);
        }

        if (Fields::has($fields, 'type')) {
            $out['type'] = Fields::enum($fields, 'type', array_column(TaxType::cases(), 'value'), null);
        }

        if (Fields::has($fields, 'applies_to')) {
            $out['applies_to'] = Fields::enum($fields, 'applies_to', array_column(TaxApplies::cases(), 'value'), null);
        }

        foreach (['is_compound', 'is_recoverable', 'is_withholding', 'is_active'] as $flag) {
            if (Fields::has($fields, $flag)) {
                $out[$flag] = Fields::flag($fields, $flag, false);
            }
        }

        return $out;
    }

    /**
     * A fixed tax cannot compound (AC-5): compounding a flat amount onto a
     * percentage base has no defined meaning in this schema.
     *
     * @throws PricingAdminRefused fixedCannotCompound
     */
    public static function assertCoherent(string $type, bool $isCompound): void
    {
        if ($type === TaxType::Fixed->value && $isCompound) {
            throw PricingAdminRefused::fixedCannotCompound('A fixed tax cannot be compound.');
        }
    }
}
