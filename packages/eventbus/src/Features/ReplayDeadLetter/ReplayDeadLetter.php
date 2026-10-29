<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\ReplayDeadLetter;

/**
 * Re-publish a chosen dead-lettered event onto its original stream (T-018
 * Ruling 10, AC-31).
 *
 * The event re-enters the shared stream, so every group sees it again:
 * Redis cannot target a replay at one group. Groups that already handled
 * it no-op, by the consumer's own idempotency (AC-23).
 */
final readonly class ReplayDeadLetter
{
    public function __construct(public string $deadId) {}
}
