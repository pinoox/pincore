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

namespace Pinoox\Flow;

use Closure;
use Pinoox\Component\Flow\Flow;
use Pinoox\Component\Http\Request;
use Pinoox\Component\RateLimiter\Limit;
use Pinoox\Component\RateLimiter\RateLimiter as RateLimiterComponent;
use Pinoox\Component\RateLimiter\Unlimited;
use Pinoox\Portal\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Enforce a named rate limiter on HTTP routes.
 *
 * Alias: throttle:api  →  ThrottleFlow::for('api')
 * Or:    new ThrottleFlow('api')
 */
class ThrottleFlow extends Flow
{
    private string $limiter;

    private ?RateLimiterComponent $rateLimiter;

    /**
     * Compatible with FlowManager (`new $class($requestEvent)`) and
     * parameterized aliases (`throttle:api` → `new ThrottleFlow('api', $event)`).
     */
    public function __construct(
        string|RequestEvent|null $limiterOrEvent = 'default',
        ?RequestEvent $requestEvent = null,
        ?RateLimiterComponent $rateLimiter = null,
    ) {
        $this->rateLimiter = $rateLimiter;

        if ($limiterOrEvent instanceof RequestEvent) {
            $this->limiter = 'default';
            parent::__construct($limiterOrEvent);

            return;
        }

        $this->limiter = $limiterOrEvent ?? 'default';
        parent::__construct($requestEvent);
    }

    public static function for(string $limiter, ?RateLimiterComponent $rateLimiter = null): self
    {
        return new self($limiter, null, $rateLimiter);
    }

    public function limiter(): string
    {
        return $this->limiter;
    }

    protected function handle(Request $request, Closure $next)
    {
        $rates = $this->rates();
        $limits = $rates->resolve($this->limiter, $request);

        foreach ($limits as $limit) {
            if ($limit instanceof Unlimited) {
                continue;
            }

            if (!$limit instanceof Limit) {
                continue;
            }

            $key = $this->resolveKey($limit, $request);

            if ($rates->tooManyAttempts($key, $limit->maxAttempts())) {
                return $this->buildResponse($request, $limit, $key, $rates);
            }

            $rates->hit($key, $limit->decaySeconds());
        }

        $response = $next($request);

        return $this->addHeadersToResponse($response, $limits, $request, $rates);
    }

    private function rates(): RateLimiterComponent
    {
        return $this->rateLimiter ?? RateLimiter::___();
    }

    private function resolveKey(Limit $limit, Request $request): string
    {
        $by = $limit->key();

        if ($by === '') {
            $by = (string) ($request->getClientIp() ?: 'unknown');
        }

        return $this->limiter . '|' . $by;
    }

    private function buildResponse(
        Request $request,
        Limit $limit,
        string $key,
        RateLimiterComponent $rates,
    ): mixed {
        $retryAfter = $rates->availableIn($key);
        $remaining = 0;
        $headers = $this->rateLimitHeaders($limit, $remaining, $retryAfter);

        if ($callback = $limit->responseCallback()) {
            $response = $callback($request, $limit, $retryAfter);

            return $this->mergeHeaders($response, $headers);
        }

        $payload = [
            'message' => $limit->getMessage(),
        ];

        if ($this->wantsJson($request)) {
            return response()->json($payload, $limit->getStatus(), $headers);
        }

        return response($limit->getMessage(), $limit->getStatus(), $headers);
    }

    /**
     * @param list<Limit|Unlimited> $limits
     */
    private function addHeadersToResponse(
        mixed $response,
        array $limits,
        Request $request,
        RateLimiterComponent $rates,
    ): mixed {
        foreach ($limits as $limit) {
            if (!$limit instanceof Limit) {
                continue;
            }

            $key = $this->resolveKey($limit, $request);
            $headers = $this->rateLimitHeaders(
                $limit,
                $rates->remaining($key, $limit->maxAttempts()),
                $rates->availableIn($key),
            );

            return $this->mergeHeaders($response, $headers);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function rateLimitHeaders(Limit $limit, int $remaining, int $retryAfter): array
    {
        $headers = array_merge([
            'X-RateLimit-Limit' => (string) $limit->maxAttempts(),
            'X-RateLimit-Remaining' => (string) max(0, $remaining),
        ], $limit->getHeaders());

        if ($retryAfter > 0) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    private function mergeHeaders(mixed $response, array $headers): mixed
    {
        if ($response instanceof Response) {
            foreach ($headers as $name => $value) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function wantsJson(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api')
            || str_contains(strtolower($request->headers->get('Accept', '')), 'json')
            || $request->isXmlHttpRequest();
    }
}
