<?php

namespace Pinoox\Component\Log;

use Monolog\Level;
use Pinoox\Component\Runtime\RuntimeMode;
use Pinoox\Portal\App\App;
use Pinoox\Portal\Mode;
use Pinoox\Support\SystemConfig;

class LogConfig
{
    /**
     * @return array{
     *     path: string,
     *     channel: string,
     *     package: string,
     *     level: Level,
     *     rotate: bool,
     *     max_files: int,
     *     max_size: int,
     *     compress: bool,
     *     buffer: bool,
     *     deduplicate: bool,
     *     deduplicate_time: int,
     *     driver: string
     * }
     */
    public static function resolve(): array
    {
        $global = config('~pinoox')->get('log') ?? [];
        $app = App::get('log') ?? [];

        if (!is_array($global)) {
            $global = [];
        }

        if (!is_array($app)) {
            $app = [];
        }

        $app = array_filter($app, static fn ($value) => $value !== null);
        $merged = array_merge($global, $app);
        $package = (string) (App::package() ?? 'pinoox');

        $path = (string) ($merged['path'] ?? '~storage/logs/pinoox.log');
        $path = str_replace('{package}', $package, $path);

        if (str_starts_with($path, '~')) {
            $path = SystemConfig::resolvePath($path);
        }

        self::ensureDirectory($path);

        return [
            'path' => $path,
            'channel' => (string) ($merged['channel'] ?? 'pinoox'),
            'package' => $package,
            'level' => self::parseLevel(self::resolveLevel($merged, $package)),
            'rotate' => (bool) ($merged['rotate'] ?? true),
            'max_files' => max(1, (int) ($merged['max_files'] ?? 14)),
            'max_size' => self::parseBytes($merged['max_size'] ?? '10MB'),
            'compress' => (bool) ($merged['compress'] ?? false),
            'buffer' => (bool) ($merged['buffer'] ?? false),
            'deduplicate' => (bool) ($merged['deduplicate'] ?? false),
            'deduplicate_time' => (int) ($merged['deduplicate_time'] ?? 60),
            'driver' => (string) ($merged['driver'] ?? 'size_daily'),
        ];
    }

    public static function channelName(?string $suffix = null): string
    {
        $config = self::resolve();
        $base = $config['channel'] . '.' . $config['package'];

        return $suffix ? $base . '.' . $suffix : $base;
    }

    public static function path(): string
    {
        return self::resolve()['path'];
    }

    public static function level(): Level
    {
        return self::resolve()['level'];
    }

    /**
     * Resolve log file path for a specific package without booting that app.
     */
    public static function resolveForPackage(string $package): array
    {
        $global = config('~pinoox')->get('log') ?? [];

        if (!is_array($global)) {
            $global = [];
        }

        $app = [];

        if ($package !== 'platform' && \Pinoox\Portal\App\AppEngine::exists($package)) {
            $appConfig = \Pinoox\Portal\App\AppEngine::config($package)->get('log') ?? [];
            $app = is_array($appConfig) ? $appConfig : [];
        }

        $app = array_filter($app, static fn ($value) => $value !== null);
        $merged = array_merge($global, $app);

        $path = (string) ($merged['path'] ?? '~storage/logs/pinoox.log');
        $path = str_replace('{package}', $package, $path);

        if (str_starts_with($path, '~')) {
            $path = SystemConfig::resolvePath($path);
        }

        self::ensureDirectory($path);

        return [
            'path' => $path,
            'channel' => (string) ($merged['channel'] ?? 'pinoox'),
            'package' => $package,
            'level' => self::parseLevel(self::resolveLevel($merged, $package)),
            'rotate' => (bool) ($merged['rotate'] ?? true),
            'max_files' => max(1, (int) ($merged['max_files'] ?? 14)),
            'max_size' => self::parseBytes($merged['max_size'] ?? '10MB'),
            'compress' => (bool) ($merged['compress'] ?? false),
            'buffer' => (bool) ($merged['buffer'] ?? false),
            'deduplicate' => (bool) ($merged['deduplicate'] ?? false),
            'deduplicate_time' => (int) ($merged['deduplicate_time'] ?? 60),
            'driver' => (string) ($merged['driver'] ?? 'size_daily'),
        ];
    }

    public static function parseLevel(string|Level $level): Level
    {
        if ($level instanceof Level) {
            return $level;
        }

        return Level::fromName(strtoupper(trim($level)));
    }

    /**
     * Parse human-readable byte strings into bytes (e.g. '10MB', '500KB', '1GB').
     */
    public static function parseBytes(int|string|null $size): int
    {
        if ($size === null || $size === '') {
            return 0;
        }

        if (is_numeric($size)) {
            return max(0, (int) $size);
        }

        $size = trim($size);
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([a-zA-Z]+)?$/', $size, $matches)) {
            return 0;
        }

        $value = (float) $matches[1];
        $unit = strtoupper($matches[2] ?? 'B');

        return (int) match ($unit) {
            'K', 'KB' => $value * 1024,
            'M', 'MB' => $value * 1024 * 1024,
            'G', 'GB' => $value * 1024 * 1024 * 1024,
            'T', 'TB' => $value * 1024 * 1024 * 1024 * 1024,
            default => $value,
        };
    }

    /**
     * @param array<string, mixed> $merged
     */
    private static function resolveLevel(array $merged, ?string $package = null): string
    {
        $level = $merged['level'] ?? null;

        if ($level !== null && $level !== '') {
            return (string) $level;
        }

        try {
            return Mode::defaultLogLevel($package);
        } catch (\Throwable) {
            $globalMode = RuntimeMode::readGlobal()['mode'];
            return RuntimeMode::defaultLogLevelForMode($globalMode);
        }
    }

    private static function ensureDirectory(string $path): void
    {
        $directory = dirname($path);

        if ($directory !== '' && !is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }
    }
}

