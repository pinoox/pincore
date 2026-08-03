<?php

namespace Pinoox\Component\Kernel\Controller;

use Illuminate\Pagination\AbstractPaginator;
use Pinoox\Component\Http\Api\ApiResource;
use Pinoox\Component\Http\Api\ApiResponse;
use Pinoox\Component\Http\Api\ResourceCollection;
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

    /**
     * Respond with a resource (single item or collection).
     *
     * Accepts: ApiResource, ResourceCollection, or ResourceCollection from paginator().
     */
    protected function resource(ApiResource|ResourceCollection $resource, ?string $message = null, array $meta = [], int $status = 200): JsonResponse
    {
        if ($resource instanceof ResourceCollection && !empty($meta)) {
            $content = $resource->toArray();
            if (is_array($content) && isset($content['meta'])) {
                $mergedMeta = array_merge($content['meta'], $meta);
                return ApiResponse::success(
                    ['data' => $content['data'] ?? [], 'meta' => $mergedMeta],
                    $message,
                    [],
                    $status
                );
            }
        }

        return ApiResponse::success($resource, $message, $meta, $status);
    }

    /**
     * Respond with a paginated resource collection.
     *
     * Usage:
     *   $tasks = Task::paginate($perPage);
     *   return $this->paginator($tasks, TaskResource::class);
     */
    protected function paginator(AbstractPaginator $paginator, string $resourceClass, ?string $message = null, int $status = 200): JsonResponse
    {
        $result = ApiResource::paginator($paginator, $resourceClass);

        return ApiResponse::success(
            ['data' => $result['data'], 'meta' => $result['meta']],
            $message,
            [],
            $status
        );
    }

    /**
     * Respond with a collection of resources.
     *
     * Usage:
     *   return $this->collection($tasks, TaskResource::class);
     */
    protected function collection(iterable $items, string $resourceClass, ?string $message = null, int $status = 200): JsonResponse
    {
        $data = ApiResource::collection($items, $resourceClass);

        return ApiResponse::success(['data' => $data], $message, [], $status);
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

