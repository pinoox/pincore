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
    name: 'config:set',
    description: 'Set keys in app.php, theme.php, or *.config.php (Pinker runtime overlay)',
)]
class ConfigSetCommand extends Terminal
{
    use ManagesCliConfig;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Writes Pinker runtime overlays (pinker/state), not the git source files.

App manifest (app.php):
  php pinoox config:set com_my_shop theme spark
  php pinoox config:set com_my_shop --set theme=spark --set enable=true

Theme manifest (theme.php):
  php pinoox config:set com_my_shop --file=theme.php --theme=spark name=spark
  php pinoox config:set com_my_shop developer pinoox --file=theme.php --theme=spark

App / platform config:
  php pinoox config:set com_my_shop enabled true --file=options
  php pinoox config:set platform connections.mysql.host 127.0.0.1 --file=database

Values: true, false, null, numbers, JSON objects/arrays, or strings.
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, 'App package or platform. Leave empty to pick from the list.')
            ->addArgument('key', InputArgument::OPTIONAL, 'Dot-notation key, or key=value')
            ->addArgument('value', InputArgument::OPTIONAL, 'Value when key is not written as key=value')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'app.php (default), theme.php, or config name (options, database, …)', 'app.php')
            ->addOption('theme', 't', InputOption::VALUE_REQUIRED, 'Theme folder when --file=theme.php (default: active theme)')
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Set key=value (repeatable)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the written keys as JSON')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show changes without saving');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);

        try {
            $target = $this->resolveConfigTarget($input, $output, $io, 'Set config for');
            $pairs = $this->collectConfigPairs($input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($pairs === []) {
            $io->error('Nothing to set. Pass {key} {value} or --set key=value.');

            return Command::FAILURE;
        }

        try {
            foreach (array_keys($pairs) as $key) {
                $this->assertWritableKey($target, $key);
            }
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($input->getOption('dry-run')) {
            $rows = [];

            foreach ($pairs as $key => $value) {
                $rows[] = [$key, $this->formatCliValue($target['config']->get($key)), $this->formatCliValue($value)];
            }

            $io->title('Config dry-run (' . $target['label'] . ')');
            $io->table(['Key', 'Current', 'New'], $rows);

            return Command::SUCCESS;
        }

        foreach ($pairs as $key => $value) {
            $target['config']->set($key, $value);
        }

        $target['config']->save();
        $this->rememberConfigChange($target['package']);

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'package' => $target['package'],
                'file' => $target['label'],
                'set' => $pairs,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        $written = [];
        foreach ($pairs as $key => $value) {
            $written[] = $key . '=' . $this->formatCliValue($value);
        }

        $io->success(sprintf(
            'Updated %s for %s: %s',
            $target['label'],
            $target['package'],
            implode(', ', $written),
        ));

        return Command::SUCCESS;
    }
}
