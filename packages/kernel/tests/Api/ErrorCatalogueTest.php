<?php

use Flexipos\Kernel\Api\DeclaresErrorCodes;
use Flexipos\Kernel\Api\ErrorCatalogue;
use Flexipos\Kernel\Api\ErrorCatalogueRefused;
use Flexipos\Kernel\Api\ErrorCode;
use Flexipos\Kernel\Api\KernelErrorCodes;
use Flexipos\Kernel\Api\UnknownErrorCode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Tests\TestCase;

/*
 * T-021 AC-9 to AC-13, AC-15: the error-code catalogue registers by
 * declaration, validates at registration, and is lazy/memoised against the
 * set of booted providers.
 */

uses(TestCase::class);

/**
 * @param  array<string, list<ErrorCode>>  $declarationsByModule
 */
function registerFixtureErrorCodes(array $declarationsByModule): void
{
    $provider = new class(app(), $declarationsByModule) extends ServiceProvider implements DeclaresErrorCodes
    {
        /**
         * @param  array<string, list<ErrorCode>>  $declarations
         */
        public function __construct($app, private readonly array $declarations)
        {
            parent::__construct($app);
        }

        public function errorCodes(): array
        {
            return $this->declarations;
        }
    };

    app()->register($provider);
}

/**
 * A second, distinct anonymous class from {@see registerFixtureErrorCodes()}
 * (same source location means the same PHP class, which Laravel's
 * `register()` treats as already-registered and skips on a second call) -
 * needed only where a test genuinely registers two separate providers.
 *
 * @param  array<string, list<ErrorCode>>  $declarationsByModule
 */
function registerFixtureErrorCodesFromSecondProvider(array $declarationsByModule): void
{
    $provider = new class(app(), $declarationsByModule) extends ServiceProvider implements DeclaresErrorCodes
    {
        /**
         * @param  array<string, list<ErrorCode>>  $declarations
         */
        public function __construct($app, private readonly array $declarations)
        {
            parent::__construct($app);
        }

        public function errorCodes(): array
        {
            return $this->declarations;
        }
    };

    app()->register($provider);
}

it('holds every kernel code with its status (AC-9)', function () {
    $catalogue = app(ErrorCatalogue::class);

    $expected = [
        KernelErrorCodes::VALIDATION_FAILED => 422,
        KernelErrorCodes::UNAUTHENTICATED => 401,
        KernelErrorCodes::FORBIDDEN => 403,
        KernelErrorCodes::NOT_FOUND => 404,
        KernelErrorCodes::METHOD_NOT_ALLOWED => 405,
        KernelErrorCodes::RATE_LIMITED => 429,
        KernelErrorCodes::SERVER_ERROR => 500,
        KernelErrorCodes::SERVICE_UNAVAILABLE => 503,
        KernelErrorCodes::IDEMPOTENCY_KEY_REQUIRED => 400,
        KernelErrorCodes::IDEMPOTENCY_KEY_INVALID => 400,
        KernelErrorCodes::IDEMPOTENCY_KEY_REUSED => 422,
        KernelErrorCodes::IDEMPOTENCY_IN_PROGRESS => 409,
        KernelErrorCodes::BAD_REQUEST => 400,
        KernelErrorCodes::PAYLOAD_TOO_LARGE => 413,
    ];

    foreach ($expected as $code => $status) {
        expect($catalogue->resolve($code)->status)->toBe($status);
    }
});

it('refuses a malformed code, naming it (AC-10)', function (string $code) {
    registerFixtureErrorCodes(['qa_fix' => [new ErrorCode($code, 400, 'x')]]);

    expect(fn () => app(ErrorCatalogue::class)->resolve('qa_fix.anything'))
        ->toThrow(ErrorCatalogueRefused::class, $code);
})->with([
    'two dots' => ['qa_fix.a.b'],
    'upper case' => ['qa_fix.Bad'],
    'a hyphen' => ['qa_fix.bad-code'],
    'no dot' => ['qafixbad'],
]);

it('refuses a code whose prefix is not the declaring module (AC-10)', function () {
    registerFixtureErrorCodes(['qa_wrong_prefix' => [new ErrorCode('other.bad', 400, 'x')]]);

    expect(fn () => app(ErrorCatalogue::class)->resolve('other.bad'))
        ->toThrow(ErrorCatalogueRefused::class, 'other.bad');
});

it('refuses a duplicate code within one module (AC-11)', function () {
    registerFixtureErrorCodes(['qa_dup_one' => [
        new ErrorCode('qa_dup_one.a', 400, 'x'),
        new ErrorCode('qa_dup_one.a', 400, 'y'),
    ]]);

    expect(fn () => app(ErrorCatalogue::class)->resolve('qa_dup_one.a'))
        ->toThrow(ErrorCatalogueRefused::class, 'qa_dup_one.a');
});

it('refuses a duplicate code registered by a second provider under the same module (AC-11)', function () {
    registerFixtureErrorCodes(['qa_dup_a' => [new ErrorCode('qa_dup_a.x', 400, 'one')]]);
    registerFixtureErrorCodesFromSecondProvider(['qa_dup_a' => [new ErrorCode('qa_dup_a.x', 400, 'two')]]);

    expect(fn () => app(ErrorCatalogue::class)->resolve('qa_dup_a.x'))
        ->toThrow(ErrorCatalogueRefused::class, 'qa_dup_a.x');
});

it('throws on an unknown code outside production (AC-12)', function () {
    expect(fn () => app(ErrorCatalogue::class)->resolve('qa_no_such.code'))
        ->toThrow(UnknownErrorCode::class, 'qa_no_such.code');
});

it('degrades to kernel.server_error and logs in production (AC-12)', function () {
    Log::shouldReceive('error')->once();

    app()->instance('env', 'production');

    try {
        $resolved = app(ErrorCatalogue::class)->resolve('qa_no_such.code');
    } finally {
        app()->instance('env', 'testing');
    }

    expect($resolved->code)->toBe(KernelErrorCodes::SERVER_ERROR);
});

it('picks up a provider registered after the catalogue was first read (AC-13)', function () {
    $catalogue = app(ErrorCatalogue::class);

    expect($catalogue->has('qa_late.added'))->toBeFalse();

    registerFixtureErrorCodes(['qa_late' => [new ErrorCode('qa_late.added', 400, 'x')]]);

    expect($catalogue->has('qa_late.added'))->toBeTrue();
});

it('sorts all() by code', function () {
    registerFixtureErrorCodes(['qa_sort' => [
        new ErrorCode('qa_sort.zzz', 400, 'x'),
        new ErrorCode('qa_sort.aaa', 400, 'y'),
    ]]);

    $codes = array_map(fn (ErrorCode $e) => $e->code, app(ErrorCatalogue::class)->all());
    $sorted = $codes;
    sort($sorted);

    expect($codes)->toBe($sorted);
});
