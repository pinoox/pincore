<?php

namespace Pinoox\Component\File;

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Visibility;
use Pinoox\Portal\Storage;
use Pinoox\Portal\Url;
use Pinoox\Model\FileModel;

class FileStorage
{
    public static function disk(?string $package = null, ?string $disk = null): FilesystemAdapter
    {
        $config = FileConfig::resolve();
        $package = $package ?? $config['package'];
        $disk = $disk ?? $config['disk'];

        return Storage::app($package, $disk);
    }

    public static function key(string $directory, string $filename): string
    {
        return trim(trim($directory, '/') . '/' . ltrim($filename, '/'), '/');
    }

    public static function thumbKey(string $directory, string $filename): string
    {
        return self::key(trim($directory, '/') . '/thumbs', 'thumb_' . ltrim($filename, '/'));
    }

    public static function visibility(string $access): string
    {
        return strtolower($access) === 'public' ? Visibility::PUBLIC : Visibility::PRIVATE;
    }

    public static function resolveDisk(FileModel $file): ?string
    {
        $column = isset($file->file_disk) ? trim((string) $file->file_disk) : '';
        if ($column !== '') {
            return $column;
        }

        $metadata = $file->file_metadata ?? [];

        return is_array($metadata) && !empty($metadata['disk'])
            ? (string) $metadata['disk']
            : null;
    }

    public static function dispatcherUrl(FileModel $file, bool $thumb = false): ?string
    {
        $hash = trim((string) ($file->hash_id ?? ''));
        if ($hash === '') {
            return null;
        }

        $package = is_string($file->app ?? null) && $file->app !== '' ? $file->app : null;
        $path = FileConfig::buildDispatcherPath(
            FileConfig::dispatcherPath($package),
            $hash,
            $thumb,
        );

        try {
            $base = rtrim(Url::forApp($package), '/');

            return $base . '/' . ltrim($path, '/');
        } catch (\Throwable) {
            return Url::link($path, Url::SCOPE_SITE, Url::MODE_CLEAN);
        }
    }

    public static function url(FileModel $file): ?string
    {
        if (empty($file->file_name) || empty($file->file_path)) {
            return null;
        }

        $diskName = self::resolveDisk($file);

        // Unlocked / public web disk → direct URL (`/storage/{disk}/…` or remote).
        if ($diskName && FileConfig::isPublicDisk($diskName)) {
            $key = self::key($file->file_path, $file->file_name);
            $url = self::webUrl($file->app, $diskName, $key);
            if ($url !== null) {
                return $url;
            }
        }

        $dispatcher = self::dispatcherUrl($file);
        if ($dispatcher !== null) {
            return $dispatcher;
        }

        if (self::legacyExists($file)) {
            return Url::asset($file->file_path . '/' . $file->file_name);
        }

        return Url::asset($file->file_path . '/' . $file->file_name);
    }

    public static function thumbUrl(FileModel $file): ?string
    {
        if ($file->file_ext === 'svg') {
            return self::url($file);
        }

        if (!in_array($file->file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return null;
        }

        $diskName = self::resolveDisk($file);

        if ($diskName && FileConfig::isPublicDisk($diskName)) {
            $key = self::thumbKey($file->file_path, $file->file_name);
            $url = self::webUrl($file->app, $diskName, $key);
            if ($url !== null) {
                return $url;
            }
        }

        $dispatcher = self::dispatcherUrl($file, true);
        if ($dispatcher !== null) {
            return $dispatcher;
        }

        if (self::legacyThumbExists($file)) {
            return Url::asset($file->file_path . '/thumbs/thumb_' . $file->file_name);
        }

        return null;
    }

    public static function delete(FileModel $file): void
    {
        $disk = self::disk($file->app, self::resolveDisk($file));
        $paths = [
            self::key($file->file_path, $file->file_name),
            self::thumbKey($file->file_path, $file->file_name),
        ];

        $existing = array_values(array_filter($paths, static fn (string $path) => $disk->exists($path)));

        if ($existing !== []) {
            $disk->delete($existing);
        }

        self::deleteLegacy($file);
    }

    /**
     * Direct disk URL without probing file existence (list/API hot path).
     */
    private static function webUrl(?string $package, string $diskName, string $key): ?string
    {
        try {
            $disk = self::disk($package, $diskName);
            if (!method_exists($disk, 'url')) {
                return null;
            }
            $url = $disk->url($key);

            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function legacyExists(FileModel $file): bool
    {
        $path = path($file->file_path, $file->app) . '/' . $file->file_name;

        return is_file($path);
    }

    private static function legacyThumbExists(FileModel $file): bool
    {
        $path = path($file->file_path, $file->app) . '/thumbs/thumb_' . $file->file_name;

        return is_file($path);
    }

    private static function deleteLegacy(FileModel $file): void
    {
        $base = path($file->file_path, $file->app);
        $original = $base . '/' . $file->file_name;
        $thumb = $base . '/thumbs/thumb_' . $file->file_name;

        if (is_file($original)) {
            unlink($original);
        }

        if (is_file($thumb)) {
            unlink($thumb);
        }
    }
}
