<?php

use App\Support\OpenApi\OpenApiDocument;
use Flexipos\Kernel\Api\ErrorCatalogue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/*
 * T-021 AC-37: the committed copy is the drift gate. A route under api/v1
 * missing from the committed document (or the reverse) fails this test,
 * naming the route - and this runs in `composer check`.
 */

/**
 * `METHOD /path` for every operation a document declares.
 *
 * @param  array<string, mixed>  $document
 * @return list<string>
 */
function openApiCompletenessOperations(array $document): array
{
    $operations = [];

    foreach ($document['paths'] as $path => $methods) {
        foreach (array_keys($methods) as $method) {
            $operations[] = strtoupper($method).' '.$path;
        }
    }

    sort($operations);

    return $operations;
}

it('matches the generator exactly, over the committed demo-module boot', function () {
    /** @var TestCase $this */
    $this->bootHostWith('demo');

    $generated = OpenApiDocument::build(app('router'), app(ErrorCatalogue::class));

    $committedPath = dirname(base_path(), 2).'/docs/api/openapi.v1.json';
    $committed = json_decode(
        str_replace("\r\n", "\n", (string) file_get_contents($committedPath)),
        true
    );

    $generatedOps = openApiCompletenessOperations($generated);
    $committedOps = openApiCompletenessOperations($committed);

    $missingFromDocument = array_values(array_diff($generatedOps, $committedOps));
    $missingFromRoutes = array_values(array_diff($committedOps, $generatedOps));

    expect($missingFromDocument)->toBe([], 'Routes missing from the committed document: '.implode(', ', $missingFromDocument));
    expect($missingFromRoutes)->toBe([], 'Document entries with no matching route: '.implode(', ', $missingFromRoutes));

    expect(json_encode($generated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n")
        ->toBe(str_replace("\r\n", "\n", (string) file_get_contents($committedPath)));
});

it('reports a scratch route planted by the test as missing from the document (control)', function () {
    /** @var TestCase $this */
    $this->bootHostWith('demo');

    Route::middleware('api')->get('/api/v1/qa-drift/planted', fn () => response()->json([]));
    app('router')->getRoutes()->refreshNameLookups();

    $generated = OpenApiDocument::build(app('router'), app(ErrorCatalogue::class));

    $committedPath = dirname(base_path(), 2).'/docs/api/openapi.v1.json';
    $committed = json_decode(
        str_replace("\r\n", "\n", (string) file_get_contents($committedPath)),
        true
    );

    $missingFromDocument = array_values(array_diff(
        openApiCompletenessOperations($generated),
        openApiCompletenessOperations($committed)
    ));

    expect($missingFromDocument)->toContain('GET /api/v1/qa-drift/planted');
});
