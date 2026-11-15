<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Support;

use Flexipos\Pricing\Exceptions\PricingAdminRefused;

/**
 * The tax authority columns a create and an update both read (AC-4), so the two
 * slices cannot drift on what a country or a filing frequency is.
 *
 * `payable_account_id` and `receivable_account_id` are not here, and not in any
 * allow-list: the columns are not built (F-17), so `Fields::only` refuses a
 * command that names them as unknown.
 */
final class TaxAuthorityFields
{
    public const FIELDS = ['code', 'name', 'country', 'registration_no', 'filing_frequency'];

    public const FILING_FREQUENCIES = ['monthly', 'quarterly', 'yearly'];

    /**
     * Each submitted field, normalised, and no field that was not submitted.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, string|null>
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

        if (Fields::has($fields, 'country')) {
            $country = Fields::optionalText($fields, 'country', 2);

            if ($country !== null && preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
                throw PricingAdminRefused::validation('The country must be two upper-case letters.');
            }

            $out['country'] = $country;
        }

        if (Fields::has($fields, 'registration_no')) {
            $out['registration_no'] = Fields::optionalText($fields, 'registration_no', 50);
        }

        if (Fields::has($fields, 'filing_frequency')) {
            $out['filing_frequency'] = $fields['filing_frequency'] === null
                ? null
                : Fields::enum($fields, 'filing_frequency', self::FILING_FREQUENCIES, null);
        }

        return $out;
    }
}
