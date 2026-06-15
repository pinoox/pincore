<?php

namespace Pinoox\Component\Kernel\Controller;

use Pinoox\Component\Http\Api\ApiResource;
use Pinoox\Component\Http\Api\ApiResponse;
use Pinoox\Component\Http\JsonResponse;
use Pinoox\Component\Http\Request;
use Pinoox\Component\Http\ResponseException;
use Pinoox\Component\Validation\ValidationException;

/**
 * Base controller for JSON API endpoints.
 *
 * Envelope:
 * - success: { success, data, message, meta }
 * - error:   { success, error: { code, message, details } }
 *
 * Helpers:
 * - ok() / fail() / resource() — standard envelope
 * - message() / error() — manager-style flash responses (legacy-compatible)
 * - deny() — soft failure (HTTP 200, data:false, error toast)
 * - validated() — validate request or abort with JSON error
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

    /**
     * Validate request input and return validated data, or abort with a JSON error response.
     */
    protected function validated(Request $request, array $rules, array $messages = [], array $attributes = []): array
    {
        try {
            return $request->validate($rules, $messages, $attributes);
        } catch (ValidationException $e) {
            throw ResponseException::init($this->error($e->first()));
        }
    }

    /**
     * Flash-style API response for SPA panels.
     *
     * - message('lang.key') — success toast
     * - message('lang.key', $data) — success toast + payload
     * - message(null, $data) — payload only (no toast)
     * - message('lang.key', false) — soft error toast (HTTP 200, data:false)
     */
    protected function message(mixed $message, mixed $result = null, ?int $code = 200, bool $exception = false): JsonResponse
    {
        $response = $this->buildMessageResponse($message, $result, $code, func_num_args() >= 2);

        if ($exception) {
            throw ResponseException::init($response);
        }

        return $response;
    }

    /**
     * Soft failure — HTTP 200 with data:false; shows an error toast in manager UI.
     */
    protected function deny(mixed $message, int $code = 200, bool $exception = false): JsonResponse
    {
        return $this->message($message, false, $code, $exception);
    }

    /**
     * HTTP error envelope (success:false).
     */
    protected function error(mixed $error, ?int $code = 422, bool $exception = false): JsonResponse
    {
        $response = $this->buildErrorResponse($error, $code);

        if ($exception) {
            throw ResponseException::init($response);
        }

        return $response;
    }

    private function buildMessageResponse(mixed $message, mixed $result, int $code, bool $hasSecondArg): JsonResponse
    {
        if ($hasSecondArg && $result !== null) {
            if ($result === false) {
                return ApiResponse::success(
                    false,
                    is_string($message) ? $message : null,
                    [],
                    $code,
                    is_string($message),
                );
            }

            return ApiResponse::success(
                $result,
                is_string($message) ? $message : null,
                [],
                $code,
                is_string($message),
            );
        }

        if (is_string($message)) {
            return ApiResponse::success(null, $message, [], $code, true);
        }

        return ApiResponse::success($message, null, [], $code, false);
    }

    private function buildErrorResponse(mixed $error, int $code): JsonResponse
    {
        if (is_string($error)) {
            return ApiResponse::error('API_ERROR', $error, [], $code, true);
        }

        if (is_array($error)) {
            return ApiResponse::error('API_ERROR', 'API_ERROR', $error, $code, false);
        }

        return ApiResponse::error('API_ERROR', (string) $error, [], $code, false);
    }
}
