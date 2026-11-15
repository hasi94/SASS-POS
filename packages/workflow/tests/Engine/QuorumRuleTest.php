<?php

use Flexipos\Workflow\Definitions\Quorum;
use Flexipos\Workflow\Engine\QuorumRule;
use Flexipos\Workflow\Engine\StepState;
use Tests\TestCase;

uses(TestCase::class);

it('decides every worked case of Ruling 7s table (AC-10)', function (Quorum $quorum, int $n, int $approved, int $rejected, StepState $expected) {
    expect(QuorumRule::state($quorum, $n, $approved, $rejected))->toBe($expected);
})->with([
    'n=2 any, 1 reject -> open' => [Quorum::Any, 2, 0, 1, StepState::Open],
    'n=2 any, 2 rejects -> rejected' => [Quorum::Any, 2, 0, 2, StepState::Rejected],
    'n=2 all, 1 approve -> open' => [Quorum::All, 2, 1, 0, StepState::Open],
    'n=2 all, 1 reject -> rejected' => [Quorum::All, 2, 0, 1, StepState::Rejected],
    'n=3 majority, 2 approve -> satisfied' => [Quorum::Majority, 3, 2, 0, StepState::Satisfied],
    'n=3 majority, 2 reject -> rejected' => [Quorum::Majority, 3, 0, 2, StepState::Rejected],
    'n=3 majority, 1 approve 1 reject -> open' => [Quorum::Majority, 3, 1, 1, StepState::Open],
    'n=4 majority, 2 approve -> open (half is not enough)' => [Quorum::Majority, 4, 2, 0, StepState::Open],
    'n=4 majority, 2 reject -> rejected' => [Quorum::Majority, 4, 0, 2, StepState::Rejected],
]);

it('handles n=1 for each quorum', function (Quorum $quorum, int $approved, int $rejected, StepState $expected) {
    expect(QuorumRule::state($quorum, 1, $approved, $rejected))->toBe($expected);
})->with([
    'any, 1 approve' => [Quorum::Any, 1, 0, StepState::Satisfied],
    'any, 1 reject' => [Quorum::Any, 0, 1, StepState::Rejected],
    'all, 1 approve' => [Quorum::All, 1, 0, StepState::Satisfied],
    'all, 1 reject' => [Quorum::All, 0, 1, StepState::Rejected],
    'majority, 1 approve' => [Quorum::Majority, 1, 0, StepState::Satisfied],
    'majority, 1 reject' => [Quorum::Majority, 0, 1, StepState::Rejected],
]);

it('stays open before any vote', function (Quorum $quorum) {
    expect(QuorumRule::state($quorum, 2, 0, 0))->toBe(StepState::Open);
})->with([[Quorum::Any], [Quorum::All], [Quorum::Majority]]);
