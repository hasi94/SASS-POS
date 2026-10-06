<?php

namespace App\Support\ModuleHost;

use RuntimeException;

/**
 * Thrown while the host boots when MODULES names something that is not a
 * usable module. The offending name is always part of the message so a
 * misconfigured deployment is obvious from the very first log line.
 */
class UnknownModuleException extends RuntimeException
{
    public function __construct(private readonly string $module, string $reason)
    {
        parent::__construct(sprintf('Unknown module "%s" listed in MODULES: %s', $module, $reason));
    }

    public function module(): string
    {
        return $this->module;
    }
}
