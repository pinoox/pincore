<?php

/**
 *      ****  *  *     *  ****  ****  *    *
 *      *  *  *  * *   *  *  *  *  *   *  *
 *      ****  *  *  *  *  *  *  *  *    *
 *      *     *  *   * *  *  *  *  *   *  *
 *      *     *  *    **  ****  ****  *    *
 * @author   Pinoox
 * @link https://www.pinoox.com/
 * @link https://www.pinoox.com/
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Terminal\Migrate;

use Pinoox\Component\Migration\Migrator;
use Pinoox\Component\Terminal;
use Pinoox\Portal\Database\Schema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'migrate:rollback',
    description: 'Rollback migration batches for an app or platform',
    aliases: ['mg:rollback', 'mg:back'],
)]
class MigrateRollbackCommand extends Terminal
{
    use SelectsMigrationPackage;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Rollback the last migration batch, or multiple batches with --step.

Examples:
  php pinoox migrate:rollback com_my_shop
  php pinoox migrate:rollback com_my_shop --step=2
  php pinoox migrate:rollback com_my_shop --all
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, 'App package or platform. Leave empty to pick from the list.')
            ->addOption('step', null, InputOption::VALUE_REQUIRED, 'Number of batches to rollback', '1')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Rollback every executed batch')
            ->addOption('ignore-fk', 'f', InputOption::VALUE_NONE, 'Disable foreign key checks during rollback');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $package = $this->resolvePackage($input, $output, $io);
        $ignoreFk = (bool) $input->getOption('ignore-fk');
        $all = (bool) $input->getOption('all');
        $steps = $all ? 0 : max(1, (int) $input->getOption('step'));

        try {
            if ($ignoreFk) {
                Schema::disableForeignKeyConstraints();
            }

            $messages = (new Migrator($package))->rollback($steps);
            foreach ($messages as $message) {
                if ($message === 'Nothing to rollback.') {
                    $io->success($message);
                    continue;
                }

                $io->writeln((string) $message);
            }

            if ($messages !== ['Nothing to rollback.']) {
                $io->success($all
                    ? 'All migration batches were rolled back.'
                    : sprintf('Rolled back %d batch(es).', $steps));
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            if ($ignoreFk) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }
}
