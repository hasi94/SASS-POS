<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Plugins;

/**
 * What `HookGateway::dispatch()` returns (T-019 plan Ruling 4).
 */
final readonly class HookOutcome
{
    /**
     * @param  array<string, mixed>  $payload  the payload after filtering
     * @param  list<HookContribution>  $contributions
     * @param  list<string>  $skipped  plugins the breaker refused
     * @param  list<string>  $failed  plugins whose handler failed during this call
     */
    public function __construct(
        public string $point,
        public ?HookVeto $veto,
        public array $payload,
        public mixed $result,
        public ?string $overriddenBy,
        public array $contributions,
        public array $skipped,
        public array $failed,
    ) {}

    public function vetoed(): bool
    {
        return $this->veto !== null;
    }
}
