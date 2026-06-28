<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Database\Seeder\SeederToolkit;
use Pinoox\Component\Terminal;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'devdb:seed', description: 'Run app seeders against Pinoox DevDB')]
class DevDbSeedCommand extends Terminal
{
    use SelectsPackage;
    use UsesDevDbStore;

    protected function configure(): void
    {
        $this
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp())
            ->addOption('class', 'c', InputOption::VALUE_OPTIONAL, 'Run only one seeder class')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Continue running even if a seeder fails');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $this->forceDevDbConnection();
        $io = new SymfonyStyle($input, $output);
        $package = $this->resolvePackageRequired($input, $output, $io, [
            'sectionTitle' => 'Run DevDB seeders for',
        ]);
        $onlyClass = $input->getOption('class');

        $toolkit = new SeederToolkit();
        $toolkit->package($package)->load();

        if (!$toolkit->isSuccess()) {
            $io->error((string) $toolkit->getErrors());

            return Command::FAILURE;
        }

        $ran = 0;
        foreach ($toolkit->getSeeders() as $seeder) {
            if (is_string($onlyClass) && $onlyClass !== '' && $seeder['class'] !== $onlyClass) {
                continue;
            }

            try {
                $seeder['instance']->run();
                $ran++;
            } catch (\Throwable $e) {
                $io->error($e->getMessage());
                if (!$input->getOption('force')) {
                    return Command::FAILURE;
                }
            }
        }

        $io->success('DevDB seeders completed. Ran: ' . $ran);

        return Command::SUCCESS;
    }
}

