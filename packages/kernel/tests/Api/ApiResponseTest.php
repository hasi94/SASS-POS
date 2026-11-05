<?php

use Flexipos\Kernel\Api\ApiError;
use Flexipos\Kernel\Api\ApiResponse;
use Flexipos\Kernel\Api\KernelErrorCodes;
use Flexipos\Kernel\Http\RequestId;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/*
 * T-021 AC-1 to AC-5, AC-15: the envelope helper.
 */

uses(TestCase::class);

beforeEach(function () {
    app()->instance(RequestId::class, RequestId::fixed('qa-request-id'));
});

it('has exactly five keys, in order, on success (AC-1)', function () {
    $response = ApiResponse::success(['x' => 1]);
    $body = json_decode($response->getContent(), true);

    expect(array_keys($body))->toBe(['success', 'data', 'message', 'errors', 'meta'])
        ->and($body['success'])->toBeTrue()
        ->and($body['errors'])->toBe([])
        ->and($body['meta']['request_id'])->toBeString()
        ->and($body['data'])->toBe(['x' => 1]);
});

it('answers data null and message null with no payload (AC-2)', function () {
    $response = ApiResponse::success();
    $body = json_decode($response->getContent(), true);

    expect($body['data'])->toBeNull()
        ->and($body['message'])->toBeNull()
        ->and($response->getStatusCode())->toBe(200);
});

it('error items carry exactly code, field, detail; field is null when absent (AC-3)', function () {
    $response = ApiResponse::failure(KernelErrorCodes::NOT_FOUND);
    $body = json_decode($response->getContent(), true);

    expect($body['success'])->toBeFalse()
        ->and($body['data'])->toBeNull()
        ->and($body['errors'])->toHaveCount(1)
        ->and(array_keys($body['errors'][0]))->toBe(['code', 'field', 'detail'])
        ->and($body['errors'][0]['field'])->toBeNull();
});

it('meta.request_id equals RequestId::current() for the bound resolver (AC-4)', function () {
    app()->instance(RequestId::class, RequestId::fixed('qa-fixed-id'));

    $response = ApiResponse::success(['x' => 1]);
    $body = json_decode($response->getContent(), true);

    expect($body['meta']['request_id'])->toBe('qa-fixed-id');
});

it('success() refuses a status other than 200 or 201 (AC-5)', function () {
    expect(fn () => ApiResponse::success(null, null, 400))->toThrow(InvalidArgumentException::class);
    expect(fn () => ApiResponse::success(null, null, 500))->toThrow(InvalidArgumentException::class);
});

it('created() answers 201 (AC-5)', function () {
    $response = ApiResponse::created(['id' => 1]);

    expect($response->getStatusCode())->toBe(201);
});

it('failure() takes the catalogue status for the code (AC-5)', function () {
    $response = ApiResponse::failure(KernelErrorCodes::FORBIDDEN);

    expect($response->getStatusCode())->toBe(403);
});

it('logs one info line on failure with no detail text in it', function () {
    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'API request refused.'
                && ! str_contains((string) json_encode($context), 'a secret path');
        });

    ApiResponse::failure(KernelErrorCodes::NOT_FOUND, 'a secret path /api/v1/x/abc123');
});

it('failures() uses the first code for the status and lists every error', function () {
    $response = ApiResponse::failures([
        new ApiError(KernelErrorCodes::VALIDATION_FAILED, 'first', 'a'),
        new ApiError(KernelErrorCodes::VALIDATION_FAILED, 'second', 'b'),
    ]);

    $body = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(422)
        ->and($body['errors'])->toHaveCount(2);
});
