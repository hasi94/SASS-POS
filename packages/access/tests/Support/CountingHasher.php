<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Features\VerifyPin\VerifyPinHandler;
use Illuminate\Contracts\Hashing\Hasher;
use SensitiveParameter;

/**
 * A hasher that delegates everything and remembers every digest it was asked
 * to verify against (AC-50).
 *
 * AC-50 is a statement about **how many times** the hasher runs and
 * **against what**: exactly once in every case, including the unknown code
 * and the employee with no PIN, against a fixed dummy digest. Neither half
 * is observable outside the handler and the NFR forbids asserting wall-clock
 * timing, so the count is taken here.
 *
 * It delegates rather than answering: a double that returned a fixed verdict
 * would be deciding the test's outcome as well as observing it.
 *
 * **`$blindTo` is AC-50's named negative control, and it is a property of
 * the double rather than a mutation of the handler.** The criterion requires
 * that "a double that counts zero for the unknown case" turn it red. Handed
 * {@see VerifyPinHandler::DUMMY_PIN_HASH},
 * this one records every check except that one, which is exactly what a
 * handler skipping the dummy verification would produce - so the control
 * runs against the shipped handler with nothing in `src/` altered.
 *
 * **Why this is not `AuthFixtureCountingHasher`**, which is the same object
 * one package away: `Package_Access` may depend on the kernel and the
 * published contracts, and on `packages/auth` it may not - in `src` or in
 * `tests`, both of which deptrac analyses. Reusing it would trade a
 * forty-line double for the boundary AC-11 exists to protect.
 */
final class CountingHasher implements Hasher
{
    /**
     * Every digest `check()` was called against, in order.
     *
     * @var list<string>
     */
    public array $checked = [];

    public function __construct(
        private readonly Hasher $inner,
        private readonly ?string $blindTo = null,
    ) {}

    /**
     * Install one of these over the bound hasher and hand it back.
     *
     * The container alias matters: `Hasher::class` is an alias of
     * `hash.driver`, the default driver, while the `Hash` facade resolves
     * `hash`, the manager. Binding the instance under `Hasher::class`
     * replaces exactly what a handler receives by injection and leaves a
     * fixture seeding a PIN with the real Argon2id driver - which this
     * criterion needs, since the digests it counts checks against have to be
     * real ones.
     */
    public static function install(?string $blindTo = null): self
    {
        /** @var Hasher $inner */
        $inner = app(Hasher::class);

        $counting = new self($inner, $blindTo);

        app()->instance(Hasher::class, $counting);

        return $counting;
    }

    public function count(): int
    {
        return count($this->checked);
    }

    /**
     * @param  string  $hashedValue
     * @return array<string, mixed>
     */
    public function info($hashedValue): array
    {
        return $this->inner->info($hashedValue);
    }

    /**
     * @param  string  $value
     * @param  array<string, mixed>  $options
     */
    public function make(#[SensitiveParameter] $value, array $options = []): string
    {
        return $this->inner->make($value, $options);
    }

    /**
     * @param  string  $value
     * @param  string|null  $hashedValue
     * @param  array<string, mixed>  $options
     */
    public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        if ($this->blindTo === null || (string) $hashedValue !== $this->blindTo) {
            $this->checked[] = (string) $hashedValue;
        }

        return $this->inner->check($value, $hashedValue, $options);
    }

    /**
     * @param  string  $hashedValue
     * @param  array<string, mixed>  $options
     */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        return $this->inner->needsRehash($hashedValue, $options);
    }
}
