<?php

namespace Pinoox\Terminal\Theme;

use Pinoox\Component\Terminal;
use Pinoox\Support\ProjectCli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'dev',
    description: 'Start PHP + Vite dev for an app theme (shortcut for fe dev)',
)]
class DevCommand extends Terminal
{
    protected function configure(): void
    {
        $serve = ProjectCli::platformFormat('serve');
        $this
            ->setHelp($this->cliHelp(
                "One-command local development: starts {$serve} + npm run dev for a theme.\n\nSame as: "
                . $this->cliFormat('fe {target} dev'),
                [
                    'dev',
                    'dev spark',
                    'dev com_pinoox_welcome',
                    'dev spark --no-serve',
                    'dev com_pinoox_manager --network',
                ],
                "Use MAMP or another PHP server:\n  " . $this->cliFormat('dev spark --no-serve')
                . "\n\nLAN (phone/tablet on same Wi‑Fi):\n  " . $this->cliFormat('dev spark --network')
                . "\n\nOpen the PHP app URL in your browser (not :5173). Vite HMR is injected via vite_tags().",
            ))
            ->addArgument('target', InputArgument::OPTIONAL, 'App package or theme folder (interactive when omitted)')
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, 'Theme folder when target is a package')
            ->addOption('install', null, InputOption::VALUE_NONE, 'Run npm install when needed')
            ->addOption('no-install', null, InputOption::VALUE_NONE, 'Skip npm install')
            ->addOption('no-serve', null, InputOption::VALUE_NONE, 'Do not start ' . ProjectCli::platformFormat('serve') . ' (MAMP, Docker, etc.)')
            ->addOption('serve-app', null, InputOption::VALUE_REQUIRED, 'App binding for ' . ProjectCli::platformFormat('serve'))
            ->addOption('serve-host', null, InputOption::VALUE_REQUIRED, 'Host for ' . ProjectCli::platformFormat('serve'))
            ->addOption('serve-port', null, InputOption::VALUE_REQUIRED, 'Port for ' . ProjectCli::platformFormat('serve'))
            ->addOption('network', 'N', InputOption::VALUE_NONE, 'Serve PHP app + Vite on LAN (same Wi‑Fi)')
            ->addOption('vite-host', null, InputOption::VALUE_REQUIRED, 'Vite bind host (default 127.0.0.1)')
            ->addOption('vite-network', null, InputOption::VALUE_NONE, 'Bind Vite to 0.0.0.0 for LAN access')
            ->addOption('verbose-vite', null, InputOption::VALUE_NONE, 'Show full Vite startup URLs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $target = trim((string) $input->getArgument('target'));

        if ($target === '') {
            $io->title('Pinoox dev');
            $io->writeln('Starting interactive theme frontend dev (fe dev)…');
        }

        $command = $this->getApplication()?->find('theme:frontend');

        if ($command === null) {
            $io->error('theme:frontend command is not registered.');

            return Command::FAILURE;
        }

        $arguments = array_filter([
            'command' => 'theme:frontend',
            'target' => $target !== '' ? $target : null,
            'action' => 'dev',
            '--theme' => $input->getOption('theme'),
            '--no-serve' => $input->getOption('no-serve') ? true : null,
            '--install' => $input->getOption('install') ? true : null,
            '--no-install' => $input->getOption('no-install') ? true : null,
            '--serve-app' => $input->getOption('serve-app'),
            '--serve-host' => $input->getOption('serve-host'),
            '--serve-port' => $input->getOption('serve-port'),
            '--network' => $input->getOption('network') ? true : null,
            '--vite-host' => $input->getOption('vite-host'),
            '--vite-network' => $input->getOption('vite-network') ? true : null,
            '--verbose-vite' => $input->getOption('verbose-vite') ? true : null,
        ], static fn ($value) => $value !== null && $value !== false && $value !== '');

        return $command->run(new ArrayInput($arguments), $output);
    }
}
