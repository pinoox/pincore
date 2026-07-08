<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Kernel\Exception;

/**
 * Resolve Composer path repositories and materialize vendor/pinoox/* symlinks.
 */
final class PlatformVendorMaterializer
{
    /**
     * @return list<string> materialized package names
     */
    public static function materialize(string $vendorDir, string $projectRoot, ?string $sourceVendorDir = null): array
    {
        $vendorDir = rtrim(str_replace('\\', '/', $vendorDir), '/');
        $sourceVendorDir = $sourceVendorDir !== null
            ? rtrim(str_replace('\\', '/', $sourceVendorDir), '/')
            : null;
        $pinooxVendor = $vendorDir . '/pinoox';

        if (!is_dir($pinooxVendor) && !mkdir($pinooxVendor, 0777, true) && !is_dir($pinooxVendor)) {
            throw new Exception('Failed to create vendor/pinoox directory: ' . $pinooxVendor);
        }

        $pathPackages = self::discoverPathPackages($projectRoot);
        $materialized = [];

        foreach (self::installedPinooxProductionPackages($vendorDir) as $packageName => $shortName) {
            $targetDir = $pinooxVendor . '/' . $shortName;
            $sourcePath = self::resolvePackageSource($packageName, $shortName, $pathPackages, $sourceVendorDir);

            if ($sourcePath === null || !is_dir($sourcePath)) {
                continue;
            }

            if (!self::shouldMaterializePackage($targetDir, $packageName, $shortName, $pathPackages, $sourceVendorDir)) {
                continue;
            }

            self::replaceWithCopy($targetDir, $sourcePath);
            $materialized[] = $packageName;
        }

        sort($materialized);

        return array_values(array_unique($materialized));
    }

    /**
     * @return array<string, string> package name => vendor/pinoox short directory name
     */
    public static function installedPinooxProductionPackages(string $vendorDir): array
    {
        $vendorDir = rtrim(str_replace('\\', '/', $vendorDir), '/');
        $packages = self::installedPinooxProductionPackagesFromPhp($vendorDir);

        if ($packages !== []) {
            return $packages;
        }

        return self::installedPinooxProductionPackagesFromJson($vendorDir);
    }

    /**
     * @return array<string, string> package name => absolute source path
     */
    public static function discoverPathPackages(string $projectRoot): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $packages = [];

        foreach (self::pathRepositoriesFromConfig(self::projectComposerConfig($projectRoot)) as $repository) {
            self::mergePathRepository($packages, $repository, $projectRoot);
        }

        foreach (self::pathRepositoriesFromConfig(self::globalComposerConfig()) as $repository) {
            self::mergePathRepository($packages, $repository, $projectRoot);
        }

        foreach (self::installedPathPackages($projectRoot . '/vendor/composer/installed.json') as $name => $path) {
            $packages[$name] = $path;
        }

        foreach (self::installedPathPackages($projectRoot . '/vendor/composer/installed.php') as $name => $path) {
            $packages[$name] = $path;
        }

        return $packages;
    }

    /**
     * @return list<array{type: string, url: string, options?: array<string, mixed>}> $pathRepositories
     */
    public static function pathRepositoriesForComposer(string $projectRoot): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $repositories = [];
        $seen = [];

        foreach (self::discoverPathPackages($projectRoot) as $packageName => $path) {
            $key = $packageName . '@' . $path;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $repositories[] = [
                'type' => 'path',
                'url' => $path,
                'options' => [
                    'symlink' => false,
                ],
            ];
        }

        return $repositories;
    }

    /**
     * @param array<string, string> $pathPackages
     */
    private static function shouldMaterializePackage(
        string $targetDir,
        string $packageName,
        string $shortName,
        array $pathPackages,
        ?string $sourceVendorDir,
    ): bool {
        if (isset($pathPackages[$packageName])) {
            return true;
        }

        if ($sourceVendorDir !== null) {
            $sourcePackageDir = $sourceVendorDir . '/pinoox/' . $shortName;

            if (is_dir($sourcePackageDir) && self::isLinkedPackage($sourcePackageDir)) {
                return true;
            }
        }

        if (self::isLinkedPackage($targetDir)) {
            return true;
        }

        return self::directoryIsEmpty($targetDir);
    }

    /**
     * @param array<string, string> $pathPackages
     */
    private static function resolvePackageSource(
        string $packageName,
        string $shortName,
        array $pathPackages,
        ?string $sourceVendorDir,
    ): ?string {
        if (isset($pathPackages[$packageName])) {
            return $pathPackages[$packageName];
        }

        if ($sourceVendorDir === null) {
            return null;
        }

        $packageDir = $sourceVendorDir . '/pinoox/' . $shortName;

        if (!is_dir($packageDir) && !is_link($packageDir)) {
            return null;
        }

        $resolved = realpath($packageDir);

        if ($resolved === false) {
            return null;
        }

        return str_replace('\\', '/', $resolved);
    }

    /**
     * @return array<string, string>
     */
    private static function installedPinooxProductionPackagesFromPhp(string $vendorDir): array
    {
        $installedPhp = $vendorDir . '/composer/installed.php';

        if (!is_file($installedPhp)) {
            return [];
        }

        $data = include $installedPhp;

        if (!is_array($data['versions'] ?? null)) {
            return [];
        }

        $packages = [];

        foreach ($data['versions'] as $name => $meta) {
            if (!is_string($name) || !str_starts_with($name, 'pinoox/') || $name === 'pinoox/pinoox') {
                continue;
            }

            if (!is_array($meta) || !empty($meta['dev_requirement'])) {
                continue;
            }

            $packages[$name] = substr($name, strlen('pinoox/'));
        }

        return $packages;
    }

    /**
     * @return array<string, string>
     */
    private static function installedPinooxProductionPackagesFromJson(string $vendorDir): array
    {
        $installedJson = $vendorDir . '/composer/installed.json';

        if (!is_file($installedJson)) {
            return [];
        }

        $raw = file_get_contents($installedJson);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($decoded)) {
            return [];
        }

        $devNames = array_fill_keys(
            array_map('strval', is_array($decoded['dev-package-names'] ?? null) ? $decoded['dev-package-names'] : []),
            true,
        );
        $packages = [];
        $items = is_array($decoded['packages'] ?? null) ? $decoded['packages'] : $decoded;

        foreach ($items as $package) {
            if (!is_array($package)) {
                continue;
            }

            $name = (string) ($package['name'] ?? '');

            if ($name === '' || !str_starts_with($name, 'pinoox/') || $name === 'pinoox/pinoox') {
                continue;
            }

            if (isset($devNames[$name])) {
                continue;
            }

            $packages[$name] = substr($name, strlen('pinoox/'));
        }

        return $packages;
    }

    /**
     * @param array<string, string> $packages
     * @param array{type?: string, url?: string, package?: string} $repository
     */
    private static function mergePathRepository(array &$packages, array $repository, string $projectRoot): void
    {
        if (($repository['type'] ?? '') !== 'path') {
            return;
        }

        $url = trim((string) ($repository['url'] ?? ''));

        if ($url === '') {
            return;
        }

        $path = self::resolveRepositoryPath($url, $projectRoot);
        $packageName = self::packageNameFromPath($path);

        if ($packageName === null || !str_starts_with($packageName, 'pinoox/')) {
            return;
        }

        $packages[$packageName] = $path;
    }

    /**
     * @return array<string, mixed>
     */
    private static function projectComposerConfig(string $projectRoot): array
    {
        $file = $projectRoot . '/composer.json';

        if (!is_file($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function globalComposerConfig(): array
    {
        $home = self::composerHome();

        if ($home === null) {
            return [];
        }

        $configFile = $home . '/config.json';

        if (!is_file($configFile)) {
            return [];
        }

        $raw = file_get_contents($configFile);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private static function composerHome(): ?string
    {
        $env = getenv('COMPOSER_HOME');

        if (is_string($env) && $env !== '') {
            return rtrim(str_replace('\\', '/', $env), '/');
        }

        $appData = getenv('APPDATA');

        if (is_string($appData) && $appData !== '') {
            return rtrim(str_replace('\\', '/', $appData), '/') . '/Composer';
        }

        $home = getenv('HOME');

        if (is_string($home) && $home !== '') {
            return rtrim(str_replace('\\', '/', $home), '/') . '/.composer';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array{type?: string, url?: string, package?: string}>
     */
    private static function pathRepositoriesFromConfig(array $config): array
    {
        $repositories = [];

        foreach (self::normalizeRepositories($config['repositories'] ?? []) as $repository) {
            if (($repository['type'] ?? '') === 'path') {
                $repositories[] = $repository;
            }
        }

        $nested = $config['config']['repositories'] ?? null;

        if (is_array($nested)) {
            foreach (self::normalizeRepositories($nested) as $repository) {
                if (($repository['type'] ?? '') === 'path') {
                    $repositories[] = $repository;
                }
            }
        }

        return $repositories;
    }

    /**
     * @return list<array{type?: string, url?: string, package?: string}>
     */
    private static function normalizeRepositories(mixed $repositories): array
    {
        if (!is_array($repositories)) {
            return [];
        }

        if ($repositories === []) {
            return [];
        }

        if (array_is_list($repositories)) {
            return array_values(array_filter($repositories, 'is_array'));
        }

        $normalized = [];

        foreach ($repositories as $repository) {
            if (is_array($repository)) {
                $normalized[] = $repository;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private static function installedPathPackages(string $installedPath): array
    {
        if (!is_file($installedPath)) {
            return [];
        }

        if (str_ends_with($installedPath, '.php')) {
            return self::installedPathPackagesFromPhp($installedPath);
        }

        $raw = file_get_contents($installedPath);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($decoded)) {
            return [];
        }

        $packages = is_array($decoded['packages'] ?? null) ? $decoded['packages'] : $decoded;
        $paths = [];

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $name = (string) ($package['name'] ?? '');

            if ($name === '' || !str_starts_with($name, 'pinoox/')) {
                continue;
            }

            $dist = is_array($package['dist'] ?? null) ? $package['dist'] : [];

            if (($dist['type'] ?? '') !== 'path') {
                continue;
            }

            $url = trim((string) ($dist['url'] ?? ''));

            if ($url === '') {
                continue;
            }

            $resolved = realpath($url) ?: $url;

            if (is_dir($resolved)) {
                $paths[$name] = str_replace('\\', '/', $resolved);
            }
        }

        return $paths;
    }

    /**
     * @return array<string, string>
     */
    private static function installedPathPackagesFromPhp(string $installedPhp): array
    {
        $vendorDir = rtrim(str_replace('\\', '/', dirname(dirname($installedPhp))), '/');
        $data = include $installedPhp;

        if (!is_array($data['versions'] ?? null)) {
            return [];
        }

        $paths = [];

        foreach ($data['versions'] as $name => $meta) {
            if (!is_string($name) || !str_starts_with($name, 'pinoox/')) {
                continue;
            }

            if (!is_array($meta) || !isset($meta['install_path'])) {
                continue;
            }

            $packageDir = self::resolveInstalledPackageDir($vendorDir, (string) $meta['install_path']);

            if ($packageDir === null || !self::isLinkedPackage($packageDir)) {
                continue;
            }

            $resolved = realpath($packageDir);

            if ($resolved === false || !is_dir($resolved)) {
                continue;
            }

            $paths[$name] = str_replace('\\', '/', $resolved);
        }

        return $paths;
    }

    private static function resolveInstalledPackageDir(string $vendorDir, string $installPath): ?string
    {
        $installPath = str_replace('\\', '/', $installPath);

        if (!str_starts_with($installPath, '/') && !preg_match('/^[A-Za-z]:\\//', $installPath)) {
            $installPath = $vendorDir . '/composer/' . ltrim($installPath, '/');
        }

        $resolved = realpath($installPath) ?: $installPath;

        return is_dir($resolved) ? str_replace('\\', '/', $resolved) : null;
    }

    private static function resolveRepositoryPath(string $url, string $projectRoot): string
    {
        $url = trim(str_replace('\\', '/', $url));

        if (preg_match('/^[A-Za-z]:\//', $url) === 1 || str_starts_with($url, '/')) {
            $resolved = realpath($url) ?: $url;

            return str_replace('\\', '/', $resolved);
        }

        $resolved = realpath($projectRoot . '/' . ltrim($url, '/')) ?: $projectRoot . '/' . ltrim($url, '/');

        return str_replace('\\', '/', $resolved);
    }

    private static function packageNameFromPath(string $path): ?string
    {
        $composerFile = rtrim(str_replace('\\', '/', $path), '/') . '/composer.json';

        if (!is_file($composerFile)) {
            return null;
        }

        $raw = file_get_contents($composerFile);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $name = is_array($decoded) ? trim((string) ($decoded['name'] ?? '')) : '';

        return $name !== '' ? $name : null;
    }

    private static function isLinkedPackage(string $packageDir): bool
    {
        if (is_link($packageDir)) {
            return true;
        }

        $real = realpath($packageDir);

        if ($real === false) {
            return false;
        }

        $normalizedPackage = str_replace('\\', '/', $packageDir);
        $normalizedReal = str_replace('\\', '/', $real);

        return strcasecmp(rtrim($normalizedPackage, '/'), rtrim($normalizedReal, '/')) !== 0;
    }

    private static function directoryIsEmpty(string $directory): bool
    {
        if (!is_dir($directory)) {
            return true;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return false;
            }
        }

        return true;
    }

    private static function replaceWithCopy(string $packageDir, string $sourcePath): void
    {
        self::removeDirectory($packageDir);
        self::copyDirectory($sourcePath, $packageDir);
    }

    private static function copyDirectory(string $source, string $target): void
    {
        $sourceRoot = rtrim(str_replace('\\', '/', realpath($source) ?: $source), '/');

        if (!is_dir($sourceRoot)) {
            throw new Exception('Path package source not found: ' . $source);
        }

        if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
            throw new Exception('Failed to create vendor package directory: ' . $target);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $absolutePath = str_replace('\\', '/', $item->getPathname());
            $relativePath = ltrim(substr($absolutePath, strlen($sourceRoot)), '/');

            if ($relativePath === '' || str_ends_with($relativePath, '.gitignore')) {
                continue;
            }

            $targetPath = rtrim(str_replace('\\', '/', $target), '/') . '/' . $relativePath;

            if ($item->isDir() && !$item->isLink()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0777, true);
                }

                continue;
            }

            if (!$item->isFile()) {
                continue;
            }

            $targetDir = dirname($targetPath);

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }

            if (!copy($item->getPathname(), $targetPath)) {
                throw new Exception('Failed to materialize path package file: ' . $targetPath);
            }
        }
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            }

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
