<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Helpers\Filesystem;
use Pinoox\Component\Kernel\Exception;
use Pinoox\Component\Package\AppProvisioner;
use Pinoox\Component\Package\Engine\AppEngine;
use Pinoox\Portal\App\App;
use Pinoox\Portal\App\AppEngine as AppEnginePortal;
use Pinoox\Support\SystemConfig;
use ZipArchive;

/**
 * Apply a platform .zip over an existing install, then migrate/patch like the web installer.
 */
final class PlatformUpdater
{
    /** @var callable|null */
    private $stepListener = null;

    public function __construct(
        private readonly AppEngine $engine,
    ) {
    }

    /**
     * @param callable(string $step, string $status, string $message): void|null $listener
     */
    public function onStep(?callable $listener): self
    {
        $this->stepListener = $listener;

        return $this;
    }

    /**
     * @param array{
     *     force?: bool,
     *     dry_run?: bool,
     *     skip_migrate?: bool,
     *     skip_patch?: bool,
     *     skip_lifecycle?: bool,
     *     skip_cache?: bool,
     *     project_root?: string,
     *     progress?: callable(string $phase, string $message, ?int $percent=null): void
     * } $options
     */
    public function update(string $archivePath, array $options = []): PlatformUpdateResult
    {
        $steps = [];
        $archivePath = $this->resolveArchivePath($archivePath);
        $projectRoot = rtrim(str_replace('\\', '/', (string) ($options['project_root'] ?? SystemConfig::rootPath())), '/');
        $fromVersion = PinxVersion::platform();
        $stagingRoot = '';

        try {
            if (!class_exists(ZipArchive::class)) {
                throw new Exception('The ext-zip extension is required to update from a platform archive.');
            }

            $this->reportProgress($options, 'validate', 'Validating platform archive...', 5);

            if (!PlatformArchive::isPlatformArchive($archivePath)) {
                throw new Exception('File is not a Pinoox platform archive (.zip with BUILD.json or vendor/pinoox).');
            }

            $manifest = PlatformArchive::readManifest($archivePath);
            $toVersion = PlatformArchive::versionFromManifest($manifest);
            $apps = PlatformArchive::listApps($archivePath);
            $this->recordStep($steps, 'validate', 'ok', 'Platform archive validated.');

            if (
                !($options['force'] ?? false)
                && $toVersion['code'] !== null
                && $fromVersion['code'] !== null
                && $toVersion['code'] < $fromVersion['code']
            ) {
                throw new Exception(sprintf(
                    'Archive version #%d is older than the installed platform #%d. Use --force to apply anyway.',
                    $toVersion['code'],
                    $fromVersion['code'],
                ));
            }

            $this->recordStep(
                $steps,
                'version',
                'ok',
                $this->describeVersion($fromVersion, $toVersion),
            );

            if ($apps === []) {
                $this->recordStep($steps, 'apps', 'skipped', 'No apps/ packages found in the archive.');
            } else {
                $this->recordStep($steps, 'apps', 'ok', 'Archive apps: ' . implode(', ', $apps) . '.');
            }

            if ($options['dry_run'] ?? false) {
                $this->recordStep($steps, 'dry_run', 'ok', 'Dry run — files were not extracted.');

                return new PlatformUpdateResult(
                    true,
                    'Platform update dry run completed.',
                    $steps,
                    $apps,
                    $fromVersion,
                    $toVersion,
                );
            }

            $this->reportProgress($options, 'extract', 'Extracting platform archive...', 20);
            $stagingRoot = $this->extractArchive($archivePath, $projectRoot, (string) ($manifest['prefix'] ?? ''));
            $this->recordStep($steps, 'extract', 'ok', 'Archive extracted to a temporary workspace.');

            $lifecycleContext = $this->lifecycleContexts(
                $projectRoot,
                $apps,
                $this->extractedAppVersions($stagingRoot, $apps),
            );

            $this->reportProgress($options, 'apply', 'Applying files to the project...', 45);
            $this->applyExtracted($stagingRoot, $projectRoot, $apps);
            $this->recordStep($steps, 'apply', 'ok', 'Project files updated (runtime data preserved).');

            $liveRoot = rtrim(str_replace('\\', '/', SystemConfig::rootPath()), '/');

            if ($projectRoot !== $liveRoot) {
                $this->recordStep($steps, 'provision', 'skipped', 'Files applied to a non-live project root.');
                $this->reportProgress($options, 'done', 'Platform update finished.', 100);
                $this->recordStep($steps, 'complete', 'ok', 'Platform files updated successfully.');

                return new PlatformUpdateResult(
                    true,
                    'Platform files updated successfully.',
                    $steps,
                    $apps,
                    $fromVersion,
                    $toVersion,
                );
            }

            SystemConfig::clearCache();
            AppEnginePortal::__rebuild();

            foreach ($apps as $package) {
                $appPath = $projectRoot . '/apps/' . $package;

                if (is_dir($appPath)) {
                    App::addPackage($package, $appPath);
                }
            }

            $this->reportProgress($options, 'provision', 'Running migrations and patches...', 70);
            $this->provision(AppEnginePortal::___(), $apps, $options, $lifecycleContext, $steps);

            $this->reportProgress($options, 'done', 'Platform update finished.', 100);
            $this->recordStep($steps, 'complete', 'ok', 'Platform updated successfully.');

            return new PlatformUpdateResult(
                true,
                'Platform updated successfully.',
                $steps,
                $apps,
                $fromVersion,
                $toVersion,
            );
        } catch (\Throwable $e) {
            $this->recordStep($steps, 'failed', 'error', $e->getMessage());

            return new PlatformUpdateResult(
                false,
                $e->getMessage(),
                $steps,
                $apps ?? [],
                $fromVersion,
                $toVersion ?? ['name' => '', 'code' => null],
            );
        } finally {
            Filesystem::removeDirectory($projectRoot . '/' . PlatformBuildConfig::UPDATE_DIR);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, array<string, mixed>> $lifecycleContext
     * @param list<array{step: string, status: string, message: string}> $steps
     * @param list<string> $apps
     */
    private function provision(AppEngine $engine, array $apps, array $options, array $lifecycleContext, array &$steps): void
    {
        $provisioner = new AppProvisioner($engine);

        try {
            $provisioner->updatePackages($apps, [
                'skip_migrate' => (bool) ($options['skip_migrate'] ?? false),
                'skip_patch' => (bool) ($options['skip_patch'] ?? false),
                'skip_lifecycle' => (bool) ($options['skip_lifecycle'] ?? false),
                'skip_cache' => (bool) ($options['skip_cache'] ?? false),
                'lifecycle_context' => $lifecycleContext,
            ]);
        } catch (\Throwable $e) {
            throw new Exception('Migrate/patch failed after files were applied: ' . $e->getMessage(), previous: $e);
        }

        if ($options['skip_migrate'] ?? false) {
            $this->recordStep($steps, 'migrate', 'skipped', 'Migrations skipped by option.');
        } else {
            $this->recordStep($steps, 'migrate', 'ok', 'Core and app migrations completed.');
        }

        if ($options['skip_patch'] ?? false) {
            $this->recordStep($steps, 'patch', 'skipped', 'Patches skipped by option.');
        } else {
            $this->recordStep($steps, 'patch', 'ok', 'Core and app patches completed.');
        }

        if ($options['skip_lifecycle'] ?? false) {
            $this->recordStep($steps, 'lifecycle', 'skipped', 'Lifecycle skipped by option.');
        } else {
            $this->recordStep($steps, 'lifecycle', 'ok', 'App UPDATE lifecycle completed.');
        }

        if ($options['skip_cache'] ?? false) {
            $this->recordStep($steps, 'cache', 'skipped', 'Cache rebuild skipped by option.');
        } else {
            $this->recordStep($steps, 'cache', 'ok', 'App caches rebuilt.');
        }
    }

    /**
     * @param list<string> $apps
     * @param array<string, array{name: string, code: ?int}> $toVersions
     * @return array<string, array<string, mixed>>
     */
    private function lifecycleContexts(string $projectRoot, array $apps, array $toVersions): array
    {
        $contexts = [];

        foreach ($apps as $package) {
            $from = $this->readAppVersion($projectRoot . '/apps/' . $package . '/app.php');
            $to = $toVersions[$package] ?? ['name' => '', 'code' => null];
            $contexts[$package] = [
                'fromVersionCode' => $from['code'],
                'fromVersionName' => $from['name'],
                'toVersionCode' => $to['code'],
                'toVersionName' => $to['name'],
            ];
        }

        return $contexts;
    }

    /**
     * Capture per-app target version after apply by re-reading app.php from extracted tree.
     *
     * @param list<string> $apps
     * @return array<string, array{name: string, code: ?int}>
     */
    private function extractedAppVersions(string $extractedRoot, array $apps): array
    {
        $versions = [];

        foreach ($apps as $package) {
            $versions[$package] = $this->readAppVersion($extractedRoot . '/apps/' . $package . '/app.php');
        }

        return $versions;
    }

    /**
     * @return array{name: string, code: ?int}
     */
    private function readAppVersion(string $appFile): array
    {
        if (!is_file($appFile)) {
            return ['name' => '', 'code' => null];
        }

        $data = include $appFile;

        if (!is_array($data)) {
            return ['name' => '', 'code' => null];
        }

        $name = trim((string) ($data['version-name'] ?? $data['version_name'] ?? ''));
        $rawCode = $data['version-code'] ?? $data['version_code'] ?? null;
        $code = null;

        if ($rawCode !== null && $rawCode !== '') {
            $code = (int) $rawCode;
        }

        return [
            'name' => $name,
            'code' => $code,
        ];
    }

    private function extractArchive(string $archivePath, string $projectRoot, string $prefix): string
    {
        $updateRoot = $projectRoot . '/' . PlatformBuildConfig::UPDATE_DIR;
        Filesystem::removeDirectory($updateRoot);

        if (!mkdir($updateRoot, 0777, true) && !is_dir($updateRoot)) {
            throw new Exception('Failed to create platform update workspace: ' . $updateRoot);
        }

        $zip = new ZipArchive();

        if ($zip->open($archivePath) !== true) {
            throw new Exception('Unable to extract platform archive: ' . $archivePath);
        }

        try {
            if (!$zip->extractTo($updateRoot)) {
                throw new Exception('Failed to extract platform archive.');
            }
        } finally {
            $zip->close();
        }

        $prefix = trim(str_replace('\\', '/', $prefix), '/');
        $extracted = $prefix === '' ? $updateRoot : $updateRoot . '/' . $prefix;

        if (!is_dir($extracted)) {
            throw new Exception('Extracted platform archive is missing the expected root folder.');
        }

        return $extracted;
    }

    /**
     * @param list<string> $apps
     */
    private function applyExtracted(string $extractedRoot, string $projectRoot, array $apps): void
    {
        $extractedRoot = rtrim(str_replace('\\', '/', $extractedRoot), '/');
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

        $extractedVendor = $extractedRoot . '/vendor';

        if (is_dir($extractedVendor)) {
            Filesystem::replaceDirectory($extractedVendor, $projectRoot . '/vendor');
        }

        foreach ($apps as $package) {
            $sourceApp = $extractedRoot . '/apps/' . $package;

            if (!is_dir($sourceApp)) {
                continue;
            }

            Filesystem::replaceDirectory($sourceApp, $projectRoot . '/apps/' . $package);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractedRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $absolutePath = str_replace('\\', '/', $item->getPathname());
            $relativePath = PlatformArchive::normalizeRelative(substr($absolutePath, strlen($extractedRoot)));

            if ($relativePath === '' || PlatformArchive::shouldPreserve($relativePath)) {
                continue;
            }

            if ($relativePath === 'vendor' || str_starts_with($relativePath, 'vendor/')) {
                continue;
            }

            if ($relativePath === 'apps' || str_starts_with($relativePath, 'apps/')) {
                continue;
            }

            $targetPath = $projectRoot . '/' . $relativePath;

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
                throw new Exception('Failed to copy update file: ' . $relativePath);
            }
        }
    }

    /**
     * @param array{name: string, code: ?int} $from
     * @param array{name: string, code: ?int} $to
     */
    private function describeVersion(array $from, array $to): string
    {
        $format = static function (array $version): string {
            $name = $version['name'] !== '' ? $version['name'] : 'unknown';

            return $version['code'] !== null ? $name . ' #' . $version['code'] : $name;
        };

        return 'Installed ' . $format($from) . ' → archive ' . $format($to) . '.';
    }

    private function resolveArchivePath(string $archivePath): string
    {
        $archivePath = trim(str_replace('\\', '/', $archivePath));

        if ($archivePath !== '' && is_file($archivePath)) {
            return $archivePath;
        }

        throw new Exception('Platform archive not found: ' . $archivePath);
    }

    /**
     * @param list<array{step: string, status: string, message: string}> $steps
     */
    private function recordStep(array &$steps, string $step, string $status, string $message): void
    {
        $steps[] = [
            'step' => $step,
            'status' => $status,
            'message' => $message,
        ];

        if (is_callable($this->stepListener)) {
            ($this->stepListener)($step, $status, $message);
        }
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
