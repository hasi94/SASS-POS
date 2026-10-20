<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Resolver;

use Flexipos\Access\Support\PermVersion;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Access\Tests\Support\MisKeyedCacheResolver;
use Flexipos\Access\Tests\Support\VersionBlindCacheResolver;
use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 10 - AC-45 (tenant isolation) and AC-46 (freshness)
|--------------------------------------------------------------------------
|
| The shipped resolver holds no cache and no per-request memo (Ruling 5), so
| both criteria are satisfied STRUCTURALLY: there is no key to get wrong and
| no entry to go stale. That is a good property and a bad proof. A criterion
| whose only evidence is the absence of a mechanism reads exactly like a
| criterion nobody tested, so each half here is a pair:
|
|   1. the shipped resolver answers correctly, and
|   2. a double that fails the criterion in the one way the criterion names
|      is shown failing it, which is what makes (1) mean something.
|
| What this file does NOT claim: that the shipped resolver's cache is keyed
| correctly. It has no cache. If a later task measures a reason to add one,
| these two doubles are the shape of the tests it owes.
|
| The isolation case uses the SAME employee id in both tenants, which is the
| input AC-45 asks for and the one a tenant-keyed cache and an unkeyed one
| answer differently. A different id per tenant could not tell them apart.
|
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

const ISOLATION_KEY = 'fixture.isolation';

/**
 * Seed the current tenant with one employee at a fixed id, holding the key
 * or not.
 */
function seedIsolationTenant(string $employeeId, bool $granted): void
{
    AccessSeed::permission(ISOLATION_KEY, false);

    AccessSeed::connection()->table('employees')->insert([
        'id' => $employeeId,
        'code' => 'shared',
        'first_name' => 'Shared',
        'display_name' => 'Shared',
        'status' => 'active',
        'perm_version' => 1,
        'created_at' => now()->format('Y-m-d H:i:s.v'),
        'updated_at' => now()->format('Y-m-d H:i:s.v'),
    ]);

    if ($granted) {
        AccessSeed::assign($employeeId, AccessSeed::role('fixture_isolation_role', [ISOLATION_KEY]));
    }
}

it('answers each tenant from its own tables, and the same worker answers both (AC-45)', function () {
    $employeeId = (string) Str::ulid();

    AccessTenantFixture::switchTo('tenant-a');
    seedIsolationTenant($employeeId, true);

    AccessTenantFixture::switchTo('tenant-b');
    seedIsolationTenant($employeeId, false);

    // One resolver object for all three questions: a per-call instance would
    // be a different worker each time and could not hold a wrong answer even
    // if it tried to.
    /** @var PermissionResolver $resolver */
    $resolver = app(PermissionResolver::class);

    AccessTenantFixture::switchTo('tenant-a');
    $firstA = $resolver->decide($employeeId, ISOLATION_KEY, null);

    AccessTenantFixture::switchTo('tenant-b');
    $b = $resolver->decide($employeeId, ISOLATION_KEY, null);

    AccessTenantFixture::switchTo('tenant-a');
    $secondA = $resolver->decide($employeeId, ISOLATION_KEY, null);

    expect($firstA->allowed)->toBeTrue()
        ->and($b->allowed)->toBeFalse()
        ->and($b->reason)->toBe(RefusalReason::NotPermitted)
        ->and($secondA->allowed)->toBeTrue();
});

it('reports the mis-keyed cache fixture handing tenant B tenant A answer (AC-45 control)', function () {
    $employeeId = (string) Str::ulid();

    AccessTenantFixture::switchTo('tenant-a');
    seedIsolationTenant($employeeId, true);

    AccessTenantFixture::switchTo('tenant-b');
    seedIsolationTenant($employeeId, false);

    /** @var PermissionResolver $shipped */
    $shipped = app(PermissionResolver::class);
    $misKeyed = new MisKeyedCacheResolver($shipped);

    AccessTenantFixture::switchTo('tenant-a');
    expect($misKeyed->decide($employeeId, ISOLATION_KEY, null)->allowed)->toBeTrue();

    AccessTenantFixture::switchTo('tenant-b');

    // The criterion's failing case, demonstrated rather than described: the
    // double says allowed for a tenant whose tables grant nothing, and the
    // shipped resolver asked at the same moment says refused.
    expect($misKeyed->decide($employeeId, ISOLATION_KEY, null)->allowed)->toBeTrue()
        ->and($shipped->decide($employeeId, ISOLATION_KEY, null)->allowed)->toBeFalse();

    fwrite(STDOUT, PHP_EOL.'AC-45 control: a cache keyed on the employee id alone answered tenant-b '
        ."with tenant-a's allow; the shipped resolver answered refused.".PHP_EOL);
});

it('reflects a change the moment it is made, with no bump needed to see it (AC-46)', function () {
    AccessTenantFixture::switchTo('tenant-a');

    $employeeId = (string) Str::ulid();
    seedIsolationTenant($employeeId, true);

    /** @var PermissionResolver $resolver */
    $resolver = app(PermissionResolver::class);

    expect($resolver->decide($employeeId, ISOLATION_KEY, null)->allowed)->toBeTrue();

    $before = AccessSeed::permVersion($employeeId);

    // A qualifying change (AC-52): a per-person deny, and the bump that goes
    // with it, in the same transaction a real slice would use.
    AccessSeed::connection()->transaction(function () use ($employeeId): void {
        AccessSeed::personal($employeeId, ISOLATION_KEY, false);
        PermVersion::bump(AccessSeed::connection(), [$employeeId]);
    });

    expect(AccessSeed::permVersion($employeeId))->toBe($before + 1)
        ->and($resolver->decide($employeeId, ISOLATION_KEY, null)->allowed)->toBeFalse();
});

it('reports the version-blind cache fixture serving the answer from before the bump (AC-46 control)', function () {
    AccessTenantFixture::switchTo('tenant-a');

    $employeeId = (string) Str::ulid();
    seedIsolationTenant($employeeId, true);

    /** @var PermissionResolver $shipped */
    $shipped = app(PermissionResolver::class);
    $versionBlind = new VersionBlindCacheResolver($shipped, 'tenant-a');

    expect($versionBlind->decide($employeeId, ISOLATION_KEY, null)->allowed)->toBeTrue();

    AccessSeed::connection()->transaction(function () use ($employeeId): void {
        AccessSeed::personal($employeeId, ISOLATION_KEY, false);
        PermVersion::bump(AccessSeed::connection(), [$employeeId]);
    });

    // Stale, and the shipped resolver asked at the same moment is not.
    expect($versionBlind->decide($employeeId, ISOLATION_KEY, null)->allowed)->toBeTrue()
        ->and($shipped->decide($employeeId, ISOLATION_KEY, null)->allowed)->toBeFalse();

    fwrite(STDOUT, PHP_EOL.'AC-46 control: a cache keyed without perm_version answered allowed after a '
        .'deny was stored and the version bumped; the shipped resolver answered refused.'.PHP_EOL);
});
