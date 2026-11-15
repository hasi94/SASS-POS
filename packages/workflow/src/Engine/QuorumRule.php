<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Engine;

use Flexipos\Workflow\Definitions\Quorum;

/**
 * A pure function: does a step's vote tally satisfy or reject it (T-020
 * AC-10, Ruling 7)?
 */
final class QuorumRule
{
    public static function state(Quorum $quorum, int $n, int $approved, int $rejected): StepState
    {
        $need = intdiv($n, 2) + 1;

        return match ($quorum) {
            Quorum::Any => match (true) {
                $approved >= 1 => StepState::Satisfied,
                $rejected === $n => StepState::Rejected,
                default => StepState::Open,
            },
            Quorum::All => match (true) {
                $approved === $n => StepState::Satisfied,
                $rejected >= 1 => StepState::Rejected,
                default => StepState::Open,
            },
            Quorum::Majority => match (true) {
                $approved >= $need => StepState::Satisfied,
                ($n - $rejected) < $need => StepState::Rejected,
                default => StepState::Open,
            },
        };
    }
}
