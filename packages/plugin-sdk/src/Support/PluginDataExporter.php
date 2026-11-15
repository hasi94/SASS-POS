<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Support;

/**
 * Exports one owned table's rows before `UninstallPlugin` drops it (T-019
 * AC-20, plan Ruling 8). The data's shape is not this task's to define
 * (Group K) - the default implementation writes whatever the rows are.
 */
interface PluginDataExporter
{
    /**
     * @param  iterable<object>  $rows
     */
    public function export(string $tenantId, string $plugin, string $table, iterable $rows): void;
}
