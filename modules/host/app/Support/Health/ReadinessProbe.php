<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * Runs every readiness check and reports the fixed-word body (T-021 Ruling
 * 7, AC-42 to AC-46).
 */
final class ReadinessProbe
{
    /**
     * @param  list<ReadinessCheck>  $checks
     */
    public function __construct(private readonly array $checks) {}

    /**
     * @return array{ready: bool, checks: array<string, string>}
     */
    public function run(): array
    {
        $results = [];
        $ready = true;

        foreach ($this->checks as $check) {
            $status = $check->check();
            $results[$check->name()] = $status->value;

            if ($status === CheckStatus::Failed) {
                $ready = false;
            }
        }

        return ['ready' => $ready, 'checks' => $results];
    }
}
