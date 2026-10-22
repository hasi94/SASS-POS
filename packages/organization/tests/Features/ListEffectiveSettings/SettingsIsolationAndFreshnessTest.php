<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\ListEffectiveSettings;

use Closure;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\ClearSetting\ClearSetting;
use Flexipos\Organization\Features\ClearSetting\ClearSettingHandler;
use Flexipos\Organization\Features\SetSetting\SetSetting;
use Flexipos\Organization\Features\SetSetting\SetSettingHandler;
use Flexipos\Organization\Support\SettingsResolver;
use Flexipos\Organization\Support\TenantConnection;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Flexipos\Organization\Tests\Support\SettingsSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 14 - tenant isolation and freshness of settings (AC-49)
|--------------------------------------------------------------------------
|
| The shipped resolver caches nothing (Ruling 15), so the criterion is
| satisfied structurally and the test would pass for ANY resolver that is
| correct. What keeps it falsifiable is the two controls: a memo keyed
| without the tenant, and a memo that is not invalidated on a write, are
| small test doubles run through the same sequences, and each is shown to
| give the wrong answer. The report prints the answers of all three.
|
| The two tenants hold the same key under the same branch id with different
| values; the id is a fixed ULID with no branch row behind it, because
| `settings.scope_id` carries no key and the resolver is what is under test.
*/

uses(TestCase::class);

const AC49_BRANCH = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

beforeEach(function () {
    AccessTenantFixture::boot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * Seed the same key and scope id into both tenants with different values.
 */
function ac49Seed(): void
{
    foreach (['tenant-a' => 'VALUE-A', 'tenant-b' => 'VALUE-B'] as $tenant => $value) {
        AccessTenantFixture::switchTo($tenant);
        SettingsSeed::register('shared.key', 'string', 'DEFAULT');
        SettingsSeed::override('branch', AC49_BRANCH, 'shared.key', $value);
    }
}

/**
 * What the shipped resolver answers for the current tenant.
 */
function ac49Resolve(): string
{
    $connection = app(TenantConnection::class)->get();

    return (string) SettingsResolver::effective($connection, AC49_BRANCH, null)[0]['value'];
}

/**
 * A memo over a resolver. `$keyed` decides what the cache key is made of and
 * `$invalidates` whether a write drops the entry - the two defects AC-49
 * names. Test doubles only: nothing in the product has a cache.
 */
function ac49Memo(Closure $keyed, bool $invalidates): object
{
    return new class($keyed, $invalidates)
    {
        /** @var array<string, string> */
        private array $memo = [];

        public function __construct(private readonly Closure $keyed, private readonly bool $invalidates) {}

        public function read(string $tenant): string
        {
            $key = ($this->keyed)($tenant);

            return $this->memo[$key] ??= ac49Resolve();
        }

        public function wrote(string $tenant): void
        {
            if ($this->invalidates) {
                unset($this->memo[($this->keyed)($tenant)]);
            }
        }
    };
}

it('answers for tenant A, B and A on one worker with each tenant\'s own value, and a memo keyed without the tenant does not (AC-49)', function () {
    ac49Seed();

    $answers = [];

    foreach (['tenant-a', 'tenant-b', 'tenant-a'] as $tenant) {
        AccessTenantFixture::switchTo($tenant);
        $answers[] = $tenant.'='.ac49Resolve();
    }

    expect($answers)->toBe(['tenant-a=VALUE-A', 'tenant-b=VALUE-B', 'tenant-a=VALUE-A']);

    // Control 1: the same sequence through a memo keyed by branch only.
    $leaky = ac49Memo(static fn (string $tenant): string => AC49_BRANCH, invalidates: true);
    $leaked = [];

    foreach (['tenant-a', 'tenant-b', 'tenant-a'] as $tenant) {
        AccessTenantFixture::switchTo($tenant);
        $leaked[] = $tenant.'='.$leaky->read($tenant);
    }

    fwrite(STDOUT, PHP_EOL.'AC-49 shipped resolver:        '.implode(', ', $answers));
    fwrite(STDOUT, PHP_EOL.'AC-49 memo keyed without tenant: '.implode(', ', $leaked).PHP_EOL);

    // The doubled cache gives tenant B A's value: the control that shows the test can tell.
    expect($leaked)->toBe(['tenant-a=VALUE-A', 'tenant-b=VALUE-A', 'tenant-a=VALUE-A']);

    // The memo done properly (tenant in the key) is correct, so the control is
    // the missing tenant and not the memo itself.
    $keyed = ac49Memo(static fn (string $tenant): string => $tenant.'|'.AC49_BRANCH, invalidates: true);
    $right = [];

    foreach (['tenant-a', 'tenant-b', 'tenant-a'] as $tenant) {
        AccessTenantFixture::switchTo($tenant);
        $right[] = $tenant.'='.$keyed->read($tenant);
    }

    expect($right)->toBe($answers);
});

it('reflects a set and a clear on the very next read, and a memo that is not invalidated on write returns the old value (AC-49)', function () {
    ac49Seed();
    AccessTenantFixture::switchTo('tenant-a');

    /** @var SetSettingHandler $set */
    $set = app(SetSettingHandler::class);
    /** @var ClearSettingHandler $clear */
    $clear = app(ClearSettingHandler::class);

    $stale = ac49Memo(static fn (string $tenant): string => $tenant.'|'.AC49_BRANCH, invalidates: false);
    $fresh = ac49Memo(static fn (string $tenant): string => $tenant.'|'.AC49_BRANCH, invalidates: true);

    $staleFirst = (string) $stale->read('tenant-a');
    $freshFirst = (string) $fresh->read('tenant-a');

    expect($staleFirst)->toBe('VALUE-A');
    expect($freshFirst)->toBe('VALUE-A');

    // A write through the shipped handler. The branch has no row, so write at the company scope.
    $set->handle(new SetSetting(['key' => 'shared.key', 'scope_type' => 'company', 'value' => 'COMPANY-NEW']));
    OrganizationSeed::connection()->table('settings')->where('scope_type', 'branch')->delete();
    $stale->wrote('tenant-a');
    $fresh->wrote('tenant-a');

    $after = ac49Resolve();
    $staleAfter = (string) $stale->read('tenant-a');
    $freshAfter = (string) $fresh->read('tenant-a');

    fwrite(STDOUT, PHP_EOL.'AC-49 after a write: shipped='.$after.', not invalidated='.$staleAfter.', invalidated='.$freshAfter.PHP_EOL);

    expect($after)->toBe('COMPANY-NEW');
    expect($freshAfter)->toBe('COMPANY-NEW');
    // The control: without invalidation the memo still says what it said before the write.
    expect($staleAfter)->toBe('VALUE-A');

    $set->handle(new SetSetting(['key' => 'shared.key', 'scope_type' => 'company', 'value' => 'COMPANY-NEWER']));

    expect(ac49Resolve())->toBe('COMPANY-NEWER');

    $clear->handle(new ClearSetting(['key' => 'shared.key', 'scope_type' => 'company']));

    expect(ac49Resolve())->toBe('DEFAULT');

    // And tenant B was never touched by any of it.
    AccessTenantFixture::switchTo('tenant-b');

    expect(ac49Resolve())->toBe('VALUE-B');
});
