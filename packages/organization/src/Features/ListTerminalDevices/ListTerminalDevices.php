<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ListTerminalDevices;

final class ListTerminalDevices
{
    public function __construct(public readonly mixed $terminalId) {}
}
