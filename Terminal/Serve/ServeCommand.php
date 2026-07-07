<?php

namespace Pinoox\Terminal\Serve;

use Pinoox\Component\Package\Routing\AppRouteMatcher;
use Pinoox\Component\Server\DevelopmentServer;
use Pinoox\Component\Server\InspectorRuntime;
use Pinoox\Component\Server\ServerPort;
use Pinoox\Component\Server\ServeAppBinding;
use Pinoox\Component\Terminal;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\App\AppRouter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'serve',
    description: 'Start the Pinoox development web server (PHP built-in)',
)]

class ServeCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->setHelp($this->cliHelp(
                "Starts a local HTTP server for development — similar to Laravel's `php artisan serve`.",
                [
                    'serve',
                    'serve --port=8080',
                    'serve --host=0.0.0.0 --port=9000',
                    'serve -N',
                    'serve --network --app=com_pinoox_manager',
                    'serve --app=/manager',
                    'serve --app=manager',
                    'serve --app=com_pinoox_manager@/manager',
                    'serve --open',
                ],
                <<<'FOOTER'
Environment (.env):
  SERVER_HOST=127.0.0.1
  SERVER_PORT=8000
  SERVER_APP=com_pinoox_manager

The server uses platform/launcher/server.php (or legacy launcher/server.php) as a router (same rules as .htaccess).
With --app, Pinoox skips app-router matching and always boots the selected app.
FOOTER
            ))
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Host address (default from SERVER_HOST or 127.0.0.1; use --network for 0.0.0.0)')
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Port number (default from SERVER_PORT or 8000; auto-picks next free port when busy)')
            ->addOption('network', 'N', InputOption::VALUE_NONE, 'Listen on 0.0.0.0 and show LAN URL for other devices on your network')
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'Lock to one app (package, route path, alias, or package@path)')
            ->addOption('tries', null, InputOption::VALUE_OPTIONAL, 'How many ports to try if the default is busy', 10)
            ->addOption('no-reload', null, InputOption::VALUE_NONE, 'Do not restart when .env changes')
            ->addOption('no-inspector', null, InputOption::VALUE_NONE, 'Disable Pinx Inspector on /~inspector')
            ->addOption('open-inspector', null, InputOption::VALUE_NONE, 'Open Pinx Inspector in the browser')
            ->addOption('open', 'o', InputOption::VALUE_NONE, 'Open the site in your default browser after start');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $host = $this->resolveServeHost($input);
        $portOption = $input->getOption('port');
        $explicitPort = ($portOption !== null && $portOption !== '')
            ? $this->normalizePort($portOption)
            : null;
        $tries = max(1, (int) $input->getOption('tries'));
        $documentRoot = rtrim(str_replace('\\', '/', (string) PINOOX_BASE_PATH), '/');
        $router = DevelopmentServer::defaultRouterScript();
        $serveApp = $this->resolveServeAppOption($input);

        if (!is_file($documentRoot . '/index.php')) {
            $io->error('index.php was not found in the project root: ' . $documentRoot);

            return Command::FAILURE;
        }

        if (!is_file($router)) {
            $io->error('Router script not found: ' . $router);

            return Command::FAILURE;
        }

        if ($serveApp !== null && $this->validateServeApp($serveApp, $io) === null) {
            return Command::FAILURE;
        }

        try {
            $resolvedPort = ServerPort::resolve($explicitPort, $host, null, $tries);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($explicitPort === null && $resolvedPort !== ServerPort::preferredServePort()) {
            $io->note(sprintf(
                'Port %d is in use — using %d.',
                ServerPort::preferredServePort(),
                $resolvedPort,
            ));
        }

        if ($this->isNetworkMode($input)) {
            $io->note('Network mode: server listens on 0.0.0.0. Allow the port in Windows Firewall if needed.');
        }

        $inspectorPackage = null;
        if (!(bool) $input->getOption('no-inspector') && InspectorRuntime::isAvailable()) {
            $inspectorPackage = InspectorRuntime::resolveDefaultPackage($serveApp);
            InspectorRuntime::applyEnvironment($documentRoot, $inspectorPackage);
            $io->note('Pinx Inspector: ' . InspectorRuntime::url($host, $resolvedPort));

            if ((bool) $input->getOption('open-inspector')) {
                InspectorRuntime::openBrowser(InspectorRuntime::url($host, $resolvedPort));
            }
        }

        $server = new DevelopmentServer(
            host: $host,
            explicitPort: $resolvedPort,
            maxTries: $tries,
            noReload: (bool) $input->getOption('no-reload'),
            documentRoot: $documentRoot,
            routerScript: $router,
            output: $output,
            serveApp: $serveApp,
        );

        if ((bool) $input->getOption('open')) {
            $this->openBrowser($server->url());
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function (): void {
                exit(0);
            });
            pcntl_signal(SIGTERM, static function (): void {
                exit(0);
            });
        }

        return $server->run();
    }

    private function resolveServeAppOption(InputInterface $input): ?string
    {
        $app = trim((string) ($input->getOption('app') ?: _env('SERVER_APP', '')));

        return $app === '' ? null : $app;
    }

    /**
     * @return array{package: string, path: string}|null
     */
    private function validateServeApp(string $binding, SymfonyStyle $io): ?array
    {
        $routes = AppRouteMatcher::normalizeRoutes(AppRouter::routes());
        $resolved = ServeAppBinding::resolveBinding($binding, $routes);

        if ($resolved === null) {
            $io->error('Could not resolve serve app binding: ' . $binding);
            $io->writeln('<comment>Try a package (com_pinoox_manager), route (/manager), alias (manager), or package@path.</comment>');

            return null;
        }

        if (!AppEngine::exists($resolved['package'])) {
            $io->error('App not found: ' . $resolved['package']);

            return null;
        }

        if (!AppRouter::stable($resolved['package'])) {
            $io->error('App is disabled: ' . $resolved['package']);

            return null;
        }

        $mount = $resolved['path'] === '/' ? '/' : $resolved['path'];
        $io->writeln('<info>Serve app:</info> ' . $resolved['package'] . ' <fg=gray>(mount ' . $mount . ', router bypassed)</>');

        return $resolved;
    }

    private function resolveServeHost(InputInterface $input): string
    {
        $hostOption = $input->getOption('host');

        if ($this->isNetworkMode($input)) {
            if (is_string($hostOption) && trim($hostOption) !== '' && trim($hostOption) !== '127.0.0.1') {
                return $this->resolveHost(trim($hostOption));
            }

            return '0.0.0.0';
        }

        if (is_string($hostOption) && trim($hostOption) !== '') {
            return $this->resolveHost(trim($hostOption));
        }

        return $this->resolveHost((string) _env('SERVER_HOST', '127.0.0.1'));
    }

    private function isNetworkMode(InputInterface $input): bool
    {
        return (bool) $input->getOption('network');
    }

    private function resolveHost(string $host): string
    {
        $host = trim($host);

        if ($host === '') {
            return '127.0.0.1';
        }

        if (preg_match('/^\[(.+)]:(\d+)$/', $host, $matches) === 1) {
            return '[' . $matches[1] . ']';
        }

        if (preg_match('/^(.+):(\d+)$/', $host, $matches) === 1 && !str_contains($host, '::')) {
            return $matches[1];
        }

        return $host;
    }

    private function normalizePort(mixed $port): ?int
    {
        if ($port === null || $port === '') {
            return null;
        }

        $port = (int) $port;

        return $port > 0 ? $port : null;
    }

    private function openBrowser(string $url): void
    {
        $os = PHP_OS_FAMILY;

        $command = match ($os) {
            'Windows' => 'start "" ' . escapeshellarg($url),
            'Darwin' => 'open ' . escapeshellarg($url),
            default => 'xdg-open ' . escapeshellarg($url),
        };

        @exec($command);
    }
}
