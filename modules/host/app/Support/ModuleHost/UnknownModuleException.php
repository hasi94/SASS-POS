<?php

namespace App\Support\ServiceHost;

use RuntimeException;

/**
 * Thrown while the host boots when SERVICES names something that is not a
 * usable service package. The offending name is always part of the message so a
 * misconfigured deployment is obvious from the very first log line.
 */
class UnknownServiceException extends RuntimeException
{
    public function __construct(private readonly string $service, string $reason)
    {
        parent::__construct(sprintf('Unknown service "%s" listed in SERVICES: %s', $service, $reason));
    }

    public function service(): string
    {
        return $this->service;
    }
}
