<?php

namespace Pinoox\Terminal\Patch;

use Pinoox\Component\Database\Patch\PatchToolkit;
use Pinoox\Component\Migration\Migrator;
use Pinoox\Component\Migration\MigrationQuery;
use Pinoox\Component\Terminal;
use Pinoox\Model\HistoryModel;
use Pinoox\Terminal\Migrate\SelectsMigrationPackage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'patch:reset',
    description: 'Rollback all rollbackable patches (or clear history with --clear)',
    aliases: ['patch:clear'],
)]
class PatchResetCommand extends Terminal
{
    use SelectsMigrationPackage;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Rolls back every successful patch that supports down().

Use --clear to also delete patch history records for the package
(including patches without rollback support).

Examples:
  php pinoox patch:reset com_my_shop
  php pinoox patch:reset com_my_shop --clear --force
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, 'App package or platform. Leave empty to pick from the list.')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Also delete all patch history records for the package')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $package = $this->resolvePackage($input, $output, $io);
        $clear = (bool) $input->getOption('clear');

        if (!$input->getOption('force') && !$io->confirm(
            $clear
                ? sprintf('Rollback rollbackable patches and CLEAR history for "%s"?', $package)
                : sprintf('Rollback ALL rollbackable patches for "%s"?', $package),
            false,
        )) {
            $io->warning('Patch reset cancelled.');

            return Command::SUCCESS;
        }

        try {
            (new Migrator('platform'))->run();

            $toolkit = new PatchToolkit();
            $toolkit->package($package)->load();

            if (!$toolkit->isSuccess()) {
                $this->error($toolkit->getErrors());

                return Command::FAILURE;
            }

            $rolled = 0;
            $skipped = 0;

            $patches = $toolkit->getPatches();
            usort($patches, static function (array $a, array $b): int {
                return ((int) ($b['record']['batch'] ?? 0)) <=> ((int) ($a['record']['batch'] ?? 0));
            });

            foreach ($patches as $patch) {
                if (empty($patch['ran'])) {
                    continue;
                }

                if (empty($patch['can_rollback'])) {
                    $io->writeln('Skipped (no down): ' . $patch['name']);
                    $skipped++;
                    continue;
                }

                $startedAt = microtime(true);
                $patch['instance']->down();
                $toolkit->deleteSuccessRecord($patch['name']);
                $toolkit->recordRolledBack($patch['name'], $patch['checksum'], (int) round((microtime(true) - $startedAt) * 1000), [
                    'class' => $patch['class'],
                    'description' => $patch['description'],
                ]);
                $this->success('Rolled back: ' . $patch['name']);
                $rolled++;
            }

            if ($clear) {
                HistoryModel::where('type', MigrationQuery::TYPE_PATCH)
                    ->where('app', $package)
                    ->delete();
                $io->writeln('Patch history cleared for package: ' . $package);
            }

            $io->success(sprintf('Patch reset finished. Rolled back: %d, skipped: %d.', $rolled, $skipped));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
