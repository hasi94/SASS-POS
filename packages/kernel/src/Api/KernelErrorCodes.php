<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api;

/**
 * The kernel's own error codes (T-021 AC-9, AC-40a), registered by
 * {@see ApiServiceProvider} through the same single entry point every module
 * uses.
 */
final class KernelErrorCodes
{
    public const MODULE = 'kernel';

    public const VALIDATION_FAILED = 'kernel.validation_failed';

    public const UNAUTHENTICATED = 'kernel.unauthenticated';

    public const FORBIDDEN = 'kernel.forbidden';

    public const NOT_FOUND = 'kernel.not_found';

    public const METHOD_NOT_ALLOWED = 'kernel.method_not_allowed';

    public const RATE_LIMITED = 'kernel.rate_limited';

    public const SERVER_ERROR = 'kernel.server_error';

    public const SERVICE_UNAVAILABLE = 'kernel.service_unavailable';

    public const IDEMPOTENCY_KEY_REQUIRED = 'kernel.idempotency_key_required';

    public const IDEMPOTENCY_KEY_INVALID = 'kernel.idempotency_key_invalid';

    public const IDEMPOTENCY_KEY_REUSED = 'kernel.idempotency_key_reused';

    public const IDEMPOTENCY_IN_PROGRESS = 'kernel.idempotency_in_progress';

    public const BAD_REQUEST = 'kernel.bad_request';

    public const PAYLOAD_TOO_LARGE = 'kernel.payload_too_large';

    /**
     * @return list<ErrorCode>
     */
    public static function declarations(): array
    {
        return [
            new ErrorCode(self::VALIDATION_FAILED, 422, 'The request failed validation.'),
            new ErrorCode(self::UNAUTHENTICATED, 401, 'Authentication is required.'),
            new ErrorCode(self::FORBIDDEN, 403, 'This action is not permitted.'),
            new ErrorCode(self::NOT_FOUND, 404, 'The requested resource was not found.'),
            new ErrorCode(self::METHOD_NOT_ALLOWED, 405, 'This method is not allowed for this route.'),
            new ErrorCode(self::RATE_LIMITED, 429, 'Too many requests.'),
            new ErrorCode(self::SERVER_ERROR, 500, 'An unexpected error occurred.'),
            new ErrorCode(self::SERVICE_UNAVAILABLE, 503, 'The service is temporarily unavailable.'),
            new ErrorCode(self::IDEMPOTENCY_KEY_REQUIRED, 400, 'An Idempotency-Key header is required for this request.'),
            new ErrorCode(self::IDEMPOTENCY_KEY_INVALID, 400, 'The Idempotency-Key header is not valid.'),
            new ErrorCode(self::IDEMPOTENCY_KEY_REUSED, 422, 'This idempotency key was already used for a different request.'),
            new ErrorCode(self::IDEMPOTENCY_IN_PROGRESS, 409, 'A request with this idempotency key is already being processed.'),
            new ErrorCode(self::BAD_REQUEST, 400, 'The request could not be processed.'),
            new ErrorCode(self::PAYLOAD_TOO_LARGE, 413, 'The request payload is too large.'),
        ];
    }
}
