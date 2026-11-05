<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api;

use Flexipos\Kernel\Http\RequestId;
use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The one envelope helper every module calls instead of hand-writing a
 * response body (T-021 Ruling 3, AC-1 to AC-5, AC-15).
 *
 * Static, and resolving the catalogue and the request id from the container
 * it is called in, so module code never has to inject anything to use it.
 */
final class ApiResponse
{
    /**
     * @param  mixed  $data
     */
    public static function success($data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        if ($status !== 200 && $status !== 201) {
            throw new InvalidArgumentException("ApiResponse::success() status must be 200 or 201, got [{$status}].");
        }

        return new JsonResponse([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'errors' => [],
            'meta' => ['request_id' => self::requestId()],
        ], $status);
    }

    /**
     * @param  mixed  $data
     */
    public static function created($data = null, ?string $message = null): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function failure(string $code, ?string $detail = null, ?string $field = null, array $headers = []): JsonResponse
    {
        return self::failures([new ApiError($code, $detail, $field)], $headers);
    }

    /**
     * @param  list<ApiError>  $errors
     * @param  array<string, string>  $headers
     */
    public static function failures(array $errors, array $headers = []): JsonResponse
    {
        $catalogue = self::catalogue();
        $first = $catalogue->resolve($errors[0]->code);

        $items = [];
        $codes = [];

        foreach ($errors as $error) {
            $entry = $catalogue->resolve($error->code);
            $items[] = [
                'code' => $error->code,
                'field' => $error->field,
                'detail' => $error->detail ?? $entry->message,
            ];
            $codes[] = $error->code;
        }

        Log::info('API request refused.', [
            'codes' => $codes,
            'status' => $first->status,
            'request_id' => self::requestId(),
        ]);

        return new JsonResponse([
            'success' => false,
            'data' => null,
            'message' => $first->message,
            'errors' => $items,
            'meta' => ['request_id' => self::requestId()],
        ], $first->status, $headers);
    }

    private static function catalogue(): ErrorCatalogue
    {
        return Container::getInstance()->make(ErrorCatalogue::class);
    }

    private static function requestId(): ?string
    {
        return Container::getInstance()->make(RequestId::class)->current();
    }
}
