<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Kernel\Exception;
use Pinoox\Component\Package\AppComposerVendor;
use Pinoox\Support\SystemConfig;
use ZipArchive;

final class PlatformBuilder
{
    public function __construct(
        private PlatformFileSelector $selector = new PlatformFileSelector(),
    ) {
    }

    /**
     * @param array{
     *     progress?: callable(string $phase, string $message, ?int $percent=null): void
     * } $options
     * @return array{
     *     path: string,
     *     files: int,
     *     composer: bool,
     *     composer_packages: list<string>,
     *     app_composers: list<string>,
     *     materialized_packages: list<string>,
     *     version_name: string,
     *     version_code: ?int
     * }
     */
    public function build(?string $outputPath = null, array $options = []): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new Exception('The ext-zip extension is required to build platform archives.');
        }

        $projectRoot = SystemConfig::rootPath();
        $build = PlatformBuildConfig::resolve($projectRoot);
        $version = PinxVersion::platform();
        $stagingRoot = PlatformComposer::stagingRoot($projectRoot);
        $archiveRoot = $stagingRoot . '/archive';

        $composerPrepared = false;
        $composerPackages = [];
        $materializedPackages = [];
        $appComposers = [];

        try {
            $this->reportProgress($options, 'storage', 'Preparing storage skeleton...', 8);
            $storageWorkspace = PlatformStorageScaffold::prepare($projectRoot);

            if ($build['composer'] && is_file(PlatformComposer::composerJsonPath($projectRoot))) {
                $this->reportProgress($options, 'composer', 'Installing production Composer dependencies (--no-dev)...', 15);
                $composerResult = PlatformComposer::prepare($projectRoot, $build['strip_require_dev']);
                $composerPrepared = $composerResult['prepared'];
                $composerPackages = $composerResult['packages'];
                $materializedPackages = is_array($composerResult['materialized'] ?? null)
                    ? $composerResult['materialized']
                    : [];
            }

            $this->reportProgress($options, 'prepare', 'Preparing platform build workspace...', 25);
            $this->resetDirectory($archiveRoot);

            $this->reportProgress($options, 'collect', 'Collecting platform files...', 30);
            $payloadFiles = $this->selector->payloadFiles($projectRoot, $build);
            $payloadFiles = $this->withoutVendorTree($payloadFiles);

            if ($payloadFiles === []) {
                throw new Exception('No files selected for platform build. Check platform/build.config.php excludes.');
            }

            $this->reportProgress($options, 'stage', 'Staging files for archive...', 45);
            $this->copyPayloadFiles($payloadFiles, $projectRoot, $archiveRoot, $build);
            $this->copyDirectory(PlatformStorageScaffold::workspaceDir($projectRoot), $archiveRoot . '/storage');

            if ($composerPrepared && is_dir(PlatformComposer::vendorPath($projectRoot))) {
                $this->copyDirectory(PlatformComposer::vendorPath($projectRoot), $archiveRoot . '/vendor');
            }

            if ($build['app_composer']) {
                $this->reportProgress($options, 'app-composer', 'Preparing app Composer vendor trees...', 60);
                $appComposers = $this->prepareAppComposers($projectRoot, $archiveRoot);
            }

            $fileCount = $this->countFiles($archiveRoot);

            if ($fileCount === 0) {
                throw new Exception('Platform build produced an empty archive.');
            }

            if ($build['manifest']) {
                $this->writeManifest(
                    $archiveRoot,
                    $version,
                    $fileCount,
                    $composerPrepared,
                    $composerPackages,
                    $appComposers,
                    $materializedPackages,
                );
            }

            $outputPath ??= $build['output_dir'] !== null
                ? rtrim($build['output_dir'], '/\\') . '/' . PinxPaths::defaultPlatformReleaseFilename()
                : PinxPaths::defaultPlatformReleasePath();
            $outputDir = dirname($outputPath);

            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0777, true);
            }

            $this->reportProgress($options, 'archive', 'Creating .zip archive...', 80);
            $this->createZipArchive($archiveRoot, $outputPath);

            $this->reportProgress($options, 'done', 'Platform build finished.', 100);

            return [
                'path' => $outputPath,
                'files' => $fileCount,
                'composer' => $composerPrepared,
                'composer_packages' => $composerPackages,
                'app_composers' => $appComposers,
                'materialized_packages' => $materializedPackages,
                'version_name' => $version['name'],
                'version_code' => $version['code'],
            ];
        } finally {
            PlatformComposer::cleanup($projectRoot);
        }
    }

    /**
     * @param array<string, string> $payloadFiles
     * @return array<string, string>
     */
    private function withoutVendorTree(array $payloadFiles): array
    {
        foreach (array_keys($payloadFiles) as $relativePath) {
            if ($relativePath === 'vendor' || str_starts_with($relativePath, 'vendor/')) {
                unset($payloadFiles[$relativePath]);
            }
        }

        return $payloadFiles;
    }

    /**
     * @param array<string, string> $payloadFiles
     * @param array{
     *     strip_require_dev: bool
     * } $build
     */
    private function copyPayloadFiles(array $payloadFiles, string $projectRoot, string $archiveRoot, array $build): void
    {
        foreach ($payloadFiles as $relativePath => $sourcePath) {
            if (!is_file($sourcePath)) {
                continue;
            }

            if (str_contains($relativePath, '/vendor/') || str_ends_with($relativePath, '/vendor')) {
                continue;
            }

            if (str_contains($relativePath, '/' . AppComposerVendor::BUILD_DIR . '/')) {
                continue;
            }

            $targetPath = $archiveRoot . '/' . $relativePath;
            $targetDir = dirname($targetPath);

            if (is_file($targetDir)) {
                throw new Exception('Cannot create platform build directory over file: ' . $targetDir);
            }

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }

            if (str_ends_with($relativePath, 'composer.json')) {
                $distribution = PlatformComposer::distributionComposerForApp($sourcePath, $build['strip_require_dev']);
                file_put_contents(
                    $targetPath,
                    json_encode($distribution, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                );
                continue;
            }

            if (!copy($sourcePath, $targetPath)) {
                throw new Exception('Failed to copy file into platform build: ' . $relativePath . ' (' . $sourcePath . ')');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function prepareAppComposers(string $projectRoot, string $archiveRoot): array
    {
        $prepared = [];
        $appsRoot = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/apps';

        if (!is_dir($appsRoot)) {
            return $prepared;
        }

        foreach (scandir($appsRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($appsRoot . '/' . $entry)) {
                continue;
            }

            $appPath = $appsRoot . '/' . $entry;

            if (!AppComposerVendor::hasComposerJson($appPath)) {
                continue;
            }

            if (!AppComposerVendor::hasDistributionRequires($appPath)) {
                continue;
            }

            $result = AppComposerVendor::prepare($appPath, $projectRoot);

            if (!$result['prepared'] || !is_string($result['vendor_dir'])) {
                continue;
            }

            $sourceVendor = $appPath . '/' . $result['vendor_dir'];
            $targetVendor = $archiveRoot . '/apps/' . $entry . '/vendor';

            if (is_dir($sourceVendor)) {
                $this->copyDirectory($sourceVendor, $targetVendor);
                $prepared[] = $entry;
            }

            AppComposerVendor::cleanup($appPath);
        }

        sort($prepared);

        return $prepared;
    }

    /**
     * @param list<string> $composerPackages
     * @param list<string> $appComposers
     * @param list<string> $materializedPackages
     * @param array{name: string, code: int|null} $version
     */
    private function writeManifest(
        string $archiveRoot,
        array $version,
        int $fileCount,
        bool $composerPrepared,
        array $composerPackages,
        array $appComposers,
        array $materializedPackages,
    ): void {
        $manifest = [
            'type' => 'platform',
            'version_name' => $version['name'],
            'version_code' => $version['code'],
            'built_at' => gmdate('c'),
            'files' => $fileCount,
            'composer' => $composerPrepared,
            'composer_packages' => $composerPackages,
            'materialized_packages' => $materializedPackages,
            'app_composers' => $appComposers,
            'kernel_version_name' => PinxVersion::kernel()['name'],
            'kernel_version_code' => PinxVersion::kernel()['code'],
        ];

        file_put_contents(
            $archiveRoot . '/BUILD.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    private function createZipArchive(string $sourceRoot, string $outputPath): void
    {
        $zip = new ZipArchive();

        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Failed to create platform archive: ' . $outputPath);
        }

        $sourceRoot = rtrim(str_replace('\\', '/', realpath($sourceRoot) ?: $sourceRoot), '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $absolutePath = str_replace('\\', '/', $item->getPathname());
            $relativePath = ltrim(substr($absolutePath, strlen($sourceRoot)), '/');

            if ($relativePath === '') {
                continue;
            }

            if ($item->isDir()) {
                $zip->addEmptyDir($relativePath);
                continue;
            }

            $zip->addFile($absolutePath, $relativePath);
        }

        $zip->close();
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($source)) {
            return;
        }

        $sourceRoot = rtrim(str_replace('\\', '/', realpath($source) ?: $source), '/');
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

            $targetPath = $target . '/' . $relativePath;

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
                throw new Exception('Failed to copy directory file: ' . $targetPath);
            }
        }
    }

    private function countFiles(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    private function resetDirectory(string $path): void
    {
        if (is_dir($path)) {
            $this->removeDirectory($path);
        } elseif (is_file($path)) {
            @unlink($path);
        }

        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new Exception('Failed to create directory: ' . $path);
        }
    }

    private function removeDirectory(string $path): void
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
                $this->removeDirectory($fullPath);
                continue;
            }

            @unlink($fullPath);
        }

        @rmdir($path);
    }

    /**
     * @param array{progress?: callable(string, string, ?int): void} $options
     */
    private function reportProgress(array $options, string $phase, string $message, ?int $percent = null): void
    {
        $callback = $options['progress'] ?? null;

        if (!is_callable($callback)) {
            return;
        }

        $callback($phase, $message, $percent);
    }
}
