<?php

namespace Pinoox\Terminal\Role;

use Pinoox\Component\Terminal;
use Pinoox\Model\RoleModel;
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
    name: 'role:list',
    description: 'List roles for an app or platform',
    aliases: ['roles'],
)]

class RoleListCommand extends Terminal
{
    use SelectsPackage;
    use ManagesCliRoles;
    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
List roles in the current access scope (respects transport.access_table).
Examples:
  php pinoox role:list
  php pinoox role:list com_my_shop
  php pinoox role:list platform --permissions
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('permissions', 'p', InputOption::VALUE_NONE, 'Include permission keys')
            ->addOption('users', 'u', InputOption::VALUE_NONE, 'Include assigned user count')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveRolePackageInput($input, $output, $io, 'List roles for');
        $this->prepareRoleScope($package);
        $withPermissions = (bool) $input->getOption('permissions');
        $withUsers = (bool) $input->getOption('users');
        $roles = RoleModel::query()->orderBy('role_key')->get();
        if ($input->getOption('json')) {
            $rows = $roles->map(
                fn (RoleModel $role) => $this->roleRow($role, $withPermissions, $withUsers),
            )->values()->all();
            $io->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }
        if ($roles->isEmpty()) {
            $io->warning('No roles found for package: ' . $package);
            $io->note('Create one with: php pinoox role:create ' . $package . ' --key=admin --name=Administrator');
            return Command::SUCCESS;
        }
        $io->title('Roles — ' . $package);
        $headers = ['ID', 'Key', 'Name', 'Description'];
        if ($withPermissions) {
            $headers[] = 'Permissions';
        }
        if ($withUsers) {
            $headers[] = 'Users';
        }
        $table = new Table($output);
        $table->setHeaders($headers);
        foreach ($roles as $role) {
            $row = [
                $role->role_id,
                $role->role_key,
                $role->name ?: '—',
                $role->description ?: '—',
            ];
            if ($withPermissions) {
                $keys = $role->permissions()->pluck('permission_key')->all();
                $row[] = $keys === [] ? '—' : implode(', ', $keys);
            }
            if ($withUsers) {
                $row[] = $role->users()->count();
            }
            $table->addRow($row);
        }
        $table->render();
        $io->text('Total: ' . $roles->count());
        return Command::SUCCESS;
    }
}
