<?php

namespace Pinoox\Terminal\App;

use Pinoox\Component\Terminal;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\StubGenerator;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'lifecycle:create',
    description: 'Create an optional lifecycle.php for install/update/uninstall/reset hooks',
    aliases: ['make:lifecycle'],
)]
class LifecycleCreateCommand extends Terminal
{
    use SelectsPackage;

    protected function configure(): void
    {
        $this
            ->setHelp($this->cliHelp(
                'Writes apps/{package}/lifecycle.php if it does not already exist. Same drop-in style as boot.php.',
                [
                    'lifecycle:create com_my_shop',
                    'make:lifecycle com_my_shop',
                ],
            ))
            ->addArgument('package', InputArgument::OPTIONAL, 'App package name (e.g. com_my_shop)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolvePackageRequired($input, $output, $io, [
            'appsOnly' => true,
            'sectionTitle' => 'Lifecycle',
        ]);

        if (!AppEngine::exists($package)) {
            $io->error('App not found: ' . $package);

            return Command::FAILURE;
        }

        $path = AppEngine::path($package, 'lifecycle.php');
        if (is_file($path)) {
            $io->warning('lifecycle.php already exists: ' . $path);

            return Command::SUCCESS;
        }

        try {
            StubGenerator::generate('lifecycle.stub', $path);
        } catch (\Throwable $e) {
            $io->error('Failed to generate lifecycle.php: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if (!is_file($path)) {
            $io->error('Failed to generate lifecycle.php.');

            return Command::FAILURE;
        }

        $io->success('Created lifecycle.php for ' . $package);
        $io->writeln('Location: ' . $path);

        return Command::SUCCESS;
    }
}
