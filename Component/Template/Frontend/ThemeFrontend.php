<?php

namespace Pinoox\Component\Template\Frontend;

use Pinoox\Component\Package\AppManifest;
use Pinoox\Component\Package\PackageName;
use Pinoox\Component\Template\Theme\ThemeContextRegistry;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\App\App;
use Pinoox\Portal\Path;
use Symfony\Component\Process\Process;

class ThemeFrontend
{
    public const INSTALL_SKIP = 'skip';
    public const INSTALL_SMART = 'smart';
    public const INSTALL_FORCE = 'force';

    /** @var callable(string): void|null */
    private $outputWriter = null;

    private ?Process $runningProcess = null;

    private ?FrontendDevSession $devSession = null;

    private bool $fixViteOnSync = false;

  /** @var list<string> */
    private array $forceDevEnvKeys = [];

    private ?string $devEnvFile = null;

    public function __construct(
        private readonly string $package,
        private readonly string $themePath,
        private readonly array $config,
    ) {
    }

    public static function forPackage(?string $package = null): self
    {
        $package = $package ?: App::package();
        $stack = \Pinoox\Component\Template\Theme\ThemeStack::resolve($package);
        $themeName = $stack['name'];
        $themePath = $stack['paths'][0] ?? rtrim(str_replace('\\', '/', Path::get(App::get('path-theme') . '/' . $themeName)), '/');

        if ($package !== App::package() && AppEngine::exists($package)) {
            $stack = \Pinoox\Component\Template\Theme\ThemeStack::resolve($package);
            $themeName = $stack['name'];
            $themePath = $stack['paths'][0] ?? rtrim(str_replace('\\', '/', AppEngine::path($package) . '/theme/' . $themeName), '/');
        }

        return new self($package, $themePath, FrontendConfig::forThemePath($themePath));
    }

    public static function forPackageAndTheme(string $package, string $themeName, ?string $contextName = null): self
    {
        $themePath = rtrim(str_replace('\\', '/', AppEngine::path($package) . '/theme/' . $themeName), '/');
        $config = FrontendConfig::forThemePath($themePath);
        $config = self::mergeContextFrontendConfig($package, $config, $themePath, $contextName, $themeName);

        return new self($package, $themePath, $config);
    }

    public static function forDevTarget(string $package, ?string $contextOrTheme = null): self
    {
        $resolved = ThemeFrontendDevTarget::resolve($package, $contextOrTheme ?? '');

        return self::forPackageAndTheme($package, $resolved['theme'], $resolved['context']);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function mergeContextFrontendConfig(
        string $package,
        array $config,
        string $themePath,
        ?string $contextName,
        string $themeName,
    ): array {
        $appConfig = AppManifest::load($package);

        if (!ThemeContextRegistry::hasContexts($appConfig)) {
            return $config;
        }

        $context = $contextName;

        if ($context === null || $context === '') {
            foreach (ThemeContextRegistry::names($appConfig) as $name) {
                $ctx = ThemeContextRegistry::context($appConfig, $name);
                if (($ctx['theme'] ?? '') === $themeName) {
                    $context = $name;
                    break;
                }
            }
        }

        if ($context === null || $context === '') {
            return $config;
        }

        $effective = ThemeContextRegistry::effectiveConfig($appConfig, $context);
        $frontend = $effective['frontend'] ?? null;

        if (!is_array($frontend) || $frontend === []) {
            return $config;
        }

        return array_replace_recursive($config, $frontend);
    }

    /**
     * @return array<string, string> folder => details label
     */
    public static function listThemeFolders(string $package): array
    {
        if (!AppEngine::exists($package)) {
            return [];
        }

        $root = ThemeFrontendPaths::themesRoot($package);
        if (!is_dir($root)) {
            return [];
        }

        $defaultTheme = (string) AppEngine::config($package)->get('theme', 'default');
        $themes = [];

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $root . '/' . $entry;
            if (!is_dir($path)) {
                continue;
            }

            $hasManifest = is_file($path . '/theme.php');
            $hasPackageJson = is_file($path . '/package.json');
            $hasFrontendConfig = is_file($path . '/frontend.config.php');

            if (!$hasManifest && !$hasPackageJson && !$hasFrontendConfig) {
                continue;
            }

            $parts = [];
            if ($hasPackageJson) {
                $parts[] = 'vite';
            }
            if ($hasManifest) {
                $parts[] = 'manifest';
            }
            if ($entry === $defaultTheme) {
                $parts[] = 'default';
            }

            $themes[$entry] = $parts === [] ? $entry : implode(', ', $parts);
        }

        ksort($themes);

        return $themes;
    }

    public static function supportsViteDev(string $package, ?string $themeOrContext = null): bool
    {
        return ThemeFrontendDevTarget::supportsVite($package, $themeOrContext);
    }

    /**
     * @return list<string>
     */
    public static function packagesWithViteDev(): array
    {
        $packages = [];

        foreach (array_keys(AppEngine::packagePaths()) as $package) {
            if (!AppEngine::exists($package) || !self::supportsViteDev($package)) {
                continue;
            }

            $packages[] = $package;
        }

        sort($packages);

        return $packages;
    }

    public static function isAppRouterPackage(string $package): bool
    {
        if (!AppEngine::exists($package)) {
            return false;
        }

        try {
            $canonical = PackageName::canonical($package);
            $routes = \Pinoox\Portal\App\AppRouter::routes();

            foreach ($routes as $routePackage) {
                if (is_string($routePackage) && PackageName::equals($routePackage, $canonical)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function packagesWithViteDevForPlatform(): array
    {
        $packages = array_values(array_filter(
            self::packagesWithViteDev(),
            static fn (string $package): bool => self::isAppRouterPackage($package),
        ));

        sort($packages);

        return $packages;
    }

    /**
     * Find app packages that contain a theme folder with the given name.
     *
     * @return array<string, string> package => app display name
     */
    public static function findPackagesByThemeFolder(string $themeName): array
    {
        $themeName = trim($themeName);
        if ($themeName === '') {
            return [];
        }

        $matches = [];

        foreach (array_keys(AppEngine::packagePaths()) as $package) {
            if (!AppEngine::exists($package)) {
                continue;
            }

            $themes = self::listThemeFolders($package);
            if (isset($themes[$themeName])) {
                $matches[$package] = AppManifest::displayName($package);

                continue;
            }

            $config = AppManifest::load($package);

            if (!ThemeContextRegistry::hasContexts($config)) {
                continue;
            }

            foreach (ThemeContextRegistry::names($config) as $context) {
                if ($context === $themeName) {
                    $matches[$package] = AppManifest::displayName($package);

                    continue 2;
                }

                $ctx = ThemeContextRegistry::context($config, $context);
                $folder = $ctx['theme'] ?? null;

                if (is_string($folder) && $folder === $themeName) {
                    $matches[$package] = AppManifest::displayName($package);

                    continue 2;
                }
            }
        }

        ksort($matches);

        return $matches;
    }

    public function setDevSession(?FrontendDevSession $session): void
    {
        $this->devSession = $session;
    }

    public function setFixViteOnSync(bool $fix): void
    {
        $this->fixViteOnSync = $fix;
    }

    /**
     * @param list<string> $keys
     */
    public function setForceDevEnvKeys(array $keys): void
    {
        $this->forceDevEnvKeys = array_values($keys);
    }

    public function setDevEnvFile(?string $envFile): void
    {
        $this->devEnvFile = $envFile !== null && trim($envFile) !== ''
            ? FrontendDevSync::normalizeEnvFile($envFile)
            : null;
    }

    public function devEnvFile(): string
    {
        return $this->devEnvFile ?? FrontendDevSync::DEFAULT_ENV_FILE;
    }

    /**
     * @return list<array{level: string, message: string}>
     */
    public function devDiagnostics(): array
    {
        return FrontendDevSync::diagnose($this->themePath, $this->config, $this->devSession);
    }

    /**
     * @param callable(string): void $writer
     */
    public function setOutputWriter(callable $writer): void
    {
        $this->outputWriter = $writer;
    }

    public function stopRunningProcess(): void
    {
        if ($this->runningProcess === null || !$this->runningProcess->isRunning()) {
            return;
        }

        $this->runningProcess->stop(5, defined('SIGINT') ? SIGINT : null);
        $this->runningProcess = null;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function themePath(): string
    {
        return $this->themePath;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    public function hasPackageJson(): bool
    {
        return is_file($this->themePath . '/package.json');
    }

    public function manifestPath(): string
    {
        return FrontendConfig::manifestAbsolutePath($this->themePath, $this->config)
            ?? $this->themePath . '/' . FrontendConfig::VITE_MANIFEST;
    }

    public function manifestExists(): bool
    {
        return is_file($this->manifestPath());
    }

    public function needsNpmInstall(): bool
    {
        $nodeModules = $this->themePath . '/node_modules';
        if (!is_dir($nodeModules)) {
            return true;
        }

        $stamp = $this->nodeModulesStamp($nodeModules);

        foreach (['package-lock.json', 'npm-shrinkwrap.json', 'package.json'] as $file) {
            $path = $this->themePath . '/' . $file;
            if (is_file($path) && filemtime($path) > $stamp) {
                return true;
            }
        }

        return false;
    }

    public function install(): int
    {
        $this->assertFrontendProject();
        $this->syncDev();

        return $this->runNpmInstall();
    }

    public function build(string $installMode = self::INSTALL_SKIP): int
    {
        $this->assertFrontendProject();
        $this->syncDev();
        $this->ensureDependencies($installMode);

        $code = $this->runNpm(['run', 'build'], extraEnv: $this->npmRunEnvironment());

        if ($code === 0) {
            FrontendWebServerFixSync::syncFromThemeConfig($this->package, $this->themePath, $this->config);
            FrontendDevSync::removeDevState($this->themePath);
        }

        return $code;
    }

    public function dev(string $installMode = self::INSTALL_SKIP): int
    {
        $this->startDevProcess($installMode);

        return $this->awaitRunningDevProcess();
    }

    public function startDevProcess(string $installMode = self::INSTALL_SKIP): void
    {
        $this->prepareDev($installMode);

        $binary = $this->npmBinary();
        $env = $this->inheritedEnvironment($this->npmRunEnvironment());
        $process = new Process([$binary, 'run', 'dev'], $this->themePath, $env, null, null);

        $this->attachLongRunningProcess($process);
        $process->start(function ($type, $buffer): void {
            $this->emit($buffer);
        });
    }

    public function hasRunningDevProcess(): bool
    {
        return $this->runningProcess !== null && $this->runningProcess->isRunning();
    }

    public function waitUntilDevReady(int $timeoutSeconds = 120): bool
    {
        if ($this->devSession === null) {
            return false;
        }

        $probeUrl = rtrim($this->devSession->viteDevServerUrl(), '/') . '/@vite/client';
        $deadline = microtime(true) + max(1, $timeoutSeconds);

        while (microtime(true) < $deadline) {
            if (!$this->hasRunningDevProcess()) {
                return false;
            }

            if (FrontendDevState::isActive($this->themePath)) {
                return true;
            }

            if ($this->viteDevServerResponds($probeUrl)) {
                return true;
            }

            usleep(400_000);
        }

        return $this->hasRunningDevProcess()
            && (FrontendDevState::isActive($this->themePath) || $this->viteDevServerResponds($probeUrl));
    }

    public function awaitRunningDevProcess(): int
    {
        $process = $this->runningProcess;

        if ($process === null) {
            return 1;
        }

        while ($process->isRunning()) {
            if (\function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            usleep(100_000);
        }

        $this->runningProcess = null;

        return (int) ($process->getExitCode() ?? 0);
    }

    private function viteDevServerResponds(string $url): bool
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        return is_string($body) && $body !== '';
    }

    public function prepareDev(string $installMode = self::INSTALL_SKIP): void
    {
        $this->assertFrontendProject();
        FrontendDevSync::removeDevState($this->themePath);

        if ($this->devSession !== null) {
            FrontendDevSync::writeDevState($this->themePath, $this->config, $this->devSession->vitePort);
        }

        $this->syncDev();
        $this->ensureDependencies($installMode);
    }

    /**
     * @return array<string, string>
     */
    public function devNpmEnvironment(): array
    {
        return $this->npmRunEnvironment();
    }

    public function watch(string $installMode = self::INSTALL_SKIP): int
    {
        $this->assertFrontendProject();
        $this->syncDev();
        $this->ensureDependencies($installMode);

        $scripts = $this->npmScripts();

        if (!isset($scripts['watch'])) {
            return $this->runNpm(['run', 'build', '--', '--watch'], longRunning: true, extraEnv: $this->npmRunEnvironment());
        }

        return $this->runNpm(['run', 'watch'], longRunning: true, extraEnv: $this->npmRunEnvironment());
    }

    public function runScript(string $script, string $installMode = self::INSTALL_SKIP): int
    {
        $scripts = $this->npmScripts();
        if (!isset($scripts[$script])) {
            throw new \InvalidArgumentException(sprintf(
                'npm script "%s" was not found. Available: %s',
                $script,
                $scripts === [] ? '(none)' : implode(', ', array_keys($scripts)),
            ));
        }

        $this->assertFrontendProject();
        $this->syncDev();
        $this->ensureDependencies($installMode);

        return $this->runNpm(
            ['run', $script],
            longRunning: $this->isLongRunningScript($script),
            extraEnv: $this->npmRunEnvironment(),
        );
    }

    /**
     * @return array{
     *     vite_plugin: bool,
     *     vite_plugin_added: bool,
     *     env_seeded: bool,
     *     dev_state_path: string,
     *     env_autodev: bool,
     *     env_file: string,
     *     vite_wired: bool,
     *     vite_inspection: array<string, mixed>
     * }
     */
    public function syncDev(): array
    {
        return FrontendDevSync::sync(
            $this->themePath,
            $this->config,
            FrontendDevSync::resolveCorePath(),
            $this->devSession,
            $this->fixViteOnSync,
            $this->devEnvFile(),
        );
    }

    /**
     * @return array<string, string>
     */
    public function npmScripts(): array
    {
        $path = $this->themePath . '/package.json';
        if (!is_file($path)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json) || !isset($json['scripts']) || !is_array($json['scripts'])) {
            return [];
        }

        $scripts = [];
        foreach ($json['scripts'] as $name => $command) {
            if (is_string($name) && (is_string($command) || is_numeric($command))) {
                $scripts[$name] = (string) $command;
            }
        }

        ksort($scripts);

        return $scripts;
    }

    /**
     * @return array<string, mixed>
     */
    public function info(): array
    {
        $stack = (string) ($this->config['stack'] ?? 'twig');
        $themeName = basename($this->themePath);
        $hints = FrontendConfig::recommendations($this->config, $this->package, $themeName);

        return [
            'package' => $this->package,
            'theme_path' => $this->themePath,
            'stack' => $stack,
            'profile' => $this->config['profile'] ?? null,
            'entry' => $this->config['entry'] ?? null,
            'entries' => FrontendConfig::entries($this->config),
            'refresh' => FrontendConfig::refreshPaths($this->config),
            'manifest' => $this->manifestPath(),
            'manifest_relative' => FrontendConfig::manifestRelativePath($this->config),
            'manifest_exists' => $this->manifestExists(),
            'uses_vite_assets' => FrontendConfig::usesViteAssets($this->config),
            'recommended_twig' => $hints['twig'],
            'assets_hint' => $hints['assets_hint'],
            'next_steps' => $hints['next_steps'],
            'package_json' => $this->hasPackageJson(),
            'dev_enabled' => FrontendConfig::isDevEnabled($this->config),
            'dev_url' => $this->config['dev']['url'] ?? null,
            'dev_state_path' => FrontendDevState::relativePath(),
            'dev_active' => FrontendDevState::isActive($this->themePath),
            'dev_port' => FrontendConfig::devPort($this->config, $this->themePath),
            'vite_plugin' => FrontendDevSync::hasVitePluginDependency($this->themePath)
                || is_file($this->themePath . '/vite.pinoox.mjs'),
            'vite_wired' => FrontendDevSync::inspectViteConfig($this->themePath)['wired'],
            'env_autodev' => FrontendDevSync::hasAutoDevBlock($this->themePath, $this->devEnvFile()),
            'env_file' => $this->devEnvFile(),
            'npm_scripts' => $this->npmScripts(),
            'node_modules' => is_dir($this->themePath . '/node_modules'),
            'needs_npm_install' => $this->hasPackageJson() && $this->needsNpmInstall(),
        ];
    }

    public function scaffold(string $stack): void
    {
        $stack = strtolower(trim($stack));
        $corePath = defined('PINOOX_CORE_PATH')
            ? rtrim(str_replace('\\', '/', (string) PINOOX_CORE_PATH), '/')
            : dirname(__DIR__, 3);
        $stubRoot = $corePath . '/stubs/frontend/' . $stack;

        if (!is_dir($stubRoot)) {
            throw new \InvalidArgumentException(sprintf('Frontend stack stub "%s" was not found.', $stack));
        }

        if (!is_dir($this->themePath)) {
            mkdir($this->themePath, 0777, true);
        }

        $this->copyStubTree($stubRoot, $this->themePath);
        $this->syncDev();
    }

    /**
     * @return array<string, string>
     */
    private function npmRunEnvironment(): array
    {
        if (!FrontendConfig::usesViteAssets($this->config)) {
            return [];
        }

        return FrontendDevSync::npmDevEnvironment(
            $this->config,
            FrontendDevSync::resolveCorePath(),
            $this->package,
            $this->devSession,
            $this->themePath,
            $this->devEnvFile(),
            $this->forceDevEnvKeys,
        );
    }

    /**
     * @return array<string, string>
     */
    private function inheritedEnvironment(array $extra = []): array
    {
        $base = getenv();

        if (!is_array($base)) {
            $base = [];
        }

        foreach ($extra as $key => $value) {
            $base[$key] = (string) $value;
        }

        return $base;
    }

    private function ensureDependencies(string $installMode): void
    {
        if ($installMode === self::INSTALL_SKIP) {
            return;
        }

        if ($installMode === self::INSTALL_FORCE || $this->needsNpmInstall()) {
            $this->runNpmInstall();
        }
    }

    private function runNpmInstall(): int
    {
        $lock = $this->themePath . '/package-lock.json';
        if (is_file($lock)) {
            return $this->runNpm(['ci'], fallback: ['install']);
        }

        return $this->runNpm(['install']);
    }

    private function assertFrontendProject(): void
    {
        if (!$this->hasPackageJson()) {
            throw new \RuntimeException('package.json was not found in theme: ' . $this->themePath);
        }
    }

    private function nodeModulesStamp(string $nodeModules): int
    {
        $lockStamp = $nodeModules . '/.package-lock.json';
        if (is_file($lockStamp)) {
            return (int) filemtime($lockStamp);
        }

        return (int) filemtime($nodeModules);
    }

    private function isLongRunningScript(string $script): bool
    {
        if (in_array($script, ['dev', 'watch', 'preview', 'serve', 'start'], true)) {
            return true;
        }

        $command = strtolower($this->npmScripts()[$script] ?? '');

        return str_contains($command, 'vite')
            && !str_contains($command, 'build');
    }

    /**
     * @param list<string> $command
     */
    private function runNpm(
        array $command,
        bool $longRunning = false,
        ?array $fallback = null,
        array $extraEnv = [],
    ): int {
        $binary = $this->npmBinary();
        $env = $extraEnv === [] ? null : $this->inheritedEnvironment($extraEnv);
        $process = new Process(array_merge([$binary], $command), $this->themePath, $env, null, null);

        if ($longRunning) {
            return $this->runLongNpmProcess($process);
        }

        $process->run(function ($type, $buffer) {
            $this->emit($buffer);
        });

        if (!$process->isSuccessful() && $fallback !== null) {
            $process = new Process(array_merge([$binary], $fallback), $this->themePath, $env, null, null);
            $process->run(function ($type, $buffer) {
                $this->emit($buffer);
            });
        }

        return (int) $process->getExitCode();
    }

    private function runLongNpmProcess(Process $process): int
    {
        $this->attachLongRunningProcess($process);

        $process->start(function ($type, $buffer): void {
            $this->emit($buffer);
        });

        return $this->awaitRunningDevProcess();
    }

    private function attachLongRunningProcess(Process $process): void
    {
        $this->runningProcess = $process;

        $stopProcess = function () use ($process): void {
            if ($process->isRunning()) {
                $process->stop(5, defined('SIGINT') ? SIGINT : null);
            }

            $this->runningProcess = null;
        };

        if (\function_exists('pcntl_async_signals') && \function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function () use ($stopProcess): never {
                $stopProcess();
                exit(130);
            });
            pcntl_signal(SIGTERM, static function () use ($stopProcess): never {
                $stopProcess();
                exit(143);
            });
        } elseif (PHP_OS_FAMILY === 'Windows' && \function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(static function (int $event) use ($stopProcess): void {
                if ($event === PHP_WINDOWS_EVENT_CTRL_C || $event === PHP_WINDOWS_EVENT_CTRL_BREAK) {
                    $stopProcess();
                    exit(130);
                }
            }, true);
        }

        register_shutdown_function($stopProcess);
    }

    private function emit(string $buffer): void
    {
        if ($this->outputWriter !== null) {
            ($this->outputWriter)($buffer);

            return;
        }

        echo $buffer;
    }

    private function npmBinary(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'npm.cmd' : 'npm';
    }

    private function copyStubTree(string $source, string $destination): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $target = $destination . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0777, true);
                }
                continue;
            }

            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }

            copy($item->getPathname(), $target);
        }
    }
}
