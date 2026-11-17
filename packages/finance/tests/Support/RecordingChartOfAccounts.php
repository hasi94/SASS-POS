<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Support;

use Flexipos\Contracts\Finance\AccountKind;
use Flexipos\Contracts\Finance\AccountSnapshot;
use Flexipos\Contracts\Finance\ChartOfAccounts;
use Flexipos\Contracts\Finance\ControlType;

/**
 * A spy over the real chart (T-033 chunk C): it records the ids `postable` is
 * asked about, in call order, then answers like the chart it wraps. Proves the
 * ascending lock order and one call per distinct account (AC-25) and that a
 * replay never reaches the chart (AC-34).
 */
final class RecordingChartOfAccounts implements ChartOfAccounts
{
    /**
     * @var list<string>
     */
    public array $postable = [];

    public function __construct(private readonly ChartOfAccounts $inner) {}

    public function byId(string $id): AccountSnapshot
    {
        return $this->inner->byId($id);
    }

    public function byCode(string $code): AccountSnapshot
    {
        return $this->inner->byCode($code);
    }

    public function byControlType(ControlType $type): array
    {
        return $this->inner->byControlType($type);
    }

    public function postable(string $id): AccountSnapshot
    {
        $this->postable[] = $id;

        return $this->inner->postable($id);
    }

    public function assignable(string $field, string $id, AccountKind $kind): AccountSnapshot
    {
        return $this->inner->assignable($field, $id, $kind);
    }
}
