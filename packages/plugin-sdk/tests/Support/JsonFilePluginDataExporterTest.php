<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Support;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\PluginSdk\Support\JsonFilePluginDataExporter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 9 - JsonFilePluginDataExporter (AC-20)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

it('writes one JSON file per table into a timestamped directory', function () {
    $tmp = sys_get_temp_dir().'/plugin-sdk-export-'.bin2hex(random_bytes(6));
    config(['plugins.export_path' => $tmp]);

    $clock = RunnerClock::fixedAt('2026-06-01 12:00:00.000');
    $exporter = new JsonFilePluginDataExporter($clock);

    $exporter->export('tenant-a', 'fixture-echo', 'plg_fixture_echo_notes', [
        (object) ['id' => '1', 'note' => 'hello'],
    ]);

    $path = $tmp.'/tenant-a/fixture-echo/20260601120000/plg_fixture_echo_notes.json';

    expect(is_file($path))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded)->toBe([['id' => '1', 'note' => 'hello']]);

    File::deleteDirectory($tmp);
});
