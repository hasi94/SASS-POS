<?php

declare(strict_types=1);

namespace Flexipos\Finance\Accounts;

use Flexipos\Contracts\Finance\AccountSubtype;
use Flexipos\Contracts\Finance\AccountType;
use Flexipos\Contracts\Finance\ControlType;
use Flexipos\Contracts\Finance\NormalBalance;

/**
 * An account request that has passed every field-level check, with each value
 * in its final form (T-032 plan Ruling 4; built by {@see AccountInput}).
 *
 * A **create** draft has every field resolved: omitted ones carry their
 * default (AC-1) and `has()` is true for all of them. An **update** draft
 * carries only what the body named, because "unsupplied fields are
 * unchanged" (AC-21) and an explicit `null` (a description, a subtype) is not
 * the same as an omission. Ask `has()` before reading a property.
 */
final readonly class AccountDraft
{
    /**
     * @param  list<string>  $supplied  the request names of the fields this draft carries
     */
    public function __construct(
        public array $supplied,
        public ?string $code = null,
        public ?string $name = null,
        public ?string $description = null,
        public ?AccountType $type = null,
        public ?AccountSubtype $subtype = null,
        public ?NormalBalance $normalBalance = null,
        public ?string $parentId = null,
        public ?bool $isPostable = null,
        public ?bool $isActive = null,
        public ?ControlType $controlType = null,
        public ?int $sortOrder = null,
    ) {}

    public function has(string $field): bool
    {
        return in_array($field, $this->supplied, true);
    }
}
