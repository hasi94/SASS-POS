<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Support\Fields;

/**
 * The `reason` of a reopen, a cancel or a close (T-034 AC-62, AC-64, AC-65): 1 to
 * 500 characters after trimming. A reason that is present is always checked, even
 * where it is optional, so a bad one is never silently dropped.
 */
final class ReasonInput
{
    public const MAX = 500;

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws PurchasingRefused validation
     */
    public static function required(array $fields): string
    {
        return Fields::requiredText($fields, 'reason', self::MAX);
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws PurchasingRefused validation
     */
    public static function optional(array $fields): ?string
    {
        return Fields::optionalText($fields, 'reason', self::MAX);
    }
}
