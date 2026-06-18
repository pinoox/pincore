<?php

namespace Pinoox\Support;

use Pinoox\Component\Http\Request;
use Pinoox\Component\Http\Response;

/**
 * Detect web requests for missing static assets that should not fall through to app routing.
 */
final class StaticAssetRequest
{
    /** @var list<string> */
    private const STATIC_EXTENSIONS = [
        'css',
        'js',
        'mjs',
        'cjs',
        'map',
        'woff',
        'woff2',
        'ttf',
        'otf',
        'eot',
        'svg',
        'png',
        'jpg',
        'jpeg',
        'gif',
        'webp',
        'ico',
        'avif',
    ];

    public static function shouldReturnPlainNotFound(Request $request, ?string $projectRoot = null): bool
    {
        $path = self::normalizePath($request->getPathInfo());

        if ($path === '' || !self::hasStaticExtension($path)) {
            return false;
        }

        $projectRoot = $projectRoot ?? rtrim(str_replace('\\', '/', SystemConfig::path('~')), '/');
        $appsPrefix = AppPublicPath::appsDirectoryPrefix($projectRoot);

        if ($appsPrefix === '') {
            return false;
        }

        return str_starts_with($path, $appsPrefix . '/');
    }

    public static function notFoundResponse(): Response
    {
        return new Response('', 404, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    private static function normalizePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }

    private static function hasStaticExtension(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::STATIC_EXTENSIONS, true);
    }
}
