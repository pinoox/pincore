<?php

namespace Pinoox\Component\Package;

use Pinoox\Component\Kernel\Exception;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Prepare a slim Composer vendor tree for distributable app packages.
 *
 * Platform packages (pinoox/pincore, pinoox/pinx-cli) and PHP extensions are
 * excluded automatically. Only third-party requires declared in composer.json
 * are installed into .pinx-build/vendor for pinx packaging.
 */
final class AppComposerVendor
{
    public const BUILD_DIR = '.pinx-build';

    public const VENDOR_SUBDIR = 'vendor';

    /** @var list<string> */
    public const PLATFORM_PACKAGES = [
        'pinoox/pincore',
        'pinoox/pinx-cli',
    ];

    public static function composerJsonPath(string $appPath): string
    {
        return rtrim(str_replace('\\', '/', $appPath), '/') . '/composer.json';
    }

    public static function buildDirectory(string $appPath): string
    {
        return rtrim(str_replace('\\', '/', $appPath), '/') . '/' . self::BUILD_DIR;
    }

    public static function distributionVendorPath(string $appPath): string
    {
        return self::buildDirectory($appPath) . '/' . self::VENDOR_SUBDIR;
    }

    public static function distributionVendorRelativePath(): string
    {
        return self::BUILD_DIR . '/' . self::VENDOR_SUBDIR;
    }

    public static function hasComposerJson(string $appPath): bool
    {
        return is_file(self::composerJsonPath($appPath));
    }

    /**
     * @return array<string, string>
     */
    public static function distributionRequires(string $appPath): array
    {
        if (!self::hasComposerJson($appPath)) {
            return [];
        }

        $raw = file_get_contents(self::composerJsonPath($appPath));

        if (!is_string($raw)) {
            return [];
        }

        $composer = json_decode($raw, true);

        if (!is_array($composer)) {
            return [];
        }

        $requires = [];

        foreach ($composer['require'] ?? [] as $name => $constraint) {
            if (!is_string($name) || !is_string($constraint)) {
                continue;
            }

            if (in_array($name, self::PLATFORM_PACKAGES, true)) {
                continue;
            }

            if ($name === 'php' || str_starts_with($name, 'ext-')) {
                continue;
            }

            $requires[$name] = $constraint;
        }

        return $requires;
    }

    public static function hasDistributionRequires(string $appPath): bool
    {
        return self::distributionRequires($appPath) !== [];
    }

    /**
     * @return array{
     *     prepared: bool,
     *     reason: ?string,
     *     vendor_dir: ?string,
     *     vendor_as: ?string,
     *     packages: list<string>
     * }
     */
    public static function prepare(string $appPath, ?string $projectRoot = null): array
    {
        $requires = self::distributionRequires($appPath);

        if ($requires === []) {
            return [
                'prepared' => false,
                'reason' => 'no third-party composer requires',
                'vendor_dir' => null,
                'vendor_as' => null,
                'packages' => [],
            ];
        }

        if (!self::hasComposerJson($appPath)) {
            return [
                'prepared' => false,
                'reason' => 'composer.json not found',
                'vendor_dir' => null,
                'vendor_as' => null,
                'packages' => [],
            ];
        }

        $buildDir = self::buildDirectory($appPath);
        self::resetBuildDirectory($buildDir);

        $distributionComposer = self::buildDistributionComposer($appPath, $requires);
        $distributionComposerPath = $buildDir . '/composer.json';

        if (file_put_contents(
            $distributionComposerPath,
            json_encode($distributionComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        ) === false) {
            throw new Exception('Failed to write distribution composer.json');
        }

        $projectRoot ??= self::detectProjectRoot($appPath);
        $command = self::buildInstallCommand($buildDir, $projectRoot);

        $process = new Process(
            $command,
            $buildDir,
            null,
            null,
            600,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            $output = trim($process->getErrorOutput() . "\n" . $process->getOutput());

            throw new Exception('Composer install failed for app distribution vendor: ' . ($output !== '' ? $output : 'unknown error'));
        }

        $vendorPath = self::distributionVendorPath($appPath);

        if (!is_file($vendorPath . '/autoload.php')) {
            throw new Exception('Distribution composer install did not produce vendor/autoload.php');
        }

        return [
            'prepared' => true,
            'reason' => null,
            'vendor_dir' => self::distributionVendorRelativePath(),
            'vendor_as' => self::VENDOR_SUBDIR,
            'packages' => array_keys($requires),
        ];
    }

    public static function cleanup(string $appPath): void
    {
        self::removeDirectory(self::buildDirectory($appPath));
    }

    /**
     * @param array<string, string> $requires
     * @return array<string, mixed>
     */
    private static function buildDistributionComposer(string $appPath, array $requires): array
    {
        $raw = file_get_contents(self::composerJsonPath($appPath));
        $source = is_string($raw) ? json_decode($raw, true) : null;
        $source = is_array($source) ? $source : [];

        $composer = [
            'name' => 'pinoox/app-distribution-vendor',
            'description' => 'Auto-generated vendor tree for pinx package build',
            'type' => 'project',
            'require' => [],
            'config' => [
                'sort-packages' => true,
            ],
            'minimum-stability' => $source['minimum-stability'] ?? 'stable',
            'prefer-stable' => $source['prefer-stable'] ?? true,
        ];

        $allowPlugins = $source['config']['allow-plugins'] ?? null;

        if (is_array($allowPlugins) && $allowPlugins !== []) {
            $composer['config']['allow-plugins'] = $allowPlugins;
        }

        if (isset($source['require']['php'])) {
            $composer['require']['php'] = $source['require']['php'];
        }

        foreach ($requires as $name => $constraint) {
            $composer['require'][$name] = $constraint;
        }

        if (!empty($source['repositories']) && is_array($source['repositories'])) {
            $composer['repositories'] = self::filterDistributionRepositories($source['repositories']);
        }

        return $composer;
    }

    /**
     * @param list<array<string, mixed>> $repositories
     * @return list<array<string, mixed>>
     */
    private static function filterDistributionRepositories(array $repositories): array
    {
        $filtered = [];

        foreach ($repositories as $repository) {
            if (!is_array($repository)) {
                continue;
            }

            $type = $repository['type'] ?? null;

            if ($type === 'path') {
                continue;
            }

            $filtered[] = $repository;
        }

        return $filtered;
    }

    private static function resetBuildDirectory(string $buildDir): void
    {
        self::removeDirectory($buildDir);

        if (!mkdir($buildDir, 0777, true) && !is_dir($buildDir)) {
            throw new Exception('Failed to create build directory: ' . $buildDir);
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

    /**
     * @return list<string>
     */
    public static function buildInstallCommand(string $workingDirectory, ?string $projectRoot = null): array
    {
        $composer = self::resolveComposerBinary($projectRoot);

        if (str_contains($composer, ' ') && str_ends_with($composer, '.phar')) {
            return array_merge(explode(' ', $composer, 2), [
                'update',
                '--no-dev',
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
            '--optimize-autoloader',
            '--no-interaction',
            '--no-progress',
            '--no-ansi',
        ];
    }

    public static function resolveComposerBinary(?string $projectRoot = null): string
    {
        $env = getenv('COMPOSER_BIN');
        if (is_string($env) && $env !== '') {
            return $env;
        }

        if ($projectRoot !== null) {
            $localPhar = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/composer.phar';
            if (is_file($localPhar)) {
                return PHP_BINARY . ' ' . $localPhar;
            }
        }

        $finder = new ExecutableFinder();
        $binary = $finder->find('composer');
        if (is_string($binary) && $binary !== '') {
            return $binary;
        }

        return 'composer';
    }

    public static function detectProjectRoot(string $appPath): string
    {
        $dir = rtrim(str_replace('\\', '/', $appPath), '/');

        while ($dir !== '' && $dir !== '.' && $dir !== '/') {
            if (is_dir($dir . '/pincore') && is_dir($dir . '/apps')) {
                return $dir;
            }

            if (is_file($dir . '/app.php') && is_dir($dir . '/vendor/pinoox/pincore')) {
                return $dir;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        return dirname($dir, 2);
    }
}
