<?php

namespace Pinoox\Component\Cors;

use Closure;

/**
 * Fluent CORS policy definition.
 */
class CorsPolicy
{
    private mixed $origins = [];

    /** @var list<string> */
    private array $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /** @var list<string> */
    private array $headers = ['*'];

    /** @var list<string> */
    private array $exposedHeaders = [];

    private bool $credentials = false;

    private int $maxAge = 0;

    public static function make(): self
    {
        return new self();
    }

    /**
     * @param list<string>|string|Closure $origins
     */
    public function allowOrigins(array|string|Closure $origins): self
    {
        $this->origins = $origins;

        return $this;
    }

    /**
     * @param list<string>|string $methods
     */
    public function allowMethods(array|string $methods): self
    {
        if (is_string($methods)) {
            $methods = $methods === '*'
                ? ['*']
                : (preg_split('/\s*,\s*/', $methods) ?: []);
        }

        $this->methods = array_values(array_map(
            static fn (string $method): string => strtoupper(trim($method)),
            $methods,
        ));

        return $this;
    }

    /**
     * @param list<string>|string $headers
     */
    public function allowHeaders(array|string $headers): self
    {
        if (is_string($headers)) {
            $headers = $headers === '*'
                ? ['*']
                : (preg_split('/\s*,\s*/', $headers) ?: []);
        }

        $this->headers = array_values(array_map(
            static fn (string $header): string => trim($header),
            $headers,
        ));

        return $this;
    }

    /**
     * @param list<string>|string $headers
     */
    public function exposeHeaders(array|string $headers): self
    {
        if (is_string($headers)) {
            $headers = preg_split('/\s*,\s*/', $headers) ?: [];
        }

        $this->exposedHeaders = array_values(array_map(
            static fn (string $header): string => trim($header),
            $headers,
        ));

        return $this;
    }

    public function allowCredentials(bool $allow = true): self
    {
        $this->credentials = $allow;

        return $this;
    }

    public function maxAge(int $seconds): self
    {
        $this->maxAge = max(0, $seconds);

        return $this;
    }

    public function origins(): mixed
    {
        return $this->origins;
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return list<string>
     */
    public function getExposedHeaders(): array
    {
        return $this->exposedHeaders;
    }

    public function allowsCredentials(): bool
    {
        return $this->credentials;
    }

    public function getMaxAge(): int
    {
        return $this->maxAge;
    }
}
