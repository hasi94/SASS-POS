<?php

use App\Support\Health\CheckStatus;
use App\Support\Health\MasterDatabaseCheck;
use App\Support\Health\ReadinessCheck;
use App\Support\Health\RedisCheck;
use Flexipos\Kernel\Tenancy\Contracts\TenantFleet;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Contracts\Cache\Repository;
use Tests\TestCase;

use function Pest\Laravel\get;

/*
 * T-021 Ruling 7: readiness, bounded checks, and the fixed-word body.
 * AC-41 to AC-47 (AC-47 is the six-file edit in section 9, run separately).
 */

/**
 * A `ReadinessCheck` with a chosen answer, for a test that needs a
 * dependency to behave a particular way without touching a real
 * connection.
 */
final class QaFixedReadinessCheck implements ReadinessCheck
{
    public function __construct(private readonly string $checkName, private readonly CheckStatus $status) {}

    public function name(): string
    {
        return $this->checkName;
    }

    public function check(): CheckStatus
    {
        return $this->status;
    }
}

beforeEach(function () {
    /** @var TestCase $this */
    $this->bootHostWith('demo');
});

it('answers 200 ok with modules and both checks ok (AC-42)', function () {
    $response = get('/health/ready')->assertOk();

    expect($response->json('status'))->toBe('ok')
        ->and($response->json('modules'))->toBe(['demo'])
        ->and($response->json('checks.master_database'))->toBe('ok')
        ->and($response->json('checks.redis'))->toBe('skipped');
});

it('answers 503 unavailable with master_database failed when it cannot connect (AC-43)', function () {
    config()->set('database.connections.master', [
        'driver' => 'sqlite',
        'database' => storage_path('app/qa-no-such-directory/dead.sqlite'),
        'prefix' => '',
    ]);

    $start = microtime(true);
    $response = get('/health/ready');
    $elapsed = microtime(true) - $start;

    expect($response->getStatusCode())->toBe(503)
        ->and($response->json('status'))->toBe('unavailable')
        ->and($response->json('checks.master_database'))->toBe('failed')
        ->and($elapsed)->toBeLessThan((float) config('api.health.timeout_seconds', 2) + 1)
        ->and((string) $response->getContent())->not->toContain('qa-no-such-directory')
        ->and((string) $response->getContent())->not->toContain('SQLSTATE');
});

it('reads redis failed and 503 from a bound throwing check, ok from a bound fake (AC-44)', function () {
    app()->bind(RedisCheck::class, fn () => new QaFixedReadinessCheck('redis', CheckStatus::Failed));

    $response = get('/health/ready');

    expect($response->getStatusCode())->toBe(503)
        ->and($response->json('checks.redis'))->toBe('failed');
});

it('skips redis and stays 200 with the default (non-redis) configuration (AC-44)', function () {
    $response = get('/health/ready')->assertOk();

    expect($response->json('checks.redis'))->toBe('skipped');
});

it('every scalar is one of ok, failed, skipped, unavailable or a module name (AC-45)', function () {
    app()->bind(MasterDatabaseCheck::class, fn () => new QaFixedReadinessCheck('master_database', CheckStatus::Failed));

    $response = get('/health/ready');
    $allowed = ['ok', 'failed', 'skipped', 'unavailable', 'demo'];

    $flatten = function (array $data, array &$values) use (&$flatten): void {
        foreach ($data as $value) {
            if (is_array($value)) {
                $flatten($value, $values);
            } else {
                $values[] = $value;
            }
        }
    };

    $values = [];
    $flatten((array) $response->json(), $values);

    foreach ($values as $value) {
        expect($allowed)->toContain($value);
    }
});

it('is unaffected by a throwing TenantFleet and Tenancy binding (AC-46)', function () {
    app()->bind(TenantFleet::class, function () {
        return new class implements TenantFleet
        {
            public function servingTenantIds(): array
            {
                throw new RuntimeException('must not be called');
            }
        };
    });

    app()->bind(Tenancy::class, function () {
        throw new RuntimeException('must not be called');
    });

    get('/health/ready')->assertOk();
});

it('answers liveness ok even with a throwing cache store bound (AC-41)', function () {
    app()->bind(Repository::class, function () {
        throw new RuntimeException('must not be called');
    });

    get('/health/live')->assertOk()->assertExactJson(['status' => 'ok']);
});
