<?php



namespace Pinoox\Component\Template\Frontend;



use Pinoox\Component\Package\PackageName;

use Pinoox\Component\Server\DevelopmentServer;
use Pinoox\Component\Server\ServerPort;

use Pinoox\Component\Template\Frontend\ThemeFrontendDevTarget;
use Pinoox\Support\ProjectCli;

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
            $preferred = self::preferredPort($target);
            $explicit = self::hasExplicitVitePort($target) ? $preferred : null;
            $port = ServerPort::resolve($explicit, '127.0.0.1', $preferred);

            while (in_array($port, $used, true) || !ServerPort::isAvailable('127.0.0.1', $port)) {
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
     * @param array{package?: string, theme?: string, config?: array<string, mixed>, themePath?: string} $target
     */
    private static function hasExplicitVitePort(array $target): bool
    {
        $config = is_array($target['config'] ?? null) ? $target['config'] : [];
        $dev = is_array($config['dev'] ?? null) ? $config['dev'] : [];

        if (isset($dev['port']) && is_numeric($dev['port']) && (int) $dev['port'] > 0) {
            return true;
        }

        $themePath = trim((string) ($target['themePath'] ?? ''));

        if ($themePath === '') {
            return false;
        }

        $configFile = rtrim(str_replace('\\', '/', $themePath), '/') . '/frontend.config.php';

        if (!is_file($configFile)) {
            return false;
        }

        $raw = include $configFile;

        return is_array($raw)
            && isset($raw['dev']['port'])
            && is_numeric($raw['dev']['port'])
            && (int) $raw['dev']['port'] > 0;
    }



    /**

     * @param list<ThemeFrontend> $frontends

     * @param list<FrontendDevSession> $sessions

     * @param list<array{package?: string, theme?: string, context?: ?string}> $stackTargets

     */

    public function run(

        SymfonyStyle $io,

        OutputInterface $output,

        array $frontends,

        array $sessions,

        ?string $serveHost,

        ?int $servePort,

        array $stackTargets = [],

    ): int {

        if ($frontends === [] || $sessions === [] || count($frontends) !== count($sessions)) {

            throw new \InvalidArgumentException('Frontend dev stack requires at least one app.');

        }



        $this->renderBanner($io, $frontends, $sessions, $stackTargets);



        $serveProcess = $this->startServeProcess($output, $io, $serveHost, $servePort);

        $viteProcesses = [];



        try {

            foreach ($frontends as $index => $frontend) {

                $label = self::stackLabel($frontend->package(), $stackTargets[$index]['context'] ?? null);

                $process = $this->startViteProcess($frontend, $label, $output);

                $viteProcesses[] = ['label' => $label, 'process' => $process];

                usleep(350_000);

            }



            $io->writeln('  <fg=gray>Ready — open the URLs above. Vite HMR runs in the background.</>');

            $io->writeln('');



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

    private function renderBanner(SymfonyStyle $io, array $frontends, array $sessions, array $stackTargets = []): void

    {

        $primary = $sessions[0];

        $origin = $primary->phpOrigin();

        $network = $primary->serveHost === '0.0.0.0' || $primary->serveHost === '[::]';



        $io->writeln('');

        $io->writeln(sprintf(

            '  <fg=green;options=bold>➜</>  <fg=cyan;options=bold>Platform dev</>  <fg=gray>(%d apps — 1 PHP + %d Vite)</>',

            count($frontends),

            count($frontends),

        ));

        $io->writeln('  <fg=gray>Serve App</>     platform');

        $io->writeln('  <fg=gray>PHP</>           ' . $origin);



        if ($network) {

            $lan = FrontendDevSession::detectLanIp();



            if ($lan !== null) {

                $io->writeln('  <fg=gray>LAN</>           http://' . $lan . ':' . $primary->servePort);

            }

        }



        $io->writeln('');

        $io->writeln('  <fg=gray>Open in browser</>  <fg=gray>(app-router paths)</>');



        $rows = [];



        foreach ($frontends as $index => $frontend) {

            $session = $sessions[$index];

            $label = self::stackLabel($frontend->package(), $stackTargets[$index]['context'] ?? null);



            foreach ($session->displayAppUrls() as $url) {

                $rows[] = [$label, $url];

            }

        }



        $io->table(['App', 'URL'], $rows);

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

            $command[] = '--port=' . (int) $servePort;

        }



        $process = new Process($command, $basePath, null, null, null);

        $process->setTimeout(null);



        $io->writeln('<info>Starting servers…</info> <fg=gray>(platform + ' . ProjectCli::platformFormat('serve') . ' + Vite)</>');



        $process->start(function (string $type, string $buffer) use ($output): void {

            $this->forwardServeLines($output, $buffer);

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



        $base['VITE_DEV_STACK'] = 'true';

        $base['VITE_SERVE_APP'] = FrontendDevSession::SERVE_PLATFORM;

        $base['VITE_DEV_QUIET'] = 'true';



        $process = new Process([$binary, 'run', 'dev'], $frontend->themePath(), $base, null, null);

        $process->setTimeout(null);



        $process->start(function (string $type, string $buffer) use ($output, $label): void {

            $this->forwardViteLines($output, $label, $buffer);

        });



        return $process;

    }



    /**

     * @param list<array{label: string, process: Process}> $viteProcesses

     */

    private function waitUntilStopped(array $viteProcesses, Process $serveProcess, OutputInterface $output): int

    {

        $stop = false;



        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {

            pcntl_async_signals(true);

            pcntl_signal(SIGINT, static function () use (&$stop): void {

                $stop = true;

            });

            pcntl_signal(SIGTERM, static function () use (&$stop): void {

                $stop = true;

            });

        } elseif (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_set_ctrl_handler')) {

            sapi_windows_set_ctrl_handler(static function (int $event) use (&$stop): void {

                if ($event === PHP_WINDOWS_EVENT_CTRL_C || $event === PHP_WINDOWS_EVENT_CTRL_BREAK) {

                    $stop = true;

                }

            }, true);

        }



        /** @var array<string, true> $warned */

        $warned = [];



        while (true) {

            if ($stop) {

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



    private function forwardServeLines(OutputInterface $output, string $buffer): void

    {

        foreach (preg_split("/\r\n|\n|\r/", $buffer) ?: [] as $line) {

            $line = trim($line);



            if ($line === '' || !$this->shouldForwardServeLine($line)) {

                continue;

            }



            $output->writeln('  <fg=cyan>[serve]</> ' . $line);

        }

    }



    private function forwardViteLines(OutputInterface $output, string $label, string $buffer): void

    {

        foreach (preg_split("/\r\n|\n|\r/", $buffer) ?: [] as $line) {

            $line = trim($line);



            if ($line === '' || !$this->shouldForwardViteLine($line)) {

                continue;

            }



            $output->writeln(sprintf('  <fg=red>[%s]</> %s', $label, $line));

        }

    }



    private function shouldForwardServeLine(string $line): bool

    {

        if (preg_match('/^Pinoox development server running:/i', $line)) {

            return false;

        }



        if (preg_match('/^Press Ctrl\+C to stop$/i', $line)) {

            return false;

        }



        return preg_match('/\b(error|failed|fatal|warning)\b/i', $line) === 1;

    }



    private function shouldForwardViteLine(string $line): bool

    {

        if (preg_match('/^\s*➜\s+(Open app|Local|Network|LAN)|Serve App|Vite HMR|Press Ctrl+C to stop|^Port \d+ is in use|^>\s|\[BABEL\] Note:/i', $line)) {

            return false;

        }



        return preg_match('/\b(error|failed|ERR!|EADDRINUSE|cannot find|not found)\b/i', $line) === 1;

    }



    private static function stackLabel(string $package, ?string $context = null): string

    {

        return ThemeFrontendDevTarget::stackLabel($package, $context);

    }

}


