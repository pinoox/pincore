<?php

namespace Pinoox\Terminal\Permission;

use Pinoox\Component\Terminal;
use Pinoox\Model\PermissionModel;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Pinoox\Terminal\Role\Concerns\ManagesCliRoles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'permission:list',
    description: 'List permissions for an app or platform',
    aliases: ['permissions'],
)]

class PermissionListCommand extends Terminal
{
    use SelectsPackage;
    use ManagesCliRoles;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
List permissions in the current access scope.

Examples:
  php pinoox permission:list
  php pinoox permission:list com_my_shop
  php pinoox permission:list com_my_shop --roles
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('roles', 'r', InputOption::VALUE_NONE, 'Include roles that use each permission')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveRolePackageInput($input, $output, $io, 'List permissions for');
        $this->prepareRoleScope($package);

        $withRoles = (bool) $input->getOption('roles');
        $permissions = PermissionModel::query()->orderBy('permission_key')->get();

        if ($input->getOption('json')) {
            $rows = $permissions->map(
                fn (PermissionModel $permission) => $this->permissionRow($permission, $withRoles),
            )->values()->all();
            $io->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ($permissions->isEmpty()) {
            $io->warning('No permissions found for package: ' . $package);
            $io->note('Create one with: php pinoox permission:create ' . $package . ' --key=manager.* --name="Manager access"');

            return Command::SUCCESS;
        }

        $io->title('Permissions — ' . $package);

        $headers = ['ID', 'Key', 'Name', 'Description'];
        if ($withRoles) {
            $headers[] = 'Roles';
        }

        $table = new Table($output);
        $table->setHeaders($headers);

        foreach ($permissions as $permission) {
            $row = [
                $permission->permission_id,
                $permission->permission_key,
                $permission->name ?: '—',
                $permission->description ?: '—',
            ];

            if ($withRoles) {
                $keys = $permission->roles()->pluck('role_key')->all();
                $row[] = $keys === [] ? '—' : implode(', ', $keys);
            }

            $table->addRow($row);
        }

        $table->render();
        $io->text('Total: ' . $permissions->count());

        return Command::SUCCESS;
    }
}
