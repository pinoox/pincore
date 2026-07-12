<?php

/**
 * ***  *  *     *  ****  ****  *    *
 *   *  *  * *   *  *  *  *  *   *  *
 * ***  *  *  *  *  *  *  *  *    *
 *      *  *   * *  *  *  *  *   *  *
 *      *  *    **  ****  ****  *    *
 *
 * @author   Pinoox
 * @link https://www.pinoox.com
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Portal;

use Pinoox\Component\Http\Http as HttpClient;
use Pinoox\Component\Source\Portal;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Outbound HTTP client facade.
 *
 * Http::get($url);
 * Http::post($url, ['json' => [...]]);
 * Http::query($url, ['json' => ['filters' => [...]]]);
 * Http::request('QUERY', $url, $options);
 * Http::withOptions(['timeout' => 15])->get($url);
 *
 * @method static ResponseInterface|null get(string $url, array $options = [])
 * @method static ResponseInterface|null post(string $url, array $options = [])
 * @method static ResponseInterface|null put(string $url, array $options = [])
 * @method static ResponseInterface|null patch(string $url, array $options = [])
 * @method static ResponseInterface|null delete(string $url, array $options = [])
 * @method static ResponseInterface|null query(string $url, array $options = [])
 * @method static ResponseInterface|null options(string $url, array $options = [])
 * @method static ResponseInterface|null head(string $url, array $options = [])
 * @method static ResponseInterface|null request(string $method, string $url, array $options = [])
 * @method static HttpClient withOptions(array $options)
 * @method static HttpClientInterface client()
 * @method static array getDefaultOptions()
 * @method static HttpClient ___()
 *
 * @see HttpClient
 */
class Http extends Portal
{
    public static function __register(): void
    {
        self::__bind(HttpClient::class)->setFactory([HttpClient::class, 'create']);
    }

    public static function valid(string $method): bool
    {
        return HttpClient::valid($method);
    }

    public static function create(array $defaultOptions = [], ?HttpClientInterface $client = null): HttpClient
    {
        return HttpClient::create($defaultOptions, $client);
    }

    public static function __name(): string
    {
        return 'http';
    }

    public static function __exclude(): array
    {
        return [];
    }

    public static function __callback(): array
    {
        return [
            'withOptions',
        ];
    }
}
