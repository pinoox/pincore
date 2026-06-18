<?php

namespace Pinoox\Component\Kernel\Controller;

use Pinoox\Component\Http\Api\ApiResource;
use Pinoox\Component\Http\Api\ApiResponse;
use Pinoox\Component\Http\JsonResponse;
use Pinoox\Component\Http\Request;

/**
 * Base controller for JSON API endpoints.
 *
 * Envelope:
 * - success: { success, data, message, meta }
 * - error:   { success, error: { code, message, details } }
 */
abstract class ApiController extends Controller
{
    protected function ok(
        mixed $data = null,
        ?string $message = null,
        array $meta = [],
        int $status = 200,
        bool $translate = false,
    ): JsonResponse {
        return ApiResponse::success($data, $message, $meta, $status, $translate);
    }

    protected function fail(
        string $code,
        ?string $message = null,
        array $details = [],
        int $status = 400,
        bool $translate = true,
    ): JsonResponse {
        return ApiResponse::error($code, $message, $details, $status, $translate);
    }

    protected function resource(ApiResource $resource, ?string $message = null, array $meta = [], int $status = 200): JsonResponse
    {
        return $this->ok($resource, $message, $meta, $status);
    }

    protected function message(mixed $messageOrData = null, mixed $data = null): JsonResponse
    {
        if (is_array($messageOrData)) {
            return $this->ok($messageOrData, translate: true);
        }

        if ($data !== null) {
            return ApiResponse::success($data, is_string($messageOrData) ? $messageOrData : null, translate: true);
        }

        return ApiResponse::success(null, is_string($messageOrData) ? $messageOrData : null, translate: true);
    }

    protected function error(string $message, int $status = 400): JsonResponse
    {
        return ApiResponse::error('API_ERROR', $message, status: $status, translate: true);
    }

    protected function deny(string $message, int $status = 403): JsonResponse
    {
        return ApiResponse::error('ACCESS_DENIED', $message, status: $status, translate: true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, array $rules): array
    {
        return $request->validate($rules);
    }
}

