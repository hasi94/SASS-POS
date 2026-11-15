<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning;

use Flexipos\Contracts\Organization\ActivationCodeFormat;
use Flexipos\Platform\Exceptions\PlatformRefused;

/**
 * `tenants.code`'s own normalisation rule (T-026 D-1, AC-11), the same
 * trim-then-uppercase shape `Flexipos\Organization\Support\OrgCode::normalise()`
 * already uses for branches/locations/terminals.
 *
 * The check itself is not a second rule: it runs against
 * {@see ActivationCodeFormat::TENANT_PART_PATTERN} directly, because that is
 * the exact pattern `ActivationIssuer` will check again the first time this
 * tenant issues an activation code (AC-11's control, AC-38). A tenant code
 * this normaliser accepts and `ActivationIssuer` later refuses would be two
 * rules pretending to be one.
 */
final class TenantCode
{
    /**
     * @throws PlatformRefused validation
     */
    public static function normalise(mixed $submitted): string
    {
        if (! is_string($submitted) || trim($submitted) === '') {
            throw PlatformRefused::validation('The --code option is required and must not be blank.');
        }

        $code = strtoupper(trim($submitted));

        if (preg_match(ActivationCodeFormat::TENANT_PART_PATTERN, $code) !== 1) {
            throw PlatformRefused::validation(
                'The --code option must be 1 to 30 characters of A-Z, 0-9 and hyphen, never starting or '
                .'ending with a hyphen.'
            );
        }

        return $code;
    }
}
