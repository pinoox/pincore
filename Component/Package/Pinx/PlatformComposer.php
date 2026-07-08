<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Kernel\Exception;
use Pinoox\Component\Package\AppComposerVendor;
use Pinoox\Component\Package\ComposerVendorGuard;

/**
 * Bundle the project Composer vendor tree for platform .zip builds.
 *
 * Build does not run Composer. Install dependencies first:
 *   composer install --no-dev --optimize-autoloader
 */
final class PlatformComposer
{
    /**
     * @return array{
     *     prepared: bool,
     *     reason: ?string,
     *     packages: list<string>,
     *     materialized: list<string>
     * }
     */
    public static function prepare(string $projectRoot, bool $stripRequireDev = true): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $composerJson = self::composerJsonPath($projectRoot);

        if (!is_file($composerJson)) {
            return [
                'prepared' => false,
                'reason' => 'composer.json not found',
                'packages' => [],
                'materialized' => [],
            ];
        }

        ComposerVendorGuard::requireInstalled($projectRoot, 'platform');

        if ($stripRequireDev) {
            ComposerVendorGuard::assertProductionVendor($projectRoot, 'platform');
        }

        $distributionComposer = self::distributionComposer($composerJson, $stripRequireDev);
        $stagingVendor = self::vendorPath($projectRoot);
        $sourceVendor = ComposerVendorGuard::vendorDir($projectRoot);

        ComposerVendorGuard::copyVendorTree($sourceVendor, $stagingVendor);
        $materialized = PlatformVendorMaterializer::materialize($stagingVendor, $projectRoot);

        return [
            'prepared' => true,
            'reason' => null,
            'packages' => array_keys($distributionComposer['require'] ?? []),
            'materialized' => $materialized,
        ];
    }

    public static function stagingRoot(string $projectRoot): string
    {
        return PlatformBuildConfig::buildPath($projectRoot, PlatformBuildConfig::STAGING_DIR);
    }

    public static function vendorPath(string $projectRoot): string
    {
        return self::stagingRoot($projectRoot) . '/vendor';
    }

    public static function composerJsonPath(string $projectRoot): string
    {
        return rtrim(str_replace('\\', '/', $projectRoot), '/') . '/composer.json';
    }

    public static function cleanup(string $projectRoot): void
    {
        self::removeDirectory(PlatformBuildConfig::buildPath($projectRoot));
    }

    /**
     * @return array<string, mixed>
     */
    public static function distributionComposer(string $composerJsonPath, bool $stripRequireDev = true): array
    {
        $raw = file_get_contents($composerJsonPath);

        if (!is_string($raw)) {
            throw new Exception('Unable to read composer.json');
        }

        $source = json_decode($raw, true);

        if (!is_array($source)) {
            throw new Exception('Invalid composer.json');
        }

        if ($stripRequireDev) {
            unset($source['require-dev'], $source['autoload-dev']);
        }

        unset($source['scripts']);

        $projectRoot = dirname($composerJsonPath);
        $source['repositories'] = self::mergeRepositories(
            is_array($source['repositories'] ?? null) ? $source['repositories'] : [],
            PlatformVendorMaterializer::pathRepositoriesForComposer($projectRoot),
        );

        return $source;
    }

    /**
     * @return array<string, mixed>
     */
    public static function distributionComposerForApp(string $composerJsonPath, bool $stripRequireDev = true): array
    {
        $distribution = self::distributionComposer($composerJsonPath, $stripRequireDev);
        unset($distribution['scripts']);

        return $distribution;
    }

    /**
     * @param array<int|string, mixed> $existing
     * @param list<array{type: string, url: string, options?: array<string, mixed>}> $pathRepositories
     * @return list<array<string, mixed>>
     */
    private static function mergeRepositories(array $existing, array $pathRepositories): array
    {
        $merged = array_is_list($existing) ? $existing : array_values(array_filter($existing, 'is_array'));
        $seen = [];

        foreach ($merged as $repository) {
            if (!is_array($repository)) {
                continue;
            }

            if (($repository['type'] ?? '') === 'path' && isset($repository['url'])) {
                $seen[self::repositoryKey((string) $repository['url'])] = true;
            }
        }

        foreach ($pathRepositories as $repository) {
            $key = self::repositoryKey((string) ($repository['url'] ?? ''));

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            array_unshift($merged, $repository);
            $seen[$key] = true;
        }

        return $merged;
    }

    private static function repositoryKey(string $url): string
    {
        $url = trim(str_replace('\\', '/', $url));

        return $url === '' ? '' : (realpath($url) ?: $url);
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath) && !is_link($fullPath)) {
                self::removeDirectory($fullPath);
                continue;
            }

            @unlink($fullPath);
        }

        @rmdir($path);
    }
}
