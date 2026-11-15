<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\UninstallPlugin;

final class UninstallPluginResult
{
    /**
     * @param  list<string>  $droppedTables
     */
    public function __construct(
        public readonly array $droppedTables,
    ) {}
}
