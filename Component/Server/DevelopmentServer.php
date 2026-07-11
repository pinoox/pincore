<?php

namespace Pinoox\Component\Server;

use Pinoox\Component\Template\Frontend\FrontendConfig;
use Pinoox\Component\Template\Frontend\FrontendDevSession;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class DevelopmentServer
{
    /** @var list<string> */

    public const PASSTHROUGH_ENV = [
        'PATH',
        'PATHEXT',
        'SYSTEMROOT',
        'PHP_IDE_CONFIG',
        'XDEBUG_CONFIG',
        'XDEBUG_MODE',
        'XDEBUG_SESSION',
        'PINOOX_CORE_PATH',
        'PINOOX_BASE_PATH',
        'PINOOX_CLI_PACKAGE',
        'PINOOX_SERVE_APP',
        'PINOOX_VITE_HMR',
        'PINX_PACKAGE',
        'PINOOX_DEV_APP',
        'SERVER_APP',
        'PINX_DEV',
        'PINX_INSPECTOR_ENABLED',
        'PINX_INSPECTOR_ROUTE',
        'PINX_INSPECTOR_ROUTER',
        'PINX_INSPECTOR_WIDGET',
        'PINX_INSPECTOR_PROJECT_ROOT',
        'PINX_INSPECTOR_DEFAULT_PACKAGE',
        'PINX_INSPECTOR_PACKAGE',
    ];

    private int $portOffset = 0;
    private bool $bannerShown = false;
    private string $outputBuffer = '';

    public function __construct(
        private readonly string $host,
        private readonly ?int $explicitPort,
        private readonly int $maxTries,
        private readonly bool $noReload,
        private readonly string $documentRoot,
        private readonly string $routerScript,
        private readonly OutputInterface $output,
        private readonly ?string $serveApp = null,
        private readonly ?string $domain = null,
    ) {
    }

    public function run(): int
    {
        $envFile = rtrim($this->documentRoot, '/\\') . DIRECTORY_SEPARATOR . '.env';
        $hasEnv = is_file($envFile);
        $envModifiedAt = $hasEnv ? (int) filemtime($envFile) : 0;

        while (true) {
            $process = $this->startProcess($envFile, $hasEnv);

            while ($process->isRunning()) {
                if (!$this->noReload && $hasEnv) {
                    clearstatcache(false, $envFile);
                    $mtime = (int) filemtime($envFile);

                    if ($mtime > $envModifiedAt) {
                        $envModifiedAt = $mtime;
                        $this->output->writeln('');
                        $this->output->writeln('<info>.env changed — restarting server…</info>');
                        $process->stop(5, defined('SIGINT') ? SIGINT : null);
                        $this->bannerShown = false;
                        $process = $this->startProcess($envFile, $hasEnv);
                    }
                }

                usleep(500_000);
            }

            $exitCode = $process->getExitCode() ?? 1;

            if ($exitCode !== 0 && $this->canTryAnotherPort()) {
                $this->portOffset++;
                $this->output->writeln('<comment>Port in use, trying ' . $this->port() . '…</comment>');

                continue;
            }

            return $exitCode;
        }
    }

    public function url(): string
    {
        return ServeLocalDomain::browserHttpUrl($this->domain, $this->displayHost(), $this->port());
    }

    public function inspectorUrl(): string
    {
        return rtrim($this->url(), '/') . InspectorRuntime::ROUTE;
    }

    public function port(): int
    {
        $port = $this->explicitPort ?? (int) (_env('SERVER_PORT', 8000));

        return $port + $this->portOffset;
    }

    /**
     * @return list<string>
     */
    public function serverCommand(): array
    {
        return [
            self::phpBinary(),
            '-S',
            $this->host . ':' . $this->port(),
            '-t',
            $this->documentRoot,
            $this->routerScript,
        ];
    }

    public static function defaultRouterScript(): string
    {
        $root = rtrim(str_replace('\\', '/', (string) PINOOX_BASE_PATH), '/');

        foreach ([
            $root . '/platform/launcher/server.php',
            $root . '/launcher/server.php',
            $root . '/server.php',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $root . '/platform/launcher/server.php';
    }

    public static function defaultCliScript(): string
    {
        return \Pinoox\Support\ProjectCli::script();
    }

    public static function phpBinary(): string
    {
        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            return PHP_BINARY;
        }

        return 'php';
    }

    private function startProcess(string $envFile, bool $hasEnv): Process
    {
        $process = new Process(
            $this->serverCommand(),
            $this->documentRoot,
            $this->serverEnvironment($hasEnv),
        );

        $process->setTimeout(null);
        $process->start(function (string $type, string $buffer): void {
            $this->handleProcessOutput($buffer);
        });

        return $process;
    }

    /**
     * @return array<string, string|false>
     */
    private function serverEnvironment(bool $hasEnv): array
    {
        $env = [];

        foreach ($_ENV as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if ($this->shouldPassEnv($key, $hasEnv)) {
                $env[$key] = is_scalar($value) ? (string) $value : false;
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || array_key_exists($key, $env)) {
                continue;
            }

            if ($this->shouldPassEnv($key, $hasEnv) && is_scalar($value)) {
                $env[$key] = (string) $value;
            }
        }

        foreach (self::PASSTHROUGH_ENV as $key) {
            if (array_key_exists($key, $env)) {
                continue;
            }

            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }

        $env['PINOOX_SERVER_LOG'] = '1';

        if (!$this->hasExplicitViteHmrEnv($env)) {
            $env['PINOOX_VITE_HMR'] = '0';
        }

        if ($this->serveApp !== null && $this->serveApp !== '') {
            $env[ServeAppBinding::ENV] = $this->serveApp;
        }

        return $env;
    }

    private function shouldPassEnv(string $key, bool $hasEnv): bool
    {
        if ($this->noReload || !$hasEnv) {
            return true;
        }

        return in_array($key, self::PASSTHROUGH_ENV, true);
    }

    /**
     * @param array<string, string|false> $env
     */
    private function hasExplicitViteHmrEnv(array $env): bool
    {
        if (array_key_exists('PINOOX_VITE_HMR', $env)) {
            return true;
        }

        $fromGetenv = getenv('PINOOX_VITE_HMR');

        return is_string($fromGetenv) && $fromGetenv !== '';
    }

    private function handleProcessOutput(string $buffer): void
    {
        $this->outputBuffer .= $buffer;

        while (($pos = strpos($this->outputBuffer, "\n")) !== false) {
            $line = trim(substr($this->outputBuffer, 0, $pos));
            $this->outputBuffer = substr($this->outputBuffer, $pos + 1);

            if ($line === '') {
                continue;
            }

            if (str_contains($line, 'Development Server (http')) {
                if (!$this->bannerShown) {
                    $this->bannerShown = true;
                    $this->output->writeln('');
                    $this->renderStartupBanner();

                    $this->output->writeln('<comment>Press Ctrl+C to stop</comment>');
                    $this->output->writeln('');
                }

                continue;
            }

            if (str_contains($line, 'URI:')) {
                $this->output->writeln('  <fg=gray>' . $line . '</>');

                continue;
            }

            if (str_contains($line, 'Failed to listen') || str_contains($line, 'Address already in use')) {
                $this->output->writeln('<error>' . $line . '</error>');
            }
        }
    }

    private function renderStartupBanner(): void
    {
        $port = $this->port();
        $localUrl = $this->url();
        $bindUrl = ServeLocalDomain::httpUrl('127.0.0.1', $port);

        if ($this->isNetworkBound()) {
            $this->output->writeln('<info>Pinoox development server running</info>');
            $this->output->writeln('<info>Local:</info> <comment>' . $localUrl . '</comment>');

            if ($this->domain !== null && $localUrl !== $bindUrl) {
                $this->output->writeln('<fg=gray>  bind ' . $bindUrl . '</>');
            }

            $lan = FrontendDevSession::detectLanIp();

            if ($lan !== null) {
                $this->output->writeln('<info>LAN:</info> <comment>' . ServeLocalDomain::httpUrl($lan, $port) . '</comment>');
            } else {
                $this->output->writeln('<info>Network:</info> <comment>0.0.0.0:' . $port . '</comment>');
            }
        } else {
            $this->output->writeln('<info>Pinoox development server running:</info> <comment>' . $localUrl . '</comment>');

            if ($this->domain !== null && $localUrl !== $bindUrl) {
                $this->output->writeln('<fg=gray>  bind ' . $bindUrl . '</>');
            }
        }

        if ($this->serveApp !== null && $this->serveApp !== '') {
            $this->output->writeln('<info>Locked app:</info> <comment>' . $this->serveApp . '</comment> <fg=gray>(app router bypassed)</>');
        }
    }

    private function isNetworkBound(): bool
    {
        return $this->host === '0.0.0.0' || $this->host === '[::]';
    }

    private function canTryAnotherPort(): bool
    {
        return $this->explicitPort === null && $this->portOffset + 1 < $this->maxTries;
    }

    private function displayHost(): string
    {
        if ($this->domain !== null && $this->domain !== '') {
            return $this->domain;
        }

        if ($this->host === '0.0.0.0' || $this->host === '[::]') {
            return '127.0.0.1';
        }

        if (str_starts_with($this->host, '[') && str_ends_with($this->host, ']')) {
            return $this->host;
        }

        return $this->host;
    }

    /**
     * Environment for nested `pinoox serve` started by fe dev (PHP workers must keep HMR).
     *
     * @return array<string, string>
     */
    public static function feDevServeSubprocessEnv(): array
    {
        $env = [];

        foreach ($_ENV as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $env[$key] = (string) $value;
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || array_key_exists($key, $env) || !is_scalar($value)) {
                continue;
            }

            $env[$key] = (string) $value;
        }

        foreach (self::PASSTHROUGH_ENV as $key) {
            if (array_key_exists($key, $env)) {
                continue;
            }

            $value = getenv($key);

            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }

        $env[FrontendConfig::VITE_HMR_ENV] = '1';

        return $env;
    }
}

