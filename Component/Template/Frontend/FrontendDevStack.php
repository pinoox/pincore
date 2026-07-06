<?php

namespace Pinoox\Component\Template\Frontend;

use Pinoox\Component\Package\AppManifest;
use Pinoox\Component\Server\DevelopmentServer;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Runs one shared PHP serve process plus parallel Vite dev servers for multiple app themes.
 */
final class FrontendDevStack
{
    /**
     * @param list<array{package: string, theme: string, config: array<string, mixed>, themePath?: string}> $targets
     * @return list<int>
     */
    public static function allocateVitePorts(array $targets): array
    {
        $used = [];
        $ports = [];

        foreach ($targets as $target) {
            $port = self::preferredPort($target);

            while (in_array($port, $used, true)) {
                $port++;
            }

            $used[] = $port;
            $ports[] = $port;
        }

        return $ports;
    }

    /**
     * @param array{package?: string, theme?: string, config?: array<string, mixed>, themePath?: string} $target
     */
    private static function preferredPort(array $target): int
    {
        $config = is_array($target['config'] ?? null) ? $target['config'] : [];
        $dev = is_array($config['dev'] ?? null) ? $config['dev'] : [];

        if (isset($dev['port']) && is_numeric($dev['port']) && (int) $dev['port'] > 0) {
            return (int) $dev['port'];
        }

        $themePath = trim((string) ($target['themePath'] ?? ''));

        if ($themePath !== '') {
            $configFile = rtrim(str_replace('\\', '/', $themePath), '/') . '/frontend.config.php';

            if (is_file($configFile)) {
                $raw = include $configFile;

                if (is_array($raw) && isset($raw['dev']['port']) && is_numeric($raw['dev']['port']) && (int) $raw['dev']['port'] > 0) {
                    return (int) $raw['dev']['port'];
                }
            }
        }

        return 5173;
    }

    /**
     * @param list<ThemeFrontend> $frontends
     * @param list<FrontendDevSession> $sessions
     */
    public function run(
        SymfonyStyle $io,
        OutputInterface $output,
        array $frontends,
        array $sessions,
        ?string $serveHost,
        ?int $servePort,
    ): int {
        if ($frontends === [] || $sessions === [] || count($frontends) !== count($sessions)) {
            throw new \InvalidArgumentException('Frontend dev stack requires at least one app.');
        }

        $this->renderBanner($io, $frontends, $sessions);

        $serveProcess = $this->startServeProcess($output, $io, $serveHost, $servePort);
        $viteProcesses = [];

        try {
            foreach ($frontends as $index => $frontend) {
                $label = self::stackLabel($frontend->package());
                $process = $this->startViteProcess($frontend, $label, $output);
                $viteProcesses[] = ['label' => $label, 'process' => $process];
                usleep(350_000);
            }

            $exitCode = $this->waitUntilStopped($viteProcesses, $serveProcess, $output);

            return $exitCode;
        } finally {
            $this->stopProcesses($viteProcesses, $serveProcess, $io);
        }
    }

    /**
     * @param list<ThemeFrontend> $frontends
     * @param list<FrontendDevSession> $sessions
     */
    private function renderBanner(SymfonyStyle $io, array $frontends, array $sessions): void
    {
        $io->writeln('');
        $io->writeln('<info>Development apps</info> <fg=gray>(one PHP server + ' . count($frontends) . ' Vite)</>');
        $io->writeln('');

        foreach ($frontends as $index => $frontend) {
            $session = $sessions[$index];
            $name = AppManifest::displayName($frontend->package());
            $io->writeln(sprintf(
                '  <fg=green;options=bold>➜</>  <fg=cyan;options=bold>%s</>  %s  <fg=gray>(Vite :%d)</>',
                $name,
                $session->phpAppUrl,
                $session->vitePort,
            ));
        }

        $origin = $sessions[0]->phpOrigin();
        $io->writeln('');
        $io->writeln('  <fg=gray>PHP</>  ' . $origin);
        $io->writeln('  <fg=gray>Press Ctrl+C to stop all servers</>');
        $io->writeln('');
    }

    private function startServeProcess(
        OutputInterface $output,
        SymfonyStyle $io,
        ?string $serveHost,
        ?int $servePort,
    ): Process {
        $basePath = rtrim(str_replace('\\', '/', (string) PINOOX_BASE_PATH), '/');
        $command = [
            DevelopmentServer::phpBinary(),
            $basePath . '/pinoox',
            'serve',
            '--no-reload',
        ];

        if (is_string($serveHost) && trim($serveHost) !== '') {
            $command[] = '--host=' . trim($serveHost);
        }

        if ($servePort !== null && $servePort > 0) {
            $command[] = '--port=' . $servePort;
        }

        $process = new Process($command, $basePath, null, null, null);
        $process->setTimeout(null);

        $io->writeln('<info>Starting Pinoox server</info> <fg=gray>(php pinoox serve)</>');

        $process->start(function (string $type, string $buffer) use ($output): void {
            $this->forwardLines($output, '[serve]', $buffer, 'cyan');
        });

        usleep(750_000);

        if (!$process->isRunning()) {
            throw new \RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Could not start Pinoox server.');
        }

        return $process;
    }

    private function startViteProcess(ThemeFrontend $frontend, string $label, OutputInterface $output): Process
    {
        $frontend->prepareDev();
        $binary = PHP_OS_FAMILY === 'Windows' ? 'npm.cmd' : 'npm';
        $env = $frontend->devNpmEnvironment();
        $base = getenv();

        if (!is_array($base)) {
            $base = [];
        }

        foreach ($env as $key => $value) {
            $base[$key] = (string) $value;
        }

        $process = new Process([$binary, 'run', 'dev'], $frontend->themePath(), $base, null, null);
        $process->setTimeout(null);

        $output->writeln('<info>Starting Vite</info> <fg=gray>(' . $label . ')</>');

        $process->start(function (string $type, string $buffer) use ($output, $label): void {
            $this->forwardLines($output, '[' . $label . ']', $buffer, 'magenta');
        });

        return $process;
    }

    /**
     * @param list<array{label: string, process: Process}> $viteProcesses
     */
    private function waitUntilStopped(array $viteProcesses, Process $serveProcess, OutputInterface $output): int
    {
        if (function_exists('pcntl_signal')) {
            $stop = false;
            pcntl_signal(SIGINT, static function () use (&$stop): void {
                $stop = true;
            });
            pcntl_signal(SIGTERM, static function () use (&$stop): void {
                $stop = true;
            });
        }

        /** @var array<string, true> $warned */
        $warned = [];

        while (true) {
            if (isset($stop) && $stop) {
                break;
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            if (!$serveProcess->isRunning()) {
                break;
            }

            $running = 0;

            foreach ($viteProcesses as $item) {
                if ($item['process']->isRunning()) {
                    $running++;

                    continue;
                }

                if (isset($warned[$item['label']])) {
                    continue;
                }

                $warned[$item['label']] = true;
                $exitCode = $item['process']->getExitCode();
                $output->writeln(sprintf(
                    '  <fg=red>[%s]</> Vite stopped (exit %s). Other apps keep running — fix ports or run <info>fe info %s</info>.',
                    $item['label'],
                    $exitCode === null ? '?' : (string) $exitCode,
                    $item['label'],
                ));
            }

            if ($running === 0) {
                break;
            }

            usleep(200_000);
        }

        return $warned === [] || $this->hasRunningVite($viteProcesses) ? 0 : 1;
    }

    /**
     * @param list<array{label: string, process: Process}> $viteProcesses
     */
    private function hasRunningVite(array $viteProcesses): bool
    {
        foreach ($viteProcesses as $item) {
            if ($item['process']->isRunning()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{label: string, process: Process}> $viteProcesses
     */
    private function stopProcesses(array $viteProcesses, Process $serveProcess, SymfonyStyle $io): void
    {
        $io->writeln('');
        $io->writeln('<comment>Stopping development apps…</comment>');

        foreach ($viteProcesses as $item) {
            if ($item['process']->isRunning()) {
                $item['process']->stop(5, defined('SIGINT') ? SIGINT : null);
            }
        }

        if ($serveProcess->isRunning()) {
            $serveProcess->stop(5, defined('SIGINT') ? SIGINT : null);
        }
    }

    private function forwardLines(OutputInterface $output, string $prefix, string $buffer, string $color): void
    {
        foreach (preg_split("/\r\n|\n|\r/", $buffer) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $output->writeln(sprintf('  <fg=%s>%s</> %s', $color, $prefix, $line));
        }
    }

    private static function stackLabel(string $package): string
    {
        if (str_starts_with($package, 'com_pinoox_')) {
            return substr($package, 11);
        }

        return $package;
    }
}
