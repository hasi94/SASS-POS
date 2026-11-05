<?php

use Flexipos\Kernel\Api\Idempotency\IdempotencyClaim;
use Flexipos\Kernel\Api\Idempotency\IdempotencyFingerprint;
use Flexipos\Kernel\Api\Idempotency\IdempotencyKey;
use Flexipos\Kernel\Api\Idempotency\IdempotencyStore;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * T-021 Ruling 6: claim/complete/release over the tenant connection, on the
 * kernel's own tenancy fixture - a real SQLite tenant database, entered only
 * through Tenancy::enter().
 */

require_once __DIR__.'/../../Support/tenancy-fixture.php';

uses(TestCase::class);

/**
 * `idempotency_keys`, created directly on the fixture's tenant connection
 * (the fixture itself only carries a `marker` table) - the same shape the
 * host's tenant migration for this table creates.
 */
function idempotencyStoreCreateTable(): void
{
    if (Schema::connection('tenant')->hasTable('idempotency_keys')) {
        return;
    }

    Schema::connection('tenant')->create('idempotency_keys', function ($table) {
        $table->string('key', 80)->primary();
        $table->char('request_hash', 64);
        $table->unsignedSmallInteger('response_code')->nullable();
        $table->json('response_body')->nullable();
        $table->dateTime('created_at', 3);
        $table->index('created_at');
    });
}

beforeEach(function () {
    tenancyBootFixture();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-30 00:00:00.000'));
});

afterEach(function () {
    tenancyForgetFixture();
});

afterAll(function () {
    tenancyDeleteFixtureRoot();
});

it('claims, completes, then replays the same key', function () {
    tenancy()->run('tenant-a', function () {
        idempotencyStoreCreateTable();

        $store = app(IdempotencyStore::class);
        $hash = IdempotencyFingerprint::of('POST', 'api/v1/qa', 'user:1', '{"a":1}');

        $claim = $store->claim('k1', $hash);
        expect($claim->outcome)->toBe(IdempotencyClaim::Claimed);

        $store->complete('k1', $hash, 201, ['ok' => true]);

        $replay = $store->claim('k1', $hash);
        expect($replay->outcome)->toBe(IdempotencyClaim::Replay)
            ->and($replay->responseCode)->toBe(201)
            ->and($replay->responseBody)->toBe(['ok' => true]);
    });
});

it('refuses a different hash as reused, even while the row is in flight', function () {
    tenancy()->run('tenant-a', function () {
        idempotencyStoreCreateTable();

        $store = app(IdempotencyStore::class);
        $hash = IdempotencyFingerprint::of('POST', 'api/v1/qa', 'user:1', '{"a":1}');
        $otherHash = IdempotencyFingerprint::of('POST', 'api/v1/qa', 'user:2', '{"a":1}');

        $store->claim('k2', $hash);

        $result = $store->claim('k2', $otherHash);
        expect($result->outcome)->toBe(IdempotencyClaim::Reused);
    });
});

it('refuses an in-flight row as in progress', function () {
    tenancy()->run('tenant-a', function () {
        idempotencyStoreCreateTable();

        $store = app(IdempotencyStore::class);
        $hash = IdempotencyFingerprint::of('POST', 'api/v1/qa', 'user:1', '{"a":1}');

        $store->claim('k3', $hash);

        $result = $store->claim('k3', $hash);
        expect($result->outcome)->toBe(IdempotencyClaim::InProgress);
    });
});

it('takes over a stale in-flight row after the claim timeout', function () {
    tenancy()->run('tenant-a', function () {
        idempotencyStoreCreateTable();

        $store = app(IdempotencyStore::class);
        $hash = IdempotencyFingerprint::of('POST', 'api/v1/qa', 'user:1', '{"a":1}');

        $store->claim('k4', $hash);

        app(RunnerClock::class)->advanceSeconds(121);

        $result = $store->claim('k4', $hash);
        expect($result->outcome)->toBe(IdempotencyClaim::Claimed);
    });
});

it('re-claims an expired completed row as new', function () {
    tenancy()->run('tenant-a', function () {
        idempotencyStoreCreateTable();

        $store = app(IdempotencyStore::class);
        $hash = IdempotencyFingerprint::of('POST', 'api/v1/qa', 'user:1', '{"a":1}');

        $store->claim('k5', $hash);
        $store->complete('k5', $hash, 201, ['ok' => true]);

        app(RunnerClock::class)->advanceMinutes(25 * 60);

        $result = $store->claim('k5', $hash);
        expect($result->outcome)->toBe(IdempotencyClaim::Claimed);
    });
});

it('release deletes only a null row', function () {
    tenancy()->run('tenant-a', function () {
        idempotencyStoreCreateTable();

        $store = app(IdempotencyStore::class);
        $hash = IdempotencyFingerprint::of('POST', 'api/v1/qa', 'user:1', '{"a":1}');

        $store->claim('k6', $hash);
        $store->complete('k6', $hash, 201, ['ok' => true]);
        $store->release('k6', $hash);

        expect(DB::connection('tenant')->table('idempotency_keys')->where('key', 'k6')->first()->response_code)
            ->toBe(201);

        $store->claim('k7', $hash);
        $store->release('k7', $hash);

        expect(DB::connection('tenant')->table('idempotency_keys')->where('key', 'k7')->first())->toBeNull();
    });
});

it('complete with the wrong hash updates nothing', function () {
    tenancy()->run('tenant-a', function () {
        idempotencyStoreCreateTable();

        $store = app(IdempotencyStore::class);
        $hash = IdempotencyFingerprint::of('POST', 'api/v1/qa', 'user:1', '{"a":1}');
        $otherHash = IdempotencyFingerprint::of('POST', 'api/v1/qa', 'user:2', '{"a":1}');

        $store->claim('k8', $hash);
        $store->complete('k8', $otherHash, 201, ['ok' => true]);

        $row = DB::connection('tenant')->table('idempotency_keys')->where('key', 'k8')->first();
        expect($row->response_code)->toBeNull();
    });
});

it('accepts an 80 character key and refuses 81, empty, a space and a non-ascii letter', function () {
    expect(IdempotencyKey::isValid(str_repeat('a', 80)))->toBeTrue()
        ->and(IdempotencyKey::isValid(str_repeat('a', 81)))->toBeFalse()
        ->and(IdempotencyKey::isValid(''))->toBeFalse()
        ->and(IdempotencyKey::isValid('a b'))->toBeFalse()
        ->and(IdempotencyKey::isValid('é'))->toBeFalse();
});
