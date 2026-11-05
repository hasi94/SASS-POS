<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\OpenApi\OpenApiDocument;
use Flexipos\Kernel\Api\ErrorCatalogue;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /api/v1/openapi.json` (T-021 Ruling 8, D-11, AC-39).
 *
 * Off by default: `API_DOCS_ENABLED` must be true, or this answers 404,
 * rendered as the failure envelope by the generic renderer - a production
 * API map is reconnaissance for an attacker and has no end-user value.
 *
 * **Not wrapped in the envelope when enabled.** The document is the body;
 * wrapping it in `data` would mean no committed copy and no client could
 * agree on what "the document" is without unwrapping it first.
 */
final class OpenApiController extends Controller
{
    public function __invoke(Router $router, ErrorCatalogue $catalogue, ConfigRepository $config): JsonResponse
    {
        if (! $config->get('api.docs_enabled')) {
            throw new NotFoundHttpException;
        }

        return response()->json(OpenApiDocument::build($router, $catalogue));
    }
}
