<?php

namespace Pinoox\Terminal\Permission;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Pinoox\Terminal\Role\Concerns\ManagesCliRoles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'permission:show',
    description: 'Show details for a permission',
)]

class PermissionShowCommand extends Terminal
{
    use SelectsPackage;
    use ManagesCliRoles;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Show a permission by id or permission_key.

Examples:
  php pinoox permission:show manager.*
  php pinoox permission:show com_my_shop posts.edit
HELP
            )
            ->addArgument('permission', InputArgument::OPTIONAL, 'Permission id or permission_key. Leave empty to pick from the list.')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);

        try {
            $package = $this->resolveRolePackageInput($input, $output, $io, 'Show permission for');
            $this->prepareRoleScope($package);

            $permission = $this->resolvePermissionInput($input, $output, $io, 'Select permission to show');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($permission === null) {
            $io->error('Permission not found.');

            return Command::FAILURE;
        }

        $roles = $permission->roles()->pluck('role_key')->all();
        $row = $this->permissionRow($permission, withRoles: true);

        if ($input->getOption('json')) {
            $io->writeln(json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('Permission #' . $permission->permission_id);
        $io->definitionList(
            ['Package context' => $package],
            ['Key' => (string) $permission->permission_key],
            ['Name' => (string) ($permission->name ?: '—')],
            ['Description' => (string) ($permission->description ?: '—')],
            ['App scope' => (string) ($permission->app ?: '—')],
            ['Roles' => $roles === [] ? '—' : implode(', ', $roles)],
            ['Created' => (string) ($permission->created_at?->format('Y-m-d H:i:s') ?: '—')],
        );

        return Command::SUCCESS;
    }
}
