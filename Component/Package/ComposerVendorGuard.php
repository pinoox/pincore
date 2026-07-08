<?php

namespace Pinoox\Component\Package;

use Pinoox\Component\Kernel\Exception;
use Symfony\Component\Process\Process;

/**
 * Guardrails for build flows that bundle an existing Composer vendor tree.
 */
final class ComposerVendorGuard
{
    public static function vendorDir(string $basePath): string
    {
        return rtrim(str_replace('\\', '/', $basePath), '/') . '/vendor';
    }

    public static function isInstalled(string $basePath): bool
    {
        return is_file(self::vendorDir($basePath) . '/autoload.php');
    }

    public static function installCommand(): string
    {
        return 'composer install --no-dev --optimize-autoloader --no-interaction';
    }

    public static function requireInstalled(string $basePath, string $label = 'project'): void
    {
        if (self::isInstalled($basePath)) {
            return;
        }

        $path = rtrim(str_replace('\\', '/', $basePath), '/');

        throw new Exception(sprintf(
            "Composer vendor is not installed for this %s.\nRun the following in your terminal, then build again:\n\n  cd %s\n  %s",
            $label,
            $path,
            self::installCommand(),
        ));
    }

    /**
     * @return list<string>
     */
    public static function installedDevPackageNames(string $basePath): array
    {
        $vendorDir = self::vendorDir($basePath);
        $names = self::installedDevPackageNamesFromPhp($vendorDir);

        if ($names !== []) {
            return $names;
        }

        return self::installedDevPackageNamesFromJson($vendorDir);
    }

    public static function hasInstalledDevPackages(string $basePath): bool
    {
        return self::installedDevPackageNames($basePath) !== [];
    }

    /**
     * @return list<string> vendor-relative directory paths (e.g. pestphp/pest)
     */
    public static function installedDevVendorPaths(string $basePath): array
    {
        $vendorDir = rtrim(str_replace('\\', '/', self::vendorDir($basePath)), '/');
        $paths = self::installedDevVendorPathsFromPhp($vendorDir);

        if ($paths !== []) {
            return $paths;
        }

        return self::installedDevVendorPathsFromJson($vendorDir);
    }

    /**
     * @deprecated Use installedDevPackageNames() and exclude dev packages during copy instead.
     */
    public static function assertProductionVendor(string $basePath, string $label = 'project'): void
    {
    }

    /**
     * @param list<string> $excludeVendorPaths vendor-relative directory prefixes to skip
     */
    public static function copyVendorTree(
        string $sourceVendor,
        string $targetVendor,
        bool $prune = false,
        array $excludeVendorPaths = [],
    ): void {
        $sourceVendor = rtrim(str_replace('\\', '/', $sourceVendor), '/');
        $targetVendor = rtrim(str_replace('\\', '/', $targetVendor), '/');
        $excludeVendorPaths = self::normalizeVendorPathPrefixes($excludeVendorPaths);

        if (!is_file($sourceVendor . '/autoload.php')) {
            throw new Exception('Source vendor/autoload.php was not found: ' . $sourceVendor);
        }

        self::removeDirectory($targetVendor);

        if (!mkdir($targetVendor, 0777, true) && !is_dir($targetVendor)) {
            throw new Exception('Failed to create vendor directory: ' . $targetVendor);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceVendor, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $absolutePath = str_replace('\\', '/', $item->getPathname());
            $relativePath = ltrim(substr($absolutePath, strlen($sourceVendor)), '/');

            if ($relativePath === '' || str_ends_with($relativePath, '.gitignore')) {
                continue;
            }

            if ($excludeVendorPaths !== [] && self::matchesVendorPathPrefix($relativePath, $excludeVendorPaths)) {
                continue;
            }

            if (VendorPruner::shouldSkipBundledVendorPath($relativePath)) {
                continue;
            }

            if ($prune && VendorPruner::shouldSkipPath($relativePath)) {
                continue;
            }

            $targetPath = $targetVendor . '/' . $relativePath;

            if ($item->isLink() || self::isVendorReparsePoint($absolutePath)) {
                self::copyVendorLinkTarget($item->getPathname(), $targetPath, $prune, $excludeVendorPaths);

                continue;
            }

            if ($item->isDir()) {
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
                throw new Exception('Failed to copy vendor file: ' . $targetPath);
            }
        }
    }

    /**
     * @param list<string> $devPackageNames
     */
    public static function pruneInstalledMetadata(string $vendorDir, array $devPackageNames): void
    {
        if ($devPackageNames === []) {
            return;
        }

        $vendorDir = rtrim(str_replace('\\', '/', $vendorDir), '/');
        $devLookup = array_fill_keys($devPackageNames, true);

        $installedPhp = $vendorDir . '/composer/installed.php';

        if (is_file($installedPhp)) {
            $data = include $installedPhp;

            if (is_array($data)) {
                if (isset($data['root']) && is_array($data['root'])) {
                    $data['root']['dev'] = false;
                }

                if (isset($data['versions']) && is_array($data['versions'])) {
                    foreach (array_keys($data['versions']) as $packageName) {
                        if (isset($devLookup[$packageName])) {
                            unset($data['versions'][$packageName]);
                        }
                    }
                }

                self::writeInstalledPhp($installedPhp, $data);
            }
        }

        $installedJson = $vendorDir . '/composer/installed.json';

        if (!is_file($installedJson)) {
            return;
        }

        $raw = file_get_contents($installedJson);
        $installed = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($installed)) {
            return;
        }

        $installed['dev'] = false;
        $installed['dev-package-names'] = [];

        if (isset($installed['packages']) && is_array($installed['packages'])) {
            $installed['packages'] = array_values(array_filter(
                $installed['packages'],
                static fn ($package): bool => is_array($package)
                    && (!isset($package['name']) || !isset($devLookup[(string) $package['name']])),
            ));
        }

        file_put_contents(
            $installedJson,
            json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );
    }

    /**
     * @param array<string, mixed> $distributionComposer
     */
    public static function regenerateProductionAutoload(
        string $workingDirectory,
        array $distributionComposer,
        ?string $projectRoot = null,
    ): void {
        $workingDirectory = rtrim(str_replace('\\', '/', $workingDirectory), '/');

        file_put_contents(
            $workingDirectory . '/composer.json',
            json_encode($distributionComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );

        $command = self::dumpAutoloadCommand($projectRoot);
        $process = new Process($command, $workingDirectory, null, null, 300);
        $process->run();

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput() . "\n" . $process->getOutput());

            throw new Exception(
                'Failed to regenerate production Composer autoload.'
                . ($error !== '' ? "\n" . $error : ''),
            );
        }
    }

    private static function vendorRelativePath(string $vendorDir, string $installPath): string
    {
        $vendorDir = rtrim(str_replace('\\', '/', $vendorDir), '/');
        $installPath = str_replace('\\', '/', $installPath);

        if ($installPath === '') {
            return '';
        }

        if (!str_starts_with($installPath, '/') && !preg_match('/^[A-Za-z]:\\//', $installPath)) {
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
     * @return list<string>
     */
    private static function installedDevPackageNamesFromPhp(string $vendorDir): array
    {
        $paths = self::installedDevVendorPathsFromPhp($vendorDir);

        if ($paths === []) {
            return [];
        }

        $installedPhp = $vendorDir . '/composer/installed.php';

        if (!is_file($installedPhp)) {
            return [];
        }

        $data = include $installedPhp;

        if (!is_array($data['versions'] ?? null)) {
            return [];
        }

        $names = [];

        foreach ($data['versions'] as $name => $meta) {
            if (!is_array($meta) || empty($meta['dev_requirement']) || !isset($meta['install_path'])) {
                continue;
            }

            $relativePath = self::vendorRelativePath($vendorDir, (string) $meta['install_path']);

            if ($relativePath !== '' && self::matchesVendorPathPrefix($relativePath, $paths)) {
                $names[] = (string) $name;
            }
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private static function installedDevVendorPathsFromPhp(string $vendorDir): array
    {
        $vendorDir = rtrim(str_replace('\\', '/', $vendorDir), '/');
        $installedPhp = $vendorDir . '/composer/installed.php';

        if (!is_file($installedPhp)) {
            return [];
        }

        $data = include $installedPhp;

        if (!is_array($data['versions'] ?? null)) {
            return [];
        }

        $paths = [];

        foreach ($data['versions'] as $meta) {
            if (!is_array($meta) || empty($meta['dev_requirement']) || !isset($meta['install_path'])) {
                continue;
            }

            $relativePath = self::vendorRelativePath($vendorDir, (string) $meta['install_path']);

            if ($relativePath !== '') {
                $paths[] = $relativePath;
            }
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    /**
     * @return list<string>
     */
    private static function installedDevPackageNamesFromJson(string $vendorDir): array
    {
        $paths = self::installedDevVendorPathsFromJson($vendorDir);

        if ($paths === []) {
            return [];
        }

        $installedJson = $vendorDir . '/composer/installed.json';

        if (!is_file($installedJson)) {
            return [];
        }

        $raw = file_get_contents($installedJson);
        $installed = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($installed)) {
            return [];
        }

        $names = is_array($installed['dev-package-names'] ?? null) ? $installed['dev-package-names'] : [];
        $names = array_values(array_filter(array_map('strval', $names)));

        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function installedDevVendorPathsFromJson(string $vendorDir): array
    {
        $vendorDir = rtrim(str_replace('\\', '/', $vendorDir), '/');
        $installedJson = $vendorDir . '/composer/installed.json';

        if (!is_file($installedJson)) {
            return [];
        }

        $raw = file_get_contents($installedJson);
        $installed = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($installed) || empty($installed['dev'])) {
            return [];
        }

        $paths = [];
        $packages = is_array($installed['packages'] ?? null) ? $installed['packages'] : $installed;
        $devNames = array_fill_keys(
            array_map('strval', is_array($installed['dev-package-names'] ?? null) ? $installed['dev-package-names'] : []),
            true,
        );

        foreach ($packages as $package) {
            if (!is_array($package) || !isset($package['name']) || !isset($devNames[(string) $package['name']])) {
                continue;
            }

            $relativePath = isset($package['install-path'])
                ? self::vendorRelativePath($vendorDir, (string) $package['install-path'])
                : '';

            if ($relativePath === '') {
                continue;
            }

            $paths[] = $relativePath;
        }

        sort($paths);

        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * @param list<string> $excludeVendorPaths
     */
    private static function copyVendorLinkTarget(
        string $linkPath,
        string $targetPath,
        bool $prune,
        array $excludeVendorPaths,
    ): void {
        $resolved = realpath($linkPath);

        if ($resolved === false) {
            return;
        }

        if (is_dir($resolved)) {
            self::copyVendorDirectory($resolved, $targetPath, $prune, $excludeVendorPaths);

            return;
        }

        if (!is_file($resolved)) {
            return;
        }

        $targetDir = dirname($targetPath);

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        if (!copy($resolved, $targetPath)) {
            throw new Exception('Failed to copy vendor link target: ' . $targetPath);
        }
    }

    /**
     * @param list<string> $excludeVendorPaths
     */
    private static function copyVendorDirectory(
        string $sourceDir,
        string $targetDir,
        bool $prune,
        array $excludeVendorPaths,
    ): void {
        $sourceDir = rtrim(str_replace('\\', '/', realpath($sourceDir) ?: $sourceDir), '/');
        $targetDir = rtrim(str_replace('\\', '/', $targetDir), '/');
        $sourcePrefix = $sourceDir . '/';

        if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
            throw new Exception('Failed to create vendor directory: ' . $targetDir);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $absolutePath = str_replace('\\', '/', $item->getPathname());
            $relativePath = ltrim(substr($absolutePath, strlen($sourceDir)), '/');

            if ($relativePath === '' || str_ends_with($relativePath, '.gitignore')) {
                continue;
            }

            if ($excludeVendorPaths !== [] && self::matchesVendorPathPrefix($relativePath, $excludeVendorPaths)) {
                continue;
            }

            if (VendorPruner::shouldSkipPathPackageSourcePath($relativePath)) {
                continue;
            }

            if ($prune && VendorPruner::shouldSkipPath($relativePath)) {
                continue;
            }

            $targetPath = $targetDir . '/' . $relativePath;

            if ($item->isLink() || self::isVendorReparsePoint($absolutePath)) {
                self::copyVendorLinkTarget($item->getPathname(), $targetPath, $prune, $excludeVendorPaths);

                continue;
            }

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0777, true);
                }

                continue;
            }

            if (!$item->isFile()) {
                continue;
            }

            $targetParent = dirname($targetPath);

            if (!is_dir($targetParent)) {
                mkdir($targetParent, 0777, true);
            }

            if (!copy($item->getPathname(), $targetPath)) {
                throw new Exception('Failed to copy vendor file: ' . $targetPath);
            }
        }
    }

    private static function isVendorReparsePoint(string $path): bool
    {
        if (is_link($path)) {
            return true;
        }

        if (!is_dir($path)) {
            return false;
        }

        $real = realpath($path);

        if ($real === false) {
            return false;
        }

        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedReal = str_replace('\\', '/', $real);

        return strcasecmp(rtrim($normalizedPath, '/'), rtrim($normalizedReal, '/')) !== 0;
    }

    /**
     * @param list<string> $prefixes
     */
    private static function matchesVendorPathPrefix(string $relativePath, array $prefixes): bool
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        foreach ($prefixes as $prefix) {
            if ($relativePath === $prefix || str_starts_with($relativePath, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private static function normalizeVendorPathPrefixes(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            $path = trim(str_replace('\\', '/', $path), '/');

            if ($path !== '') {
                $normalized[] = $path;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return list<string>
     */
    private static function dumpAutoloadCommand(?string $projectRoot): array
    {
        $composer = AppComposerVendor::resolveComposerBinary($projectRoot);

        if (str_contains($composer, ' ') && str_ends_with($composer, '.phar')) {
            return array_merge(explode(' ', $composer, 2), [
                'dump-autoload',
                '--no-dev',
                '--optimize',
                '--classmap-authoritative',
                '--no-interaction',
                '--no-ansi',
            ]);
        }

        return [
            $composer,
            'dump-autoload',
            '--no-dev',
            '--optimize',
            '--classmap-authoritative',
            '--no-interaction',
            '--no-ansi',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function writeInstalledPhp(string $path, array $data): void
    {
        $export = var_export($data, true);
        $contents = "<?php return {$export};\n";

        file_put_contents($path, $contents);
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
