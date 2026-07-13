<?php

namespace Pinoox\Terminal\Theme;

use Pinoox\Component\Terminal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'fe:build',
    description: 'Build production frontend assets (shortcut for fe build)',
)]
class FeBuildCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->setHelp($this->cliHelp(
                'Build production assets for one theme, every theme context, or all themes with package.json.',
                [
                    'fe:build',
                    'fe:build com_pinoox_manager',
                    'fe:build --theme=spark',
                    'fe:build --theme=panel',
                    'fe:build --theme=all',
                    'fe:build --install',
                ],
                'Leave target and --theme empty to pick interactively.',
            ))
            ->addArgument('target', InputArgument::OPTIONAL, 'App package or theme folder (interactive when omitted)')
            ->addOption('theme', 't', InputOption::VALUE_REQUIRED, 'Theme folder, context (site, panel, …), or all')
            ->addOption('install', null, InputOption::VALUE_NONE, 'Run npm install before build when needed')
            ->addOption('no-install', null, InputOption::VALUE_NONE, 'Skip npm install');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $target = trim((string) $input->getArgument('target'));

        if ($target === '' && !$input->getOption('theme')) {
            $io->title('Frontend build');
            $io->writeln('Pick an app theme or build every theme with package.json…');
        }

        $command = $this->getApplication()?->find('theme:frontend');

        if ($command === null) {
            $io->error('theme:frontend command is not registered.');

            return Command::FAILURE;
        }

        $arguments = array_filter([
            'command' => 'theme:frontend',
            'target' => $target !== '' ? $target : null,
            'action' => 'build',
            '--theme' => $input->getOption('theme'),
            '--install' => $input->getOption('install') ? true : null,
            '--no-install' => $input->getOption('no-install') ? true : null,
        ], static fn ($value) => $value !== null && $value !== false && $value !== '');

        return $command->run(new ArrayInput($arguments), $output);
    }
}
