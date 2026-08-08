<?php

namespace Pinoox\Terminal\Migrate;

use Pinoox\Component\Migration\MigrationCreator;
use Pinoox\Component\Migration\MigrationToolkit;
use Pinoox\Component\Terminal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'migrate:create',
    description: 'Create a new database migration file',
    aliases: ['mg:create', 'mg:make', 'make:migration'],
)]
class MigrateCreateCommand extends Terminal
{
    use SelectsMigrationPackage;

    private string $package;

    private MigrationToolkit $mig;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Create a migration file. The stub is chosen from the name or from --create / --table.

Examples:
  php pinoox migrate:create posts com_my_shop
  php pinoox migrate:create CreatePosts com_my_shop
  php pinoox migrate:create create_products_table com_my_shop
  php pinoox migrate:create add_email_to_users com_my_shop
  php pinoox migrate:create drop_posts_table com_my_shop
  php pinoox migrate:create sync_legacy_flags com_my_shop --table=users
  php pinoox make:migration add_status --create=orders com_my_shop

migrate:drop hard-drops tables. To scaffold a DROP/ALTER file, use migrate:create.
HELP
            )
            ->addArgument('migration', InputArgument::REQUIRED, 'Migration name (e.g. create_products_table, add_email_to_users)')
            ->addArgument('package', InputArgument::OPTIONAL, 'App package or platform. Leave empty to pick from the list.')
            ->addOption('create', null, InputOption::VALUE_REQUIRED, 'The table to be created')
            ->addOption('table', null, InputOption::VALUE_REQUIRED, 'The table to migrate (update stub)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->package = $this->resolvePackage($input, $output, new SymfonyStyle($input, $output));

        $this->init();
        $this->create($input);

        return Command::SUCCESS;
    }

    private function init(): void
    {
        try {
            $this->mig = new MigrationToolkit();
            $this->mig->package($this->package)->action('create')
                ->load();
        } catch (\Exception $e) {
            $this->error($e);
        }

        if (!$this->mig->isSuccess()) {
            $this->error($this->mig->getErrors());
        }
    }

    private function create(InputInterface $input): void
    {
        try {
            $result = (new MigrationCreator())->create(
                $this->mig->getMigrationPath(),
                (string) $input->getArgument('migration'),
                $this->getNamespace(),
                $this->optionString($input, 'create'),
                $this->optionString($input, 'table'),
            );

            $this->success('✓ Migration [' . $result['name'] . '] created successfully');
            $this->newLine();
        } catch (\Exception $e) {
            $this->error($e);
        }
    }

    private function optionString(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function getNamespace(): string
    {
        return $this->package === 'platform'
            ? 'Pinoox\\Database\\migrations'
            : 'App\\' . $this->package . '\\database\\migrations';
    }
}
