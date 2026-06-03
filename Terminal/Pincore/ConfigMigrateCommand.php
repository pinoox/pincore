<?php

namespace Pinoox\Terminal\Pincore;

use Pinoox\Component\Foundation\ApplicationPaths;
use Pinoox\Component\Terminal;
use Pinoox\Portal\FileSystem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'config:migrate',
    description: 'Migrate baked config from pincore/pinker to storage/pinoox/config',
)]
class ConfigMigrateCommand extends Terminal
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $legacyRoot = ApplicationPaths::legacyCorePinkerPath('config');
        $targetRoot = ApplicationPaths::runtimeConfigPath();

        if (!is_dir($legacyRoot)) {
            $output->writeln('<comment>No legacy pincore/pinker/config directory found. Nothing to migrate.</comment>');

            return Command::SUCCESS;
        }

        $migrated = $this->copyTree($legacyRoot, $targetRoot, $output);

        $output->writeln("<info>Migrated {$migrated} config file(s) to {$targetRoot}</info>");
        $output->writeln('<comment>You may remove pincore/pinker after verifying the installation.</comment>');

        return Command::SUCCESS;
    }

    private function copyTree(string $source, string $destination, OutputInterface $output): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($source) + 1);
            $target = $destination . '/' . $relative;

            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }

            if (!is_file($target)) {
                copy($file->getPathname(), $target);
                $output->writeln("  <info>+</info> {$relative}");
                $count++;
            } else {
                $output->writeln("  <comment>=</comment> {$relative} (already exists, skipped)");
            }
        }

        return $count;
    }
}
