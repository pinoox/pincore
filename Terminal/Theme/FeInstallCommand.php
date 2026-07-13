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
    name: 'fe:install',
    description: 'Install npm dependencies for an app theme (shortcut for fe install)',
)]
class FeInstallCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->setHelp($this->cliHelp(
                'Install npm dependencies for one theme, every theme context, or all themes with package.json.',
                [
                    'fe:install',
                    'fe:install com_pinoox_manager',
                    'fe:install --theme=spark',
                    'fe:install --theme=panel',
                    'fe:install --theme=all',
                    'fe:install --install',
                ],
                'Leave target and --theme empty to pick interactively.',
            ))
            ->addArgument('target', InputArgument::OPTIONAL, 'App package or theme folder (interactive when omitted)')
            ->addOption('theme', 't', InputOption::VALUE_REQUIRED, 'Theme folder, context (site, panel, …), or all')
            ->addOption('install', null, InputOption::VALUE_NONE, 'Force npm install even when up to date')
            ->addOption('no-install', null, InputOption::VALUE_NONE, 'Skip npm install');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $target = trim((string) $input->getArgument('target'));

        if ($target === '' && !$input->getOption('theme')) {
            $io->title('Frontend install');
            $io->writeln('Pick an app theme or install every theme with package.json…');
        }

        $command = $this->getApplication()?->find('theme:frontend');

        if ($command === null) {
            $io->error('theme:frontend command is not registered.');

            return Command::FAILURE;
        }

        $arguments = array_filter([
            'command' => 'theme:frontend',
            'target' => $target !== '' ? $target : null,
            'action' => 'install',
            '--theme' => $input->getOption('theme'),
            '--install' => $input->getOption('install') ? true : null,
            '--no-install' => $input->getOption('no-install') ? true : null,
        ], static fn ($value) => $value !== null && $value !== false && $value !== '');

        return $command->run(new ArrayInput($arguments), $output);
    }
}
