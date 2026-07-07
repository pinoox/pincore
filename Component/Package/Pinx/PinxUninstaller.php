<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Kernel\Exception;
use Pinoox\Component\Migration\Migrator;
use Pinoox\Component\Package\AppDependency;
use Pinoox\Component\Package\Engine\AppEngine;
use Pinoox\Component\Package\PackageName;
use Pinoox\Portal\App\AppEngine as AppEnginePortal;
use Pinoox\Portal\App\AppRouter;
use Pinoox\Portal\FileSystem;
use Pinoox\Support\SystemConfig;

class PinxUninstaller
{
    /** @var callable|null */
    private $stepListener = null;

    public function __construct(
        private AppEngine $engine,
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
     *     skip_migrate?: bool,
     *     skip_routes?: bool,
     *     keep_files?: bool
     * } $options
     */
    public function uninstallApp(string $package, array $options = []): PinxUninstallResult
    {
        $steps = [];

        try {
            $this->assertAppTarget($package, $steps, (bool) ($options['force'] ?? false));
            $this->assertNoDependents($package, $steps, (bool) ($options['force'] ?? false));

            if (!($options['skip_migrate'] ?? false)) {
                $this->rollbackMigrations($package, $steps);
            } else {
                $this->recordStep($steps, 'migrate', 'skipped', 'Migration rollback skipped by option.');
            }

            if (!($options['skip_routes'] ?? false)) {
                $this->removeRoutes($package, $steps);
            } else {
                $this->recordStep($steps, 'routes', 'skipped', 'Route cleanup skipped by option.');
            }

            $this->purgePinker($package, $steps);

            if (!($options['keep_files'] ?? false)) {
                $this->removePath($this->engine->path($package), $steps, 'files');
            } else {
                $this->recordStep($steps, 'files', 'skipped', 'App folder kept on disk.');
            }

            AppEnginePortal::__rebuild();
            $message = sprintf('App "%s" uninstalled successfully.', $package);
            $this->recordStep($steps, 'complete', 'ok', $message);

            return new PinxUninstallResult(true, 'app', $package, null, $steps, $message);
        } catch (\Throwable $e) {
            $this->recordStep($steps, 'failed', 'error', $e->getMessage());

            return new PinxUninstallResult(false, 'app', $package, null, $steps, $e->getMessage());
        }
    }

    /**
     * @param array{
     *     keep_files?: bool
     * } $options
     */
    public function uninstallTheme(string $package, string $themeName, array $options = []): PinxUninstallResult
    {
        $steps = [];
        $themeName = trim($themeName);

        try {
            if ($themeName === '') {
                throw new Exception('Theme name is required.');
            }

            if (!$this->engine->exists($package)) {
                throw new Exception('Host app not found: ' . $package);
            }

            $themePath = $this->engine->path($package, 'theme/' . $themeName);

            if (!is_dir($themePath)) {
                throw new Exception('Theme folder not found: ' . $themePath);
            }

            $this->recordStep($steps, 'validate', 'ok', 'Theme "' . $themeName . '" found in "' . $package . '".');

            if (!($options['keep_files'] ?? false)) {
                $this->removePath($themePath, $steps, 'files');
            } else {
                $this->recordStep($steps, 'files', 'skipped', 'Theme folder kept on disk.');
            }

            AppEnginePortal::__rebuild();
            $message = sprintf('Theme "%s" removed from "%s".', $themeName, $package);
            $this->recordStep($steps, 'complete', 'ok', $message);

            return new PinxUninstallResult(true, 'theme', $package, $themeName, $steps, $message);
        } catch (\Throwable $e) {
            $this->recordStep($steps, 'failed', 'error', $e->getMessage());

            return new PinxUninstallResult(false, 'theme', $package, $themeName ?: null, $steps, $e->getMessage());
        }
    }

    /**
     * @param list<array{step: string, status: string, message: string}> $steps
     */
    private function assertAppTarget(string $package, array &$steps, bool $force): void
    {
        $error = PackageName::validationError($package);

        if ($error !== null) {
            throw new Exception($error);
        }

        if (!$this->engine->exists($package)) {
            throw new Exception('App not found: ' . $package);
        }

        $appFile = $this->engine->path($package, 'app.php');
        $config = is_file($appFile) ? include $appFile : [];

        if (is_array($config) && !empty($config['sys-app']) && !$force) {
            throw new Exception(
                'Cannot uninstall system app "' . $package . '". Use --force if you really intend to remove it.',
            );
        }

        $this->recordStep($steps, 'validate', 'ok', 'App "' . $package . '" is eligible for uninstall.');
    }

    /**
     * @param list<array{step: string, status: string, message: string}> $steps
     */
    private function assertNoDependents(string $package, array &$steps, bool $force): void
    {
        $dependents = AppDependency::dependents($package, $this->engine);

        if ($dependents === []) {
            $this->recordStep($steps, 'dependents', 'skipped', 'No installed apps depend on this package.');

            return;
        }

        if (!$force) {
            throw new Exception(
                'Cannot uninstall "' . $package . '". Required by: ' . implode(', ', $dependents) . '.',
            );
        }

        $this->recordStep(
            $steps,
            'dependents',
            'ok',
            'Forced uninstall despite dependents: ' . implode(', ', $dependents) . '.',
        );
    }

    /**
     * @param list<array{step: string, status: string, message: string}> $steps
     */
    private function rollbackMigrations(string $package, array &$steps): void
    {
        if (!$this->hasMigrationHistory($package)) {
            $this->recordStep($steps, 'migrate', 'skipped', 'No migration history found for ' . $package . '.');

            return;
        }

        try {
            (new Migrator($package))->rollback();
            $this->recordStep($steps, 'migrate', 'ok', 'Migrations rolled back for ' . $package . '.');
        } catch (\Throwable $e) {
            throw new Exception('Migration rollback failed: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * @param list<array{step: string, status: string, message: string}> $steps
     */
    private function removeRoutes(string $package, array &$steps): void
    {
        AppRouter::deletePackage($package);
        $this->recordStep($steps, 'routes', 'ok', 'URL routes removed for ' . $package . '.');
    }

    /**
     * @param list<array{step: string, status: string, message: string}> $steps
     */
    private function purgePinker(string $package, array &$steps): void
    {
        $purged = PinxPinkerRegistry::purge($package);

        if ($purged > 0) {
            $this->recordStep($steps, 'pinker', 'ok', 'Pinker cache/state removed for ' . $purged . ' file(s).');
        } else {
            $this->recordStep($steps, 'pinker', 'skipped', 'No pinker artifacts found for ' . $package . '.');
        }
    }

    /**
     * @param list<array{step: string, status: string, message: string}> $steps
     */
    private function removePath(string $path, array &$steps, string $step): void
    {
        if (!is_dir($path) && !is_file($path)) {
            $this->recordStep($steps, $step, 'skipped', 'Path already absent: ' . $path);

            return;
        }

        FileSystem::remove($path);
        $this->recordStep($steps, $step, 'ok', 'Removed ' . $path);
    }

    private function hasMigrationHistory(string $package): bool
    {
        $folder = trim((string) SystemConfig::rawPath('app_migrations', 'database/migrations'), '/\\');
        $path = $this->engine->path($package) . '/' . $folder;

        if (!is_dir($path)) {
            return false;
        }

        foreach (glob($path . '/*.php') ?: [] as $file) {
            if (is_file($file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{step: string, status: string, message: string}> $steps
     */
    private function recordStep(array &$steps, string $step, string $status, string $message): void
    {
        $entry = [
            'step' => $step,
            'status' => $status,
            'message' => $message,
        ];
        $steps[] = $entry;

        if ($this->stepListener !== null) {
            ($this->stepListener)($step, $status, $message);
        }
    }
}
