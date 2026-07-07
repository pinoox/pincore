<?php

namespace Pinoox\Component\Template\Frontend;

/**
 * Shared dev/build metadata between PHP and @pinooxhq/vite-plugin.
 *
 * @phpstan-type DevState array{viteUrl?: string, port?: int, outDir?: string}
 */
final class FrontendDevState
{
    public const RELATIVE_PATH = '.pinoox/dev.json';

    public static function relativePath(): string
    {
        return self::RELATIVE_PATH;
    }

    public static function absolutePath(string $themePath): string
    {
        return rtrim(str_replace('\\', '/', $themePath), '/') . '/' . self::RELATIVE_PATH;
    }

    /**
     * @return DevState|null
     */
    public static function read(string $themePath): ?array
    {
        $path = self::absolutePath($themePath);

        if (!is_file($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : null;
    }

    public static function write(
        string $themePath,
        ?string $viteUrl = null,
        ?int $port = null,
        ?string $outDir = null,
    ): void {
        /** @var DevState $state */
        $state = self::read($themePath) ?? [];

        if ($viteUrl !== null) {
            $trimmed = trim($viteUrl);

            if ($trimmed !== '') {
                $state['viteUrl'] = rtrim($trimmed, '/');
            } else {
                unset($state['viteUrl']);
            }
        }

        if ($port !== null) {
            if ($port > 0 && $port <= 65535) {
                $state['port'] = $port;
            } else {
                unset($state['port']);
            }
        }

        if ($outDir !== null) {
            $normalized = self::normalizeOutDir($outDir);

            if ($normalized !== '') {
                $state['outDir'] = $normalized;
            } else {
                unset($state['outDir']);
            }
        }

        if ($state === []) {
            self::remove($themePath);

            return;
        }

        $path = self::absolutePath($themePath);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $path,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    public static function remove(string $themePath): void
    {
        $path = self::absolutePath($themePath);

        if (is_file($path)) {
            @unlink($path);
        }

        self::removeLegacyArtifacts($themePath);
    }

    public static function isActive(string $themePath): bool
    {
        return self::viteUrl($themePath) !== null;
    }

    public static function viteUrl(string $themePath): ?string
    {
        $state = self::read($themePath);
        $url = trim((string) ($state['viteUrl'] ?? ''));

        return $url !== '' ? rtrim($url, '/') : null;
    }

    public static function port(string $themePath): ?int
    {
        $state = self::read($themePath);
        $port = $state['port'] ?? null;

        if (is_numeric($port) && (int) $port > 0) {
            return (int) $port;
        }

        $url = self::viteUrl($themePath);

        if ($url === null) {
            return null;
        }

        $parsed = parse_url($url);
        $fromUrl = $parsed['port'] ?? null;

        return is_numeric($fromUrl) && (int) $fromUrl > 0 ? (int) $fromUrl : null;
    }

    public static function outDir(string $themePath): ?string
    {
        $state = self::read($themePath);
        $outDir = trim((string) ($state['outDir'] ?? ''));

        return $outDir !== '' ? self::normalizeOutDir($outDir) : null;
    }

    private static function normalizeOutDir(string $outDir): string
    {
        return trim(str_replace('\\', '/', $outDir), '/');
    }

    private static function removeLegacyArtifacts(string $themePath): void
    {
        $base = rtrim(str_replace('\\', '/', $themePath), '/');

        foreach ([
            $base . '/.pinoox/build-out-dir',
            $base . '/dist/.vite-dev-port',
            $base . '/dist/hot',
        ] as $legacy) {
            if (is_file($legacy)) {
                @unlink($legacy);
            }
        }
    }
}
