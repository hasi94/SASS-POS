<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Plugins;

use RuntimeException;

/**
 * The other way a {@see BeforeHook} may veto: throwing instead of returning
 * a {@see Veto} (T-019 AC-23: "returning/throwing").
 */
class VetoSignal extends RuntimeException
{
    public readonly string $reason;

    public function __construct(string $reason)
    {
        parent::__construct($reason);

        $this->reason = $reason;
    }
}
