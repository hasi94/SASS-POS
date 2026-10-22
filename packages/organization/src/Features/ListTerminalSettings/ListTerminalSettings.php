<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ListTerminalSettings;

/**
 * The settings one terminal receives. The id is the verified token's.
 */
final class ListTerminalSettings
{
    public function __construct(public readonly string $terminalId) {}
}
