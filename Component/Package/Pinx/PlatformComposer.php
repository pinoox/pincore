<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Kernel\Exception;
use Pinoox\Component\Package\AppComposerVendor;
use Symfony\Component\Process\Process;

/**
 * Prepare a production-only Composer vendor tree for platform .zip builds.
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
        $composerJson = self::composerJsonPath($projectRoot);

        if (!is_file($composerJson)) {
            return [
                'prepared' => false,
                'reason' => 'composer.json not found',
                'packages' => [],
                'materialized' => [],
            ];
        }

        $stagingRoot = self::stagingRoot($projectRoot);
        self::resetDirectory($stagingRoot);

        $distributionComposer = self::distributionComposer($composerJson, $stripRequireDev);
        $distributionComposerPath = $stagingRoot . '/composer.json';

        if (file_put_contents(
            $distributionComposerPath,
            json_encode($distributionComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        ) === false) {
            throw new Exception('Failed to write platform distribution composer.json');
        }

        $command = self::buildInstallCommand($stagingRoot, $projectRoot);
        $process = new Process($command, $stagingRoot, null, null, 900);
        $process->run();

        if (!$process->isSuccessful()) {
            $output = trim($process->getErrorOutput() . "\n" . $process->getOutput());

            throw new Exception('Composer install failed for platform distribution: ' . ($output !== '' ? $output : 'unknown error'));
        }

        $vendorPath = $stagingRoot . '/vendor';

        if (!is_file($vendorPath . '/autoload.php')) {
            throw new Exception('Platform composer install did not produce vendor/autoload.php');
        }

        $materialized = PlatformVendorMaterializer::materialize($vendorPath, $projectRoot);

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

    private static function buildInstallCommand(string $workingDirectory, ?string $projectRoot): array
    {
        $composer = AppComposerVendor::resolveComposerBinary($projectRoot);

        if (str_contains($composer, ' ') && str_ends_with($composer, '.phar')) {
            return array_merge(explode(' ', $composer, 2), [
                'update',
                '--no-dev',
                '--prefer-dist',
                '--no-scripts',
                '--optimize-autoloader',
                '--no-interaction',
                '--no-progress',
                '--no-ansi',
            ]);
        }

        return [
            $composer,
            'update',
            '--no-dev',
            '--prefer-dist',
            '--no-scripts',
            '--optimize-autoloader',
            '--no-interaction',
            '--no-progress',
            '--no-ansi',
        ];
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

    private static function resetDirectory(string $path): void
    {
        self::removeDirectory($path);

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new Exception('Failed to create platform build directory: ' . $path);
        }
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
