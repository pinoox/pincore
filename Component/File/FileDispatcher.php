<?php

namespace Pinoox\Component\File;

use Closure;
use Pinoox\Component\Http\Response;
use Pinoox\Model\FileModel;
use Pinoox\Portal\Auth;
use Pinoox\Portal\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use function Pinoox\Router\get;

class FileDispatcher
{
    /** @var array<string, Closure> */
    private static array $authCallbacks = [];

    private static ?Closure $defaultAuth = null;

    /**
     * Register dispatcher routes for a URL prefix (e.g. `file`, `direct`, `link/to`).
     * Must run inside a Router load context (route file or AppEngine core routes).
     */
    public static function registerRoutes(?string $prefix = null): void
    {
        $prefix = FileConfig::normalizeDispatcherPath($prefix ?? FileConfig::DISPATCHER_DEFAULT);
        $base = '/' . $prefix;

        get(
            path: $base . '/{hash}',
            action: [self::class, 'show'],
        );

        get(
            path: $base . '/{hash}/thumb',
            action: [self::class, 'thumb'],
        );
    }

    public static function auth(Closure $callback): void
    {
        self::$defaultAuth = $callback;
    }

    public static function authFor(string $package, Closure $callback): void
    {
        self::$authCallbacks[$package] = $callback;
    }

    public static function clearAuth(): void
    {
        self::$authCallbacks = [];
        self::$defaultAuth = null;
    }

    public static function forgetHash(?string $hash): void
    {
        $hash = trim((string) $hash);
        if ($hash === '') {
            return;
        }

        try {
            Cache::delete(self::cacheKey($hash));
        } catch (\Throwable) {
            // cache optional
        }
    }

    public function show(string $hash): Response|BinaryFileResponse|StreamedResponse
    {
        return $this->serve($hash, false);
    }

    public function thumb(string $hash): Response|BinaryFileResponse|StreamedResponse
    {
        return $this->serve($hash, true);
    }

    private function serve(string $hash, bool $thumb): Response|BinaryFileResponse|StreamedResponse
    {
        $file = $this->findByHash($hash);

        // Deprecated fallback: numeric file_id when hash_id is missing from older clients
        if (!$file && ctype_digit($hash)) {
            $file = FileModel::withoutGlobalScopes()->where('file_id', (int) $hash)->first();
        }

        if (!$file) {
            return new Response('Not Found', 404);
        }

        if (!$this->authorize($file, $thumb)) {
            return new Response('Forbidden', 403);
        }

        $disk = FileStorage::disk($file->app, FileStorage::resolveDisk($file));
        $key = $thumb
            ? FileStorage::thumbKey($file->file_path, $file->file_name)
            : FileStorage::key($file->file_path, $file->file_name);

        if ($thumb && !$disk->exists($key)) {
            $key = FileStorage::key($file->file_path, $file->file_name);
        }

        if (!$disk->exists($key)) {
            $legacy = $thumb
                ? path($file->file_path, $file->app) . '/thumbs/thumb_' . $file->file_name
                : path($file->file_path, $file->app) . '/' . $file->file_name;

            if (is_file($legacy)) {
                return $this->streamLocalPath($legacy, $file);
            }

            return new Response('Not Found', 404);
        }

        try {
            $absolute = $disk->path($key);
            if (is_string($absolute) && is_file($absolute)) {
                return $this->streamLocalPath($absolute, $file);
            }
        } catch (\Throwable) {
            // remote disks fall through to streamed response
        }

        $headers = $this->cacheHeaders($file);
        $name = $file->file_realname ?: $file->file_name;

        return $disk->response($key, $name, $headers, 'inline');
    }

    private function findByHash(string $hash): ?FileModel
    {
        $hash = trim($hash);
        if ($hash === '') {
            return null;
        }

        $ttl = max(0, (int) env('FILE_LOOKUP_CACHE_TTL', 60));
        if ($ttl > 0) {
            try {
                $cached = Cache::get(self::cacheKey($hash));
                if (is_array($cached) && $cached !== []) {
                    $file = new FileModel();
                    $file->forceFill($cached);
                    $file->exists = true;

                    return $file;
                }
            } catch (\Throwable) {
                // ignore cache failures
            }
        }

        $file = FileModel::withoutGlobalScopes()
            ->where('hash_id', $hash)
            ->first();

        if ($file && $ttl > 0) {
            try {
                Cache::set(self::cacheKey($hash), $file->getAttributes(), $ttl);
            } catch (\Throwable) {
                // ignore
            }
        }

        return $file;
    }

    private function authorize(FileModel $file, bool $thumb = false): bool
    {
        $expires = $_GET['expires'] ?? null;
        $signature = $_GET['signature'] ?? null;
        if (FileTemporaryUrl::isValid((string) ($file->hash_id ?? ''), $expires, is_string($signature) ? $signature : null, $thumb)) {
            return true;
        }

        $callback = self::$authCallbacks[$file->app ?? ''] ?? self::$defaultAuth;

        return FilePolicy::allows($file, Auth::user(), $callback instanceof Closure ? $callback : null);
    }

    /**
     * @return array<string, string>
     */
    private function cacheHeaders(FileModel $file): array
    {
        $etag = '"' . ($file->hash_id ?: (string) $file->file_id) . '-' . (int) $file->file_size . '"';

        return [
            'ETag' => $etag,
            'Cache-Control' => (FileStorage::resolveDisk($file) === 'public'
                || strtolower((string) $file->file_access) === 'public')
                ? 'public, max-age=604800'
                : 'private, max-age=0, must-revalidate',
        ];
    }

    private function streamLocalPath(string $absolute, FileModel $file): Response|BinaryFileResponse
    {
        $headers = $this->cacheHeaders($file);
        $mime = mime_content_type($absolute) ?: 'application/octet-stream';
        $headers['Content-Type'] = $mime;

        if ($this->wantsXSendfile()) {
            $response = new Response('', 200, $headers);
            $response->headers->set('X-Sendfile', $absolute);

            return $response;
        }

        if ($this->wantsXAccel()) {
            $response = new Response('', 200, $headers);
            $response->headers->set('X-Accel-Redirect', $this->accelPath($absolute));

            return $response;
        }

        $response = new BinaryFileResponse($absolute, 200, $headers, true, 'inline');
        $response->setAutoEtag();
        $response->headers->set('Content-Type', $mime);

        if (!empty($headers['ETag'])) {
            $response->setEtag(trim($headers['ETag'], '"'));
        }

        return $response;
    }

    private function wantsXSendfile(): bool
    {
        $flag = env('FILE_XSENDFILE', 'auto');

        if (is_bool($flag)) {
            return $flag;
        }

        $flag = strtolower(trim((string) $flag));
        if ($flag === '' || $flag === 'auto') {
            return function_exists('apache_get_modules')
                && in_array('mod_xsendfile', apache_get_modules(), true);
        }

        return filter_var($flag, FILTER_VALIDATE_BOOL);
    }

    private function wantsXAccel(): bool
    {
        $flag = env('FILE_XACCEL', false);

        return filter_var($flag, FILTER_VALIDATE_BOOL);
    }

    private function accelPath(string $absolute): string
    {
        $prefix = (string) env('FILE_XACCEL_PREFIX', '/protected');

        return rtrim($prefix, '/') . '/' . ltrim(str_replace('\\', '/', $absolute), '/');
    }

    private static function cacheKey(string $hash): string
    {
        return 'file.dispatch.' . $hash;
    }
}
