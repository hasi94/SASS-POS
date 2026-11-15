<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Definitions;

/**
 * One validated `steps[]` entry (T-020 Ruling 10).
 */
final readonly class ValidatedStep
{
    /**
     * @param  array{roles?: list<string>, employees?: list<string>}  $approverRef
     * @param  list<array{field: string, operator: string, value: mixed}>|null  $canSkipIf
     */
    public function __construct(
        public int $sequence,
        public string $name,
        public ApproverType $approverType,
        public array $approverRef,
        public Quorum $quorum,
        public bool $canEdit = false,
        public ?array $canSkipIf = null,
        public ?int $slaHours = null,
        public mixed $escalateTo = null,
    ) {}
}
