<?php

namespace Pinoox\Terminal\App;

use Pinoox\Component\Terminal;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\Pinx;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reset',
    description: 'Reset app data (keep files), then re-run migrate, patch, and install lifecycle',
)]
class AppResetCommand extends Terminal
{
    use SelectsPackage;

    protected function configure(): void
    {
        $this
            ->setHelp($this->cliHelp(
                'Wipes app data without deleting the app folder: reset.php / onReset, rollback patches + migrations, then migrate + patch + onInstall again.',
                [
                    'app:reset com_my_shop',
                    'app:reset com_my_shop --force -y',
                    'app:reset com_my_shop --skip-lifecycle',
                ],
            ))
            ->addArgument('package', InputArgument::OPTIONAL, 'App package name (e.g. com_my_shop)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Allow resetting a system app (sys-app)')
            ->addOption('skip-lifecycle', null, InputOption::VALUE_NONE, 'Skip lifecycle.php reset/install hooks')
            ->addOption('skip-migrate', null, InputOption::VALUE_NONE, 'Skip migration reset and re-run')
            ->addOption('skip-patch', null, InputOption::VALUE_NONE, 'Skip patch rollback/re-run')
            ->addOption('skip-cache', null, InputOption::VALUE_NONE, 'Skip cache rebuild')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolvePackageRequired($input, $output, $io, [
            'appsOnly' => true,
            'sectionTitle' => 'Reset',
        ]);

        if (!AppEngine::exists($package)) {
            $io->error('App not found: ' . $package);

            return Command::FAILURE;
        }

        $appFile = AppEngine::path($package, 'app.php');
        $config = is_file($appFile) ? include $appFile : [];

        $io->section('App reset');
        $io->definitionList(
            ['Package' => $package],
            ['Keeps' => 'App folder on disk'],
            ['Wipes' => 'DB schema/data via migrate reset + patches, then re-installs'],
        );

        if (is_array($config) && !empty($config['sys-app'])) {
            $io->caution('This is a system app (sys-app).');
        }

        if (!$input->getOption('yes') && !$io->confirm('Reset all data for "' . $package . '"?', false)) {
            $io->warning('Reset canceled.');

            return Command::SUCCESS;
        }

        $resetter = Pinx::resetter();
        $resetter->onStep(static function (string $step, string $status, string $message) use ($io): void {
            $io->writeln(sprintf('  <comment>[%s]</comment> %s: %s', strtoupper($status), $step, $message));
        });

        $result = $resetter->reset($package, [
            'force' => (bool) $input->getOption('force'),
            'skip_lifecycle' => (bool) $input->getOption('skip-lifecycle'),
            'skip_migrate' => (bool) $input->getOption('skip-migrate'),
            'skip_patch' => (bool) $input->getOption('skip-patch'),
            'skip_cache' => (bool) $input->getOption('skip-cache'),
        ]);

        if (!$result->success) {
            $io->error($result->message);

            return Command::FAILURE;
        }

        $io->success($result->message);

        return Command::SUCCESS;
    }
}
