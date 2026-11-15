<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that keeps what it was given, so a test can read the level,
 * the message and the context of every line (T-014 Ruling 7, AC-27).
 *
 * Bound over `Psr\Log\LoggerInterface` for one test. **The context is kept
 * as passed**, which is what lets a test assert that a secret is in none of
 * it, and that a liveness control - a line that does carry a planted value -
 * is found by the same scan.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $lines = [];

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * Every line at one level.
     *
     * @return list<array{level: string, message: string, context: array<array-key, mixed>}>
     */
    public function at(string $level): array
    {
        return array_values(array_filter($this->lines, static fn (array $line): bool => $line['level'] === $level));
    }

    /**
     * All the lines as one string, for a substring scan.
     */
    public function text(): string
    {
        return (string) json_encode($this->lines);
    }
}
