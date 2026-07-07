<?php

namespace Pinoox\Terminal\Inspector;

use Pinoox\Component\Server\DevelopmentServer;
use Pinoox\Component\Server\InspectorRuntime;
use Pinoox\Component\Server\ServerPort;
use Pinoox\Component\Terminal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'inspector',
    description: 'Start the local-only Pinx Inspector dashboard',
)]
class InspectorCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->setHelp($this->cliHelp(
                'Starts Pinx Inspector on a dedicated local PHP built-in server.',
                [
                    'inspector',
                    'inspector --port=8010',
                    'inspector --open',
                    'inspector --app=com_pinoox_manager',
                ],
                'Requires pinoox/pinx-inspector in require-dev. For PHP + app pages together, use `php pinoox serve` (Inspector mounts at /~inspector when available).',
            ))
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Inspector host', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Inspector port', '8010')
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'Default app package for multi-app platform installs')
            ->addOption('open', 'o', InputOption::VALUE_NONE, 'Open Inspector in the browser');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $projectRoot = rtrim(str_replace('\\', '/', (string) PINOOX_BASE_PATH), '/');

        if (!InspectorRuntime::isAvailable()) {
            $io->error('Pinx Inspector is not installed. Run: composer require --dev pinoox/pinx-inspector');

            return Command::FAILURE;
        }

        $host = trim((string) $input->getOption('host'));
        $port = max(1, (int) $input->getOption('port'));
        $defaultPackage = trim((string) ($input->getOption('app') ?: _env('SERVER_APP', '')));
        $router = InspectorRuntime::routerPath();

        if (!is_file($router)) {
            $io->error('Inspector router was not found: ' . $router);

            return Command::FAILURE;
        }

        try {
            $port = ServerPort::resolve($port, $host, null, 20);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $env = InspectorRuntime::environment($projectRoot, $defaultPackage !== '' ? $defaultPackage : null, false);
        $env['PINX_INSPECTOR_BASE_PATH'] = '';
        $env['PINX_INSPECTOR_WIDGET'] = '0';

        $process = new Process(
            [DevelopmentServer::phpBinary(), '-S', $host . ':' . $port, $router],
            $projectRoot,
            $env,
        );
        $process->setTimeout(null);

        $url = 'http://' . ($host === '0.0.0.0' ? '127.0.0.1' : $host) . ':' . $port . '/';

        $io->success('Pinx Inspector started.');
        $io->writeln('<comment>' . $url . '</comment>');

        if ((bool) $input->getOption('open')) {
            InspectorRuntime::openBrowser($url);
        }

        $process->start(function (string $type, string $buffer) use ($output): void {
            if ($output->isVerbose()) {
                $output->write($buffer);
            }
        });

        return $process->wait();
    }
}
