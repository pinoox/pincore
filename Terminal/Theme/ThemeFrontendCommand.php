<?php

namespace Pinoox\Terminal\Theme;

use Pinoox\Component\Package\AppManifest;
use Pinoox\Component\Package\PackageName;
use Pinoox\Component\Server\DevelopmentServer;
use Pinoox\Component\Template\Frontend\FrontendConfig;
use Pinoox\Component\Template\Frontend\FrontendDevSession;
use Pinoox\Component\Template\Frontend\FrontendDevStack;
use Pinoox\Component\Template\Frontend\FrontendDevSync;
use Pinoox\Component\Template\Frontend\ThemeFrontend;
use Pinoox\Component\Terminal;
use Pinoox\Support\CliText;
use Pinoox\Support\ProjectCli;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Pinoox\Terminal\Concerns\SelectsTheme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'theme:frontend',
    description: 'Build, run, inspect, or scaffold frontend assets for an app theme',
    aliases: ['fe', 'frontend'],
)]

class ThemeFrontendCommand extends Terminal
{
    use SelectsPackage;
    use SelectsTheme;

    /** @var list<string> */
    private const ACTIONS = ['info', 'install', 'build', 'dev', 'dev:apps', 'watch', 'run', 'scaffold'];

    /** @var list<string> */
    private const DEPRECATED_ACTIONS = ['dev-stack'];

    private const VITE_HMR_AUTO = 'auto';

    private bool $platformServe = false;

    private bool $devAppsAuto = false;

    /** @var list<string> */
    private array $devAppsAutoPackages = [];

    protected function configure(): void
    {
        $serve = ProjectCli::platformFormat('serve');
        $this
            ->setHelp($this->cliHelp(
                <<<'INTRO'
Manage frontend assets inside apps/{package}/theme/{theme}/.

Actions:
  info      Stack, recommended Twig line (vite_tags), npm scripts
  install   Install npm dependencies (skips when up to date; use --install to force)
  build     Run npm run build
  dev       Run npm run dev (Vite HMR + Twig refresh, live output)
  dev:apps  One shared 
INTRO
                . $serve . <<<'INTRO'
 + Vite for multiple apps (pick packages interactively)
  watch     Run npm run watch (rebuild assets on file changes)
  run       Run any npm script from package.json (--script=name)
  scaffold  Copy starter files (default stack: vue; auto-detect from package.json when possible)

Recommended assets in Twig: {{ vite_tags('src/main.js')|raw }} — or multiple entries like Laravel @vite([...]).
Built assets in Twig: {{ vite_asset('src/images/logo.png') }}

Create a new theme:
INTRO
                . '  ' . $this->cliFormat('theme:create {name}'),
                [
                    'fe info',
                    'fe spark dev',
                    'fe dev:apps',
                    'fe dev:apps com_pinoox_manager,com_pinoox_welcome',
                    'fe dev:apps --apps=com_pinoox_manager,com_pinoox_welcome',
                    'fe spark build',
                    'fe spark watch',
                    'fe com_my_shop build',
                    'fe com_my_shop dev --theme=admin',
                    'fe spark run --script=preview',
                    'fe spark install --install',
                    'fe com_my_shop scaffold --stack=vue',
                ],
                <<<FOOTER
The first argument is an app package (com_my_shop) or theme folder (spark), then the action.
Legacy order (action first) still works: {$this->cliFormat('fe dev spark')}

If the theme name exists in one app only, the package is resolved automatically.
If it exists in multiple apps, pick the package from a list.

Target and action can be omitted — pick from a list interactively (defaults to info).

dev also starts {$serve} for the resolved app (use --no-serve to skip).

dev:apps starts one shared {$serve} plus Vite for multiple apps. Use full package names (e.g. com_pinoox_manager, io_yoosefap_ai).

Dev auto-setup (no manual .env required):
  - Syncs vite.pinoox.mjs (hot file + mount-aware proxy)
  - Merges dev keys into theme .env (default) — use --env-file for a custom name
  - Injects env into npm run dev (VITE_SERVER_URL, VITE_DEV_PROXY, …)
  - Use --fix-vite to patch vite.config.js when pinooxHot/pinooxServer are missing

build, dev, and run skip npm install by default (faster workflow).
Use --install to install dependencies alongside the command when needed.
The install action runs npm install; add --install to force reinstall.

Development (.env):
  VITE_DEV=true
  VITE_DEV_SERVER=http://127.0.0.1:5173

HMR: fe dev/build/run auto-sync vite.pinoox.mjs and write theme/dist/hot on Vite start.
Override hot path in frontend.config.php dev.hot or VITE_HOT_FILE.
FOOTER
            ))
            ->addArgument('target', InputArgument::OPTIONAL, 'App package (com_my_shop) or theme folder (spark). Leave empty to pick interactively.')
            ->addArgument('action', InputArgument::OPTIONAL, 'Action: info, install, build, dev, dev:apps, run, scaffold')
            ->addOption('stack', null, InputOption::VALUE_REQUIRED, 'Frontend stack for scaffold: twig, vite, vue, react (default: auto or vue)')
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, 'Theme folder name (defaults to app.php theme or interactive pick)')
            ->addOption('script', null, InputOption::VALUE_REQUIRED, 'npm script name for the run action')
            ->addOption('install', null, InputOption::VALUE_NONE, 'Run npm install alongside the command (or force reinstall with the install action)')
            ->addOption('no-install', null, InputOption::VALUE_NONE, 'Skip npm install (default for build/dev/run)')
            ->addOption('no-serve', null, InputOption::VALUE_NONE, 'Do not start ' . ProjectCli::platformFormat('serve') . ' alongside dev')
            ->addOption('apps', null, InputOption::VALUE_REQUIRED, 'Comma-separated package names for dev:apps (e.g. com_pinoox_manager,com_pinoox_welcome)')
            ->addOption('serve-app', null, InputOption::VALUE_REQUIRED, 'App binding for the dev server (defaults to the resolved package; use "platform" for full router)')
            ->addOption('serve-host', null, InputOption::VALUE_REQUIRED, 'Host for ' . ProjectCli::platformFormat('serve') . ' (default from SERVER_HOST or 127.0.0.1)')
            ->addOption('serve-port', null, InputOption::VALUE_REQUIRED, 'Port for ' . ProjectCli::platformFormat('serve') . ' (default from SERVER_PORT or 8000)')
            ->addOption('network', 'N', InputOption::VALUE_NONE, 'Serve PHP app + Vite on LAN (0.0.0.0, shows your network IP)')
            ->addOption('vite-host', null, InputOption::VALUE_REQUIRED, 'Vite bind host (default 127.0.0.1; use --network or --vite-network for LAN)')
            ->addOption('vite-network', null, InputOption::VALUE_NONE, 'Bind Vite to 0.0.0.0 for LAN access')
            ->addOption('verbose-vite', null, InputOption::VALUE_NONE, 'Show full Vite startup URLs (Local/Network)')
            ->addOption('fix-vite', null, InputOption::VALUE_NONE, 'Auto-wire vite.config.js with pinooxHot/pinooxServer when missing')
            ->addOption('env-file', null, InputOption::VALUE_REQUIRED, 'Theme env file for fe dev auto-setup (default: .env)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $this->platformServe = false;
        $this->devAppsAuto = false;
        $this->devAppsAutoPackages = [];

        try {
            [$target, $action] = $this->parseArguments($input);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!in_array($action, self::ACTIONS, true)) {
            $io->error('Unknown action "' . $action . '". Use info, install, build, dev, dev:apps, watch, run, or scaffold.');

            return Command::FAILURE;
        }

        if ($action === 'dev:apps') {
            try {
                return $this->runDevApps($io, $input, $output, $target);
            } catch (\Throwable $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        try {
            $resolved = $this->resolveTarget($input, $output, $io, $action, $target);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $package = $resolved['package'];
        $themeName = $resolved['theme'];

        if ($action === 'dev' && $this->devAppsAuto) {
            try {
                return $this->runDevApps($io, $input, $output, implode(',', $this->devAppsAutoPackages));
            } catch (\Throwable $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        $frontend = ThemeFrontend::forPackageAndTheme($package, $themeName);
        $frontend->setOutputWriter(static fn (string $buffer) => $output->write($buffer));

        $installMode = $this->resolveInstallMode($input, $action);

        try {
            return match ($action) {
                'info' => $this->runInfo($io, $frontend),
                'install' => $this->runInstall($io, $frontend, $installMode),
                'build' => $this->runBuild($io, $frontend, $installMode),
                'dev' => $this->runDev($io, $frontend, $installMode, $package, $input, $output),
                'watch' => $this->runWatch($io, $frontend, $installMode),
                'run' => $this->runScript($input, $output, $io, $frontend, $installMode),
                'scaffold' => $this->runScaffold($io, $frontend, (string) $input->getOption('stack')),
            };
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @return array{0: string, 1: string} [target, action]
     */
    private function parseArguments(InputInterface $input): array
    {
        $arg1 = trim((string) $input->getArgument('target'));
        $arg2 = trim((string) $input->getArgument('action'));
        $first = $this->normalizeAction($arg1);
        $second = $this->normalizeAction($arg2);

        if ($arg1 === '' && $arg2 === '') {
            return ['', 'info'];
        }

        if ($arg1 !== '' && $arg2 === '') {
            if ($this->isKnownAction($first)) {
                return ['', $first];
            }

            throw new \RuntimeException(
                'Action is required. Example: ' . $this->cliFormat('fe ' . $arg1 . ' dev'),
            );
        }

        if ($this->isKnownAction($first) && !$this->isKnownAction($second)) {
            return [$arg2, $first];
        }

        if ($this->isKnownAction($second)) {
            return [$arg1, $second];
        }

        if ($this->isKnownAction($first)) {
            return ['', $first];
        }

        throw new \RuntimeException(
            'Could not parse arguments. Use: ' . $this->cliFormat('fe spark dev') . ' (or ' . $this->cliFormat('fe dev spark') . ').',
        );
    }

    private function normalizeAction(string $action): string
    {
        $action = strtolower(trim($action));

        if ($action === 'dev-stack') {
            return 'dev:apps';
        }

        return $action;
    }

    private function isKnownAction(string $action): bool
    {
        return in_array($action, self::ACTIONS, true)
            || in_array($action, self::DEPRECATED_ACTIONS, true);
    }

    /**
     * @return array{package: string, theme: string}
     */
    private function resolveTarget(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $action,
        string $targetInput = '',
    ): array {
        $rawTarget = strtolower(trim((string) $input->getArgument('target')));

        if ($targetInput !== '') {
            $positional = $this->normalizePackageInput($targetInput);
        } elseif ($rawTarget !== '' && in_array($rawTarget, self::ACTIONS, true)) {
            $positional = '';
        } else {
            $positional = $this->readPackageInput($input, 'target', ['package', 'app']);
        }

        $themeOption = $this->readThemeInput($input);

        if ($positional !== '' && AppEngine::exists($positional)) {
            return [
                'package' => $positional,
                'theme' => $this->resolveThemeForPackage($input, $output, $io, $positional, $action, $themeOption),
            ];
        }

        $themeName = $themeOption !== '' ? $themeOption : $positional;
        if ($themeName !== '') {
            return $this->resolveByThemeName($input, $output, $io, $themeName);
        }

        if ($action === 'dev') {
            return $this->resolveDevTarget($input, $output, $io, $targetInput);
        }

        $candidates = $this->frontendPackageCandidates();

        $package = $candidates !== []
            ? $this->resolvePackageFromCandidates($input, $output, $io, $candidates, [
                'sectionTitle' => 'Apps with frontend themes',
                'emptyMessage' => 'No apps with frontend themes were found.',
                'resolvedInput' => $positional,
            ])
            : $this->resolvePackageRequired($input, $output, $io, [
                'sectionTitle' => 'Theme frontend for',
                'appsOnly' => true,
            ]);

        return [
            'package' => $package,
            'theme' => $this->resolveThemeForPackage($input, $output, $io, $package, $action, ''),
        ];
    }

    /**
     * @return array{package: string, theme: string}
     */
    private function resolveDevTarget(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $targetInput = '',
    ): array {
        $rawTarget = strtolower(trim((string) $input->getArgument('target')));

        if ($targetInput !== '') {
            $positional = $this->normalizePackageInput($targetInput);
        } elseif ($rawTarget !== '' && in_array($rawTarget, self::ACTIONS, true)) {
            $positional = '';
        } else {
            $positional = $this->readPackageInput($input, 'target', ['package', 'app']);
        }

        if (strtolower($positional) === FrontendDevSession::SERVE_PLATFORM) {
            $this->platformServe = true;
            $positional = '';
        }

        $themeOption = $this->readThemeInput($input);
        $candidates = $this->frontendPackageCandidates();

        if ($positional !== '' && AppEngine::exists($positional)) {
            return [
                'package' => $positional,
                'theme' => $this->resolveThemeForPackage($input, $output, $io, $positional, 'dev', $themeOption),
            ];
        }

        $themeName = $themeOption !== '' ? $themeOption : $positional;

        if ($themeName !== '') {
            return $this->resolveByThemeName($input, $output, $io, $themeName);
        }

        if ($candidates === []) {
            throw new \RuntimeException('No apps with frontend themes were found.');
        }

        if (!$this->platformServe) {
            $devCandidates = [
                FrontendDevSession::SERVE_PLATFORM => 'All apps (platform router)',
            ] + $candidates;

            if (!$input->isInteractive() && $positional === '') {
                throw new \RuntimeException('Dev target is required in non-interactive mode.');
            }

            if (count($devCandidates) === 1) {
                $selected = array_key_first($devCandidates);
                $io->note('Using the only available dev target: ' . $selected);
            } else {
                $selected = $this->resolvePackageFromCandidates($input, $output, $io, $devCandidates, [
                    'sectionTitle' => 'Frontend dev for',
                    'emptyMessage' => 'No apps with frontend themes were found.',
                    'invalidMessage' => "Dev target '%s' was not found.",
                    'argument' => 'target',
                    'resolvedInput' => $positional,
                ]);
            }

            if ($selected !== FrontendDevSession::SERVE_PLATFORM) {
                return [
                    'package' => $selected,
                    'theme' => $this->resolveThemeForPackage($input, $output, $io, $selected, 'dev', ''),
                ];
            }

            $this->platformServe = true;
        }

        if (strtolower($positional) === self::VITE_HMR_AUTO) {
            return $this->resolveAutoViteDevTarget($input, $output, $io);
        }

        $appsOption = strtolower(trim((string) $input->getOption('apps')));

        if ($appsOption === self::VITE_HMR_AUTO) {
            return $this->resolveAutoViteDevTarget($input, $output, $io);
        }

        $platformCandidates = $this->frontendPlatformViteCandidates();

        if ($platformCandidates === []) {
            throw new \RuntimeException(
                'No routed apps with Vite HMR support were found. Add apps to app-router.config.php first.',
            );
        }

        $viteCandidates = [
            self::VITE_HMR_AUTO => 'All routed Vite apps (auto-detect HMR)',
        ] + $platformCandidates;

        $selected = count($viteCandidates) === 1
            ? array_key_first($viteCandidates)
            : $this->resolvePackageFromCandidates($input, $output, $io, $viteCandidates, [
                'sectionTitle' => 'Vite HMR for (app-router)',
                'emptyMessage' => 'No routed apps with Vite HMR support were found.',
                'invalidMessage' => "Package '%s' was not found.",
                'argument' => 'target',
                'resolvedInput' => '',
            ]);

        if ($selected === self::VITE_HMR_AUTO) {
            return $this->resolveAutoViteDevTarget($input, $output, $io);
        }

        $package = $selected;

        $io->note('Platform serve: all apps available via the router (like `php pinoox serve`).');

        return [
            'package' => $package,
            'theme' => $this->resolveThemeForPackage($input, $output, $io, $package, 'dev', ''),
        ];
    }

    /**
     * @return array{package: string, theme: string}
     */
    private function resolveAutoViteDevTarget(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
    ): array {
        $packages = ThemeFrontend::packagesWithViteDevForPlatform();

        if ($packages === []) {
            throw new \RuntimeException(
                'No routed apps with Vite HMR support were found. Add apps to app-router.config.php first.',
            );
        }

        $this->devAppsAuto = true;
        $this->devAppsAutoPackages = $packages;
        $this->platformServe = true;

        $package = $packages[0];
        $io->note([
            'Platform serve: all apps available via the router (like `php pinoox serve`).',
            'Auto Vite HMR for: ' . implode(', ', $packages),
        ]);

        return [
            'package' => $package,
            'theme' => $this->resolveThemeForPackage($input, $output, $io, $package, 'dev', ''),
        ];
    }

    /**
     * @return array{package: string, theme: string}
     */
    private function resolveByThemeName(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $themeName,
    ): array {
        $packages = ThemeFrontend::findPackagesByThemeFolder($themeName);

        if ($packages === []) {
            throw new \RuntimeException(sprintf("Theme '%s' was not found in any app.", $themeName));
        }

        if (count($packages) === 1) {
            $package = array_key_first($packages);
            $io->note(sprintf('Using theme %s in %s', $themeName, $package));

            return ['package' => $package, 'theme' => $themeName];
        }

        $package = $this->resolvePackageFromCandidates($input, $output, $io, $packages, [
            'sectionTitle' => sprintf("Apps with theme '%s'", $themeName),
            'emptyMessage' => sprintf("Theme '%s' was not found in any app.", $themeName),
            'invalidMessage' => "Package '%s' does not contain theme '$themeName'.",
            'argument' => 'target',
        ]);

        return ['package' => $package, 'theme' => $themeName];
    }

    private function resolveThemeForPackage(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $package,
        string $action,
        string $themeOption,
    ): string {
        $themeChoices = ThemeFrontend::listThemeFolders($package);
        $defaultTheme = (string) AppEngine::config($package)->get('theme', 'default');

        if ($themeOption !== '') {
            if (!isset($themeChoices[$themeOption]) && $action !== 'scaffold') {
                throw new \RuntimeException(sprintf("Theme '%s' was not found in package '%s'.", $themeOption, $package));
            }

            return $themeOption;
        }

        if ($themeChoices === [] && $action !== 'scaffold') {
            throw new \RuntimeException('No theme folders were found under apps/' . $package . '/theme/.');
        }

        if ($action === 'scaffold' && $themeChoices === []) {
            return $defaultTheme;
        }

        return $this->resolveThemeChoice($input, $output, $io, $package, $themeChoices, [
            'default' => $defaultTheme,
            'sectionTitle' => 'Themes in ' . $package,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function frontendPackageCandidates(): array
    {
        $candidates = [];

        foreach (AppEngine::all() as $package => $manager) {
            if (ThemeFrontend::listThemeFolders($package) === []) {
                continue;
            }

            $candidates[$package] = AppManifest::displayName($package);
        }

        return $candidates;
    }

    /**
     * Routed apps in app-router.config.php that support Vite HMR.
     *
     * @return array<string, string>
     */
    private function frontendPlatformViteCandidates(): array
    {
        $candidates = [];

        foreach (ThemeFrontend::packagesWithViteDevForPlatform() as $package) {
            $candidates[$package] = AppManifest::displayName($package);
        }

        return $candidates;
    }

    private function resolveInstallMode(InputInterface $input, string $action): string
    {
        if ((bool) $input->getOption('no-install')) {
            return ThemeFrontend::INSTALL_SKIP;
        }

        if ((bool) $input->getOption('install')) {
            return $action === 'install'
                ? ThemeFrontend::INSTALL_FORCE
                : ThemeFrontend::INSTALL_SMART;
        }

        if ($action === 'install') {
            return ThemeFrontend::INSTALL_SMART;
        }

        return ThemeFrontend::INSTALL_SKIP;
    }

    private function runInfo(SymfonyStyle $io, ThemeFrontend $frontend): int
    {
        $info = $frontend->info();
        $io->title('Theme Frontend');
        $io->definitionList(
            ['Package' => $info['package']],
            ['Theme path' => $info['theme_path']],
            ['Stack' => $info['stack']],
            ['Entries' => implode(', ', $info['entries'] ?? [])],
            ['Recommended Twig' => (string) ($info['recommended_twig'] ?? '-')],
            ['Manifest' => ($info['manifest_exists'] ?? false) ? 'built' : 'missing — run fe build'],
            ['Dev' => !empty($info['dev_enabled']) ? (string) ($info['dev_url'] ?? 'on') : 'off'],
            ['Dev port' => (string) ($info['dev_port'] ?? '-')],
            ['Hot file' => ($info['hot_exists'] ?? false) ? (string) ($info['hot_relative'] ?? '-') : 'missing'],
            ['vite.pinoox.mjs' => !empty($info['pinoox_bundle']) ? 'synced' : 'missing — run fe dev/build'],
            ['vite.config wired' => !empty($info['vite_wired']) ? 'yes' : 'no — run fe dev --fix-vite'],
            ['Theme .env' => !empty($info['env_autodev'])
                ? (string) ($info['env_file'] ?? '.env') . ' (auto block present)'
                : (string) ($info['env_file'] ?? '.env') . ' — runtime only; set ' . FrontendDevSync::ENV_SERVER_SYNC_KEY . '=true to persist'],
            ['npm' => ($info['package_json'] ?? false)
                ? (($info['needs_npm_install'] ?? false) ? 'install needed' : 'ready')
                : 'none'],
        );

        if (!empty($info['assets_hint'])) {
            $io->note((string) $info['assets_hint']);
        }

        $scripts = $info['npm_scripts'];
        if ($scripts !== []) {
            $io->section('npm scripts');
            $rows = [];
            foreach ($scripts as $name => $command) {
                $rows[] = [$name, $command];
            }
            $io->table(['Script', 'Command'], $rows);
        }

        return Command::SUCCESS;
    }

    private function runInstall(SymfonyStyle $io, ThemeFrontend $frontend, string $installMode): int
    {
        $io->section('npm install: ' . $frontend->themePath());

        if ($installMode === ThemeFrontend::INSTALL_SKIP) {
            $io->warning('Skipped (--no-install).');

            return Command::SUCCESS;
        }

        if ($installMode === ThemeFrontend::INSTALL_SMART && !$frontend->needsNpmInstall()) {
            $io->success('Dependencies are already up to date. Use --install to force reinstall.');

            return Command::SUCCESS;
        }

        $code = $frontend->install();

        return $code === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function runBuild(SymfonyStyle $io, ThemeFrontend $frontend, string $installMode): int
    {
        $io->section('Building frontend: ' . $frontend->themePath());
        $this->noteInstallPlan($io, $frontend, $installMode);

        $code = $frontend->build($installMode);

        return $code === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function runWatch(SymfonyStyle $io, ThemeFrontend $frontend, string $installMode): int
    {
        $io->section('Watching frontend for changes: ' . $frontend->themePath());
        $this->noteInstallPlan($io, $frontend, $installMode);
        $io->note('Rebuilds production assets on save. Use `fe dev` for HMR during development.');

        $code = $frontend->watch($installMode);

        return $code === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function runDev(
        SymfonyStyle $io,
        ThemeFrontend $frontend,
        string $installMode,
        string $package,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io->section('Starting frontend dev server: ' . $frontend->themePath());
        $this->noteInstallPlan($io, $frontend, $installMode);

        $withServe = !(bool) $input->getOption('no-serve');
        $servePortOption = $input->getOption('serve-port');
        $servePort = $servePortOption !== null && $servePortOption !== ''
            ? (int) $servePortOption
            : null;
        $explicitServeApp = trim((string) $input->getOption('serve-app'));
        $platformServe = $this->platformServe
            || strtolower($explicitServeApp) === FrontendDevSession::SERVE_PLATFORM;
        $serveApp = $platformServe
            ? FrontendDevSession::SERVE_PLATFORM
            : ($explicitServeApp !== '' ? $explicitServeApp : $package);

        $viteOpts = $this->resolveViteDevOptions($input, $frontend->config());

        $session = FrontendDevSession::fromOptions(
            $package,
            $frontend->config(),
            $this->resolveServeHost($input),
            $servePort,
            $serveApp,
            $withServe,
            null,
            $viteOpts['host'],
            $viteOpts['quiet'],
        );

        if ($this->isNetworkMode($input)) {
            $this->renderNetworkDevNote($io, $session);
        }

        $frontend->setDevSession($session);
        $frontend->setFixViteOnSync((bool) $input->getOption('fix-vite'));
        $envFileOption = $input->getOption('env-file');
        if (is_string($envFileOption) && trim($envFileOption) !== '') {
            $frontend->setDevEnvFile(trim($envFileOption));
        }

        $sync = $frontend->syncDev();
        $this->renderDevDiagnostics($io, $frontend, $sync);

        $serveProcess = null;

        if ($withServe) {
            try {
                $serveProcess = $this->startServeProcess($package, $input, $output, $io, $platformServe, $serveApp);
            } catch (\Throwable $e) {
                $io->error('Could not start Pinoox server: ' . $e->getMessage());

                return Command::FAILURE;
            }
        }

        $this->renderDevApplicationUrl($io, $session, $withServe);

        try {
            return $frontend->dev($installMode) === 0 ? Command::SUCCESS : Command::FAILURE;
        } finally {
            $frontend->stopRunningProcess();
            $this->stopServeProcess($serveProcess, $io);
        }
    }

    private function runDevApps(
        SymfonyStyle $io,
        InputInterface $input,
        OutputInterface $output,
        string $targetList = '',
    ): int {
        $packages = $this->resolveDevAppsPackages($input, $output, $io, $targetList);

        if ($packages === []) {
            $io->error('Select at least one app for dev:apps.');

            return Command::FAILURE;
        }

        $installMode = $this->resolveInstallMode($input, 'dev');
        $serveHost = $input->getOption('serve-host');
        $servePortOption = $input->getOption('serve-port');
        $servePort = $servePortOption !== null && $servePortOption !== ''
            ? (int) $servePortOption
            : null;
        $envFileOption = $input->getOption('env-file');
        $envFile = is_string($envFileOption) && trim($envFileOption) !== ''
            ? trim($envFileOption)
            : null;
        $fixVite = (bool) $input->getOption('fix-vite');

        $targets = [];
        $frontends = [];
        $sessions = [];

        foreach ($packages as $package) {
            $themeName = $this->resolveThemeForPackage($input, $output, $io, $package, 'dev', '');
            $frontend = ThemeFrontend::forPackageAndTheme($package, $themeName);
            $frontend->setFixViteOnSync($fixVite);

            if ($envFile !== null) {
                $frontend->setDevEnvFile($envFile);
            }

            $targets[] = [
                'package' => $package,
                'theme' => $themeName,
                'config' => $frontend->config(),
            ];
        }

        $vitePorts = FrontendDevStack::allocateVitePorts($targets);
        $sharedHost = is_string($serveHost) && trim($serveHost) !== '' ? trim($serveHost) : null;
        $forceEnvKeys = array_merge(FrontendDevSync::stackForceEnvKeys(), [
            'VITE_DEV_STACK',
            'VITE_SERVE_APP',
            'VITE_DEV_QUIET',
        ]);
        $viteOpts = $this->resolveViteDevOptions($input, $targets[0]['config'] ?? []);
        $networkServeHost = $this->isNetworkMode($input) ? '0.0.0.0' : ($sharedHost !== '' ? $sharedHost : null);
        $quietStack = $this->devAppsAuto || count($packages) > 1;

        foreach ($targets as $index => $target) {
            $frontend = ThemeFrontend::forPackageAndTheme($target['package'], $target['theme']);
            $frontend->setFixViteOnSync($fixVite);
            $frontend->setForceDevEnvKeys($forceEnvKeys);

            if ($envFile !== null) {
                $frontend->setDevEnvFile($envFile);
            }

            $session = FrontendDevSession::fromOptions(
                $target['package'],
                $target['config'],
                $networkServeHost,
                $servePort,
                FrontendDevSession::SERVE_PLATFORM,
                false,
                $vitePorts[$index],
                $viteOpts['host'],
                true,
            );

            $frontend->setDevSession($session);
            $sync = $frontend->syncDev();

            if (!$quietStack) {
                $this->renderDevDiagnostics($io, $frontend, $sync);
            }

            $frontends[] = $frontend;
            $sessions[] = $session;
        }

        if ($quietStack && $this->isNetworkMode($input)) {
            $this->renderNetworkDevNote($io, $sessions[0]);
        }

        $stackServeHost = $this->isNetworkMode($input) ? '0.0.0.0' : $sharedHost;

        return (new FrontendDevStack())->run($io, $output, $frontends, $sessions, $stackServeHost, $servePort) === 0
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function resolveDevAppsPackages(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $targetList = '',
    ): array {
        $appsOption = trim((string) $input->getOption('apps'));

        if ($appsOption !== '') {
            if (strtolower($appsOption) === self::VITE_HMR_AUTO) {
                return ThemeFrontend::packagesWithViteDev();
            }

            return $this->resolveDevAppsPackageList($this->parsePackageList($appsOption));
        }

        if (trim($targetList) !== '') {
            return $this->resolveDevAppsPackageList($this->parsePackageList($targetList));
        }

        $candidates = $this->frontendPackageCandidates();

        if ($candidates === []) {
            throw new \RuntimeException('No apps with frontend themes were found.');
        }

        if (!$this->shouldPromptForDevApps($input)) {
            throw new \RuntimeException(
                'Package list is required in non-interactive mode. Use --apps=com_pinoox_manager,com_pinoox_welcome.',
            );
        }

        if ($input instanceof \Symfony\Component\Console\Input\Input) {
            $input->setInteractive(true);
        }

        return $this->askDevAppsPackages($input, $output, $io, $candidates);
    }

    private function shouldPromptForDevApps(InputInterface $input): bool
    {
        if ($input->hasOption('no-interaction') && $input->getOption('no-interaction')) {
            return false;
        }

        if ($input->isInteractive()) {
            return true;
        }

        return function_exists('stream_isatty') && defined('STDIN') && @stream_isatty(STDIN);
    }

    /**
     * @param array<string, string> $candidates
     * @return list<string>
     */
    private function askDevAppsPackages(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        array $candidates,
    ): array {
        $packages = array_keys($candidates);

        if (count($packages) === 1) {
            $only = $packages[0];
            $io->note('Using the only available package: ' . $only);

            return [$only];
        }

        $io->section('Apps with frontend themes');
        $rows = [];

        foreach ($packages as $index => $package) {
            $rows[] = [$index, $package, CliText::isolateRtl($candidates[$package])];
        }

        $io->table(['#', 'Package', 'Name'], $rows);
        $io->writeln([
            '<comment>Enter one or more packages:</comment>',
            '  • numbers: <info>1,7</info>',
            '  • package names: <info>com_pinoox_manager,com_pinoox_welcome</info>',
            '  • all apps: <info>all</info>',
            '  • Vite HMR auto-detect: <info>auto</info>',
        ]);

        $question = new Question('Select packages: ');
        $question->setAutocompleterValues(array_merge(['all', self::VITE_HMR_AUTO], $packages));
        $question->setValidator(function (mixed $answer) use ($packages): array {
            return $this->parseDevAppsSelection((string) $answer, $packages);
        });

        /** @var list<string> */
        return $this->getHelper('question')->ask($input, $output, $question);
    }

    /**
     * @param list<string> $packages
     * @return list<string>
     */
    private function parseDevAppsSelection(string $raw, array $packages): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            throw new \RuntimeException('Select at least one package.');
        }

        if (strtolower($raw) === 'all') {
            return $packages;
        }

        if (strtolower($raw) === self::VITE_HMR_AUTO) {
            $auto = ThemeFrontend::packagesWithViteDev();

            if ($auto === []) {
                throw new \RuntimeException('No apps with Vite HMR support were found.');
            }

            return $auto;
        }

        $tokens = preg_split('/\s*,\s*/', $raw) ?: [];
        $resolved = [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            if (in_array($token, $packages, true)) {
                $resolved[] = $token;

                continue;
            }

            if (ctype_digit($token)) {
                $resolved[] = $packages[(int) $token] ?? throw new \RuntimeException("App #$token was not found.");

                continue;
            }

            $resolved[] = $this->assertDevAppsPackage($token);
        }

        $resolved = array_values(array_unique($resolved));

        if ($resolved === []) {
            throw new \RuntimeException('Select at least one package.');
        }

        return $resolved;
    }

    /**
     * @param list<string> $inputs
     * @return list<string>
     */
    private function resolveDevAppsPackageList(array $inputs): array
    {
        $packages = [];

        foreach ($inputs as $item) {
            $packages[] = $this->assertDevAppsPackage($item);
        }

        return array_values(array_unique($packages));
    }

    private function assertDevAppsPackage(string $item): string
    {
        $item = trim($item);

        if ($item === '') {
            throw new \RuntimeException('Package name cannot be empty.');
        }

        $package = $this->normalizePackageInput($item);

        if ($package === '' || !PackageName::isValid($package)) {
            throw new \RuntimeException(sprintf(
                "Invalid package '%s'. Use full package names (%s), e.g. com_pinoox_manager,io_yoosefap_ai.",
                $item,
                PackageName::formatHint(),
            ));
        }

        if (!AppEngine::exists($package)) {
            throw new \RuntimeException(sprintf("Package '%s' was not found.", $package));
        }

        $candidates = $this->frontendPackageCandidates();

        if (!isset($candidates[$package])) {
            throw new \RuntimeException(sprintf("Package '%s' has no frontend theme.", $package));
        }

        return $package;
    }

    /**
     * @return list<string>
     */
    private function parsePackageList(string $raw): array
    {
        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];

        return array_values(array_filter(array_map(static fn (string $part): string => trim($part), $parts), static fn (string $part): bool => $part !== ''));
    }

    private function renderDevApplicationUrl(SymfonyStyle $io, FrontendDevSession $session, bool $withServe): void
    {
        $io->writeln('');
        $io->writeln('  <fg=gray>Starting Vite… the app URL appears below when ready.</>');
        $io->writeln('  <fg=gray>Open the PHP app URL in your browser — not the Vite port.</>');
        $io->writeln('');
    }

    /**
     * @return array{host: string, quiet: bool}
     */
    private function resolveViteDevOptions(InputInterface $input, array $config): array
    {
        $host = FrontendConfig::devHost($config);
        $quiet = FrontendConfig::devQuiet($config);

        if ($this->isNetworkMode($input) || (bool) $input->getOption('vite-network')) {
            $host = '0.0.0.0';
        }

        $viteHostOption = $input->getOption('vite-host');

        if (is_string($viteHostOption) && trim($viteHostOption) !== '') {
            $host = trim($viteHostOption);
        }

        if ((bool) $input->getOption('verbose-vite')) {
            $quiet = false;
        }

        return [
            'host' => $host,
            'quiet' => $quiet,
        ];
    }

    private function isNetworkMode(InputInterface $input): bool
    {
        return (bool) $input->getOption('network');
    }

    private function resolveServeHost(InputInterface $input): ?string
    {
        $explicit = $input->getOption('serve-host');

        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        if ($this->isNetworkMode($input)) {
            return '0.0.0.0';
        }

        return null;
    }

    private function renderNetworkDevNote(SymfonyStyle $io, FrontendDevSession $session): void
    {
        $lan = FrontendDevSession::detectLanIp();
        $io->note([
            'Network mode: PHP + Vite listen on 0.0.0.0.',
            'Local: ' . $session->localPhpAppUrl(),
            $lan !== null
                ? 'LAN: ' . $session->phpAppUrl . ' (IP ' . $lan . ')'
                : 'LAN: open ' . $session->phpAppUrl . ' using this PC\'s network IP.',
            'Allow ports ' . $session->servePort . ' and Vite in Windows Firewall if needed.',
        ]);
    }

    /**
     * @param array<string, mixed> $sync
     */
    private function renderDevDiagnostics(SymfonyStyle $io, ThemeFrontend $frontend, array $sync): void
    {
        $hasError = false;

        foreach ($frontend->devDiagnostics() as $item) {
            $level = $item['level'] ?? 'info';
            $message = (string) ($item['message'] ?? '');

            if ($message === '') {
                continue;
            }

            match ($level) {
                'error' => (function () use ($io, $message, &$hasError): void {
                    $io->error($message);
                    $hasError = true;
                })(),
                'warning' => $io->warning($message),
                'comment' => $io->comment($message),
                default => null,
            };
        }

        if (!empty($sync['vite_wired'])) {
            $io->writeln('<info>Vite config:</info> pinooxHot + pinooxServer wired');
        } elseif (!empty($sync['vite_inspection']['patched'])) {
            $io->writeln('<info>Vite config:</info> auto-wired with --fix-vite');
        }

        if (!empty($sync['env_autodev'])) {
            $io->writeln('<info>Theme env:</info> ' . ($sync['env_file'] ?? '.env') . ' autogenerated block updated');
        }

        if ($hasError) {
            throw new \RuntimeException('Fix the errors above before starting dev mode.');
        }
    }

    private function startServeProcess(
        string $package,
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        bool $platformServe = false,
        ?string $serveApp = null,
    ): Process {
        $basePath = ProjectCli::root();
        $serveApp = trim((string) ($serveApp ?? $input->getOption('serve-app') ?: $package));

        $command = ProjectCli::processCommand([
            'serve',
            '--no-reload',
        ], $basePath);

        if (!$platformServe) {
            $command[] = '--app=' . $serveApp;
        }

        $serveHost = $this->resolveServeHost($input);
        if (is_string($serveHost) && trim($serveHost) !== '') {
            $command[] = '--host=' . trim($serveHost);
        }

        $servePort = $input->getOption('serve-port');
        if ($servePort !== null && $servePort !== '') {
            $command[] = '--port=' . (int) $servePort;
        }

        $process = new Process($command, $basePath, null, null, null);
        $process->setTimeout(null);

        $serveLabel = $platformServe
            ? 'serve (platform)'
            : 'serve --app=' . $serveApp;
        $io->writeln('<info>Starting Pinoox server</info> <fg=gray>(' . ProjectCli::platformFormat($serveLabel, $basePath) . ')</>');

        $process->start(function (string $type, string $buffer) use ($output): void {
            foreach (preg_split("/\r\n|\n|\r/", $buffer) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $output->writeln('  <fg=cyan>[serve]</> ' . $line);
            }
        });

        usleep(750_000);

        if (!$process->isRunning()) {
            throw new \RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Unknown error');
        }

        return $process;
    }

    private function stopServeProcess(?Process $process, SymfonyStyle $io): void
    {
        if ($process === null || !$process->isRunning()) {
            return;
        }

        $io->writeln('');
        $io->writeln('<comment>Stopping Pinoox server…</comment>');
        $process->stop(5, defined('SIGINT') ? SIGINT : null);
    }

    private function runScript(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        ThemeFrontend $frontend,
        string $installMode,
    ): int {
        $script = trim((string) $input->getOption('script'));
        $scripts = $frontend->npmScripts();

        if ($script === '') {
            if ($scripts === []) {
                $io->error('No npm scripts were found in package.json.');

                return Command::FAILURE;
            }

            if (count($scripts) === 1) {
                $script = array_key_first($scripts);
                $io->note('Using the only npm script: ' . $script);
            } elseif ($input->isInteractive()) {
                $io->section('npm scripts');
                $rows = [];
                foreach ($scripts as $name => $command) {
                    $rows[] = [count($rows), $name, $command];
                }
                $io->table(['#', 'Script', 'Command'], $rows);

                $question = new Question('Select script: ');
                $question->setAutocompleterValues(array_keys($scripts));
                $question->setValidator(function ($answer) use ($scripts) {
                    $answer = trim((string) $answer);
                    if (isset($scripts[$answer])) {
                        return $answer;
                    }
                    if (ctype_digit($answer)) {
                        $keys = array_keys($scripts);

                        return $keys[(int) $answer] ?? throw new \RuntimeException("Script '$answer' was not found.");
                    }

                    throw new \RuntimeException("Script '$answer' was not found.");
                });
                $script = $this->getHelper('question')->ask($input, $output, $question);
            } else {
                $io->error('Script is required in non-interactive mode. Use --script=name.');

                return Command::FAILURE;
            }
        }

        $io->section('Running npm run ' . $script . ': ' . $frontend->themePath());
        $this->noteInstallPlan($io, $frontend, $installMode);

        $code = $frontend->runScript($script, $installMode);

        return $code === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function runScaffold(SymfonyStyle $io, ThemeFrontend $frontend, string $stack): int
    {
        $stack = strtolower(trim($stack));
        if ($stack === '') {
            $detected = FrontendConfig::detectStack($frontend->themePath());
            $stack = $detected !== FrontendConfig::STACK_TWIG
                ? $detected
                : FrontendConfig::defaultStackForNewTheme();
            $io->note('Stack auto-selected: ' . $stack);
        }

        if (!in_array($stack, ['twig', 'vite', 'vue', 'react'], true)) {
            $io->error('Unknown stack "' . $stack . '". Use twig, vite, vue, or react.');

            return Command::FAILURE;
        }

        $frontend->scaffold($stack);
        $config = FrontendConfig::forThemePath($frontend->themePath());
        $themeName = basename($frontend->themePath());
        $hints = FrontendConfig::recommendations($config, $frontend->package(), $themeName);

        $io->success('Scaffolded ' . $stack . ' frontend into ' . $frontend->themePath());
        $io->writeln('Twig: ' . $hints['twig']);

        foreach ($hints['next_steps'] as $step) {
            $io->writeln($step);
        }

        return Command::SUCCESS;
    }

    private function noteInstallPlan(SymfonyStyle $io, ThemeFrontend $frontend, string $installMode): void
    {
        if ($installMode === ThemeFrontend::INSTALL_SKIP) {
            return;
        }

        if ($installMode === ThemeFrontend::INSTALL_FORCE) {
            $io->writeln('<comment>npm install: forced (--install)</comment>');

            return;
        }

        if ($frontend->needsNpmInstall()) {
            $io->writeln('<comment>npm install: dependencies changed or missing — installing…</comment>');

            return;
        }

        $io->writeln('<info>npm install: skipped (dependencies up to date)</info>');
    }
}
