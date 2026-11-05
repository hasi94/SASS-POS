<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Middleware\RequirePlatformToken;
use App\Http\Middleware\RequireTenantToken;
use App\Http\Middleware\RequireTerminalToken;
use Flexipos\Kernel\Api\ErrorCatalogue;
use Flexipos\Kernel\Api\ErrorCode;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * A pure, deterministic OpenAPI 3.1 document, built from the booted
 * routing table and the error-code catalogue (T-021 Ruling 8).
 *
 * **Pure.** `build()` takes the router and the catalogue as arguments and
 * returns an array; it opens no connection and reads no request. That is
 * what makes `OpenApiCompletenessTest` meaningful and `AC-38`'s
 * byte-stability provable: the same routing table always produces the
 * same bytes.
 */
final class OpenApiDocument
{
    /**
     * Tags whose operations still answer a legacy, non-envelope body
     * (D-1, FP-369): marked `x-flexipos-legacy-body: true` so the document
     * never claims they return the envelope.
     *
     * @var list<string>
     */
    public const LEGACY_BODY_TAGS = ['access', 'auth', 'organization', 'terminal'];

    private const METHOD_ORDER = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * @return array<string, mixed>
     */
    public static function build(Router $router, ErrorCatalogue $catalogue): array
    {
        $paths = [];
        $tags = [];

        foreach (self::apiRoutes($router) as $route) {
            $uri = '/'.self::normalisedUri($route->uri());
            $tag = self::tagFor($route->uri());
            $tags[$tag] = true;

            $paths[$uri] ??= [];

            foreach (self::methodsOf($route) as $method) {
                $paths[$uri][$method] = self::operation($route, $method, $tag);
            }
        }

        ksort($paths);

        foreach (array_keys($paths) as $path) {
            $ordered = [];

            foreach (self::METHOD_ORDER as $method) {
                if (isset($paths[$path][$method])) {
                    $ordered[$method] = $paths[$path][$method];
                }
            }

            $paths[$path] = $ordered;
        }

        $tagList = array_keys($tags);
        sort($tagList);

        $codes = self::errorCodes($catalogue);

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'FlexiPOS API', 'version' => 'v1'],
            'servers' => [['url' => '/']],
            'tags' => array_map(static fn (string $tag): array => ['name' => $tag], $tagList),
            'paths' => $paths === [] ? (object) [] : $paths,
            'components' => [
                'schemas' => self::schemas(array_column($codes, 'code')),
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
                ],
            ],
            'x-error-codes' => $codes,
        ];
    }

    /**
     * Every route registered by a booted provider or module whose URI
     * starts with `api/v1/`, sorted by URI then by method for a
     * deterministic walk (ksort() on $paths handles the final order;
     * this sort only makes the walk itself reproducible).
     *
     * @return list<Route>
     */
    private static function apiRoutes(Router $router): array
    {
        /** @var list<Route> $routes */
        $routes = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/')) {
                $routes[] = $route;
            }
        }

        usort($routes, static fn (Route $a, Route $b): int => $a->uri() <=> $b->uri());

        return $routes;
    }

    private static function normalisedUri(string $uri): string
    {
        return (string) preg_replace('/\{([a-zA-Z0-9_]+)\?\}/', '{$1}', $uri);
    }

    /**
     * The first URI segment after `api/v1/` - unless the URI has only one
     * segment there at all (today, only the docs route), which is tagged
     * `kernel` instead (Ruling 8).
     */
    private static function tagFor(string $uri): string
    {
        $rest = substr($uri, strlen('api/v1/'));
        $segments = explode('/', $rest);

        return count($segments) > 1 ? $segments[0] : 'kernel';
    }

    /**
     * @return list<string>
     */
    private static function methodsOf(Route $route): array
    {
        $methods = array_map(strtolower(...), $route->methods());

        return array_values(array_diff($methods, ['head']));
    }

    /**
     * @return array<string, mixed>
     */
    private static function operation(Route $route, string $method, string $tag): array
    {
        $uri = self::normalisedUri($route->uri());
        $operationId = $method.'_'.(string) preg_replace('/[^a-z0-9]+/', '_', $uri);
        $operationId = trim($operationId, '_');

        $middleware = $route->gatherMiddleware();
        $authenticated = self::isAuthenticated($middleware);
        $idempotent = in_array(RequireIdempotencyKey::class, $middleware, true);

        $operation = [
            'operationId' => $operationId,
            'tags' => [$tag],
            'security' => $authenticated ? [['bearerAuth' => []]] : [],
            'parameters' => self::pathParameters($uri),
            'responses' => self::responses($method, $uri, $authenticated, $idempotent),
        ];

        if ($idempotent) {
            $operation['parameters'][] = [
                'name' => 'Idempotency-Key',
                'in' => 'header',
                'required' => true,
                'schema' => ['type' => 'string'],
            ];
        }

        if (in_array($tag, self::LEGACY_BODY_TAGS, true)) {
            $operation['x-flexipos-legacy-body'] = true;
            $operation['description'] = 'This operation predates the T-021 envelope and still answers its own legacy body (FP-369).';
        }

        return $operation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function pathParameters(string $uri): array
    {
        $parameters = [];

        foreach (self::pathParameterNames($uri) as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ];
        }

        return $parameters;
    }

    /**
     * @return list<string>
     */
    private static function pathParameterNames(string $uri): array
    {
        preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $uri, $matches);

        return $matches[1];
    }

    /**
     * @param  list<mixed>  $middleware
     */
    private static function isAuthenticated(array $middleware): bool
    {
        foreach ([RequireTenantToken::class, RequireTerminalToken::class, RequirePlatformToken::class] as $tokenMiddleware) {
            if (in_array($tokenMiddleware, $middleware, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    private static function responses(string $method, string $uri, bool $authenticated, bool $idempotent): array
    {
        $responses = [
            '200' => [],
        ];

        $responses['200'] = [
            'description' => 'Success',
            'content' => [
                'application/json' => ['schema' => ['$ref' => '#/components/schemas/SuccessEnvelope']],
            ],
        ];

        if ($authenticated) {
            $responses['401'] = self::errorResponse('Unauthenticated');
            $responses['403'] = self::errorResponse('Forbidden');
        }

        if (in_array($method, ['post', 'put', 'patch'], true) || self::pathParameterNames($uri) !== []) {
            $responses['422'] = self::errorResponse('Validation failed');
        }

        $responses['429'] = self::errorResponse('Too many requests');

        if ($idempotent) {
            $responses['400'] = self::errorResponse('Idempotency key missing or invalid');
            $responses['409'] = self::errorResponse('A request with this idempotency key is in progress');
            $responses['422'] = self::errorResponse('Validation failed, or the idempotency key was reused with a different request');
        }

        ksort($responses);

        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorEnvelope']],
            ],
        ];
    }

    /**
     * @param  list<string>  $codes  every catalogue code, for ErrorItem.code's enum
     * @return array<string, mixed>
     */
    private static function schemas(array $codes): array
    {
        return [
            'SuccessEnvelope' => [
                'type' => 'object',
                'required' => ['success', 'data', 'message', 'errors', 'meta'],
                'properties' => [
                    'success' => ['type' => 'boolean', 'enum' => [true]],
                    'data' => (object) [],
                    'message' => ['type' => ['string', 'null']],
                    'errors' => ['type' => 'array', 'items' => (object) [], 'maxItems' => 0],
                    'meta' => [
                        'type' => 'object',
                        'properties' => ['request_id' => ['type' => ['string', 'null']]],
                    ],
                ],
            ],
            'ErrorEnvelope' => [
                'type' => 'object',
                'required' => ['success', 'data', 'message', 'errors', 'meta'],
                'properties' => [
                    'success' => ['type' => 'boolean', 'enum' => [false]],
                    'data' => ['type' => 'null'],
                    'message' => ['type' => ['string', 'null']],
                    'errors' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ErrorItem']],
                    'meta' => [
                        'type' => 'object',
                        'properties' => ['request_id' => ['type' => ['string', 'null']]],
                    ],
                ],
            ],
            'ErrorItem' => [
                'type' => 'object',
                'required' => ['code', 'field', 'detail'],
                'properties' => [
                    'code' => ['type' => 'string', 'enum' => $codes],
                    'field' => ['type' => ['string', 'null']],
                    'detail' => ['type' => ['string', 'null']],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function errorCodes(ErrorCatalogue $catalogue): array
    {
        return array_map(
            static fn (ErrorCode $entry): array => [
                'code' => $entry->code,
                'module' => $catalogue->moduleOf($entry->code) ?? '',
                'status' => $entry->status,
                'message' => $entry->message,
            ],
            $catalogue->all()
        );
    }
}
