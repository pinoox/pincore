<?php

namespace Pinoox\Terminal\Database;

use Pinoox\Component\Database\DatabaseConnectionNormalizer;
use Pinoox\Component\Database\DatabaseConnectionToolkit;
use Pinoox\Component\Terminal;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Terminal\Database\Concerns\ManagesCliDatabase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'db:test',
    description: 'Test a platform or app database connection',
    aliases: ['database:test'],
)]
class DbTestCommand extends Terminal
{
    use ManagesCliDatabase;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Test database connectivity for a platform connection or app.

Examples:
  php pinoox db:test mysql
  php pinoox db:test com_my_shop
  php pinoox db:test --host=127.0.0.1 --database=pin --username=root --password=secret
HELP
            )
            ->addArgument('target', InputArgument::OPTIONAL, 'Platform connection name or app package')
            ->addOption('driver', null, InputOption::VALUE_REQUIRED, 'Driver for ad-hoc test (mysql, mariadb, pgsql, sqlsrv)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host for ad-hoc test')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'Database for ad-hoc test')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Username for ad-hoc test')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password for ad-hoc test')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port for ad-hoc test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $this->prepareDatabaseCli();

        $adhoc = $this->readConnectionInput($input, $io);

        if ($adhoc !== []) {
            $config = DatabaseConnectionNormalizer::normalize($adhoc);
            $ok = DatabaseConnectionToolkit::testConfig($config);
            $label = (string) ($config['host'] ?? 'host') . '/' . (string) ($config['database'] ?? 'database');

            return $this->reportResult($io, $ok, $label);
        }

        $target = trim((string) ($input->getArgument('target') ?: ''));

        if ($target === '' && $input->isInteractive()) {
            $target = $this->resolveDatabaseTarget($input, $output, $io, 'Test database for');
        }

        try {
            if ($this->isAppTarget($target)) {
                $row = DatabaseConnectionToolkit::describeApp($target, test: true);
            } elseif ($this->isPlatformTarget($target) || $target === '') {
                $connection = $this->resolvePlatformConnectionTarget($input, $output, $io);
                $row = DatabaseConnectionToolkit::describePlatformConnection($connection, test: true);
            } else {
                $row = DatabaseConnectionToolkit::describePlatformConnection($target, test: true);
            }
        } catch (\InvalidArgumentException $e) {
            if (AppEngine::exists($target)) {
                $row = DatabaseConnectionToolkit::describeApp($target, test: true);
            } else {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        $ok = ($row['status'] ?? '') === 'connected';
        $label = (string) ($row['package'] ?? $row['name'] ?? $target);

        return $this->reportResult($io, $ok, $label);
    }

    private function reportResult(SymfonyStyle $io, bool $ok, string $label): int
    {
        if ($ok) {
            $io->success('Connection successful: ' . $label);

            return Command::SUCCESS;
        }

        $io->error('Connection failed: ' . $label);

        return Command::FAILURE;
    }
}
