<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\GetTerminal;

final class GetTerminal
{
    public function __construct(public readonly mixed $terminalId) {}
}
