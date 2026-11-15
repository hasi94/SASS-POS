<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Support;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\PluginSdk\Support\PluginSettingsStore;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 8 - PluginSettingsStore (AC-18)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    // The worktree ships no .env, so config('app.key') is empty by default
    // and Crypt::encryptString() throws MissingAppKeyException. A key set
    // here, before the encrypter is first resolved, is picked up the same
    // way an .env-provided one would be.
    if (empty(config('app.key'))) {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

it('stores a secret value encrypted at rest, and decrypts it back', function () {
    app(PluginSettingsStore::class)->put('fixture-echo', 'api-key', 'topsecret', true);

    $stored = DB::connection(AccessTenantFixture::connectionName())
        ->table('plugin_settings')
        ->where('plugin_name', 'fixture-echo')
        ->where('key', 'api-key')
        ->value('value');

    expect($stored)->not->toBe('topsecret')
        ->and(Crypt::decryptString((string) $stored))->toBe('topsecret')
        ->and(app(PluginSettingsStore::class)->get('fixture-echo', 'api-key'))->toBe('topsecret');
});

it('stores a non-secret value as plain text', function () {
    app(PluginSettingsStore::class)->put('fixture-echo', 'display-name', 'Fixture Echo', false);

    $stored = DB::connection(AccessTenantFixture::connectionName())
        ->table('plugin_settings')
        ->where('plugin_name', 'fixture-echo')
        ->where('key', 'display-name')
        ->value('value');

    expect($stored)->toBe('Fixture Echo')
        ->and(app(PluginSettingsStore::class)->get('fixture-echo', 'display-name'))->toBe('Fixture Echo');
});

it('updates an existing row in place rather than duplicating it', function () {
    app(PluginSettingsStore::class)->put('fixture-echo', 'display-name', 'first', false);
    app(PluginSettingsStore::class)->put('fixture-echo', 'display-name', 'second', false);

    expect(DB::connection(AccessTenantFixture::connectionName())->table('plugin_settings')->count())->toBe(1)
        ->and(app(PluginSettingsStore::class)->get('fixture-echo', 'display-name'))->toBe('second');
});

it('gives null for an unset key', function () {
    expect(app(PluginSettingsStore::class)->get('fixture-echo', 'no-such-key'))->toBeNull();
});
