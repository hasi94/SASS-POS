<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Support;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Facades\File;

/**
 * The default {@see PluginDataExporter}: one JSON file per table, under
 * `<plugins.export_path>/<tenant>/<plugin>/<YmdHis>/<table>.json`
 * (T-019 AC-20, plan Ruling 8).
 */
final class JsonFilePluginDataExporter implements PluginDataExporter
{
    public function __construct(
        private readonly RunnerClock $clock,
    ) {}

    public function export(string $tenantId, string $plugin, string $table, iterable $rows): void
    {
        $directory = rtrim((string) config('plugins.export_path'), '/\\')
            .'/'.$tenantId.'/'.$plugin.'/'.$this->clock->now()->format('YmdHis');

        File::ensureDirectoryExists($directory);

        $data = [];

        foreach ($rows as $row) {
            $data[] = (array) $row;
        }

        File::put($directory.'/'.$table.'.json', json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
