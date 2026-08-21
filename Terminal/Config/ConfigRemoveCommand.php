<?php

namespace Pinoox\Terminal\Config;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\Config\Concerns\ManagesCliConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'config:remove',
    description: 'Remove keys from app.php, theme.php, or *.config.php (Pinker runtime overlay)',
    aliases: ['config:unset'],
)]
class ConfigRemoveCommand extends Terminal
{
    use ManagesCliConfig;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Removes a key from the Pinker overlay / baked config.

  php pinoox config:remove com_my_shop theme
  php pinoox config:remove com_my_shop developer --file=theme.php --theme=spark
  php pinoox config:remove com_my_shop enabled --file=options
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, 'App package or platform. Leave empty to pick from the list.')
            ->addArgument('key', InputArgument::OPTIONAL, 'Dot-notation key to remove')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'app.php (default), theme.php, or config name', 'app.php')
            ->addOption('theme', 't', InputOption::VALUE_REQUIRED, 'Theme folder when --file=theme.php (default: active theme)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);

        try {
            $target = $this->resolveConfigTarget($input, $output, $io, 'Remove config from');
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $key = trim((string) ($input->getArgument('key') ?: ''));

        if ($key === '' && $input->isInteractive()) {
            $key = trim((string) $io->ask('Key to remove'));
        }

        if ($key === '') {
            $io->error('Key is required.');

            return Command::FAILURE;
        }

        try {
            $this->assertWritableKey($target, $key);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $target['config']->remove($key);
        $target['config']->save();
        $this->rememberConfigChange($target['package']);

        $io->success(sprintf('Removed %s from %s (%s).', $key, $target['label'], $target['package']));

        return Command::SUCCESS;
    }
}
