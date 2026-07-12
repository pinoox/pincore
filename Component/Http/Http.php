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

namespace Pinoox\Component\Http;

use BadMethodCallException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Outbound HTTP client (Symfony HttpClient).
 *
 * Prefer {@see \Pinoox\Portal\Http} in app code. Static helpers on this class
 * remain for backward compatibility and delegate to the portal instance when available.
 *
 * Verb helpers (get/post/query/…) are provided via {@see __call} / {@see __callStatic}
 * so both instance and static APIs stay available without method-name conflicts.
 *
 * @method ResponseInterface|null get(string $url, array $options = [])
 * @method ResponseInterface|null post(string $url, array $options = [])
 * @method ResponseInterface|null put(string $url, array $options = [])
 * @method ResponseInterface|null patch(string $url, array $options = [])
 * @method ResponseInterface|null delete(string $url, array $options = [])
 * @method ResponseInterface|null query(string $url, array $options = [])
 * @method ResponseInterface|null options(string $url, array $options = [])
 * @method ResponseInterface|null head(string $url, array $options = [])
 * @method static ResponseInterface|null get(string $url, array $options = [])
 * @method static ResponseInterface|null post(string $url, array $options = [])
 * @method static ResponseInterface|null put(string $url, array $options = [])
 * @method static ResponseInterface|null patch(string $url, array $options = [])
 * @method static ResponseInterface|null delete(string $url, array $options = [])
 * @method static ResponseInterface|null query(string $url, array $options = [])
 * @method static ResponseInterface|null options(string $url, array $options = [])
 * @method static ResponseInterface|null head(string $url, array $options = [])
 * @method static ResponseInterface|null request(string $method, string $url, array $options = [])
 * @method static self withOptions(array $options)
 * @method static HttpClientInterface client()
 */
class Http
{
    public const GET = 'GET';

    public const POST = 'POST';

    public const PUT = 'PUT';

    public const PATCH = 'PATCH';

    public const DELETE = 'DELETE';

    public const QUERY = 'QUERY';

    public const HEAD = 'HEAD';

    public const OPTIONS = 'OPTIONS';

    /**
     * @var string[]
     */
    public const METHODS = [
        self::HEAD,
        self::GET,
        self::POST,
        self::PUT,
        self::PATCH,
        self::DELETE,
        self::QUERY,
        self::OPTIONS,
    ];

    /**
     * @param array<string, mixed> $defaultOptions
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly array $defaultOptions = [],
    ) {
    }

    /**
     * @param array<string, mixed> $defaultOptions
     */
    public static function create(array $defaultOptions = [], ?HttpClientInterface $client = null): self
    {
        $defaults = array_replace_recursive(self::baseDefaultOptions(), $defaultOptions);

        return new self($client ?? HttpClient::create($defaults), $defaults);
    }

    /**
     * @return array<string, mixed>
     */
    public static function baseDefaultOptions(): array
    {
        return [
            'verify_peer' => false,
            'verify_host' => false,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): self
    {
        return new self(
            $this->client->withOptions($options),
            array_replace_recursive($this->defaultOptions, $options),
        );
    }

    public function client(): HttpClientInterface
    {
        return $this->client;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultOptions(): array
    {
        return $this->defaultOptions;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ?ResponseInterface
    {
        $method = strtoupper($method);

        if (!self::valid($method)) {
            throw new BadMethodCallException('"' . $method . '" HTTP method is not supported by ' . __CLASS__);
        }

        try {
            return $this->client->request($method, $url, $options);
        } catch (TransportExceptionInterface) {
            return null;
        }
    }

    public static function valid(string $method): bool
    {
        return in_array(strtoupper($method), self::METHODS, true);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): ?ResponseInterface
    {
        $httpMethod = strtoupper($method);

        if (!self::valid($httpMethod)) {
            throw new BadMethodCallException('"' . $method . '" method is not found in ' . __CLASS__ . ' class');
        }

        return $this->request(
            $httpMethod,
            (string) ($arguments[0] ?? ''),
            $arguments[1] ?? [],
        );
    }

    /**
     * Backward-compatible static entry points (`Http::get()`, `Http::request()`, …).
     *
     * @param array<int, mixed> $arguments
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        $instance = self::resolve();

        return match ($method) {
            'create' => self::create($arguments[0] ?? [], $arguments[1] ?? null),
            'valid' => self::valid((string) ($arguments[0] ?? '')),
            'withOptions' => $instance->withOptions($arguments[0] ?? []),
            'client' => $instance->client(),
            'getDefaultOptions' => $instance->getDefaultOptions(),
            'request' => $instance->request(
                (string) ($arguments[0] ?? ''),
                (string) ($arguments[1] ?? ''),
                $arguments[2] ?? [],
            ),
            default => (static function () use ($instance, $method, $arguments) {
                $httpMethod = strtoupper($method);

                if (!self::valid($httpMethod)) {
                    throw new BadMethodCallException(
                        '"' . $method . '" static method is not found in ' . self::class . ' class'
                    );
                }

                return $instance->request(
                    $httpMethod,
                    (string) ($arguments[0] ?? ''),
                    $arguments[1] ?? [],
                );
            })(),
        };
    }

    private static function resolve(): self
    {
        try {
            if (class_exists(\Pinoox\Portal\Http::class)) {
                $instance = \Pinoox\Portal\Http::___();

                if ($instance instanceof self) {
                    return $instance;
                }
            }
        } catch (\Throwable) {
        }

        return self::create();
    }
}
