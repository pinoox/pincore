<?php

namespace Pinoox\Terminal\Pincore;

use Pinoox\Component\Foundation\ApplicationPaths;
use Pinoox\Component\Terminal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'config:publish',
    description: 'Publish default pincore config stubs to the project config/ directory',
)]
class ConfigPublishCommand extends Terminal
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $source = ApplicationPaths::pincoreConfigPath();
        $target = ApplicationPaths::projectConfigPath();

        if (!is_dir($source)) {
            $output->writeln('<error>Pincore config directory not found.</error>');

            return Command::FAILURE;
        }

        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }

        $published = 0;
        foreach (glob($source . '/*.config.php') ?: [] as $file) {
            $name = basename($file);
            $dest = $target . '/' . $name;

            if (is_file($dest)) {
                $output->writeln("  <comment>=</comment> {$name} (exists, skipped)");

                continue;
            }

            copy($file, $dest);
            $output->writeln("  <info>+</info> {$name}");
            $published++;
        }

        $appSource = $source . '/app';
        if (is_dir($appSource)) {
            $appTarget = $target . '/app';
            if (!is_dir($appTarget)) {
                mkdir($appTarget, 0755, true);
            }

            foreach (glob($appSource . '/*.config.php') ?: [] as $file) {
                $name = 'app/' . basename($file);
                $dest = $target . '/' . $name;

                if (is_file($dest)) {
                    $output->writeln("  <comment>=</comment> {$name} (exists, skipped)");

                    continue;
                }

                copy($file, $dest);
                $output->writeln("  <info>+</info> {$name}");
                $published++;
            }
        }

        $output->writeln("<info>Published {$published} config file(s) to {$target}</info>");

        return Command::SUCCESS;
    }
}
