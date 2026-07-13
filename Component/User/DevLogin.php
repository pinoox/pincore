<?php

namespace Pinoox\Component\User;

use Pinoox\Component\Cookie;
use Pinoox\Component\Helpers\EnvFile;
use Pinoox\Component\Kernel\Loader;
use Pinoox\Portal\Env;

/**
 * Auto-login when PINOOX_LOGIN_TOKEN is set in .env (any mode / environment).
 *
 * Empty or missing token => disabled. Cleared with: user:login --clear
 */
final class DevLogin
{
    public const ENV_TOKEN = 'PINOOX_LOGIN_TOKEN';

    /** @deprecated Legacy keys cleared on remember/clear */
    private const LEGACY_ENV_ENABLED = 'PINOOX_DEV_LOGIN';

    /** @deprecated */
    private const LEGACY_ENV_TOKEN = 'PINOOX_DEV_LOGIN_TOKEN';

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

        // Migrate legacy env name if still present
        $legacy = trim((string) (Env::get(self::LEGACY_ENV_TOKEN) ?? ''));
        if ($legacy !== '') {
            return $legacy;
        }

        $stored = self::readStore();

        return trim((string) ($stored['token'] ?? ''));
    }

    /**
     * Inject the saved token into the current request auth stack.
     */
    public static function apply(): void
    {
        if (self::$applied || !self::enabled()) {
            return;
        }

        self::$applied = true;

        $token = self::token();
        if ($token === '') {
            return;
        }

        AuthSession::setRequestToken($token);

        $auth = AuthConfig::resolve();
        $key = (string) ($auth['key'] ?? '');
        if ($key === '') {
            return;
        }

        $lifetime = (int) ($auth['lifetime'] ?? 30);
        $unit = (string) ($auth['lifetime_unit'] ?? 'day');
        $seconds = match ($unit) {
            'min', 'minute', 'minutes' => $lifetime * 60,
            'hour', 'hours' => $lifetime * 3600,
            default => $lifetime * 86400,
        };

        Cookie::set($key, $token, $seconds);
    }

    /**
     * Persist a CLI/Inspector login token to .env + storage.
     *
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

        $variables = [
            self::ENV_TOKEN => $token,
            self::LEGACY_ENV_ENABLED => '',
            self::LEGACY_ENV_TOKEN => '',
        ];

        $env = EnvFile::forProject();
        $ok = $env->setMany($variables);
        $env->applyToRuntime([
            self::ENV_TOKEN => $token,
            self::LEGACY_ENV_ENABLED => '',
            self::LEGACY_ENV_TOKEN => '',
        ]);

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
        $ok = $env->setMany([
            self::ENV_TOKEN => '',
            self::LEGACY_ENV_ENABLED => '',
            self::LEGACY_ENV_TOKEN => '',
        ]);
        $env->applyToRuntime([
            self::ENV_TOKEN => '',
            self::LEGACY_ENV_ENABLED => '',
            self::LEGACY_ENV_TOKEN => '',
        ]);

        $path = self::storePath();
        if (is_file($path)) {
            @unlink($path);
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

        // Drop legacy filename if present
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
