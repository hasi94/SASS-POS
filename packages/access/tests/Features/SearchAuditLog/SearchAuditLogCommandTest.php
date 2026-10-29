<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\SearchAuditLog;

use Flexipos\Access\Support\AccessLogWriter;
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

function searchCommandSeedRowWithIp(string $ip): string
{
    AccessTenantFixture::switchTo('tenant-a');

    $key = AccessSeed::permission('audit.read');
    $actor = AccessSeed::actorHolding('cli-reader', [$key]);

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
        'ip' => AccessLogWriter::packIp($ip),
        'user_agent' => null,
        'request_id' => null,
        'created_at' => '2026-06-01 00:00:00.000',
    ]);

    tenancy()->leave();

    return $actor;
}

it('exports a row with a real IPv4 address as JSON, printable rather than packed bytes (R-1)', function () {
    $actor = searchCommandSeedRowWithIp('192.168.1.10');

    $path = searchCommandFreshPath('ipv4.json');

    $exitCode = Artisan::call('audit:search', [
        '--tenant' => 'tenant-a',
        '--as' => $actor,
        '--output' => $path,
        '--format' => 'json',
    ]);

    expect($exitCode)->toBe(0)
        ->and((string) file_get_contents($path))->toContain('192.168.1.10');
});

it('exports a row with a real IPv4 address as CSV, printable rather than packed bytes (R-1)', function () {
    $actor = searchCommandSeedRowWithIp('192.168.1.10');

    $path = searchCommandFreshPath('ipv4.csv');

    $exitCode = Artisan::call('audit:search', [
        '--tenant' => 'tenant-a',
        '--as' => $actor,
        '--output' => $path,
        '--format' => 'csv',
    ]);

    expect($exitCode)->toBe(0)
        ->and((string) file_get_contents($path))->toContain('192.168.1.10');
});

it('exports a row with a real IPv6 address as JSON, printable rather than packed bytes (R-1)', function () {
    $actor = searchCommandSeedRowWithIp('2001:db8::1');

    $path = searchCommandFreshPath('ipv6.json');

    $exitCode = Artisan::call('audit:search', [
        '--tenant' => 'tenant-a',
        '--as' => $actor,
        '--output' => $path,
        '--format' => 'json',
    ]);

    expect($exitCode)->toBe(0)
        ->and((string) file_get_contents($path))->toContain('2001:db8::1');
});

it('exports a row with a real IPv6 address as CSV, printable rather than packed bytes (R-1)', function () {
    $actor = searchCommandSeedRowWithIp('2001:db8::1');

    $path = searchCommandFreshPath('ipv6.csv');

    $exitCode = Artisan::call('audit:search', [
        '--tenant' => 'tenant-a',
        '--as' => $actor,
        '--output' => $path,
        '--format' => 'csv',
    ]);

    expect($exitCode)->toBe(0)
        ->and((string) file_get_contents($path))->toContain('2001:db8::1');
});

it('exports a row with a leading-zero-byte IPv4 address as JSON, printable rather than packed bytes (R-1, QA)', function () {
    // 10.0.0.1 packs to the byte sequence 0x0A 0x00 0x00 0x01 - a naive
    // unpack that treats the packed column as a C string (stops at the
    // first NUL byte, or trims trailing NULs) silently truncates or
    // mangles this one even though plain 192.168.1.10 round-trips fine.
    $actor = searchCommandSeedRowWithIp('10.0.0.1');

    $path = searchCommandFreshPath('ipv4-leading-zero.json');

    $exitCode = Artisan::call('audit:search', [
        '--tenant' => 'tenant-a',
        '--as' => $actor,
        '--output' => $path,
        '--format' => 'json',
    ]);

    expect($exitCode)->toBe(0)
        ->and((string) file_get_contents($path))->toContain('10.0.0.1');
});

it('exports a row with a leading-zero-byte IPv4 address as CSV, printable rather than packed bytes (R-1, QA)', function () {
    $actor = searchCommandSeedRowWithIp('10.0.0.1');

    $path = searchCommandFreshPath('ipv4-leading-zero.csv');

    $exitCode = Artisan::call('audit:search', [
        '--tenant' => 'tenant-a',
        '--as' => $actor,
        '--output' => $path,
        '--format' => 'csv',
    ]);

    expect($exitCode)->toBe(0)
        ->and((string) file_get_contents($path))->toContain('10.0.0.1');
});

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
