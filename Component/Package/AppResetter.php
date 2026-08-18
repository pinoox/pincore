<?php

namespace Pinoox\Component\Package;

use Pinoox\Component\Cache\AppCacheManager;
use Pinoox\Component\Database\Patch\PatchToolkit;
use Pinoox\Component\Kernel\Exception;
use Pinoox\Component\Migration\Migrator;
use Pinoox\Component\Package\Engine\AppEngine;
use Pinoox\Component\Package\Lifecycle\AppLifecycle;
use Pinoox\Component\Package\Lifecycle\AppLifecycleRunner;
use Pinoox\Component\Package\PackageName;

/**
 * Reset app data (keep files), then re-run migrate + patch + install lifecycle.
 */
final class AppResetter
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
     *     skip_lifecycle?: bool,
     *     skip_migrate?: bool,
     *     skip_patch?: bool,
     *     skip_cache?: bool
     * } $options
     */
    public function reset(string $package, array $options = []): AppResetResult
    {
        $steps = [];
        $runner = new AppLifecycleRunner($this->engine);

        try {
            $error = PackageName::validationError($package);
            if ($error !== null) {
                throw new Exception($error);
            }

            if (!$this->engine->exists($package)) {
                throw new Exception('App not found: ' . $package);
            }

            $appFile = $this->engine->path($package, 'app.php');
            $config = is_file($appFile) ? include $appFile : [];
            if (is_array($config) && !empty($config['sys-app']) && !($options['force'] ?? false)) {
                throw new Exception(
                    'Cannot reset system app "' . $package . '". Use --force if you really intend to wipe its data.',
                );
            }

            $this->recordStep($steps, 'validate', 'ok', 'App "' . $package . '" is eligible for reset.');

            $versionCode = is_array($config) ? (int) ($config['version-code'] ?? 0) : 0;
            $versionName = is_array($config) ? (string) ($config['version-name'] ?? '') : '';
            $lifeContext = [
                'toVersionCode' => $versionCode ?: null,
                'toVersionName' => $versionName !== '' ? $versionName : null,
            ];

            if (!($options['skip_lifecycle'] ?? false)) {
                $result = $runner->run($package, AppLifecycle::RESET, $lifeContext, [
                    'once' => false,
                    'record' => false,
                    'dispatch_after' => false,
                ]);
                $this->recordStep($steps, 'lifecycle', $result['status'], $result['message']);
            } else {
                $this->recordStep($steps, 'lifecycle', 'skipped', 'Lifecycle skipped by option.');
            }

            $this->rollbackPatchesAndHistory($package, $runner, $steps);

            if (!($options['skip_migrate'] ?? false)) {
                $messages = (new Migrator($package))->reset();
                $this->recordStep($steps, 'migrate', 'ok', implode(' ', $messages));
                (new Migrator($package))->run();
                $this->recordStep($steps, 'migrate', 'ok', 'Migrations re-applied for ' . $package . '.');
            } else {
                $this->recordStep($steps, 'migrate', 'skipped', 'Migration reset skipped by option.');
            }

            if (!($options['skip_patch'] ?? false)) {
                $this->runPatches($package, $steps, (bool) ($options['force'] ?? false));
            } else {
                $this->recordStep($steps, 'patch', 'skipped', 'Patches skipped by option.');
            }

            if (!($options['skip_lifecycle'] ?? false)) {
                $result = $runner->run($package, AppLifecycle::INSTALL, $lifeContext, [
                    'once' => false,
                    'record' => true,
                ]);
                $this->recordStep($steps, 'lifecycle', $result['status'], $result['message']);
            }

            if (!($options['skip_cache'] ?? false)) {
                AppCacheManager::build($package, null, true);
                $this->recordStep($steps, 'cache', 'ok', 'Runtime cache rebuilt for ' . $package . '.');
            } else {
                $this->recordStep($steps, 'cache', 'skipped', 'Cache rebuild skipped by option.');
            }

            if (!($options['skip_lifecycle'] ?? false)) {
                $runner->dispatchAfter($package, AppLifecycle::RESET, $lifeContext);
            }

            $message = sprintf('App "%s" reset successfully.', $package);
            $this->recordStep($steps, 'complete', 'ok', $message);

            return new AppResetResult(true, $package, $steps, $message);
        } catch (\Throwable $e) {
            $this->recordStep($steps, 'failed', 'error', $e->getMessage());

            return new AppResetResult(false, $package, $steps, $e->getMessage());
        }
    }

    /**
     * @param list<array{step: string, status: string, message: string}> $steps
     */
    private function rollbackPatchesAndHistory(string $package, AppLifecycleRunner $runner, array &$steps): void
    {
        $toolkit = new PatchToolkit();
        $toolkit->package($package)->load();

        $rolled = 0;
        $skipped = 0;
        if ($toolkit->isSuccess()) {
            $result = $toolkit->rollbackAll();
            $rolled = $result['rolled'];
            $skipped = $result['skipped'];
        }

        $toolkit->clearHistory();
        $runner->clearHistory($package);

        $this->recordStep(
            $steps,
            'patch',
            'ok',
            sprintf(
                'Rolled back %d patch(es), skipped %d; patch and lifecycle history cleared.',
                $rolled,
                $skipped,
            ),
        );
    }

    /**
     * @param list<array{step: string, status: string, message: string}> $steps
     */
    private function runPatches(string $package, array &$steps, bool $force): void
    {
        $toolkit = new PatchToolkit();
        $toolkit->package($package)->load();

        if (!$toolkit->isSuccess()) {
            throw new Exception('Patch load failed: ' . $toolkit->getErrors());
        }

        $executed = 0;
        foreach ($toolkit->getPatches() as $patch) {
            if ($patch['ran'] || !$patch['should_run']) {
                continue;
            }

            try {
                $startedAt = microtime(true);
                $patch['instance']->run();
                $toolkit->recordSuccess(
                    $patch['name'],
                    $patch['checksum'],
                    (int) round((microtime(true) - $startedAt) * 1000),
                );
                $executed++;
            } catch (\Throwable $e) {
                if (!$force) {
                    throw new Exception('Patch failed: ' . $patch['name'] . ' - ' . $e->getMessage(), previous: $e);
                }
            }
        }

        $this->recordStep($steps, 'patch', 'ok', $executed . ' patch(es) executed.');
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
