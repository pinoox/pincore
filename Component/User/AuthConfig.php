<?php

namespace Pinoox\Component\User;

use Pinoox\Component\Transport\TransportConfig;
use Pinoox\Component\Transport\TransportScenario;
use Pinoox\Portal\App\App;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\Env;

class AuthConfig
{

    public const MODE_COOKIE = 'cookie';

    public const MODE_SESSION = 'session';

    public const MODE_JWT = 'jwt';

    private static ?array $resolved = null;

    /**
     * @return array{
     *     mode: string,
     *     key: string,
     *     lifetime: int,
     *     lifetime_unit: string,
     *     remember_lifetime: int,
     *     remember_unit: string,
     *     jwt_secret: string,
     *     provider: string,
     *     source: string|null,
     * }
     */
    public static function resolve(bool $refresh = false): array
    {
        if (self::$resolved !== null && !$refresh) {
            return self::$resolved;
        }

        $package = App::package();
        $source = TransportConfig::authSource();

        if ($source !== null && AppEngine::exists($source)) {
            $auth = self::readFromApp($source);
            $auth['source'] = $source;
        } else {
            $auth = self::readFromApp($package);
            $auth['source'] = null;
        }

        $auth['provider'] = TransportConfig::package(TransportScenario::USER_TABLE);

        self::$resolved = $auth;

        return self::$resolved;
    }

    public static function fingerprint(?array $config = null): string
    {
        $config ??= self::resolve();

        return implode('|', [
            (string) ($config['mode'] ?? ''),
            (string) ($config['key'] ?? ''),
            (string) ($config['jwt_secret'] ?? ''),
            (string) ($config['source'] ?? ''),
        ]);
    }

    public static function reset(): void
    {
        self::$resolved = null;
    }

    /**
     * @return array{
     *     mode: string,
     *     key: string,
     *     lifetime: int,
     *     lifetime_unit: string,
     *     remember_lifetime: int,
     *     remember_unit: string,
     *     jwt_secret: string,
     * }
     */
    private static function readFromApp(string $package): array
    {
        $config = AppEngine::config($package);

        return [
            'mode' => strtolower((string) ($config->get('auth.mode') ?? self::MODE_COOKIE)),
            'key' => (string) ($config->get('auth.key') ?? $package . '_pinoox'),
            'lifetime' => (int) ($config->get('auth.lifetime') ?? 30),
            'lifetime_unit' => (string) ($config->get('auth.lifetime_unit') ?? 'day'),
            'remember_lifetime' => (int) ($config->get('auth.remember_lifetime') ?? 365),
            'remember_unit' => (string) ($config->get('auth.remember_unit') ?? 'day'),
            'jwt_secret' => (string) ($config->get('auth.jwt_secret')
                ?? Env::get('PINOOX_JWT_SECRET')
                ?? 'BAF55D93DF7A2B3AA64722AA85448424AAB5CF4214AD2899CD9440BEC9B44894'),
        ];
    }

    /**
     * Client-safe auth payload for window.__PINOOX__.auth (@pinooxhq/auth).
     *
     * Controlled by app.php `auth.client` (legacy: via, expose, bootstrap):
     * - true / null (default): mode, key, provider, source
     * - false: omit auth from __PINOOX__
     * - list: whitelist of those base fields, e.g. ['mode', 'key']
     * - map: merge extras onto the base (strategy, loginUrl, baseUrl, endpoints, …)
     *
     * @return array<string, mixed>|null
     */
    public static function forClient(): ?array
    {
        $package = App::package();
        if ($package === '' || $package === null) {
            return null;
        }

        $config = AppEngine::config($package);
        $flag = $config->get('auth.client');
        foreach (['auth.via', 'auth.expose', 'auth.bootstrap'] as $legacy) {
            if ($flag === null && $config->get($legacy) !== null) {
                $flag = $config->get($legacy);
                break;
            }
        }

        if ($flag === false) {
            return null;
        }

        $resolved = self::resolve();
        $base = [
            'mode' => $resolved['mode'],
            'key' => $resolved['key'],
            'provider' => $resolved['provider'],
            'source' => $resolved['source'],
        ];

        if ($flag === null || $flag === true) {
            return $base;
        }

        if (!is_array($flag)) {
            return $base;
        }

        if (array_is_list($flag)) {
            $allowed = [];
            foreach ($flag as $field) {
                if (is_string($field) && array_key_exists($field, $base)) {
                    $allowed[$field] = $base[$field];
                }
            }

            return $allowed;
        }

        return array_replace($base, $flag);
    }

    /**
     * @deprecated Use forClient()
     * @return array<string, mixed>|null
     */
    public static function forBootstrap(): ?array
    {
        return self::forClient();
    }
}
