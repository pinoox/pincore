<?php

namespace Pinoox\Component\Template\Frontend;

use Pinoox\Support\ProjectCli;

/**
 * Shared dev/build metadata between PHP and @pinooxhq/vite-plugin.
 *
 * Vite still writes theme-local `.pinoox/dev.json`; PHP mirrors entries into
 * `{project}/.pinoox/dev.json` so multi-app dev state is visible in one place.
 *
 * @phpstan-type DevState array{viteUrl?: string, port?: int, outDir?: string}
 * @phpstan-type DevRegistry array<string, DevState>
 */
final class FrontendDevState
{
    /** Theme-local path written by @pinooxhq/vite-plugin. */
    public const RELATIVE_PATH = '.pinoox/dev.json';

    /** Project-root registry (all themes). */
    public const PROJECT_REGISTRY_RELATIVE_PATH = '.pinoox/dev.json';

    public static function relativePath(): string
    {
        return self::RELATIVE_PATH;
    }

    public static function projectRegistryRelativePath(): string
    {
        return self::PROJECT_REGISTRY_RELATIVE_PATH;
    }

    public static function absolutePath(string $themePath): string
    {
        return rtrim(str_replace('\\', '/', $themePath), '/') . '/' . self::RELATIVE_PATH;
    }

    public static function projectRegistryAbsolutePath(?string $projectRoot = null): string
    {
        $root = rtrim(str_replace('\\', '/', ProjectCli::root($projectRoot)), '/');

        return $root . '/' . self::PROJECT_REGISTRY_RELATIVE_PATH;
    }

    public static function themeRegistryKey(string $themePath, ?string $projectRoot = null): string
    {
        $themePath = rtrim(str_replace('\\', '/', $themePath), '/');
        $root = rtrim(str_replace('\\', '/', ProjectCli::root($projectRoot)), '/');

        if ($root !== '' && str_starts_with($themePath, $root . '/')) {
            return substr($themePath, strlen($root) + 1);
        }

        return $themePath;
    }

    /**
     * @return DevState|null
     */
    public static function read(string $themePath): ?array
    {
        $local = self::readThemeLocal($themePath);

        if ($local !== null) {
            return $local;
        }

        return self::readFromRegistry($themePath);
    }

    /**
     * @return DevState|null
     */
    private static function readThemeLocal(string $themePath): ?array
    {
        $path = self::absolutePath($themePath);

        if (!is_file($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : null;
    }

    /**
     * @return DevState|null
     */
    private static function readFromRegistry(string $themePath, ?string $projectRoot = null): ?array
    {
        $registry = self::readRegistry($projectRoot);

        if ($registry === null) {
            return null;
        }

        $entry = $registry[self::themeRegistryKey($themePath, $projectRoot)] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * @return DevRegistry|null
     */
    private static function readRegistry(?string $projectRoot = null): ?array
    {
        $path = self::projectRegistryAbsolutePath($projectRoot);

        if (!is_file($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);

        if (!is_array($json)) {
            return null;
        }

        if (isset($json['themes']) && is_array($json['themes'])) {
            /** @var DevRegistry $themes */
            $themes = $json['themes'];

            return $themes;
        }

        /** @var DevRegistry $json */
        return $json;
    }

    public static function write(
        string $themePath,
        ?string $viteUrl = null,
        ?int $port = null,
        ?string $outDir = null,
        ?string $projectRoot = null,
    ): void {
        /** @var DevState $state */
        $state = self::readThemeLocal($themePath) ?? self::readFromRegistry($themePath) ?? [];

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

        self::writeThemeLocal($themePath, $state);
        self::writeRegistryEntry($themePath, $state, $projectRoot);
    }

    /**
     * @param DevState $state
     */
    private static function writeThemeLocal(string $themePath, array $state): void
    {
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

    /**
     * @param DevState $state
     */
    private static function writeRegistryEntry(string $themePath, array $state, ?string $projectRoot = null): void
    {
        $registry = self::readRegistry($projectRoot) ?? [];
        $key = self::themeRegistryKey($themePath, $projectRoot);
        $registry[$key] = $state;

        if ($registry === []) {
            self::removeRegistryFile($projectRoot);

            return;
        }

        $path = self::projectRegistryAbsolutePath($projectRoot);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $path,
            json_encode(['themes' => $registry], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    public static function remove(string $themePath, ?string $projectRoot = null): void
    {
        $path = self::absolutePath($themePath);

        if (is_file($path)) {
            @unlink($path);
        }

        self::removeRegistryEntry($themePath, $projectRoot);
        self::removeLegacyArtifacts($themePath);
    }

    private static function removeRegistryEntry(string $themePath, ?string $projectRoot = null): void
    {
        $registry = self::readRegistry($projectRoot);

        if ($registry === null) {
            return;
        }

        $key = self::themeRegistryKey($themePath, $projectRoot);
        unset($registry[$key]);

        if ($registry === []) {
            self::removeRegistryFile($projectRoot);

            return;
        }

        $path = self::projectRegistryAbsolutePath($projectRoot);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $path,
            json_encode(['themes' => $registry], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    private static function removeRegistryFile(?string $projectRoot = null): void
    {
        $path = self::projectRegistryAbsolutePath($projectRoot);

        if (is_file($path)) {
            @unlink($path);
        }
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
