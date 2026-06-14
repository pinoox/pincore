<?php

namespace Pinoox\Terminal\Database;

use Pinoox\Component\Database\DatabaseConnectionNormalizer;
use Pinoox\Component\Database\DatabaseConnectionToolkit;
use Pinoox\Component\Database\PlatformDatabaseStore;
use Pinoox\Component\Terminal;
use Pinoox\Portal\App\AppEngine;
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
    name: 'db:create',
    description: 'Add a platform database connection or configure an app database',
    aliases: ['database:create', 'make:db'],
)]
class DbCreateCommand extends Terminal
{
    use ManagesCliDatabase;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Create a platform connection or configure app database settings in app.php.

Platform:
  php pinoox db:create --name=reports --driver=mysql --host=127.0.0.1 --database=reports --username=root --password=secret
  php pinoox db:create --name=reports --default

App (reuse platform connection with custom prefix):
  php pinoox db:create com_my_shop --use=platform --prefix=shop_
  php pinoox db:create com_my_shop --use=mysql --prefix=shop_

App (dedicated connection):
  php pinoox db:create com_my_shop --driver=mysql --host=127.0.0.1 --database=shop --username=root --password=secret --prefix=shop_
HELP
            )
            ->addArgument('target', InputArgument::OPTIONAL, 'App package, or leave empty for platform connection')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Platform connection name (platform only)')
            ->addOption('default', 'd', InputOption::VALUE_NONE, 'Set as platform default connection')
            ->addOption('driver', null, InputOption::VALUE_REQUIRED, 'Driver: mysql, mariadb, pgsql, sqlsrv')
            ->addOption('use', 'u', InputOption::VALUE_REQUIRED, 'Reuse platform connection (platform, mysql, …)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Database host')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'Database name')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Database username')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Database password')
            ->addOption('prefix', 'p', InputOption::VALUE_REQUIRED, 'Table prefix')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Database port')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Timezone (mysql/mariadb)')
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Set key=value (repeatable)')
            ->addOption('test', 't', InputOption::VALUE_NONE, 'Test connection before saving');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $this->prepareDatabaseCli();

        $target = trim((string) ($input->getArgument('target') ?: ''));

        if ($target === '' || $target === 'platform') {
            return $this->createPlatformConnection($input, $io);
        }

        if (!$this->isAppTarget($target)) {
            $io->error('Package not found: ' . $target);

            return Command::FAILURE;
        }

        return $this->configureAppDatabase($input, $io, $target);
    }

    private function createPlatformConnection(InputInterface $input, SymfonyStyle $io): int
    {
        $name = strtolower(trim((string) ($input->getOption('name') ?: '')));

        if ($name === '' && $input->isInteractive()) {
            $name = strtolower(trim((string) $io->ask('Connection name (e.g. mysql, analytics)')));
        }

        if ($name === '') {
            $io->error('Connection name is required (--name).');

            return Command::FAILURE;
        }

        try {
            $this->validateConnectionName($name);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $existing = $this->platformConnectionNames();

        if (in_array($name, $existing, true)) {
            $io->error('Connection already exists: ' . $name . '. Use db:update instead.');

            return Command::FAILURE;
        }

        $data = $this->readConnectionInput($input, $io, requireCredentials: true);
        $data['driver'] = DatabaseConnectionNormalizer::driverName($data, $this->defaultPlatformDriver());
        $config = DatabaseConnectionNormalizer::normalize($data, $data['driver']);

        if ($input->getOption('test') && !DatabaseConnectionToolkit::testConfig($config)) {
            $io->error('Connection test failed. Nothing was saved.');

            return Command::FAILURE;
        }

        if (!PlatformDatabaseStore::saveConnection($name, $config, (bool) $input->getOption('default'))) {
            $io->error('Failed to save platform connection.');

            return Command::FAILURE;
        }

        $io->success(sprintf('Platform connection "%s" created.', $name));

        if ($input->getOption('default')) {
            $io->note('Set as default connection.');
        }

        $this->envOverrideNote($io);

        return Command::SUCCESS;
    }

    private function configureAppDatabase(InputInterface $input, SymfonyStyle $io, string $package): int
    {
        $current = AppEngine::config($package)->get('database');
        $currentBlock = is_array($current) ? $current : null;
        $inputData = $this->readConnectionInput($input, $io);
        $databaseBlock = DatabaseConnectionToolkit::buildAppDatabaseBlock($inputData, $currentBlock);

        if ($databaseBlock === [] && $currentBlock === null) {
            if ($input->isInteractive()) {
                $use = (string) $io->ask('Platform connection to use', 'platform');
                $prefix = (string) $io->ask('Table prefix (e.g. shop_)', DB::tablePrefixForPackage($package));
                $databaseBlock = DatabaseConnectionToolkit::cleanupAppDatabaseBlock([
                    'use' => $use,
                    'prefix' => $prefix,
                ]);
            } else {
                $io->error('Provide --use and/or --prefix, or dedicated credentials.');

                return Command::FAILURE;
            }
        }

        if (isset($databaseBlock['driver']) || isset($databaseBlock['host'])) {
            $config = DatabaseConnectionNormalizer::normalize($databaseBlock, DatabaseConnectionNormalizer::driverName($databaseBlock));

            if ($input->getOption('test') && !DatabaseConnectionToolkit::testConfig($config)) {
                $io->error('Connection test failed. app.php was not changed.');

                return Command::FAILURE;
            }
        }

        try {
            DatabaseConnectionToolkit::saveAppDatabase($package, $databaseBlock === [] ? null : $databaseBlock);
        } catch (\Throwable $e) {
            $io->error('Failed to save app database config: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Database config saved for %s.', $package));
        $row = DatabaseConnectionToolkit::describeApp($package, test: (bool) $input->getOption('test'));
        $this->renderConnectionDetails($io, $row);

        return Command::SUCCESS;
    }
}
