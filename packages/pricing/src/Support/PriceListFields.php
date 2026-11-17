<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Support;

use Flexipos\Pricing\Engine\ListType;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;

/**
 * The `price_lists` columns a create and an update both read (AC-12).
 *
 * `currency` is required and never defaulted (F-7: no `company_profile` table
 * exists to default from). It is upper-case as typed, because the engine
 * compares a list's currency with the document's by strict equality.
 */
final class PriceListFields
{
    public const FIELDS = ['code', 'name', 'type', 'currency', 'priority', 'starts_at', 'ends_at', 'is_active'];

    /**
     * Each submitted field, normalised, and no field that was not submitted.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, string|int|bool|null>
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
            $out['type'] = Fields::enum($fields, 'type', array_column(ListType::cases(), 'value'), null);
        }

        if (Fields::has($fields, 'currency')) {
            $currency = $fields['currency'];

            if (! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw PricingAdminRefused::validation('The currency must be three upper-case letters.');
            }

            $out['currency'] = $currency;
        }

        if (Fields::has($fields, 'priority')) {
            $out['priority'] = Fields::integer($fields, 'priority', 0, -32768, 32767);
        }

        if (Fields::has($fields, 'starts_at')) {
            $out['starts_at'] = Fields::instant($fields, 'starts_at');
        }

        if (Fields::has($fields, 'ends_at')) {
            $out['ends_at'] = Fields::instant($fields, 'ends_at');
        }

        if (Fields::has($fields, 'is_active')) {
            $out['is_active'] = Fields::flag($fields, 'is_active', true);
        }

        return $out;
    }

    /**
     * `ends_at` may not be before `starts_at`; either may be open (null).
     * Both are the stored `Y-m-d H:i:s.v` strings, which order as time.
     *
     * @throws PricingAdminRefused validation
     */
    public static function assertWindow(?string $startsAt, ?string $endsAt): void
    {
        if ($startsAt !== null && $endsAt !== null && $endsAt < $startsAt) {
            throw PricingAdminRefused::validation('The ends_at may not be before the starts_at.');
        }
    }
}
