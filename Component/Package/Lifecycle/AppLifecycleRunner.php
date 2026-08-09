<?php

namespace Pinoox\Component\Package\Lifecycle;

use Pinoox\Component\AppEvent\AppCoreEventDispatcher;
use Pinoox\Component\AppEvent\AppEventNames;
use Pinoox\Component\Migration\MigrationQuery;
use Pinoox\Component\Package\Engine\AppEngine;
use Pinoox\Model\HistoryModel;
use Pinoox\Model\Table;
use Pinoox\Portal\Database\DB;

final class AppLifecycleRunner
{
    private const BEFORE = [
        AppLifecycle::INSTALL => AppEventNames::INSTALLING,
        AppLifecycle::UPDATE => AppEventNames::UPDATING,
        AppLifecycle::UNINSTALL => AppEventNames::UNINSTALLING,
        AppLifecycle::RESET => AppEventNames::RESETTING,
    ];

    private const AFTER = [
        AppLifecycle::INSTALL => AppEventNames::INSTALLED,
        AppLifecycle::UPDATE => AppEventNames::UPDATED,
        AppLifecycle::UNINSTALL => AppEventNames::UNINSTALLED,
        AppLifecycle::RESET => AppEventNames::RESET,
    ];

    public function __construct(
        private readonly AppEngine $engine,
    ) {
    }

    /**
     * @param array{
     *     fromVersionCode?: ?int,
     *     toVersionCode?: ?int,
     *     fromVersionName?: ?string,
     *     toVersionName?: ?string,
     *     extra?: array<string, mixed>
     * } $context
     * @param array{
     *     once?: bool,
     *     record?: bool,
     *     dispatch?: bool,
     *     dispatch_after?: bool
     * } $options
     * @return array{status: string, message: string, ran: int}
     */
    public function run(string $package, string $action, array $context = [], array $options = []): array
    {
        if (!AppLifecycle::isAction($action)) {
            return [
                'status' => 'skipped',
                'message' => 'Unknown lifecycle action: ' . $action . '.',
                'ran' => 0,
            ];
        }

        $once = (bool) ($options['once'] ?? ($action === AppLifecycle::INSTALL));
        $record = (bool) ($options['record'] ?? ($action === AppLifecycle::INSTALL));
        $dispatch = $options['dispatch'] ?? true;
        $dispatchAfter = $options['dispatch_after'] ?? $dispatch;

        if ($once && $this->hasRecorded($package, $action)) {
            return [
                'status' => 'skipped',
                'message' => 'Lifecycle "' . $action . '" already recorded for ' . $package . '.',
                'ran' => 0,
            ];
        }

        $appPath = '';
        try {
            $appPath = $this->engine->path($package);
        } catch (\Throwable) {
        }

        $ctx = new AppLifecycleContext(
            package: $package,
            action: $action,
            fromVersionCode: isset($context['fromVersionCode']) ? (int) $context['fromVersionCode'] : null,
            toVersionCode: isset($context['toVersionCode']) ? (int) $context['toVersionCode'] : null,
            fromVersionName: isset($context['fromVersionName']) ? (string) $context['fromVersionName'] : null,
            toVersionName: isset($context['toVersionName']) ? (string) $context['toVersionName'] : null,
            appPath: $appPath,
            extra: is_array($context['extra'] ?? null) ? $context['extra'] : [],
        );

        if ($dispatch) {
            $this->dispatch($package, $action, $ctx, false);
        }

        $life = $this->load($package);
        $handlers = $life->handlers($action);
        foreach ($handlers as $handler) {
            $handler($ctx);
        }

        if ($record && $handlers !== []) {
            $this->record($package, $action);
        }

        if ($dispatchAfter) {
            $this->dispatch($package, $action, $ctx, true);
        }

        if ($handlers === []) {
            return [
                'status' => 'skipped',
                'message' => 'No lifecycle.php "' . $action . '" handler for ' . $package . '.',
                'ran' => 0,
            ];
        }

        return [
            'status' => 'ok',
            'message' => sprintf('Lifecycle "%s" ran %d handler(s) for %s.', $action, count($handlers), $package),
            'ran' => count($handlers),
        ];
    }

    /**
     * Fire past-tense event only (e.g. uninstalled after folder delete).
     *
     * @param array<string, mixed> $context
     */
    public function dispatchAfter(string $package, string $action, array $context = []): void
    {
        if (!AppLifecycle::isAction($action)) {
            return;
        }

        $appPath = '';
        try {
            $appPath = $this->engine->exists($package) ? $this->engine->path($package) : '';
        } catch (\Throwable) {
        }

        $ctx = new AppLifecycleContext(
            package: $package,
            action: $action,
            fromVersionCode: isset($context['fromVersionCode']) ? (int) $context['fromVersionCode'] : null,
            toVersionCode: isset($context['toVersionCode']) ? (int) $context['toVersionCode'] : null,
            fromVersionName: isset($context['fromVersionName']) ? (string) $context['fromVersionName'] : null,
            toVersionName: isset($context['toVersionName']) ? (string) $context['toVersionName'] : null,
            appPath: $appPath,
            extra: is_array($context['extra'] ?? null) ? $context['extra'] : [],
        );

        $this->dispatch($package, $action, $ctx, true);
    }

    public function hasRecorded(string $package, string $action): bool
    {
        if (!$this->historyTableExists()) {
            return false;
        }

        return HistoryModel::where('type', MigrationQuery::TYPE_LIFECYCLE)
            ->where('app', $package)
            ->where('migration', $action)
            ->where('status', 'success')
            ->exists();
    }

    public function record(string $package, string $action): void
    {
        if (!$this->historyTableExists()) {
            return;
        }

        if ($this->hasRecorded($package, $action)) {
            return;
        }

        HistoryModel::create([
            'type' => MigrationQuery::TYPE_LIFECYCLE,
            'migration' => $action,
            'app' => $package,
            'batch' => 1,
            'status' => 'success',
            'executed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function clearHistory(string $package): void
    {
        MigrationQuery::deleteByType(MigrationQuery::TYPE_LIFECYCLE, $package);
    }

    public function load(string $package): AppLifecycle
    {
        $path = $this->resolvePath($package);

        return $path === null ? new AppLifecycle() : AppLifecycle::fromFile($path);
    }

    public function resolvePath(string $package): ?string
    {
        $config = null;

        try {
            $config = $this->engine->config($package)->get('lifecycle');
        } catch (\Throwable) {
        }

        try {
            $root = $this->engine->path($package);
        } catch (\Throwable) {
            return null;
        }

        return AppLifecyclePath::resolve($config, $root);
    }

    private function dispatch(string $package, string $action, AppLifecycleContext $ctx, bool $after): void
    {
        $base = $after ? (self::AFTER[$action] ?? null) : (self::BEFORE[$action] ?? null);
        if ($base === null) {
            return;
        }

        AppCoreEventDispatcher::dispatch(
            new AppLifecycleEvent($package, $action, $ctx, $after),
            $base,
            $package,
        );
    }

    private function historyTableExists(): bool
    {
        try {
            return DB::schema('platform')->hasTable(DB::tableName(Table::HISTORY, 'platform'));
        } catch (\Throwable) {
            return false;
        }
    }
}
