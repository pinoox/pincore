<?php

namespace Pinoox\Component\Package;

use Pinoox\Component\Kernel\Exception;
use Symfony\Component\Process\ExecutableFinder;

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
     * Package names that should ship in an app .pinx vendor tree.
     *
     * Direct composer.json require entries (minus php/ext/platform packages)
     * plus their installed transitive dependencies.
     *
     * @return list<string>
     */
    public static function distributionPackageNames(string $appPath): array
    {
        $direct = array_keys(self::distributionRequires($appPath));

        if ($direct === []) {
            return [];
        }

        $index = self::installedPackageIndex($appPath);

        if ($index === [] || !self::hasRequireGraph($index)) {
            sort($direct);

            return array_values(array_unique($direct));
        }

        $keep = [];
        $queue = $direct;

        while ($queue !== []) {
            $name = array_shift($queue);

            if (isset($keep[$name]) || in_array($name, self::PLATFORM_PACKAGES, true)) {
                continue;
            }

            if ($name === 'php' || str_starts_with($name, 'ext-')) {
                continue;
            }

            if (!isset($index[$name])) {
                continue;
            }

            $keep[$name] = true;

            foreach ($index[$name]['require'] as $dependency) {
                $queue[] = $dependency;
            }
        }

        $names = array_keys($keep);
        sort($names);

        return $names;
    }

    /**
     * Vendor-relative directories for {@see distributionPackageNames()}.
     *
     * @return list<string>
     */
    public static function distributionVendorPaths(string $appPath): array
    {
        $index = self::installedPackageIndex($appPath);
        $paths = [];

        foreach (self::distributionPackageNames($appPath) as $name) {
            $path = $index[$name]['path'] ?? $name;

            if ($path !== '') {
                $paths[] = $path;
            }
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    /**
     * Vendor-relative directories that must not ship in an app .pinx.
     *
     * @return list<string>
     */
    public static function excludedDistributionVendorPaths(string $appPath): array
    {
        $keepPaths = array_fill_keys(self::distributionVendorPaths($appPath), true);
        $excluded = ComposerVendorGuard::installedDevVendorPaths($appPath);

        foreach (self::PLATFORM_PACKAGES as $package) {
            $excluded[] = $package;
        }

        foreach (self::installedPackageIndex($appPath) as $name => $meta) {
            if (isset($keepPaths[$meta['path']])) {
                continue;
            }

            if ($meta['path'] !== '') {
                $excluded[] = $meta['path'];
            } elseif (!in_array($name, self::PLATFORM_PACKAGES, true)) {
                $excluded[] = $name;
            }
        }

        sort($excluded);

        return array_values(array_unique(array_filter($excluded)));
    }

    /**
     * Copy a slim vendor tree into .pinx-build/vendor without running dump-autoload.
     *
     * @return array{vendor_dir: string, vendor_as: string, packages: list<string>}
     */
    public static function materializeDistributionVendor(string $appPath, bool $vendorPrune = true): array
    {
        $requires = self::distributionRequires($appPath);
        $appPath = rtrim(str_replace('\\', '/', $appPath), '/');
        ComposerVendorGuard::requireInstalled($appPath, 'app');

        $buildDir = self::buildDirectory($appPath);
        $stagingVendor = self::distributionVendorPath($appPath);
        self::resetBuildDirectory($buildDir);

        ComposerVendorGuard::copyVendorTree(
            ComposerVendorGuard::vendorDir($appPath),
            $stagingVendor,
            $vendorPrune,
            self::excludedDistributionVendorPaths($appPath),
        );

        ComposerVendorGuard::pruneInstalledMetadata(
            $stagingVendor,
            self::excludedDistributionPackageNames($appPath),
        );

        if ($vendorPrune) {
            VendorPruner::prune($stagingVendor);
        }

        return [
            'vendor_dir' => self::distributionVendorRelativePath(),
            'vendor_as' => self::VENDOR_SUBDIR,
            'packages' => array_keys($requires),
        ];
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
    public static function prepare(string $appPath, ?string $projectRoot = null, bool $vendorPrune = true): array
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

        $appPath = rtrim(str_replace('\\', '/', $appPath), '/');
        $materialized = self::materializeDistributionVendor($appPath, $vendorPrune);

        try {
            ComposerVendorGuard::regenerateProductionAutoload(
                self::buildDirectory($appPath),
                self::buildDistributionComposer($appPath, $requires),
                $projectRoot ?? $appPath,
            );
        } catch (\Throwable $e) {
            self::cleanup($appPath);

            throw $e;
        }

        return [
            'prepared' => true,
            'reason' => null,
            'vendor_dir' => $materialized['vendor_dir'],
            'vendor_as' => $materialized['vendor_as'],
            'packages' => $materialized['packages'],
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
     * @return list<string>
     */
    private static function excludedDistributionPackageNames(string $appPath): array
    {
        $keep = array_fill_keys(self::distributionPackageNames($appPath), true);
        $excluded = ComposerVendorGuard::installedDevPackageNames($appPath);

        foreach (self::PLATFORM_PACKAGES as $package) {
            $excluded[] = $package;
        }

        foreach (array_keys(self::installedPackageIndex($appPath)) as $name) {
            if (!isset($keep[$name])) {
                $excluded[] = $name;
            }
        }

        sort($excluded);

        return array_values(array_unique($excluded));
    }

    /**
     * @return array<string, array{path: string, require: list<string>}>
     */
    private static function installedPackageIndex(string $appPath): array
    {
        $vendorDir = rtrim(str_replace('\\', '/', ComposerVendorGuard::vendorDir($appPath)), '/');
        $installedJson = $vendorDir . '/composer/installed.json';

        if (is_file($installedJson)) {
            $raw = file_get_contents($installedJson);
            $installed = is_string($raw) ? json_decode($raw, true) : null;

            if (is_array($installed)) {
                $packages = is_array($installed['packages'] ?? null) ? $installed['packages'] : $installed;
                $index = [];

                foreach ($packages as $package) {
                    if (!is_array($package) || !isset($package['name'])) {
                        continue;
                    }

                    $name = (string) $package['name'];
                    $path = isset($package['install-path'])
                        ? self::vendorRelativePath($vendorDir, (string) $package['install-path'])
                        : $name;

                    if ($path === '') {
                        $path = $name;
                    }

                    $require = [];

                    foreach ($package['require'] ?? [] as $dependency => $constraint) {
                        if (is_string($dependency) && $dependency !== '') {
                            $require[] = $dependency;
                        }
                    }

                    $index[$name] = [
                        'path' => $path,
                        'require' => $require,
                    ];
                }

                if ($index !== []) {
                    return $index;
                }
            }
        }

        $installedPhp = $vendorDir . '/composer/installed.php';

        if (!is_file($installedPhp)) {
            return [];
        }

        $data = include $installedPhp;

        if (!is_array($data['versions'] ?? null)) {
            return [];
        }

        $index = [];

        foreach ($data['versions'] as $name => $meta) {
            if (!is_string($name) || !is_array($meta) || !isset($meta['install_path'])) {
                continue;
            }

            $path = self::vendorRelativePath($vendorDir, (string) $meta['install_path']);

            $index[$name] = [
                'path' => $path !== '' ? $path : $name,
                'require' => [],
            ];
        }

        return $index;
    }

    /**
     * @param array<string, array{path: string, require: list<string>}> $index
     */
    private static function hasRequireGraph(array $index): bool
    {
        foreach ($index as $meta) {
            foreach ($meta['require'] as $dependency) {
                if ($dependency !== 'php' && !str_starts_with($dependency, 'ext-')) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function vendorRelativePath(string $vendorDir, string $installPath): string
    {
        $vendorDir = rtrim(str_replace('\\', '/', $vendorDir), '/');
        $installPath = str_replace('\\', '/', $installPath);

        if ($installPath === '') {
            return '';
        }

        if (!str_starts_with($installPath, '/') && !preg_match('/^[A-Za-z]:\//', $installPath)) {
            $installPath = $vendorDir . '/composer/' . ltrim($installPath, '/');
        }

        $resolved = realpath($installPath);

        if (is_string($resolved) && $resolved !== '') {
            $installPath = str_replace('\\', '/', $resolved);
        }

        if (!is_dir($installPath) && !is_file($installPath)) {
            return '';
        }

        if (!str_starts_with($installPath, $vendorDir)) {
            return '';
        }

        return ltrim(substr($installPath, strlen($vendorDir)), '/');
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
