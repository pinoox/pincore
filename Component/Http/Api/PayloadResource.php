<?php

namespace Pinoox\Component\Http\Api;

use Pinoox\Component\Http\Request;

/**
 * Wraps array/object payloads for API responses.
 */
final class PayloadResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        if (is_array($this->resource)) {
            return $this->resource;
        }

        if (is_object($this->resource) && method_exists($this->resource, 'toArray')) {
            return $this->resource->toArray();
        }

        return ['value' => $this->resource];
    }
}

