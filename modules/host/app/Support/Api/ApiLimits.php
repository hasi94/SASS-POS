<?php

declare(strict_types=1);

namespace App\Support\Api;

/**
 * The four per-minute rate-limit buckets, validated once at boot (T-021
 * AC-33, D-9).
 *
 * `AppServiceProvider::boot()` builds this as a singleton from
 * `config('api.limits')`, so a bad value fails the whole application at
 * boot rather than disabling throttling the first time a request needs a
 * limit.
 */
final readonly class ApiLimits
{
    public const DEFAULT_TENANT = 1200;

    public const DEFAULT_USER = 120;

    public const DEFAULT_TERMINAL = 300;

    public const DEFAULT_UNAUTHENTICATED = 30;

    public function __construct(
        public int $tenant,
        public int $user,
        public int $terminal,
        public int $unauthenticated,
    ) {}

    /**
     * @param  array<string, mixed>  $limits  `config('api.limits')`
     */
    public static function fromConfig(array $limits): self
    {
        return new self(
            self::validated('tenant', $limits['tenant'] ?? null),
            self::validated('user', $limits['user'] ?? null),
            self::validated('terminal', $limits['terminal'] ?? null),
            self::validated('unauthenticated', $limits['unauthenticated'] ?? null),
        );
    }

    /**
     * An `int` of at least 1, or a digit string with no leading zero.
     * Anything else - 0, a negative number, a float, a non-numeric string,
     * a leading-zero string - is refused, naming the key and the value.
     */
    private static function validated(string $key, mixed $value): int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        $printed = is_scalar($value) ? (string) $value : get_debug_type($value);

        throw new InvalidApiConfiguration(
            "api.limits.{$key} must be a positive integer; [{$printed}] is not a value a typo should produce."
        );
    }
}
