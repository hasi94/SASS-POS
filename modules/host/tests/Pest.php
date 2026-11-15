<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| This binding covers the host's own tests only. A module's tests bind
| Tests\TestCase themselves, in the test file, because Pest refuses to bind the
| same test case to one file twice - and because a freshly generated package
| must pass `composer test` without anyone editing a file in modules/host.
| Such a package only needs one more <directory> entry in phpunit.xml.
|
*/

uses(TestCase::class)->in(__DIR__);

/*
|--------------------------------------------------------------------------
| Groups
|--------------------------------------------------------------------------
|
| T-004 FP-155. The one place the `toolchain` group is declared. Every file in
| toolchainGroupFiles() spawns a child process that boots a whole tool, and a
| filtered child run still performs full collection before filtering, so those
| files cost 60 % of the suite's time for 17 % of its cases.
|
| `composer test:fast` skips this group for the inner development loop.
| `composer test` - and therefore `composer check`, the gate - passes no group
| flag at all, and phpunit.xml declares no <groups> element, so a tag is
| structurally incapable of removing a test from the gate. That is asserted, with
| a negative control, by tests/Feature/ToolchainGroupCoverageTest.php.
|
| TestRepository::use() merges into whatever is already registered for a path, so
| this does not collide with the uses(TestCase::class) binding above.
|
*/

pest()->group('toolchain')->in(...array_map(
    static fn (string $path): string => toolchainRepositoryRoot().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path),
    toolchainGroupFiles(),
));

/*
|--------------------------------------------------------------------------
| The engine group
|--------------------------------------------------------------------------
|
| T-008. Every test under tests/Engine needs the real database engine the
| environment names, and fails - never skips - when it is unreachable (AC-63).
| The group exists so a report can name those tests and the engine they ran on,
| not so they can be excluded: `composer test`, and therefore the gate, passes
| no group flag, and the group is deliberately absent from test:fast's
| exclusions too. An engine test that only the slow path runs is an engine test
| nobody runs.
|
*/

pest()->group('engine')->in(__DIR__.DIRECTORY_SEPARATOR.'Engine');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
|
| Shared helpers live in tests/Helpers.php, which Pest loads before this file.
|
*/

/**
 * Absolute path of the modules/ directory, or of one module in it.
 */
function modulesPath(string $name = ''): string
{
    return rtrim(dirname(base_path()).DIRECTORY_SEPARATOR.$name, DIRECTORY_SEPARATOR);
}
