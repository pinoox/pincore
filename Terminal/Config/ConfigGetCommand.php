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
    name: 'config:get',
    description: 'Read keys from app.php, theme.php, or *.config.php',
    aliases: ['config:show'],
)]
class ConfigGetCommand extends Terminal
{
    use ManagesCliConfig;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Read a Pinker-resolved config value (source + bake + state overlay).

  php pinoox config:get com_my_shop theme
  php pinoox config:get com_my_shop
  php pinoox config:get com_my_shop name --file=theme.php --theme=spark
  php pinoox config:get com_my_shop enabled --file=options
  php pinoox config:get platform connections.mysql.host --file=database --json
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, 'App package or platform. Leave empty to pick from the list.')
            ->addArgument('key', InputArgument::OPTIONAL, 'Dot-notation key. Omit to print the whole file.')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'app.php (default), theme.php, or config name', 'app.php')
            ->addOption('theme', 't', InputOption::VALUE_REQUIRED, 'Theme folder when --file=theme.php (default: active theme)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);

        try {
            $target = $this->resolveConfigTarget($input, $output, $io, 'Read config for');
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $key = trim((string) ($input->getArgument('key') ?: ''));
        $value = $key === '' ? $target['config']->get() : $target['config']->get($key);

        if ($input->getOption('json')) {
            $payload = [
                'package' => $target['package'],
                'file' => $target['label'],
                'key' => $key === '' ? null : $key,
                'value' => $value,
            ];
            $output->writeln(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        if ($key === '') {
            $io->title($target['package'] . ' · ' . $target['label']);
            $output->writeln($this->formatCliValue($value));

            return Command::SUCCESS;
        }

        $output->writeln($this->formatCliValue($value));

        return Command::SUCCESS;
    }
}
