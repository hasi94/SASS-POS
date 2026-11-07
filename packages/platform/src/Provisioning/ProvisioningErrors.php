<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning;

use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;
use Flexipos\Kernel\Tenancy\Secrets\SecretResolver;
use SensitiveParameter;
use Throwable;

/**
 * The text a failed provisioning step is allowed to keep (AC-18), the same
 * two-rule idiom `Flexipos\Kernel\Schema\Features\MigrateTenant\MigrationErrorText`
 * already uses for the migrate credential - restated here, rather than
 * reused across the package boundary, because this run's needles are not one
 * credential but *every* reference this run has journalled so far.
 *
 * 1. Every needle given is replaced.
 * 2. Every remaining reference-shaped token (`scheme://rest`) is replaced
 *    too, whether or not it was named - a cause thrown from deeper than this
 *    run's own steps carries references this method never saw.
 *
 * Truncation happens after redaction, never before.
 */
final class ProvisioningErrors
{
    public const MAX_LENGTH = 2000;

    /**
     * @param  list<string>  $needles  values that must not survive
     */
    public static function sanitise(string $message, #[SensitiveParameter] array $needles = []): string
    {
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }

            $message = str_replace($needle, TenancyException::REDACTED, $message);
        }

        $message = (string) preg_replace(
            '~\b[a-z][a-z0-9+.\-]*://\S+~i',
            TenancyException::REDACTED,
            $message
        );

        if (strlen($message) <= self::MAX_LENGTH) {
            return $message;
        }

        return substr($message, 0, self::MAX_LENGTH - 3).'...';
    }

    /**
     * Every credential reference journalled anywhere in this run so far,
     * plus each one's resolved value (the `needlesFor()` idiom, Ruling 2).
     *
     * A reference is any journalled string value shaped like `scheme://...`
     * - this scans every step's output rather than trusting a fixed set of
     * key names, so a step this package has not even written yet still has
     * its references caught here. Resolution failures are swallowed: this
     * runs on the failure path, and a sanitiser that itself threw would
     * replace the operator's error with its own.
     *
     * @param  array<string, array<string, mixed>>  $outputs  every step's
     *                                                        output, keyed
     *                                                        by step name
     * @return list<string>
     */
    public static function needlesFor(array $outputs, SecretResolver $secrets): array
    {
        $needles = [];

        foreach ($outputs as $output) {
            foreach ($output as $value) {
                if (! is_string($value) || preg_match('~^[a-z][a-z0-9+.\-]*://\S+$~i', $value) !== 1) {
                    continue;
                }

                $needles[] = $value;

                try {
                    $needles[] = $secrets->resolve($value)->reveal();
                } catch (Throwable) {
                    // Nothing to add beyond the reference itself.
                }
            }
        }

        return $needles;
    }
}
