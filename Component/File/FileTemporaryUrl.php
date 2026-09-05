<?php

namespace Pinoox\Component\File;

use DateInterval;
use DateTimeInterface;
use Pinoox\Model\FileModel;
use Pinoox\Portal\Config;

class FileTemporaryUrl
{
    public static function make(FileModel $file, DateTimeInterface|DateInterval|int $expiration, bool $thumb = false): ?string
    {
        $diskName = FileStorage::resolveDisk($file) ?: 'local';

        // Unlocked / public web disk already has a stable public URL.
        if (FileConfig::isPublicDisk($diskName)) {
            return $thumb ? FileStorage::thumbUrl($file) : FileStorage::url($file);
        }

        // Prefer native disk temporary URLs (S3, local with serve, etc.).
        try {
            $disk = FileStorage::disk($file->app, $diskName);
            if (method_exists($disk, 'providesTemporaryUrls') && $disk->providesTemporaryUrls()) {
                $key = $thumb
                    ? FileStorage::thumbKey((string) $file->file_path, (string) $file->file_name)
                    : FileStorage::key((string) $file->file_path, (string) $file->file_name);

                return $disk->temporaryUrl($key, self::toDateTime($expiration));
            }
        } catch (\Throwable) {
            // fall through to signed dispatcher URL
        }

        $hash = trim((string) ($file->hash_id ?? ''));
        $base = FileStorage::dispatcherUrl($file, $thumb);
        if ($hash === '' || $base === null || $base === '') {
            return null;
        }

        $expires = self::toDateTime($expiration)->getTimestamp();
        $signature = self::sign($hash, $expires, $thumb);
        $sep = str_contains($base, '?') ? '&' : '?';

        return $base . $sep . http_build_query([
            'expires' => $expires,
            'signature' => $signature,
        ]);
    }

    public static function isValid(string $hash, int|string|null $expires, ?string $signature, bool $thumb = false): bool
    {
        $expires = (int) $expires;
        $signature = trim((string) $signature);
        $hash = trim($hash);

        if ($hash === '' || $expires < 1 || $signature === '') {
            return false;
        }

        if ($expires < time()) {
            return false;
        }

        $expected = self::sign($hash, $expires, $thumb);

        return hash_equals($expected, $signature);
    }

    public static function sign(string $hash, int $expires, bool $thumb = false): string
    {
        $payload = $hash . '|' . $expires . ($thumb ? '|thumb' : '');

        return hash_hmac('sha256', $payload, self::key());
    }

    private static function key(): string
    {
        $key = (string) (Config::name('~security')->get('key') ?: env('APP_KEY', ''));

        return $key !== '' ? $key : 'pinoox-file-temporary-url';
    }

    private static function toDateTime(DateTimeInterface|DateInterval|int $expiration): DateTimeInterface
    {
        if ($expiration instanceof DateTimeInterface) {
            return $expiration;
        }

        if ($expiration instanceof DateInterval) {
            return (new \DateTimeImmutable('now'))->add($expiration);
        }

        return new \DateTimeImmutable('@' . (time() + max(0, (int) $expiration)));
    }
}
