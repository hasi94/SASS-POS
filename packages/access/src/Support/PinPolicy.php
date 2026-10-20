<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Flexipos\Access\Exceptions\AccessRefused;

/**
 * What a cashier PIN may be (AC-47, D-16).
 *
 * **One list, in one place, and the list is derived rather than typed.** The
 * criterion names four weak PINs - `0000`, `1111`, `1234`, `4321` - and a
 * rule behind each: all one repeated digit, strictly ascending, strictly
 * descending. Writing the four as literals would be a list that is correct
 * for four digits and silently empty for eight, so {@see self::weakPins()}
 * *generates* the set for every accepted length. The four the criterion
 * names are members of what it generates, which is the point: the criterion
 * tests the list and the list covers more than the criterion could enumerate.
 *
 * **The list is an instance value, and that is what makes "one place"
 * checkable.** A rule spread over three `if` statements inside the handler
 * would pass every green case and could not be moved; here the set is handed
 * in, so the proof is the moved value - take one PIN out of the list and
 * exactly that PIN becomes acceptable, with every other refusal unchanged. A
 * handler holding its own copy cannot follow a moved value.
 *
 * **The numbers come from configuration, the vocabulary does not.** The
 * minimum and maximum digit counts are operational figures
 * (`modules/host/config/access.php`), the same way `page_size` is. They are
 * not an access decision: a shop choosing six-digit PINs widens nothing that
 * `EmployeeStatus::ACTING` protects, and D-16 says in terms that the bounds
 * are configuration.
 *
 * **It refuses, it does not hash.** Hashing is the handler's, through the
 * framework hasher T-009 already configured as Argon2id; a policy that both
 * judged and hashed would be the one place a refused PIN could still reach a
 * digest.
 */
final class PinPolicy
{
    /**
     * The shipped bounds, as the values `config/access.php` defaults to.
     *
     * Here so that a test, a seeder or a console caller that wants the
     * shipped policy without an application container has one source for
     * them rather than two literals.
     */
    public const MIN_DIGITS = 4;

    public const MAX_DIGITS = 8;

    /**
     * @param  list<string>  $weakPins
     */
    private function __construct(
        private readonly int $minDigits,
        private readonly int $maxDigits,
        private readonly array $weakPins,
    ) {}

    /**
     * The shipped policy: the configured bounds and the weak set they imply.
     *
     * What the container binds, and what every production path receives.
     */
    public static function shipped(): self
    {
        $min = (int) config('access.pin.min_digits', self::MIN_DIGITS);
        $max = (int) config('access.pin.max_digits', self::MAX_DIGITS);

        return new self($min, $max, self::weakPins($min, $max));
    }

    /**
     * The same policy over a different weak set.
     *
     * The seam the moved-value proof uses: remove one PIN from the shipped
     * set, hand the result here, and exactly that PIN must become
     * acceptable. There is no other way to vary the set, and a production
     * call to this method would be visible in a diff as what it is.
     *
     * @internal Tests and fixtures only.
     *
     * @param  list<string>  $weakPins
     */
    public static function withWeakPins(array $weakPins): self
    {
        $shipped = self::shipped();

        return new self($shipped->minDigits, $shipped->maxDigits, $weakPins);
    }

    /**
     * Every PIN of an accepted length that a rule in AC-47 forbids.
     *
     * Three generators, one per rule, over every length the policy accepts:
     * the ten repeated digits, the ascending runs (`0123`, `1234`, ... ) and
     * their reverses. Deduplicated and sorted so the set has one printable
     * form - a sweep that prints its domain prints this.
     *
     * @return list<string>
     */
    public static function weakPins(int $minDigits, int $maxDigits): array
    {
        $weak = [];

        for ($length = $minDigits; $length <= $maxDigits; $length++) {
            for ($digit = 0; $digit <= 9; $digit++) {
                $weak[] = str_repeat((string) $digit, $length);
            }

            for ($start = 0; $start + $length - 1 <= 9; $start++) {
                $ascending = '';

                for ($offset = 0; $offset < $length; $offset++) {
                    $ascending .= (string) ($start + $offset);
                }

                $weak[] = $ascending;
                $weak[] = strrev($ascending);
            }
        }

        $weak = array_values(array_unique($weak));

        sort($weak);

        return $weak;
    }

    /**
     * The weak set this policy holds, so a report can print it.
     *
     * @return list<string>
     */
    public function weak(): array
    {
        return $this->weakPins;
    }

    public function minDigits(): int
    {
        return $this->minDigits;
    }

    public function maxDigits(): int
    {
        return $this->maxDigits;
    }

    /**
     * The submitted value, as an acceptable PIN.
     *
     * **It takes `mixed` deliberately.** AC-47 lists "a non-string" and "an
     * empty body" among the refusals, so the type check is one of the rules
     * and cannot be delegated to a parameter type: a `string` parameter
     * would turn a submitted integer into a `TypeError` - a 500, not a
     * `validation` refusal - and a submitted `null` into the same.
     *
     * **No refusal message ever contains the value.** A PIN is a credential
     * and an exception message is a sink (AC-48): the messages here name the
     * rule, the bounds and, for a length failure, the length - never the
     * digits. The length is operational detail about a submission the caller
     * made themselves.
     *
     * @throws AccessRefused when the value is not an acceptable PIN
     */
    public function accept(mixed $pin): string
    {
        if (! is_string($pin)) {
            throw AccessRefused::validation('A PIN must be submitted as a string of digits.');
        }

        if ($pin === '' || preg_match('/\A[0-9]+\z/', $pin) !== 1) {
            throw AccessRefused::validation('A PIN must be digits only.');
        }

        $length = strlen($pin);

        if ($length < $this->minDigits || $length > $this->maxDigits) {
            throw AccessRefused::validation(
                "A PIN must be between {$this->minDigits} and {$this->maxDigits} digits; this one is {$length}."
            );
        }

        if (in_array($pin, $this->weakPins, true)) {
            throw AccessRefused::validation('That PIN is too easy to guess.');
        }

        return $pin;
    }
}
