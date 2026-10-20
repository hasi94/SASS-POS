<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Contracts\Access\RefusalReason;
use PHPUnit\Framework\Assert;

/**
 * Run an operation that must be refused, and hand back the refusal.
 *
 * **It fails when nothing is thrown, which is the half a bare `try`/`catch`
 * keeps forgetting.** A catch block alone passes silently when the
 * operation succeeds - the exact outcome a guard test exists to detect -
 * and section E has two dozen refusal cases across six slices, so the
 * forgetting would be a matter of time.
 *
 * **It returns the refusal rather than asserting the reason itself.** The
 * caller asserts the reason *and* the state the refusal left behind, which
 * for every criterion in this task is the other half: `not_attached` with a
 * row written anyway is a refusal that did not refuse. A helper that
 * swallowed the exception would make the second assertion easy to omit.
 */
final class AccessRefusals
{
    /**
     * @param  callable(): mixed  $operation
     */
    public static function capture(callable $operation): AccessRefused
    {
        try {
            $operation();
        } catch (AccessRefused $refused) {
            return $refused;
        }

        Assert::fail('The operation was expected to be refused and succeeded.');
    }

    /**
     * The same, asserting the reason in one line.
     *
     * @param  callable(): mixed  $operation
     */
    public static function expect(RefusalReason $reason, callable $operation): AccessRefused
    {
        $refused = self::capture($operation);

        Assert::assertSame(
            $reason,
            $refused->reason,
            "Expected [{$reason->value}] and got [{$refused->reason->value}]: {$refused->getMessage()}"
        );

        return $refused;
    }
}
