<?php

namespace Pinoox\Terminal\Database;

use Pinoox\Component\Database\DatabaseConnectionToolkit;
use Pinoox\Component\Terminal;
use Pinoox\Portal\Database\DB;
use Pinoox\Terminal\Database\Concerns\ManagesCliDatabase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'db:prefix',
    description: 'Change table prefix for an app while keeping the same database connection',
    aliases: ['database:prefix'],
)]
class DbPrefixCommand extends Terminal
{
    use ManagesCliDatabase;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Change an app's table prefix without changing the underlying database connection.

Examples:
  php pinoox db:prefix com_my_shop shop_
  php pinoox db:prefix com_my_shop shop_ --use=mysql
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, 'App package name')
            ->addArgument('prefix', InputArgument::OPTIONAL, 'New table prefix (e.g. shop_)')
            ->addOption('use', 'u', InputOption::VALUE_REQUIRED, 'Platform connection to reuse (default: keep current or platform)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $this->prepareDatabaseCli();

        $package = trim((string) ($input->getArgument('package') ?: ''));

        if ($package === '') {
            $package = $this->resolvePackageRequired($input, $output, $io, [
                'sectionTitle' => 'Change prefix for',
                'appsOnly' => true,
            ]);
        }

        if (!$this->isAppTarget($package)) {
            $io->error('Package not found: ' . $package);

            return Command::FAILURE;
        }

        $prefix = trim((string) ($input->getArgument('prefix') ?: ''));

        if ($prefix === '' && $input->isInteractive()) {
            $current = DatabaseConnectionToolkit::describeApp($package, test: false);
            $defaultPrefix = ($current['logical_prefix'] ?? '—') !== '—'
                ? (string) $current['logical_prefix']
                : DB::tablePrefixForPackage($package);
            $prefix = trim((string) $io->ask('New table prefix', $defaultPrefix));
        }

        if ($prefix === '') {
            $io->error('Prefix is required.');

            return Command::FAILURE;
        }

        $use = $input->getOption('use');
        $use = is_string($use) && $use !== '' ? $use : null;

        try {
            DatabaseConnectionToolkit::setAppPrefix($package, $prefix, $use);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Failed to update prefix: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Prefix updated for %s.', $package));
        $row = DatabaseConnectionToolkit::describeApp($package, test: false);
        $this->renderConnectionDetails($io, $row);

        return Command::SUCCESS;
    }
}
