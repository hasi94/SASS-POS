<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| This binding covers the host's own tests only. A service package's tests bind
| Tests\TestCase themselves, in the test file, because Pest refuses to bind the
| same test case to one file twice - and because a freshly generated package
| must pass `composer test` without anyone editing a file in services/host.
| Such a package only needs one more <directory> entry in phpunit.xml.
|
*/

uses(TestCase::class)->in(__DIR__);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Absolute path of the services/ directory, or of one service package in it.
 */
function servicesPath(string $name = ''): string
{
    return rtrim(dirname(base_path()).DIRECTORY_SEPARATOR.$name, DIRECTORY_SEPARATOR);
}
