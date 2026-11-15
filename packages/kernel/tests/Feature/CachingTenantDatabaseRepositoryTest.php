<?php

use Flexipos\Kernel\Tenancy\CachingTenantDatabaseRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/*
 * T-008 step 6, AC-14, AC-15 and AC-17: the 300-second decorator.
 *
 * The inner repository here is a counting double, not the master reader. That
 * is deliberate: "the master receives no query for it" is a claim about how
 * many times the *source* is consulted, and counting calls to the source states
 * it directly, while counting SQL statements would also pass for a reader that
 * cached the connection instead of the mapping.
 *
 * No engine and no database at all.
 */

require_once __DIR__.'/../Support/counting-mapping-source.php';

uses(TestCase::class);

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| AC-14: the second read inside the window does not reach the source
|--------------------------------------------------------------------------
*/

it('answers the second read for the same tenant from the cache', function () {
    $source = new CountingTenantMappingSource;
    $repository = new CachingTenantDatabaseRepository($source, Cache::store(), 300);

    $first = $repository->find('tenant-alpha');
    $second = $repository->find('tenant-alpha');

    // The same tenant, twice, inside the window. A test that called find() for
    // two *different* tenants would pass with no cache at all.
    expect($source->calls['tenant-alpha'])->toBe(1)
        ->and($second?->tenantId)->toBe($first?->tenantId)
        ->and($second?->credentialRef)->toBe('env://CACHE_FIXTURE_PASSWORD')
        ->and($second?->schemaVersion)->toBe(6)
        ->and($second?->credentialVersion)->toBe(4);

    // The key is the tenant id alone, with the credential version carried as a
    // value inside it (AC-14). A key that carried the version would make a
    // rotation leave the old entry behind, reachable by anything that still
    // computed the old key.
    expect(Cache::has('tenant:conn:tenant-alpha'))->toBeTrue()
        ->and(CachingTenantDatabaseRepository::KEY_PREFIX)->toBe('tenant:conn:');

    $entry = Cache::get('tenant:conn:tenant-alpha');

    expect($entry)->toBeArray()
        ->and($entry['credential_version'] ?? null)->toBe(4);

    // Another tenant is another key, so the cache is not a single slot that
    // answers for whoever asked first.
    $repository->find('tenant-beta');

    expect($source->calls['tenant-beta'])->toBe(1)
        ->and(Cache::has('tenant:conn:tenant-beta'))->toBeTrue();
});

it('reads the source again once the window has passed', function () {
    $source = new CountingTenantMappingSource;
    $repository = new CachingTenantDatabaseRepository($source, Cache::store(), 300);

    $repository->find('tenant-alpha');

    // Just inside the window: still one read.
    Carbon::setTestNow(Carbon::now()->addSeconds(299));
    $repository->find('tenant-alpha');

    expect($source->calls['tenant-alpha'])->toBe(1);

    // Past it: read again. The clock is moved rather than the TTL, so the input
    // that makes the term bind is the one the criterion names.
    Carbon::setTestNow(Carbon::now()->addSeconds(2));
    $repository->find('tenant-alpha');

    expect($source->calls['tenant-alpha'])->toBe(2);
});

it('does not cache the absence of a mapping', function () {
    $source = new CountingTenantMappingSource;
    $repository = new CachingTenantDatabaseRepository($source, Cache::store(), 300);

    expect($repository->find('tenant-missing'))->toBeNull()
        ->and($repository->find('tenant-missing'))->toBeNull();

    // A tenant being provisioned has no mapping row for a few seconds. Caching
    // the null would make it unreachable for five minutes after it exists, and
    // "no mapping" is already a refusal rather than a value worth keeping.
    expect($source->calls['tenant-missing'])->toBe(2)
        ->and(Cache::has('tenant:conn:tenant-missing'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| AC-17: eviction, which is the call the runner uses
|--------------------------------------------------------------------------
*/

it('reads the source again after the tenant is evicted', function () {
    $source = new CountingTenantMappingSource;
    $repository = new CachingTenantDatabaseRepository($source, Cache::store(), 300);

    $repository->find('tenant-alpha');
    $repository->find('tenant-beta');

    $repository->evict('tenant-alpha');

    $repository->find('tenant-alpha');
    $repository->find('tenant-beta');

    // Evicting one tenant evicts exactly that tenant: beta is still cached,
    // which is what distinguishes an evict from a flush.
    expect($source->calls['tenant-alpha'])->toBe(2)
        ->and($source->calls['tenant-beta'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| AC-15: the cache holds the reference, never the value
|--------------------------------------------------------------------------
*/

it('holds no resolved credential anywhere in the cache store', function () {
    $password = 's3cret-DO-NOT-LEAK-7f3a';

    $source = new CountingTenantMappingSource;
    $repository = new CachingTenantDatabaseRepository($source, Cache::store(), 300);

    $repository->find('tenant-alpha');

    // The whole store, keys and values, as text. A per-key assertion would miss
    // a secret that had been written under a key nobody thought to check.
    $dump = print_r(Cache::getStore(), true);

    expect($dump)->not->toContain($password);

    // The control: the same scan over a store that does hold the value reports
    // it, so the absence above is a fact about what was cached rather than
    // about an unreadable store.
    Cache::put('tenant:conn:planted', ['password' => $password], 300);

    expect(print_r(Cache::getStore(), true))->toContain($password);
});
