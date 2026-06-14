<?php

namespace Pinoox\Terminal\Database;

use Pinoox\Component\Database\DatabaseConnectionNormalizer;
use Pinoox\Component\Database\DatabaseConnectionToolkit;
use Pinoox\Component\Database\PlatformDatabaseStore;
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
    name: 'db:update',
    description: 'Update a platform database connection or app database settings',
    aliases: ['database:update'],
)]
class DbUpdateCommand extends Terminal
{
    use ManagesCliDatabase;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Update platform connection credentials or app database settings.

Platform:
  php pinoox db:update mysql --host=127.0.0.1 --database=pin
  php pinoox db:update mysql --set password=newsecret
  php pinoox db:update mysql --default

App:
  php pinoox db:update com_my_shop --prefix=newshop_
  php pinoox db:update com_my_shop --use=mysql --prefix=shop_
  php pinoox db:update com_my_shop --reset
HELP
            )
            ->addArgument('target', InputArgument::OPTIONAL, 'Platform connection name or app package')
            ->addOption('default', 'd', InputOption::VALUE_NONE, 'Set platform connection as default')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Remove app database block (inherit platform default)')
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
            ->addOption('test', 't', InputOption::VALUE_NONE, 'Test connection after saving');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $this->prepareDatabaseCli();

        $target = trim((string) ($input->getArgument('target') ?: ''));

        if ($target === '' && $input->isInteractive()) {
            $target = $this->resolveDatabaseTarget($input, $output, $io, 'Update database for');
        }

        if ($target === '') {
            $io->error('Target is required (platform connection name or app package).');

            return Command::FAILURE;
        }

        if ($this->isAppTarget($target)) {
            return $this->updateAppDatabase($input, $io, $target);
        }

        if ($this->isPlatformTarget($target)) {
            $target = $this->resolvePlatformConnectionTarget($input, $output, $io);
        }

        return $this->updatePlatformConnection($input, $output, $io, $target);
    }

    private function updatePlatformConnection(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $target,
    ): int {
        if (!in_array($target, $this->platformConnectionNames(), true)) {
            $io->error('Platform connection not found: ' . $target);

            return Command::FAILURE;
        }

        $name = $target;

        if ($input->getOption('default')) {
            if (!PlatformDatabaseStore::setDefault($name)) {
                $io->error('Failed to set default connection.');

                return Command::FAILURE;
            }

            $io->success(sprintf('Default platform connection set to "%s".', $name));

            return Command::SUCCESS;
        }

        $partial = $this->readConnectionInput($input, $io);

        if ($partial === []) {
            $io->warning('No changes provided.');

            return Command::SUCCESS;
        }

        $root = PlatformDatabaseStore::platformRoot();
        $current = is_array($root['connections'][$name] ?? null) ? $root['connections'][$name] : [];
        $merged = array_replace($current, $partial);
        $config = DatabaseConnectionNormalizer::normalize($merged, DatabaseConnectionNormalizer::driverName($merged));

        if ($input->getOption('test') && !DatabaseConnectionToolkit::testConfig($config)) {
            $io->error('Connection test failed. Nothing was saved.');

            return Command::FAILURE;
        }

        if (!PlatformDatabaseStore::updateConnection($name, $config)) {
            $io->error('Failed to update platform connection.');

            return Command::FAILURE;
        }

        $io->success(sprintf('Platform connection "%s" updated.', $name));
        $this->envOverrideNote($io);

        return Command::SUCCESS;
    }

    private function updateAppDatabase(InputInterface $input, SymfonyStyle $io, string $package): int
    {
        if ($input->getOption('reset')) {
            DatabaseConnectionToolkit::saveAppDatabase($package, null);
            $io->success(sprintf('Database config removed from %s (uses platform default).', $package));

            return Command::SUCCESS;
        }

        $current = AppEngine::config($package)->get('database');
        $currentBlock = is_array($current) ? $current : null;
        $inputData = $this->readConnectionInput($input, $io);
        $databaseBlock = DatabaseConnectionToolkit::buildAppDatabaseBlock($inputData, $currentBlock);

        if ($databaseBlock === [] && $currentBlock === null) {
            $io->warning('No changes provided.');

            return Command::SUCCESS;
        }

        if (isset($databaseBlock['driver']) || isset($databaseBlock['host'])) {
            $config = DatabaseConnectionNormalizer::normalize(
                $databaseBlock,
                DatabaseConnectionNormalizer::driverName($databaseBlock),
            );

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

        $io->success(sprintf('Database config updated for %s.', $package));
        $row = DatabaseConnectionToolkit::describeApp($package, test: (bool) $input->getOption('test'));
        $this->renderConnectionDetails($io, $row);

        return Command::SUCCESS;
    }
}
