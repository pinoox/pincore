<?php

namespace Pinoox\Component\User;

use Pinoox\Component\Helpers\EnvFile;
use Pinoox\Component\Kernel\Loader;
use Pinoox\Component\Transport\TransportRuntime;
use Pinoox\Model\UserModel;
use Pinoox\Portal\App\App;
use Pinoox\Portal\Auth;
use Pinoox\Portal\Env;

/**
 * Env auto-login via PINOOX_LOGIN=package:field:value
 *
 * Multiple apps (one line each — read from .env, not only the last Env value):
 *   PINOOX_LOGIN=com_pinoox_manager:id:1
 *   PINOOX_LOGIN=com_pinoox_manager:user_id:1
 *   PINOOX_LOGIN=com_pinoox_manager:personal_id:1
 *   PINOOX_LOGIN=com_pinoox_account:username:yoosef
 *   PINOOX_LOGIN=com_pinoox_app:email:info@pinoox.com
 *   PINOOX_LOGIN=com_pinoox_shop:mobile:09122220000
 *
 * Fields: id | user_id | personal_id | username | email | login | mobile
 * Independent of jwt/cookie/session client auth. Empty/missing => off.
 */
final class DevLogin
{
    public const ENV_LOGIN = 'PINOOX_LOGIN';

    /** @var list<string> */
    private const FIELDS = ['id', 'user_id', 'personal_id', 'username', 'email', 'login', 'mobile'];

    private static bool $applied = false;

    private static ?string $appliedPackage = null;

    public static function enabled(): bool
    {
        return self::parseAll() !== [];
    }

    /**
     * @return list<array{package: string, field: string, value: string}>
     */
    public static function parseAll(): array
    {
        $rawValues = EnvFile::forProject()->getAll(self::ENV_LOGIN);

        if ($rawValues === []) {
            $fallback = trim((string) (Env::get(self::ENV_LOGIN) ?? ''));
            if ($fallback !== '') {
                $rawValues = preg_split('/[\r\n,;]+/', $fallback) ?: [];
            }
        }

        $byPackage = [];

        foreach ($rawValues as $raw) {
            $parsed = self::parseExpression((string) $raw);
            if ($parsed === null) {
                continue;
            }

            // Last line wins per package
            $byPackage[$parsed['package']] = $parsed;
        }

        return array_values($byPackage);
    }

    /**
     * @return array{package: string, field: string, value: string}|null
     */
    public static function parse(?string $forPackage = null): ?array
    {
        $package = $forPackage ?? (string) App::package();
        $entries = self::parseAll();

        if ($entries === []) {
            return null;
        }

        if ($package !== '') {
            foreach ($entries as $entry) {
                if ($entry['package'] === $package) {
                    return $entry;
                }
            }

            return null;
        }

        return $entries[0];
    }

    /**
     * @return array{package: string, field: string, value: string}|null
     */
    public static function parseExpression(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $parts = explode(':', $raw, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$package, $field, $value] = $parts;
        $package = trim($package);
        $field = strtolower(trim($field));
        $value = trim($value);

        if ($package === '' || !self::hasLookupValue($value) || !in_array($field, self::FIELDS, true)) {
            return null;
        }

        return [
            'package' => $package,
            'field' => $field,
            'value' => $value,
        ];
    }

    public static function expression(?string $forPackage = null): string
    {
        $parsed = self::parse($forPackage);
        if ($parsed === null) {
            return '';
        }

        return self::format($parsed);
    }

    /**
     * @param array{package: string, field: string, value: string} $entry
     */
    public static function format(array $entry): string
    {
        return $entry['package'] . ':' . $entry['field'] . ':' . $entry['value'];
    }

    /**
     * Resolve PINOOX_LOGIN for the active app into a forced in-request user.
     */
    public static function apply(): void
    {
        $current = (string) App::package();
        if (self::$applied && self::$appliedPackage === $current) {
            return;
        }

        $parsed = self::parse($current !== '' ? $current : null);
        if ($parsed === null) {
            AuthSession::setForcedUser(null);
            self::$applied = true;
            self::$appliedPackage = $current;

            return;
        }

        TransportRuntime::use($parsed['package']);
        UserModel::clearBootedModels();

        $user = self::resolveUser($parsed);
        self::$applied = true;
        self::$appliedPackage = $parsed['package'];

        if ($user === null || $user->status !== UserModel::ACTIVE) {
            return;
        }

        AuthSession::setForcedUser($user);
    }

    /**
     * @param array{
     *     package: string,
     *     user_id?: int|null,
     *     username?: string|null,
     *     field?: string,
     *     value?: string,
     * } $payload
     */
    public static function remember(array $payload, bool $enable = true): bool
    {
        $package = trim((string) ($payload['package'] ?? ''));
        $field = strtolower(trim((string) ($payload['field'] ?? 'id')));
        $value = trim((string) ($payload['value'] ?? ''));

        if ($value === '' && isset($payload['user_id']) && (int) $payload['user_id'] > 0) {
            $field = 'id';
            $value = (string) (int) $payload['user_id'];
        }

        if ($value === '' && is_string($payload['username'] ?? null) && $payload['username'] !== '') {
            $field = 'username';
            $value = (string) $payload['username'];
        }

        if ($package === '' || !self::hasLookupValue($value) || !in_array($field, self::FIELDS, true)) {
            return false;
        }

        $expression = $package . ':' . $field . ':' . $value;
        $lines = [];

        foreach (self::parseAll() as $entry) {
            if ($entry['package'] === $package) {
                continue;
            }
            $lines[] = self::format($entry);
        }

        $lines[] = $expression;

        $env = EnvFile::forProject();
        $env->removeKeys([
            'PINOOX_LOGIN_TOKEN',
            'PINOOX_DEV_LOGIN',
            'PINOOX_DEV_LOGIN_TOKEN',
        ]);
        $ok = $env->setLines(self::ENV_LOGIN, $lines);

        return self::writeStore([
            'expression' => $expression,
            'expressions' => $lines,
            'package' => $package,
            'field' => $field,
            'value' => $value,
            'user_id' => isset($payload['user_id']) ? (int) $payload['user_id'] : null,
            'username' => $payload['username'] ?? null,
            'updated_at' => date(DATE_ATOM),
        ]) || $ok;
    }

    public static function clear(): bool
    {
        $env = EnvFile::forProject();
        $env->removeKeys([
            'PINOOX_LOGIN_TOKEN',
            'PINOOX_DEV_LOGIN',
            'PINOOX_DEV_LOGIN_TOKEN',
        ]);
        $ok = $env->setLines(self::ENV_LOGIN, []);

        foreach ([self::storePath(), dirname(self::storePath()) . '/dev-login.json'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        AuthSession::setForcedUser(null);
        AuthSession::setRequestToken(null);
        self::$applied = false;
        self::$appliedPackage = null;

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
     * @param array{package: string, field: string, value: string} $parsed
     */
    private static function resolveUser(array $parsed): ?UserModel
    {
        if (!self::hasLookupValue($parsed['value'])) {
            return null;
        }

        return match ($parsed['field']) {
            'id', 'user_id' => ctype_digit($parsed['value']) && (int) $parsed['value'] > 0
                ? Auth::find((int) $parsed['value'])
                : null,
            'personal_id' => self::findByAttribute('personal_id', $parsed['value']),
            'username' => self::findByAttribute('username', $parsed['value']),
            'email' => self::findByAttribute('email', $parsed['value']),
            'mobile' => self::findByAttribute('mobile', $parsed['value']),
            default => UserModel::query()
                ->where(function ($builder) use ($parsed) {
                    $builder->where('username', $parsed['value'])
                        ->orWhere('email', $parsed['value']);
                })
                ->orderBy('user_id')
                ->first(),
        };
    }

    /**
     * Empty / null-like values must never match DB null or blank columns.
     */
    private static function hasLookupValue(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && strcasecmp($value, 'null') !== 0;
    }

    /**
     * First matching row by user_id when duplicates exist.
     */
    private static function findByAttribute(string $column, string $value): ?UserModel
    {
        if (!self::hasLookupValue($value)) {
            return null;
        }

        return UserModel::query()
            ->where($column, $value)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->orderBy('user_id')
            ->first();
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

        return @file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX,
        ) !== false;
    }
}
