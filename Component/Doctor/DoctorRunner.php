<?php

declare(strict_types=1);

namespace Pinoox\Component\Doctor;

use Pinoox\Component\Database\DatabaseConnectionToolkit;
use Pinoox\Component\Migration\MigrationToolkit;
use Pinoox\Component\Package\AppManifest;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Support\ProjectCli;
use Pinoox\Support\SystemConfig;
use Symfony\Component\Finder\Finder;

final class DoctorRunner
{
    private const PHP_MIN = '8.2.0';

    /** @var array<string, string> */
    private array $env = [];

    public function __construct(
        private readonly bool $skipDatabase = false,
        private readonly bool $skipFrontend = false,
    ) {
    }

    public function runProject(string $root, ?string $package = null): DoctorReport
    {
        $project = DoctorProject::resolve($root, $package);

        return $this->run($project->root, $project->package, $project);
    }

    public function run(string $platformRoot, string $package, ?DoctorProject $project = null): DoctorReport
    {
        $platformRoot = $this->normalizePath($platformRoot);
        $project ??= DoctorProject::resolve($platformRoot, $package);
        $report = new DoctorReport();
        $isPlatformScope = $project->isPlatformScope();
        $this->env = $this->loadEnv($platformRoot . '/.env');

        $this->checkProjectContext($report, $project, $isPlatformScope);
        $this->checkPhpRuntime($report);
        $this->checkPhpExtensions($report);
        $this->checkProjectLayout($report, $project);

        if (!$isPlatformScope) {
            $this->checkAppManifest($report, $package, $project);
            $this->checkAppRoutesAndTheme($report, $package);
            $this->checkAppLang($report, $package);
            $this->checkPinkerCache($report, $platformRoot, $package);

            if (!$this->skipDatabase) {
                $this->checkAppDatabase($report, $package);
                $this->checkMigrations($report, $package);
            }

            if (!$this->skipFrontend) {
                $this->checkFrontend($report, $package);
            }

            if ($project->isSingleApp()) {
                $this->checkSingleAppEnvironment($report, $project);
                $this->checkWritablePaths($report, $platformRoot);
                $this->checkBuildReadiness($report, $project);
            }
        } else {
            $this->checkInstalledApps($report);
        }

        return $report;
    }

    private function checkProjectContext(
        DoctorReport $report,
        DoctorProject $project,
        bool $isPlatformScope,
    ): void {
        $label = match ($project->type) {
            DoctorProject::TYPE_PLATFORM => 'Platform project',
            DoctorProject::TYPE_SINGLE_APP => 'Single-app project',
            default => 'Application project',
        };

        $ok = $project->isPlatformProject() || $project->isSingleApp();

        $report->add(new CheckItem(
            group: 'Project',
            id: 'project_context',
            label: $label,
            status: $ok ? CheckStatus::Pass : CheckStatus::Fail,
            detail: $project->root,
            hint: $ok ? null : 'Add app.php or create apps/ for a valid Pinoox project',
        ));

        if ($isPlatformScope) {
            $report->add(new CheckItem(
                group: 'Project',
                id: 'doctor_scope',
                label: 'Doctor scope',
                status: CheckStatus::Pass,
                detail: 'Platform-wide checks',
                scored: false,
            ));

            return;
        }

        if ($project->isSingleApp()) {
            $report->add(new CheckItem(
                group: 'Project',
                id: 'doctor_scope',
                label: 'Doctor scope',
                status: CheckStatus::Pass,
                detail: $project->package . ' @ ' . $project->root,
                scored: false,
            ));

            return;
        }

        if (!AppEngine::exists($project->package)) {
            $report->add(new CheckItem(
                group: 'Project',
                id: 'doctor_scope',
                label: 'Doctor scope',
                status: CheckStatus::Fail,
                detail: 'Package not found: ' . $project->package,
                hint: 'Run ' . ProjectCli::format('app:list', $project->root) . ' to see installed apps',
            ));

            return;
        }

        $report->add(new CheckItem(
            group: 'Project',
            id: 'doctor_scope',
            label: 'Doctor scope',
            status: CheckStatus::Pass,
            detail: $project->package . ' @ ' . AppEngine::path($project->package),
            scored: false,
        ));
    }

    private function checkPhpRuntime(DoctorReport $report): void
    {
        $version = PHP_VERSION;
        $ok = version_compare($version, self::PHP_MIN, '>=');

        $report->add(new CheckItem(
            group: 'PHP',
            id: 'php_version',
            label: 'PHP version',
            status: $ok ? CheckStatus::Pass : CheckStatus::Fail,
            detail: $version . ' (required ' . self::PHP_MIN . '+)',
            hint: $ok ? null : 'Upgrade PHP to 8.2 or newer',
        ));
    }

    private function checkPhpExtensions(DoctorReport $report): void
    {
        foreach ([
            'mbstring' => 'Unicode string handling',
            'json' => 'API and config parsing',
            'pdo' => 'Database access',
            'zip' => 'Package build/extract',
        ] as $ext => $why) {
            $loaded = extension_loaded($ext);
            $report->add(new CheckItem(
                group: 'PHP',
                id: 'ext_' . $ext,
                label: 'ext-' . $ext,
                status: $loaded ? CheckStatus::Pass : CheckStatus::Fail,
                detail: $why,
                hint: $loaded ? null : 'Enable the ' . $ext . ' PHP extension',
            ));
        }

        foreach ([
            'curl' => 'HTTP client and external APIs',
            'openssl' => 'TLS and package signing',
            'fileinfo' => 'Upload MIME detection',
        ] as $ext => $why) {
            $loaded = extension_loaded($ext);
            $report->add(new CheckItem(
                group: 'PHP',
                id: 'ext_' . $ext,
                label: 'ext-' . $ext,
                status: $loaded ? CheckStatus::Pass : CheckStatus::Warn,
                detail: $why,
                hint: $loaded ? null : 'Recommended: enable ext-' . $ext . ' in php.ini',
            ));
        }
    }

    private function checkProjectLayout(DoctorReport $report, DoctorProject $project): void
    {
        $platformRoot = $project->root;

        if ($project->isPlatformProject()) {
            $report->add(new CheckItem(
                group: 'Platform',
                id: 'apps_directory',
                label: 'Apps directory',
                status: is_dir($platformRoot . '/apps') ? CheckStatus::Pass : CheckStatus::Fail,
                detail: 'apps/',
                hint: 'Create apps/ and install at least one HMVC app',
            ));
        } else {
            foreach ([
                'app.php' => 'App manifest',
                'index.php' => 'HTTP entry point',
                'platform/launcher/bootstrap.php' => 'Platform bootstrap',
                'platform/launcher/server.php' => 'Dev server router',
                'composer.json' => 'Composer project file',
                'bin/pinx' => 'Pinx CLI entry',
            ] as $relative => $label) {
                $exists = is_file($platformRoot . '/' . $relative);
                $report->add(new CheckItem(
                    group: 'Layout',
                    id: 'file_' . str_replace(['/', '.'], '_', $relative),
                    label: $relative,
                    status: $exists ? CheckStatus::Pass : CheckStatus::Fail,
                    detail: $label,
                    hint: match ($relative) {
                        'bin/pinx' => 'Copy bin/pinx from the pinoox/app template',
                        'composer.json' => 'Run pinx init or copy composer.json from the template',
                        'platform/launcher/bootstrap.php', 'platform/launcher/server.php' => 'Copy platform/launcher/ from the pinoox/app template',
                        default => 'Restore missing file: ' . $relative,
                    },
                ));
            }

            $this->checkAppsRegistry($report, $platformRoot, $project->package);
        }

        $report->add(new CheckItem(
            group: 'Platform',
            id: 'platform_config',
            label: 'Platform config',
            status: is_dir($platformRoot . '/platform') ? CheckStatus::Pass : CheckStatus::Warn,
            detail: 'platform/',
            hint: 'Add platform/*.config.php for routing and domain setup',
        ));

        $envFile = $platformRoot . '/.env';
        $report->add(new CheckItem(
            group: 'Environment',
            id: 'env_file',
            label: '.env file',
            status: is_file($envFile) ? CheckStatus::Pass : CheckStatus::Warn,
            detail: is_file($envFile) ? '.env' : 'Not found (defaults may still work)',
            hint: 'Create a .env in the platform root for local overrides',
        ));

        $vendorDir = $platformRoot . '/vendor';
        $report->add(new CheckItem(
            group: 'Dependencies',
            id: 'vendor',
            label: 'Composer vendor',
            status: is_dir($vendorDir) ? CheckStatus::Pass : CheckStatus::Fail,
            detail: is_dir($vendorDir) ? 'vendor/' : 'Missing',
            hint: 'Run composer install in the platform root',
        ));

        $report->add(new CheckItem(
            group: 'Dependencies',
            id: 'composer',
            label: 'composer.json',
            status: is_file($platformRoot . '/composer.json') ? CheckStatus::Pass : CheckStatus::Warn,
            detail: 'composer.json',
            hint: 'Restore composer.json in the platform root',
        ));

        $corePath = SystemConfig::corePath();
        $coreReady = is_dir($corePath)
            && (is_file($corePath . '/functions/base.php') || is_file($corePath . '/launcher/bootstrap.php'));
        $report->add(new CheckItem(
            group: 'Dependencies',
            id: 'pincore',
            label: 'pinoox/pincore',
            status: $coreReady ? CheckStatus::Pass : CheckStatus::Fail,
            detail: $coreReady ? $this->relativePath($corePath, $platformRoot) : 'Not installed',
            hint: $coreReady ? null : 'Run composer install or clone pinoox/pincore into pincore/',
        ));

        $storageDir = SystemConfig::path('storage');
        $report->add(new CheckItem(
            group: 'Storage',
            id: 'storage_writable',
            label: 'Storage writable',
            status: is_dir($storageDir) && is_writable($storageDir)
                ? CheckStatus::Pass
                : (is_dir($storageDir) ? CheckStatus::Fail : CheckStatus::Warn),
            detail: $storageDir,
            hint: 'Ensure storage/ exists and is writable by the web/CLI user',
        ));
    }

    private function checkInstalledApps(DoctorReport $report): void
    {
        $apps = AppEngine::all();
        $count = count($apps);

        $report->add(new CheckItem(
            group: 'Platform',
            id: 'installed_apps',
            label: 'Installed apps',
            status: $count > 0 ? CheckStatus::Pass : CheckStatus::Warn,
            detail: $count . ' app(s)',
            hint: $count > 0 ? null : 'Run ' . ProjectCli::format('app:create', SystemConfig::rootPath()),
        ));
    }

    private function checkAppManifest(DoctorReport $report, string $package, DoctorProject $project): void
    {
        $appPath = AppEngine::exists($package) ? AppEngine::path($package) : $project->root;
        $manifest = $appPath . '/app.php';
        $config = AppEngine::exists($package) ? AppManifest::load($package) : (is_file($manifest) ? (require $manifest) : []);
        $config = is_array($config) ? $config : [];
        $folderPackage = basename($appPath);
        $configPackage = (string) ($config['package'] ?? $package);

        $report->add(new CheckItem(
            group: 'App',
            id: 'manifest',
            label: 'App manifest',
            status: is_file($manifest) ? CheckStatus::Pass : CheckStatus::Fail,
            detail: is_file($manifest) ? 'app.php' : 'Missing app.php',
            hint: $project->isSingleApp()
                ? 'Restore app.php at the project root'
                : 'Create or restore apps/' . $package . '/app.php',
        ));

        $identityOk = $project->isSingleApp() || $configPackage === $folderPackage;
        $report->add(new CheckItem(
            group: 'App',
            id: 'package_identity',
            label: 'Package identity',
            status: $identityOk ? CheckStatus::Pass : CheckStatus::Warn,
            detail: $configPackage . ($project->isSingleApp() ? '' : ' / ' . $folderPackage),
            hint: $identityOk ? null : 'Keep app.php package key aligned with the apps/{package} folder name',
        ));

        $enabled = filter_var($config['enable'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $report->add(new CheckItem(
            group: 'App',
            id: 'app_enabled',
            label: 'App enabled',
            status: $enabled ? CheckStatus::Pass : CheckStatus::Warn,
            detail: $enabled ? 'enabled' : 'disabled in app.php',
            hint: 'Set enable => true when this app should be served',
        ));
    }

    private function checkAppRoutesAndTheme(DoctorReport $report, string $package): void
    {
        $appPath = AppEngine::exists($package)
            ? AppEngine::path($package)
            : (is_file(SystemConfig::rootPath() . '/app.php') ? SystemConfig::rootPath() : null);

        if ($appPath === null) {
            return;
        }

        $config = AppEngine::exists($package) ? AppManifest::load($package) : (require $appPath . '/app.php');
        $config = is_array($config) ? $config : [];
        $routes = $config['router']['routes'] ?? [];
        $routeFiles = 0;
        $missing = [];

        if (is_array($routes)) {
            foreach ($routes as $routeFile) {
                if (!is_string($routeFile) || $routeFile === '') {
                    continue;
                }

                if (is_file($appPath . '/' . $routeFile)) {
                    $routeFiles++;
                } else {
                    $missing[] = $routeFile;
                }
            }
        }

        $routerDir = $appPath . '/router';
        if (is_dir($routerDir)) {
            $routeFiles += count(glob($routerDir . '/*.php') ?: []);
        }

        $status = $missing !== [] ? CheckStatus::Fail : ($routeFiles > 0 ? CheckStatus::Pass : CheckStatus::Warn);
        $detail = $missing !== []
            ? 'Missing: ' . implode(', ', $missing)
            : $routeFiles . ' route file(s)';

        $report->add(new CheckItem(
            group: 'App',
            id: 'routes',
            label: 'Routes',
            status: $status,
            detail: $detail,
            hint: $routeFiles > 0 && $missing === []
                ? null
                : 'Register routes in router/ or routes/ for this app',
        ));

        $theme = (string) ($config['theme'] ?? 'default');
        $themeDir = $appPath . '/theme/' . $theme;
        $report->add(new CheckItem(
            group: 'App',
            id: 'theme',
            label: 'Active theme',
            status: is_dir($themeDir) ? CheckStatus::Pass : CheckStatus::Warn,
            detail: 'theme/' . $theme,
            hint: 'Create theme/' . $theme . ' or update the theme key in app.php',
        ));
    }

    private function checkAppLang(DoctorReport $report, string $package): void
    {
        if (AppEngine::exists($package)) {
            $langDir = AppEngine::path($package) . '/lang';
        } elseif (is_dir(SystemConfig::rootPath() . '/lang')) {
            $langDir = SystemConfig::rootPath() . '/lang';
        } else {
            return;
        }
        $count = 0;

        if (is_dir($langDir)) {
            $finder = new Finder();
            $finder->files()->in($langDir)->name('*.lang.php');
            $count = iterator_count($finder);
        }

        $report->add(new CheckItem(
            group: 'App',
            id: 'lang',
            label: 'Language files',
            status: $count > 0 ? CheckStatus::Pass : CheckStatus::Warn,
            detail: $count . ' file(s)',
            hint: 'Add lang/{locale}/*.lang.php for this app or theme',
        ));
    }

    private function checkPinkerCache(DoctorReport $report, string $platformRoot, string $package): void
    {
        $pinkerApp = $platformRoot . '/pinker/apps/' . $package;

        $report->add(new CheckItem(
            group: 'Pinker',
            id: 'cache',
            label: 'Pinker cache',
            status: is_dir($pinkerApp) ? CheckStatus::Pass : CheckStatus::Warn,
            detail: is_dir($pinkerApp) ? 'pinker/apps/' . $package : 'Not built yet',
            hint: ProjectCli::format('pinker:rebuild ' . $package, $platformRoot),
        ));
    }

    private function checkAppDatabase(DoctorReport $report, string $package): void
    {
        if (!AppEngine::exists($package)) {
            return;
        }

        try {
            $row = DatabaseConnectionToolkit::describeApp($package, test: true);
            $connected = ($row['status'] ?? '') === 'connected';

            $report->add(new CheckItem(
                group: 'Database',
                id: 'db_connect',
                label: 'Database connectivity',
                status: $connected ? CheckStatus::Pass : CheckStatus::Fail,
                detail: ($row['driver'] ?? '—') . ' / ' . ($row['database'] ?? '—'),
                hint: $connected ? null : 'Review DB_* values in .env or app database settings',
            ));
        } catch (\Throwable $e) {
            $report->add(new CheckItem(
                group: 'Database',
                id: 'db_connect',
                label: 'Database connectivity',
                status: CheckStatus::Fail,
                detail: $e->getMessage(),
                hint: 'Review DB_* and DEVDB_* values in .env',
            ));
        }
    }

    private function checkMigrations(DoctorReport $report, string $package): void
    {
        if (!AppEngine::exists($package)) {
            return;
        }

        try {
            $toolkit = new MigrationToolkit();
            $toolkit->package($package)->action('status')->load();

            if (!$toolkit->isSuccess()) {
                $report->add(new CheckItem(
                    group: 'Database',
                    id: 'migrations',
                    label: 'Migrations',
                    status: CheckStatus::Warn,
                    detail: implode('; ', $toolkit->getErrors()),
                    hint: ProjectCli::format('migrate:status ' . $package, SystemConfig::rootPath()),
                ));

                return;
            }

            $migrations = $toolkit->getMigrations();
            $pending = 0;

            foreach ($migrations as $migration) {
                if (empty($migration['sync'])) {
                    $pending++;
                }
            }

            $report->add(new CheckItem(
                group: 'Database',
                id: 'migrations',
                label: 'Migrations',
                status: $pending === 0 ? CheckStatus::Pass : CheckStatus::Warn,
                detail: count($migrations) . ' file(s), ' . $pending . ' pending',
                hint: $pending > 0
                    ? ProjectCli::format('migrate ' . $package, SystemConfig::rootPath())
                    : null,
            ));
        } catch (\Throwable $e) {
            $report->add(new CheckItem(
                group: 'Database',
                id: 'migrations',
                label: 'Migrations',
                status: CheckStatus::Warn,
                detail: $e->getMessage(),
                hint: ProjectCli::format('migrate:status ' . $package, SystemConfig::rootPath()),
            ));
        }
    }

    private function checkFrontend(DoctorReport $report, string $package): void
    {
        if (!AppEngine::exists($package)) {
            return;
        }

        $config = AppManifest::load($package);
        $stack = $config['frontend']['stack'] ?? null;
        $stack = is_string($stack) ? strtolower($stack) : 'twig';

        $report->add(new CheckItem(
            group: 'Frontend',
            id: 'frontend_stack',
            label: 'Frontend stack',
            status: CheckStatus::Pass,
            detail: $stack,
            scored: false,
        ));

        if (in_array($stack, ['twig', 'none', ''], true)) {
            $report->add(new CheckItem(
                group: 'Frontend',
                id: 'node_runtime',
                label: 'Node.js',
                status: CheckStatus::Skip,
                detail: 'Not required for twig-only stack',
                scored: false,
            ));

            return;
        }

        $nodeVersion = $this->commandVersion('node');
        $npmVersion = $this->commandVersion('npm');

        $report->add(new CheckItem(
            group: 'Frontend',
            id: 'node_runtime',
            label: 'Node.js',
            status: $nodeVersion !== null ? CheckStatus::Pass : CheckStatus::Fail,
            detail: $nodeVersion ?? 'Not found in PATH',
            hint: $nodeVersion !== null ? null : 'Install Node.js 18+ for Vite frontend development',
        ));

        $report->add(new CheckItem(
            group: 'Frontend',
            id: 'npm_runtime',
            label: 'npm',
            status: $npmVersion !== null ? CheckStatus::Pass : CheckStatus::Warn,
            detail: $npmVersion ?? 'Not found in PATH',
            hint: $npmVersion !== null ? null : 'Install npm (bundled with Node.js)',
        ));

        $theme = (string) ($config['theme'] ?? 'default');
        $packageJson = AppEngine::path($package) . '/theme/' . $theme . '/package.json';

        $report->add(new CheckItem(
            group: 'Frontend',
            id: 'theme_package_json',
            label: 'theme package.json',
            status: is_file($packageJson) ? CheckStatus::Pass : CheckStatus::Warn,
            detail: is_file($packageJson) ? 'theme/' . $theme . '/package.json' : 'Missing',
            hint: is_file($packageJson)
                ? null
                : ProjectCli::format('theme:frontend scaffold ' . $package . ' --stack=' . $stack, SystemConfig::rootPath()),
        ));

        if (is_file($packageJson)) {
            $hasModules = is_dir(dirname($packageJson) . '/node_modules');
            $report->add(new CheckItem(
                group: 'Frontend',
                id: 'theme_node_modules',
                label: 'node_modules',
                status: $hasModules ? CheckStatus::Pass : CheckStatus::Warn,
                detail: $hasModules ? 'Installed' : 'Not installed',
                hint: $hasModules
                    ? null
                    : ProjectCli::format('deps install ' . $package, SystemConfig::rootPath()),
            ));
        }
    }

    private function commandVersion(string $command): ?string
    {
        $binary = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';

        if ($binary === 'where') {
            exec('where ' . escapeshellarg($command) . ' 2>nul', $whereOutput, $whereCode);
            if ($whereCode !== 0 || $whereOutput === []) {
                return null;
            }
        } else {
            exec($binary . ' ' . escapeshellarg($command) . ' 2>/dev/null', $_, $code);
            if ($code !== 0) {
                return null;
            }
        }

        exec(escapeshellarg($command) . ' --version 2>&1', $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return null;
        }

        $line = trim((string) ($output[0] ?? ''));

        return $line !== '' ? $line : null;
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function relativePath(string $absolute, string $root): string
    {
        $absolute = $this->normalizePath($absolute);
        $root = $this->normalizePath($root);

        return str_starts_with($absolute, $root . '/')
            ? substr($absolute, strlen($root) + 1)
            : basename($absolute);
    }

    private function checkAppsRegistry(DoctorReport $report, string $root, string $package): void
    {
        $registryPath = $this->appsRegistryFile($root);
        $registryLabel = $this->relativePath($registryPath, $root);

        if (!is_file($registryPath)) {
            $report->add(new CheckItem(
                group: 'App',
                id: 'apps_registry',
                label: 'apps.config.php',
                status: CheckStatus::Fail,
                detail: 'Missing ' . $registryLabel,
                hint: 'Add platform/apps.config.php mapping your package to "~"',
            ));

            return;
        }

        $registryConfig = require $registryPath;
        $mapped = is_array($registryConfig)
            ? ($registryConfig['packages'][$package] ?? $registryConfig['apps'][$package] ?? null)
            : null;
        $rootMapped = $mapped === '~' || $mapped === '~/';

        $report->add(new CheckItem(
            group: 'App',
            id: 'apps_registry',
            label: 'apps.config.php mapping',
            status: $rootMapped ? CheckStatus::Pass : CheckStatus::Fail,
            detail: $rootMapped ? $package . ' => ~' : 'Package not mapped to project root',
            hint: $rootMapped ? null : "Set {$registryLabel} packages['{$package}'] = '~'",
        ));
    }

    private function checkSingleAppEnvironment(DoctorReport $report, DoctorProject $project): void
    {
        $packageEnv = $this->env['PINX_PACKAGE'] ?? '';

        if ($packageEnv !== '') {
            $matches = $packageEnv === $project->package;
            $report->add(new CheckItem(
                group: 'Environment',
                id: 'env_pinx_package',
                label: 'PINX_PACKAGE',
                status: $matches ? CheckStatus::Pass : CheckStatus::Fail,
                detail: $matches ? $packageEnv : $packageEnv . ' (expected ' . $project->package . ')',
                hint: $matches ? null : 'Set PINX_PACKAGE=' . $project->package . ' in .env',
            ));
        }

        $lock = $project->root . '/composer.lock';
        $report->add(new CheckItem(
            group: 'Dependencies',
            id: 'composer_lock',
            label: 'composer.lock',
            status: is_file($lock) ? CheckStatus::Pass : CheckStatus::Warn,
            detail: is_file($lock) ? 'Present' : 'Missing — versions are not pinned',
            hint: is_file($lock) ? null : 'Run: composer update to generate composer.lock',
        ));
    }

    private function checkWritablePaths(DoctorReport $report, string $root): void
    {
        foreach ([
            'storage' => 'Runtime storage',
            'pinker' => 'Pinker build cache',
            'storage/logs' => 'Application logs',
            'export' => 'Pinx build output',
        ] as $relative => $label) {
            $path = $root . '/' . $relative;

            if (!is_dir($path)) {
                $severity = in_array($relative, ['storage', 'pinker'], true)
                    ? CheckStatus::Warn
                    : CheckStatus::Pass;

                $report->add(new CheckItem(
                    group: 'Permissions',
                    id: 'writable_' . str_replace('/', '_', $relative),
                    label: $relative,
                    status: $severity,
                    detail: 'Directory does not exist yet',
                    hint: in_array($relative, ['storage', 'pinker'], true)
                        ? 'Run: mkdir ' . str_replace('/', DIRECTORY_SEPARATOR, $relative)
                        : null,
                    scored: in_array($relative, ['storage', 'pinker'], true),
                ));

                continue;
            }

            $writable = is_writable($path);
            $report->add(new CheckItem(
                group: 'Permissions',
                id: 'writable_' . str_replace('/', '_', $relative),
                label: $relative,
                status: $writable ? CheckStatus::Pass : CheckStatus::Fail,
                detail: $label,
                hint: $writable ? null : 'Grant write permission on ' . $relative,
            ));
        }
    }

    private function checkBuildReadiness(DoctorReport $report, DoctorProject $project): void
    {
        $config = is_file($project->root . '/app.php') ? (require $project->root . '/app.php') : [];
        $config = is_array($config) ? $config : [];
        $minpin = $config['pinx']['minpin'] ?? null;

        if ($minpin === null) {
            return;
        }

        $minpin = is_numeric($minpin) ? (int) $minpin : null;
        $installed = $this->readPackageVersion(SystemConfig::corePath('composer.json'));
        $installedMajor = $this->majorVersion($installed);

        if ($minpin === null || $installedMajor === null) {
            return;
        }

        $ok = $installedMajor >= $minpin;
        $report->add(new CheckItem(
            group: 'Build',
            id: 'minpin_compat',
            label: 'pinx minpin',
            status: $ok ? CheckStatus::Pass : CheckStatus::Warn,
            detail: 'Requires Pinoox ' . $minpin . '+, installed ' . ($installed ?? 'unknown'),
            hint: $ok ? null : 'Update pinoox/pincore: composer update pinoox/pincore',
            scored: false,
        ));
    }

    /**
     * @return array<string, string>
     */
    private function loadEnv(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $vars = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\"'");

            if ($key !== '') {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }

    private function readPackageVersion(string $composerJson): ?string
    {
        if (!is_file($composerJson)) {
            return null;
        }

        $raw = file_get_contents($composerJson);

        if (!is_string($raw)) {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return null;
        }

        $version = $data['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    private function majorVersion(?string $version): ?int
    {
        if ($version === null || $version === '') {
            return null;
        }

        $parts = explode('.', $version);

        return ctype_digit($parts[0] ?? '') ? (int) $parts[0] : null;
    }

    private function appsRegistryFile(string $root): string
    {
        $override = getenv('PINOOX_PROJECT_REGISTRY_PATH');

        if (is_string($override) && $override !== '') {
            return $this->normalizePath($override);
        }

        foreach ([
            $root . '/platform/apps.config.php',
            $root . '/config/apps.config.php',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $root . '/platform/apps.config.php';
    }
}
