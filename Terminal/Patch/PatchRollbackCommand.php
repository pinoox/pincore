<?php

namespace Pinoox\Terminal\Patch;

use Pinoox\Component\Database\Patch\PatchToolkit;
use Pinoox\Component\Migration\Migrator;
use Pinoox\Component\Terminal;
use Pinoox\Terminal\Migrate\SelectsMigrationPackage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'patch:rollback',
    description: 'Rollback executed data patches',
    aliases: ['patch:rb'],
)]
class PatchRollbackCommand extends Terminal
{
    use SelectsMigrationPackage;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Rollback the last successful patch, a specific patch, or multiple steps.

Examples:
  php pinoox patch:rollback com_my_shop
  php pinoox patch:rollback fix_user_roles com_my_shop
  php pinoox patch:rollback com_my_shop --step=2
  php pinoox patch:rollback com_my_shop --all
HELP
            )
            ->addArgument('patch', InputArgument::OPTIONAL, 'Patch name/class to rollback. Omit to rollback the latest.')
            ->addArgument('package', InputArgument::OPTIONAL, 'App package or platform. Leave empty to pick from the list.')
            ->addOption('step', null, InputOption::VALUE_REQUIRED, 'Number of successful patches to rollback', '1')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Rollback every successful patch that supports down()');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        [$target, $package] = $this->resolveTargetAndPackage($input, $output, $io);
        $all = (bool) $input->getOption('all');
        $steps = $all ? PHP_INT_MAX : max(1, (int) $input->getOption('step'));

        try {
            (new Migrator('platform'))->run();

            $toolkit = new PatchToolkit();
            $toolkit->package($package)->load();

            if (!$toolkit->isSuccess()) {
                $this->error($toolkit->getErrors());

                return Command::FAILURE;
            }

            $patches = $toolkit->getPatches();
            if ($patches === []) {
                $io->warning('No patches found in package: ' . $package);

                return Command::SUCCESS;
            }

            if ($target !== null && $target !== '') {
                return $this->rollbackNamedPatch($toolkit, $patches, $target, $io);
            }

            return $this->rollbackLatestPatches($toolkit, $patches, $steps, $io);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function resolveTargetAndPackage(InputInterface $input, OutputInterface $output, SymfonyStyle $io): array
    {
        $first = $input->getArgument('patch');
        $second = $input->getArgument('package');

        if ($second !== null && $second !== '') {
            return [(string) $first, (string) $second];
        }

        if ($first === null || $first === '') {
            return [null, $this->resolvePackage($input, $output, $io)];
        }

        $candidate = (string) $first;
        if ($this->looksLikePackage($candidate)) {
            $input->setArgument('package', $candidate);
            $input->setArgument('patch', null);

            return [null, $this->resolvePackage($input, $output, $io)];
        }

        return [$candidate, $this->resolvePackage($input, $output, $io)];
    }

    private function looksLikePackage(string $value): bool
    {
        if ($value === 'platform') {
            return true;
        }

        if (str_starts_with($value, 'com_')) {
            return true;
        }

        try {
            $path = \Pinoox\Portal\App\AppEngine::path($value);

            return is_string($path) && $path !== '' && is_dir($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<array<string, mixed>> $patches
     */
    private function rollbackNamedPatch(PatchToolkit $toolkit, array $patches, string $target, SymfonyStyle $io): int
    {
        foreach ($patches as $patch) {
            if (!$this->matches($patch, $target)) {
                continue;
            }

            return $this->rollbackOne($toolkit, $patch, $io) ? Command::SUCCESS : Command::FAILURE;
        }

        $this->error('Patch not found: ' . $target);

        return Command::FAILURE;
    }

    /**
     * @param list<array<string, mixed>> $patches
     */
    private function rollbackLatestPatches(PatchToolkit $toolkit, array $patches, int $steps, SymfonyStyle $io): int
    {
        $candidates = array_values(array_filter(
            $patches,
            static fn (array $patch): bool => !empty($patch['ran']) && !empty($patch['can_rollback']),
        ));

        usort($candidates, static function (array $a, array $b): int {
            $aBatch = (int) (($a['record']['batch'] ?? 0));
            $bBatch = (int) (($b['record']['batch'] ?? 0));
            if ($aBatch !== $bBatch) {
                return $bBatch <=> $aBatch;
            }

            return ((int) ($b['record']['id'] ?? 0)) <=> ((int) ($a['record']['id'] ?? 0));
        });

        if ($candidates === []) {
            $io->warning('No rollbackable patches found.');

            return Command::SUCCESS;
        }

        $rolled = 0;
        foreach ($candidates as $patch) {
            if ($rolled >= $steps) {
                break;
            }

            if (!$this->rollbackOne($toolkit, $patch, $io)) {
                return Command::FAILURE;
            }

            $rolled++;
        }

        $io->success(sprintf('Rolled back %d patch(es).', $rolled));

        return Command::SUCCESS;
    }

    private function rollbackOne(PatchToolkit $toolkit, array $patch, SymfonyStyle $io): bool
    {
        if (empty($patch['ran'])) {
            $io->warning('Patch has not been executed: ' . $patch['name']);

            return true;
        }

        if (empty($patch['can_rollback'])) {
            $io->warning('Patch does not declare rollback support: ' . $patch['name']);

            return false;
        }

        $startedAt = microtime(true);
        $patch['instance']->down();
        $toolkit->deleteSuccessRecord($patch['name']);
        $toolkit->recordRolledBack($patch['name'], $patch['checksum'], $this->durationMs($startedAt), [
            'class' => $patch['class'],
            'description' => $patch['description'],
        ]);

        $this->success('Rolled back: ' . $patch['name']);

        return true;
    }

    private function matches(array $patch, string $target): bool
    {
        $normalizedTarget = $this->normalizePatchName($target);
        $normalizedPatch = $this->normalizePatchName($patch['name']);

        return $patch['name'] === $target
            || $patch['class'] === $target
            || basename(str_replace('\\', '/', $patch['class'])) === $target
            || $normalizedPatch === $normalizedTarget;
    }

    private function normalizePatchName(string $patch): string
    {
        $patch = pathinfo($patch, PATHINFO_FILENAME);

        if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_(.+)$/', $patch, $matches)) {
            return $matches[1];
        }

        return $patch;
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
