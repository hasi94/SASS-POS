<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\SearchAuditLog;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-017 step 11 - audit:search (AC-18 tenant half)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
});

afterEach(function () {
    tenancy()->current()->hasTenant() && tenancy()->leave();
    AccessTenantFixture::forget();

    /** @var list<string> $directories */
    $directories = $GLOBALS['searchCommandFreshDirectories'] ?? [];

    foreach ($directories as $directory) {
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }

    $GLOBALS['searchCommandFreshDirectories'] = [];
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function searchCommandFreshPath(string $name): string
{
    $directory = sys_get_temp_dir().'/audit_search_command_test_'.bin2hex(random_bytes(6));
    $GLOBALS['searchCommandFreshDirectories'][] = $directory;

    mkdir($directory);

    return $directory.'/'.$name;
}

function searchCommandSeedRows(int $count): string
{
    AccessTenantFixture::switchTo('tenant-a');

    $key = AccessSeed::permission('audit.read');
    $actor = AccessSeed::actorHolding('cli-reader', [$key]);

    for ($i = 0; $i < $count; $i++) {
        AccessSeed::connection()->table('audit_logs')->insert([
            'actor_type' => 'employee',
            'actor_id' => null,
            'actor_name' => null,
            'branch_id' => null,
            'module' => 'access',
            'action' => 'role_created',
            'subject_type' => 'role',
            'subject_id' => 'SUBJECT0000000000000000001',
            'before' => null,
            'after' => null,
            'changed_fields' => null,
            'ip' => null,
            'user_agent' => null,
            'request_id' => null,
            'created_at' => '2026-06-0'.($i % 9 + 1).' 00:00:00.000',
        ]);
    }

    tenancy()->leave();

    return $actor;
}

it('exports the same rows the handler returns, as CSV', function () {
    $actor = searchCommandSeedRows(2);

    $path = searchCommandFreshPath('page.csv');

    $exitCode = Artisan::call('audit:search', [
        '--tenant' => 'tenant-a',
        '--as' => $actor,
        '--output' => $path,
        '--format' => 'csv',
    ]);

    expect($exitCode)->toBe(0)
        ->and(is_file($path))->toBeTrue();

    $lines = array_filter(array_map('rtrim', (array) file($path)), static fn (string $line): bool => $line !== '');

    // Header plus two data rows.
    expect($lines)->toHaveCount(3);
});

it('writes 5 of 7 rows under max_page_size=5 and prints the next cursor', function () {
    $actor = searchCommandSeedRows(7);

    config()->set('audit.max_page_size', 5);

    $path = searchCommandFreshPath('page.json');

    Artisan::call('audit:search', [
        '--tenant' => 'tenant-a',
        '--as' => $actor,
        '--output' => $path,
        '--format' => 'json',
    ]);

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded)->toHaveCount(5)
        ->and(Artisan::output())->toContain('--before-id=');
});

it('exits non-zero and prints the reason for a refusal', function () {
    AccessTenantFixture::switchTo('tenant-a');
    AccessSeed::permission('audit.read');
    tenancy()->leave();

    $exitCode = Artisan::call('audit:search', [
        '--tenant' => 'tenant-a',
        '--as' => 'no-such-employee',
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('employee_unavailable');
});
