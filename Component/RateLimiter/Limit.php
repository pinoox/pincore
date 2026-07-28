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

namespace Pinoox\Component\RateLimiter;

use Closure;

/**
 * Fluent rate-limit definition returned from RateLimiter::define() callbacks.
 */
class Limit
{
    private string $key = '';

    private ?Closure $responseCallback = null;

    private string $message = 'Too Many Requests.';

    private int $status = 429;

    /** @var array<string, string> */
    private array $responseHeaders = [];

    public function __construct(
        private int $maxAttempts,
        private int $decaySeconds = 60,
    ) {
    }

    public static function perSecond(int $maxAttempts): self
    {
        return new self($maxAttempts, 1);
    }

    public static function perMinute(int $maxAttempts): self
    {
        return new self($maxAttempts, 60);
    }

    public static function perMinutes(int $decayMinutes, int $maxAttempts): self
    {
        return new self($maxAttempts, max(1, $decayMinutes) * 60);
    }

    public static function perHour(int $maxAttempts): self
    {
        return new self($maxAttempts, 3600);
    }

    public static function perDay(int $maxAttempts): self
    {
        return new self($maxAttempts, 86400);
    }

    /**
     * Skip rate limiting for this request (e.g. trusted clients).
     */
    public static function none(): Unlimited
    {
        return new Unlimited();
    }

    public function by(mixed $key): self
    {
        $this->key = (string) $key;

        return $this;
    }

    public function response(callable $callback): self
    {
        $this->responseCallback = $callback(...);

        return $this;
    }

    public function message(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function status(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function headers(array $headers): self
    {
        $this->responseHeaders = $headers;

        return $this;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function decaySeconds(): int
    {
        return $this->decaySeconds;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function responseCallback(): ?Closure
    {
        return $this->responseCallback;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->responseHeaders;
    }
}
