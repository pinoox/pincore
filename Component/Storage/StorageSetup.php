<?php

namespace Pinoox\Component\Storage;

use Pinoox\Component\File as FileHelper;
use Pinoox\Support\SystemConfig;

class StorageSetup
{
    public const PROTECT_LOCK = 'lock';
    public const PROTECT_UNLOCK = 'unlock';

    public static function storageRoot(): string
    {
        return rtrim(str_replace('\\', '/', SystemConfig::path('storage')), '/');
    }

    public static function publicRoot(): string
    {
        $configured = SystemConfig::resolvePath((string) (
            config('filesystems.public_root')
            ?? config('filesystems.disks.public.root')
            ?? '~storage/public'
        ));

        return rtrim(str_replace('\\', '/', $configured), '/');
    }

    public static function diskRoot(string $disk): ?string
    {
        $root = config('filesystems.disks.' . $disk . '.root');
        if (!is_string($root) || $root === '') {
            if ($disk === 'public') {
                return self::publicRoot();
            }
            if ($disk === 'local') {
                $fallback = config('filesystems.app_root') ?: '~storage/local';

                return rtrim(str_replace('\\', '/', SystemConfig::resolvePath((string) $fallback)), '/');
            }

            return null;
        }

        return rtrim(str_replace('\\', '/', SystemConfig::resolvePath($root)), '/');
    }

    /**
     * Normalize protect mode. Default is always lock unless explicitly unlock.
     */
    public static function normalizeProtect(mixed $value, ?string $fallback = self::PROTECT_LOCK): string
    {
        if ($value === null || $value === '') {
            $value = $fallback ?? self::PROTECT_LOCK;
        }

        if (is_bool($value)) {
            return $value ? self::PROTECT_LOCK : self::PROTECT_UNLOCK;
        }

        $value = strtolower(trim((string) $value));

        return match ($value) {
            'unlock', 'unlocked', 'allow', 'public', 'open', 'opened', '0', 'false', 'no', 'off' => self::PROTECT_UNLOCK,
            default => self::PROTECT_LOCK,
        };
    }

    public static function ensure(): bool
    {
        $ok = self::lock();

        foreach (self::localDiskNames() as $disk) {
            $ok = self::ensureDisk($disk) && $ok;
        }

        return $ok;
    }

    public static function ensureDisk(string $disk, ?string $forceProtect = null): bool
    {
        $root = self::diskRoot($disk);
        if ($root === null || $root === '') {
            return false;
        }

        $configProtect = config('filesystems.disks.' . $disk . '.protect');
        $protect = self::normalizeProtect($forceProtect ?? $configProtect);

        return self::applyProtect($root, $protect);
    }

    /**
     * Apply protect stubs to a concrete directory (e.g. disk root or package folder).
     */
    public static function applyProtect(string $root, mixed $protect): bool
    {
        $mode = self::normalizeProtect($protect);

        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($root === '' || (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root))) {
            return false;
        }

        $files = $mode === self::PROTECT_UNLOCK
            ? self::publicProtectionFiles()
            : FileHelper::storageRootProtectionFiles();

        $ok = true;

        foreach ($files as $file => $stubFile) {
            $content = $mode === self::PROTECT_UNLOCK
                ? self::stubContent($stubFile)
                : self::lockedStubContent($stubFile);

            if ($content === '') {
                continue;
            }

            $path = $root . '/' . $file;
            if (is_file($path)) {
                continue;
            }

            $ok = FileHelper::generate($path, $content) && $ok;
        }

        return $ok;
    }

    /** @deprecated Use ensure() / ensureDisk() */
    public static function lock(): bool
    {
        return FileHelper::ensureStorageRootProtection(self::storageRoot());
    }

    /** @deprecated Use ensureDisk('public') */
    public static function unlockPublic(): bool
    {
        return self::ensureDisk('public', self::PROTECT_UNLOCK);
    }

    /**
     * @return list<string>
     */
    public static function localDiskNames(): array
    {
        $disks = config('filesystems.disks');
        if (!is_array($disks)) {
            return ['local', 'public', 'temp'];
        }

        $names = [];
        foreach ($disks as $name => $config) {
            if (!is_string($name) || !is_array($config)) {
                continue;
            }
            if (($config['driver'] ?? null) !== 'local') {
                continue;
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @return array<string, string>
     */
    public static function publicProtectionFiles(): array
    {
        return [
            '.htaccess' => 'storage.public.htaccess.stub',
            'web.config' => 'storage.public.web.config.stub',
            'nginx.conf' => 'storage.public.nginx.conf.stub',
            'Caddyfile' => 'storage.public.caddyfile.stub',
        ];
    }

    private static function lockedStubContent(string $stubFile): string
    {
        return self::storageStubContent($stubFile);
    }

    private static function stubContent(string $stubFile): string
    {
        return self::storageStubContent($stubFile);
    }

    private static function storageStubContent(string $stubFile): string
    {
        static $cache = [];

        if (isset($cache[$stubFile])) {
            return $cache[$stubFile];
        }

        $path = dirname(__DIR__, 2) . '/stubs/' . $stubFile;
        if (is_file($path)) {
            return $cache[$stubFile] = (string) file_get_contents($path);
        }

        return $cache[$stubFile] = '';
    }
}
