<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * Proves the formatter is actually enforced, not merely installed: a badly
 * formatted file is rejected by `pint --test`, fixed by `pint`, and accepted
 * afterwards. The fixture lives under storage/, which Pint never scans, so it
 * can never break `composer lint` itself.
 */

function pintFixturePath(): string
{
    return storage_path('app/pint-fixture/BadlyFormatted.php');
}

function runPint(bool $testOnly): ProcessResult
{
    $command = [PHP_BINARY, base_path('vendor/bin/pint')];

    if ($testOnly) {
        $command[] = '--test';
    }

    $command[] = pintFixturePath();

    return Process::path(base_path())->run($command);
}

beforeEach(function () {
    File::ensureDirectoryExists(dirname(pintFixturePath()));
    File::put(pintFixturePath(), "<?php\nclass  BadlyFormatted {\n  public function value( ) {return   1;}\n}\n");
});

afterEach(function () {
    deleteFixtureDirectory(dirname(pintFixturePath()));
});

it('rejects a badly formatted file, fixes it, and then accepts it', function () {
    $before = runPint(testOnly: true);

    expect($before->exitCode())->not->toBe(0)
        ->and($before->output())->toContain('BadlyFormatted');

    expect(runPint(testOnly: false)->exitCode())->toBe(0)
        ->and(File::get(pintFixturePath()))->not->toBe("<?php\nclass  BadlyFormatted {\n  public function value( ) {return   1;}\n}\n");

    expect(runPint(testOnly: true)->exitCode())->toBe(0);
});
