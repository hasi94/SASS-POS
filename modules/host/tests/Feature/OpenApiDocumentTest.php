<?php

use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Middleware\RequireTenantToken;
use App\Support\OpenApi\OpenApiDocument;
use Flexipos\Kernel\Api\ErrorCatalogue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

use function Pest\Laravel\getJson;

/*
 * T-021 Ruling 8: the generated OpenAPI document. AC-34 to AC-36, AC-38 to
 * AC-40.
 */

/**
 * @return array<string, mixed>
 */
function openApiBuild(): array
{
    return OpenApiDocument::build(app('router'), app(ErrorCatalogue::class));
}

beforeEach(function () {
    /** @var TestCase $this */
    $this->bootHostWith('demo');
});

it('has the required top-level structure (AC-34, AC-35)', function () {
    $doc = openApiBuild();

    expect($doc['openapi'])->toBe('3.1.0')
        ->and($doc['info']['version'])->toBe('v1')
        ->and($doc['servers'])->toBe([['url' => '/']])
        ->and($doc['components']['schemas'])->toHaveKeys(['SuccessEnvelope', 'ErrorEnvelope', 'ErrorItem'])
        ->and($doc['components']['securitySchemes']['bearerAuth']['type'])->toBe('http')
        ->and($doc['x-error-codes'])->toBe(array_map(
            fn ($e) => ['code' => $e->code, 'module' => app(ErrorCatalogue::class)->moduleOf($e->code), 'status' => $e->status, 'message' => $e->message],
            app(ErrorCatalogue::class)->all()
        ));
});

it('gives every operation a unique operationId, and every $ref resolves', function () {
    $doc = openApiBuild();

    $ids = [];
    $refs = [];

    foreach ($doc['paths'] as $path => $methods) {
        foreach ($methods as $operation) {
            $ids[] = $operation['operationId'];
            openApiCollectRefs($operation, $refs);
        }
    }

    expect($ids)->toBe(array_unique($ids));

    foreach (array_unique($refs) as $ref) {
        $pointer = explode('/', ltrim($ref, '#/'));
        $target = $doc;

        foreach ($pointer as $segment) {
            expect($target)->toHaveKey($segment);
            $target = $target[$segment];
        }
    }
});

/**
 * @param  list<string>  $refs
 */
function openApiCollectRefs(mixed $node, array &$refs): void
{
    if (! is_array($node)) {
        return;
    }

    if (isset($node['$ref']) && is_string($node['$ref'])) {
        $refs[] = $node['$ref'];
    }

    foreach ($node as $value) {
        openApiCollectRefs($value, $refs);
    }
}

it('declares no security on login and bearer on an access route', function () {
    $doc = openApiBuild();

    expect($doc['paths']['/api/v1/auth/login']['post']['security'])->toBe([])
        ->and($doc['paths']['/api/v1/access/roles']['get']['security'])->toBe([['bearerAuth' => []]]);
});

it('declares 401/403 only when authenticated, 422 by the rule, 429 always', function () {
    $doc = openApiBuild();

    $login = $doc['paths']['/api/v1/auth/login']['post'];
    expect($login['responses'])->not->toHaveKey('401')
        ->and($login['responses'])->toHaveKey('422') // POST
        ->and($login['responses'])->toHaveKey('429');

    $roles = $doc['paths']['/api/v1/access/roles']['get'];
    expect($roles['responses'])->toHaveKey('401')
        ->and($roles['responses'])->toHaveKey('403');
});

it('declares the Idempotency-Key header and 400/409/422 for an idempotent route (AC-36)', function () {
    Route::middleware(['api', RequireTenantToken::class, RequireIdempotencyKey::class])
        ->post('/api/v1/qa-openapi/idem', fn () => response()->json([]));
    app('router')->getRoutes()->refreshNameLookups();

    $doc = openApiBuild();
    $operation = $doc['paths']['/api/v1/qa-openapi/idem']['post'];

    expect($operation['responses'])->toHaveKeys(['400', '409', '422']);

    $headerParam = array_values(array_filter(
        $operation['parameters'],
        fn (array $p) => $p['name'] === 'Idempotency-Key'
    ))[0] ?? null;

    expect($headerParam)->not->toBeNull()
        ->and($headerParam['in'])->toBe('header')
        ->and($headerParam['required'])->toBeTrue();
});

it('builds byte-identically on two runs, and so do two command runs (AC-38)', function () {
    expect(json_encode(openApiBuild()))->toBe(json_encode(openApiBuild()));

    $first = Artisan::call('api:openapi') === 0 ? Artisan::output() : null;
    $second = Artisan::call('api:openapi') === 0 ? Artisan::output() : null;

    expect($first)->not->toBeNull()->toBe($second);
});

it('answers 404 envelope when disabled, and raw JSON when enabled (AC-39)', function () {
    config()->set('api.docs_enabled', false);
    $disabled = getJson('/api/v1/openapi.json');

    expect($disabled->getStatusCode())->toBe(404)
        ->and($disabled->json('success'))->toBeFalse();

    config()->set('api.docs_enabled', true);
    $enabled = getJson('/api/v1/openapi.json');

    expect($enabled->getStatusCode())->toBe(200)
        ->and($enabled->headers->get('Content-Type'))->toContain('application/json')
        ->and($enabled->json('openapi'))->toBe('3.1.0')
        ->and($enabled->json('success'))->toBeNull();
});

it('lists a route with no declarations, with SuccessEnvelope (AC-40)', function () {
    $doc = openApiBuild();

    expect($doc['paths']['/api/v1/demo/ping']['get']['responses']['200']['content']['application/json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/SuccessEnvelope']);
});

it('flags legacy tags but not demo', function () {
    $doc = openApiBuild();

    expect($doc['paths']['/api/v1/auth/login']['post']['x-flexipos-legacy-body'] ?? null)->toBeTrue()
        ->and($doc['paths']['/api/v1/demo/ping']['get']['x-flexipos-legacy-body'] ?? null)->toBeNull();
});
