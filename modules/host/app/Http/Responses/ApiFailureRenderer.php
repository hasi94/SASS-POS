<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Flexipos\Kernel\Api\ApiError;
use Flexipos\Kernel\Api\ApiResponse;
use Flexipos\Kernel\Api\KernelErrorCodes;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The one generic `api/*` failure renderer (T-021 Ruling 4, AC-6 to AC-8,
 * AC-48, AC-49).
 *
 * Registered **last**, so every existing render callback
 * (`PlanLimitReached`, `TenantSchemaBehind`, `RequiredSchemaVersionNotConfigured`)
 * and every controller/middleware body (`AuthRefusals`, `AccessRefusals`,
 * `OrganizationRefusals`) keeps answering first and byte-identically
 * (AC-48). This is only reached for a failure no existing renderer already
 * handles.
 *
 * **Never includes debug detail, even with `APP_DEBUG=true`.** The real
 * exception is logged by the framework's own reporting pipeline, tagged
 * with the request id through `bootstrap/app.php`'s `$exceptions->context()`
 * - that is where the detail goes (AC-8).
 */
final class ApiFailureRenderer
{
    public static function render(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return self::validation($e);
        }

        if ($e instanceof AuthenticationException) {
            return ApiResponse::failure(KernelErrorCodes::UNAUTHENTICATED);
        }

        if ($e instanceof HttpExceptionInterface) {
            return self::http($e);
        }

        return ApiResponse::failure(KernelErrorCodes::SERVER_ERROR);
    }

    /**
     * AC-7: one error item per invalid field, in dot notation, carrying that
     * field's first message.
     */
    private static function validation(ValidationException $e): JsonResponse
    {
        $errors = [];

        foreach ($e->errors() as $field => $messages) {
            $errors[] = new ApiError(KernelErrorCodes::VALIDATION_FAILED, $messages[0] ?? null, $field);
        }

        return ApiResponse::failures($errors);
    }

    private static function http(HttpExceptionInterface $e): JsonResponse
    {
        $headers = $e->getHeaders();
        $status = $e->getStatusCode();

        $code = match ($status) {
            401 => KernelErrorCodes::UNAUTHENTICATED,
            403 => KernelErrorCodes::FORBIDDEN,
            404 => KernelErrorCodes::NOT_FOUND,
            405 => KernelErrorCodes::METHOD_NOT_ALLOWED,
            413 => KernelErrorCodes::PAYLOAD_TOO_LARGE,
            422 => KernelErrorCodes::VALIDATION_FAILED,
            429 => KernelErrorCodes::RATE_LIMITED,
            503 => KernelErrorCodes::SERVICE_UNAVAILABLE,
            default => null,
        };

        if ($code !== null) {
            return ApiResponse::failure($code, headers: $headers);
        }

        // Any other 4xx collapses to 400 and any other 5xx to 500, so the
        // envelope's status always equals the catalogue's status for the
        // code it carries (AC-5, AC-40a).
        $code = $status >= 500 ? KernelErrorCodes::SERVER_ERROR : KernelErrorCodes::BAD_REQUEST;

        return ApiResponse::failure($code, headers: $headers);
    }
}
