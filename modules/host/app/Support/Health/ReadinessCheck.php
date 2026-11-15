<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * One dependency readiness asks about (T-021 Ruling 7). Bound by interface
 * so a test can swap in a throwing fake without touching a real connection.
 */
interface ReadinessCheck
{
    /**
     * The name this check's result appears under in the `checks` object.
     */
    public function name(): string;

    public function check(): CheckStatus;
}
