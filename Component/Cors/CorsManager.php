<?php

/**
 *      ****  *  *     *  ****  ****  *    *
 *      *  *  *  * *   *  *  *  *  *   *  *
 *      ****  *  *  *  *  *  *  *  *    *
 *      *     *  *   * *  *  *  *  *   *  *
 *      *     *  *    **  ****  ****  *    *
 * @author   Pinoox
 * @link https://www.pinoox.com/
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Component\Cors;

use Closure;
use InvalidArgumentException;
use Pinoox\Component\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Named CORS policies + header application for any HTTP response.
 */
class CorsManager
{
    public const DEFAULT_NAME = 'default';

    /** @var array<string, Closure> */
    private array $policies = [];

    private ?string $defaultPolicy = null;

    /**
     * @param Closure(): CorsPolicy $callback
     */
    public function define(string $name, Closure $callback): void
    {
        $this->policies[$name] = $callback;
    }

    /**
     * Register (or point to) the fallback policy used when none is named.
     *
     * @param Closure(): CorsPolicy|string $policy Callback or existing policy name
     */
    public function default(Closure|string $policy): void
    {
        if (is_string($policy)) {
            $this->defaultPolicy = $policy;

            return;
        }

        $this->define(self::DEFAULT_NAME, $policy);
        $this->defaultPolicy = self::DEFAULT_NAME;
    }

    public function defaultName(): ?string
    {
        return $this->defaultPolicy;
    }

    public function has(string $name): bool
    {
        return isset($this->policies[$name]);
    }

    /**
     * @return array<string, Closure>
     */
    public function policies(): array
    {
        return $this->policies;
    }

    public function resolve(?string $name = null): CorsPolicy
    {
        $name = $name ?: $this->defaultPolicy;

        if ($name === null || $name === '') {
            throw new InvalidArgumentException('No CORS policy name given and no default policy is configured.');
        }

        $callback = $this->policies[$name] ?? null;

        if ($callback === null) {
            throw new InvalidArgumentException(sprintf('CORS policy [%s] is not defined.', $name));
        }

        $policy = $callback();

        if (!$policy instanceof CorsPolicy) {
            throw new RuntimeException(sprintf('CORS policy [%s] must return a CorsPolicy instance.', $name));
        }

        return $policy;
    }

    /**
     * Apply CORS headers to a response for the given request and policy.
     */
    public function apply(
        Response $response,
        Request|SymfonyRequest|null $request = null,
        ?string $policy = null,
    ): Response {
        $request ??= Request::createFromGlobals();
        $resolved = $this->resolve($policy);
        $headers = $this->buildHeaders($resolved, $request);

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * Build a preflight (OPTIONS) response — controller must not run.
     */
    public function handlePreflight(
        Request|SymfonyRequest $request,
        ?string $policy = null,
    ): Response {
        $resolved = $this->resolve($policy);
        $response = new Response('', 204);

        foreach ($this->buildHeaders($resolved, $request, preflight: true) as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    public function buildHeaders(
        CorsPolicy $policy,
        Request|SymfonyRequest $request,
        bool $preflight = false,
    ): array {
        $origin = (string) $request->headers->get('Origin', '');
        $headers = [];

        if ($origin !== '' && !$this->validateOrigin($origin, $policy, $request)) {
            return $headers;
        }

        $allowedOrigin = $this->resolveAllowOrigin($origin, $policy, $request);

        if ($allowedOrigin !== null) {
            $headers['Access-Control-Allow-Origin'] = $allowedOrigin;
        }

        if ($policy->allowsCredentials() && $allowedOrigin !== null && $allowedOrigin !== '*') {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        if ($preflight) {
            $requestMethod = (string) $request->headers->get('Access-Control-Request-Method', '');
            $headers['Access-Control-Allow-Methods'] = $this->resolveAllowMethods($policy, $requestMethod);

            $requestHeaders = (string) $request->headers->get('Access-Control-Request-Headers', '');
            $headers['Access-Control-Allow-Headers'] = $this->resolveAllowHeaders($policy, $requestHeaders);

            if ($policy->getMaxAge() > 0) {
                $headers['Access-Control-Max-Age'] = (string) $policy->getMaxAge();
            }
        }

        $exposed = $policy->getExposedHeaders();
        if ($exposed !== [] && !$preflight) {
            $headers['Access-Control-Expose-Headers'] = implode(', ', $exposed);
        }

        if ($allowedOrigin !== null && $allowedOrigin !== '*') {
            $headers['Vary'] = $this->mergeVary($request, 'Origin');
        } elseif ($origin !== '') {
            $headers['Vary'] = $this->mergeVary($request, 'Origin');
        }

        return $headers;
    }

    public function validateOrigin(
        string $origin,
        CorsPolicy $policy,
        Request|SymfonyRequest|null $request = null,
    ): bool {
        if ($origin === '') {
            return false;
        }

        $allowed = $policy->origins();

        if ($allowed instanceof Closure) {
            return (bool) $allowed($origin, $request);
        }

        if (is_string($allowed)) {
            if ($allowed === '*') {
                return true;
            }

            $allowed = [$allowed];
        }

        foreach ($allowed as $pattern) {
            if ($this->originMatches((string) $pattern, $origin)) {
                return true;
            }
        }

        return false;
    }

    public function isPreflight(Request|SymfonyRequest $request): bool
    {
        return $request->getMethod() === 'OPTIONS'
            && $request->headers->has('Access-Control-Request-Method');
    }

    private function resolveAllowOrigin(
        string $origin,
        CorsPolicy $policy,
        Request|SymfonyRequest $request,
    ): ?string {
        $allowed = $policy->origins();

        if ($origin === '') {
            if (is_string($allowed) && $allowed === '*') {
                return $policy->allowsCredentials() ? null : '*';
            }

            if (is_array($allowed) && in_array('*', $allowed, true) && !$policy->allowsCredentials()) {
                return '*';
            }

            return null;
        }

        if (!$this->validateOrigin($origin, $policy, $request)) {
            return null;
        }

        if ($policy->allowsCredentials()) {
            return $origin;
        }

        if (is_string($allowed) && $allowed === '*') {
            return '*';
        }

        if (is_array($allowed) && in_array('*', $allowed, true) && count($allowed) === 1) {
            return '*';
        }

        return $origin;
    }

    private function resolveAllowMethods(CorsPolicy $policy, string $requestMethod): string
    {
        $methods = $policy->methods();

        if (in_array('*', $methods, true)) {
            if ($requestMethod !== '') {
                return strtoupper($requestMethod);
            }

            return 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD';
        }

        return implode(', ', $methods);
    }

    private function resolveAllowHeaders(CorsPolicy $policy, string $requestHeaders): string
    {
        $headers = $policy->headers();

        if (in_array('*', $headers, true)) {
            return $requestHeaders !== '' ? $requestHeaders : '*';
        }

        return implode(', ', $headers);
    }

    private function originMatches(string $pattern, string $origin): bool
    {
        if ($pattern === '*' || $pattern === $origin) {
            return true;
        }

        // Scheme-agnostic host wildcard: *.example.com
        if (str_starts_with($pattern, '*.')) {
            $suffix = substr($pattern, 1); // .example.com
            $host = parse_url($origin, PHP_URL_HOST) ?: '';

            return $host !== '' && (str_ends_with($host, $suffix) || $host === ltrim($suffix, '.'));
        }

        // Full-origin wildcard: https://*.example.com
        if (str_contains($pattern, '://*.')) {
            $regex = '/^' . str_replace('\*', '[a-z0-9-]+', preg_quote($pattern, '/')) . '$/i';

            return (bool) preg_match($regex, $origin);
        }

        return false;
    }

    private function mergeVary(Request|SymfonyRequest $request, string $value): string
    {
        $existing = $request->headers->get('Vary', '');
        $parts = array_filter(array_map('trim', explode(',', $existing . ',' . $value)));

        return implode(', ', array_unique($parts));
    }
}
