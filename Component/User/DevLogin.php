<?php

namespace Pinoox\Component\User;

use Pinoox\Component\Helpers\EnvFile;
use Pinoox\Component\Kernel\Loader;
use Pinoox\Portal\Env;

/**
 * Env-based auto-login via PINOOX_LOGIN_TOKEN.
 *
 * Separate from normal jwt / cookie / session client auth:
 * - token set  → Auth uses this token
 * - token empty/missing → normal jwt/cookie/session only
 */
final class DevLogin
{
    public const ENV_TOKEN = 'PINOOX_LOGIN_TOKEN';

    private static bool $applied = false;

    public static function enabled(): bool
    {
        return self::token() !== '';
    }

    public static function token(): string
    {
        $fromEnv = trim((string) (Env::get(self::ENV_TOKEN) ?? ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $stored = self::readStore();

        return trim((string) ($stored['token'] ?? ''));
    }

    /**
     * Activate env-token auth for this request (does not touch jwt/cookie client stores).
     */
    public static function apply(): void
    {
        if (self::$applied || !self::enabled()) {
            return;
        }

        self::$applied = true;
        AuthSession::setRequestToken(self::token());
    }

    /**
     * @param array{
     *     token: string,
     *     auth_key?: string,
     *     auth_mode?: string,
     *     package?: string,
     *     user_id?: int|null,
     *     username?: string|null,
     * } $payload
     */
    public static function remember(array $payload, bool $enable = true): bool
    {
        $token = trim((string) ($payload['token'] ?? ''));
        if ($token === '') {
            return false;
        }

        $env = EnvFile::forProject();
        $env->removeKeys(['PINOOX_DEV_LOGIN', 'PINOOX_DEV_LOGIN_TOKEN']);
        $ok = $env->setMany([self::ENV_TOKEN => $token]);
        $env->applyToRuntime([self::ENV_TOKEN => $token]);

        $store = [
            'token' => $token,
            'auth_key' => (string) ($payload['auth_key'] ?? ''),
            'auth_mode' => (string) ($payload['auth_mode'] ?? ''),
            'package' => (string) ($payload['package'] ?? ''),
            'user_id' => isset($payload['user_id']) ? (int) $payload['user_id'] : null,
            'username' => $payload['username'] ?? null,
            'updated_at' => date(DATE_ATOM),
        ];

        return self::writeStore($store) || $ok;
    }

    public static function clear(): bool
    {
        $env = EnvFile::forProject();
        $env->removeKeys(['PINOOX_DEV_LOGIN', 'PINOOX_DEV_LOGIN_TOKEN']);
        $ok = $env->setMany([self::ENV_TOKEN => '']);
        $env->applyToRuntime([self::ENV_TOKEN => '']);

        $path = self::storePath();
        if (is_file($path)) {
            @unlink($path);
        }

        $legacy = dirname($path) . '/dev-login.json';
        if (is_file($legacy)) {
            @unlink($legacy);
        }

        AuthSession::setRequestToken(null);
        self::$applied = false;

        return $ok;
    }

    /**
     * @return array<string, mixed>
     */
    public static function readStore(): array
    {
        $path = self::storePath();
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function storePath(): string
    {
        $base = is_string(Loader::getBasePath()) && Loader::getBasePath() !== ''
            ? rtrim(str_replace('\\', '/', (string) Loader::getBasePath()), '/')
            : rtrim(str_replace('\\', '/', (string) getcwd()), '/');

        return $base . '/storage/framework/login-token.json';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function writeStore(array $payload): bool
    {
        $path = self::storePath();
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }

        $legacy = dirname($path) . '/dev-login.json';
        if (is_file($legacy)) {
            @unlink($legacy);
        }

        return @file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX,
        ) !== false;
    }
}
